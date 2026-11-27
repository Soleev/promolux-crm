<?php

namespace App\Filament\Pages;

use App\Filament\CrmForms;
use App\Filament\CrmTableUi;
use App\Models\Accrual;
use App\Models\CommissionSettlement;
use App\Models\User;
use App\Services\CommissionBalances;
use App\Services\CommissionSettlements as SettlementService;
use App\Services\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CommissionSettlements extends Page implements HasTable
{
    use InteractsWithTable;

    private const KINDS = [
        CommissionSettlement::REPAYMENT => 'Погашение',
        CommissionSettlement::WRITE_OFF => 'Списание',
    ];

    private const METHODS = ['cash' => 'Наличные', 'bank_transfer' => 'Безналичный перевод'];

    protected static ?string $title = 'Расчёты по комиссиям';

    protected static string|\UnitEnum|null $navigationGroup = 'Финансы';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected string $view = 'filament.pages.commission-settlements';

    public static function canAccess(): bool
    {
        $actor = auth()->user();

        return $actor?->active === true && $actor->role === 'owner';
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->settlementAction(CommissionSettlement::REPAYMENT, 'Зафиксировать погашение', 'heroicon-o-banknotes'),
            $this->settlementAction(CommissionSettlement::WRITE_OFF, 'Списать задолженность сотрудника', 'heroicon-o-document-minus'),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(function (): Builder {
                abort_unless(static::canAccess(), 403);

                return CommissionSettlement::query()->with('beneficiary:id,name')->addSelect([
                    'recorder_name' => User::query()->select('name')->whereColumn('users.id', 'commission_settlements.recorded_by'),
                ]);
            })
            ->columns([
                TextColumn::make('settled_on')->label('Дата операции')->date('d.m.Y')->sortable(),
                TextColumn::make('kind')->label('Операция')->badge()->formatStateUsing(fn (string $state): string => self::KINDS[$state]),
                TextColumn::make('beneficiary.name')->label('Получатель начисления')->searchable()->sortable(),
                TextColumn::make('payer')->label('Исходный плательщик')->formatStateUsing(fn (string $state): string => self::payerLabel($state)),
                TextColumn::make('amount_minor')->label('Сумма')->alignEnd()->sortable()
                    ->formatStateUsing(fn (CommissionSettlement $record): string => $record->currency === 'UZS'
                        ? Money::display($record->amount_minor)
                        : Money::format($record->amount_minor).' '.$record->currency),
                TextColumn::make('method')->label('Способ')->formatStateUsing(fn (?string $state): ?string => self::METHODS[$state] ?? null)->placeholder('—'),
                TextColumn::make('reason')->label('Причина')->wrap()->searchable(),
                TextColumn::make('recorder_name')->label('Оформил')->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('kind')->label('Операция')->options(self::KINDS),
                CrmTableUi::period('settled_on', 'Дата операции'),
            ])
            ->defaultSort('settled_on', 'desc')
            ->emptyStateHeading('Расчётов по комиссиям пока нет');
    }

    private function settlementAction(string $kind, string $label, string $icon): Action
    {
        $fields = [
            Hidden::make('idempotency_key')->default(fn (): string => (string) Str::uuid())->required()->rule('uuid'),
            Select::make('beneficiary_id')->label('Получатель исходной комиссии')->required()->searchable()->live()
                ->options(fn (): array => User::query()->whereIn('id', $this->accounts($kind)->select('beneficiary_id'))
                    ->orderBy('name')->pluck('name', 'id')->all())
                ->afterStateUpdated(fn (Set $set) => $set('payer', null)),
            Select::make('payer')->label('Исходный плательщик')->required()->live()
                ->options(fn (Get $get): array => $this->payerOptions($kind, $get('beneficiary_id'))),
            Text::make(fn (Get $get): string => $this->debtNotice($kind, $get('beneficiary_id'), $get('payer')))
                ->visible(fn (Get $get): bool => filled($get('payer'))),
            CrmForms::money(),
            DatePicker::make('settled_on')->label('Дата операции')->default(today())->maxDate(today())->required(),
            Textarea::make('reason')->label('Причина')->required()->maxLength(2000),
        ];
        if ($kind === CommissionSettlement::REPAYMENT) {
            $fields[] = Select::make('method')->label('Способ погашения')->options(self::METHODS)->required();
            $fields[] = Checkbox::make('money_transferred')
                ->label('Подтверждаю фактическую передачу денег исходному плательщику')->accepted()->required();
        }

        return Action::make($kind)->label($label)->icon($icon)->authorize(static::canAccess(...))
            ->databaseTransaction(false)->modalHeading($label)->modalSubmitActionLabel($label)->schema($fields)
            ->action(function (array $data) use ($kind): void {
                try {
                    app(SettlementService::class)->record(['kind' => $kind] + $data);
                } catch (ValidationException $exception) {
                    Notification::make()->title($exception->getMessage())->danger()->send();
                    throw $exception;
                }
                $this->flushCachedTableRecords();
                Notification::make()->title('Операция зафиксирована')->success()->send();
            });
    }

    private function accounts(string $kind): Builder
    {
        abort_unless(static::canAccess(), 403);

        return Accrual::query()->where('currency', 'UZS')->whereHas('refundCommissionReversals')
            ->when($kind === CommissionSettlement::WRITE_OFF, fn (Builder $query) => $query->where('payer', '!=', 'SFP'));
    }

    private function payerOptions(string $kind, mixed $beneficiaryId): array
    {
        $id = filter_var($beneficiaryId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            return [];
        }

        return $this->accounts($kind)->where('beneficiary_id', $id)->orderBy('payer')->distinct()->pluck('payer')
            ->mapWithKeys(fn (string $payer): array => [$payer => self::payerLabel($payer)])->all();
    }

    private function debtNotice(string $kind, mixed $beneficiaryId, mixed $payer): string
    {
        if (! is_string($payer) || ! array_key_exists($payer, $this->payerOptions($kind, $beneficiaryId))) {
            return '';
        }
        $balance = app(CommissionBalances::class)->balance((int) $beneficiaryId, $payer, 'UZS', today()->toDateString());

        return $balance < 0 ? 'К погашению '.Money::display(-$balance) : 'По этому счёту встречной задолженности нет';
    }

    private static function payerLabel(string $payer): string
    {
        [$role, $id] = array_pad(explode(':', $payer, 2), 2, null);

        return match ($role) {
            'owner' => 'Маркетолог № '.$id,
            'rop' => 'РОП № '.$id,
            default => $payer,
        };
    }
}
