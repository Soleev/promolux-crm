<?php

namespace App\Filament\Resources\CashReceipts;

use App\Filament\CrmForms;
use App\Filament\CrmTableUi;
use App\Models\CashReceipt;
use App\Models\Order;
use App\Models\User;
use App\Services\CashDesk;
use App\Services\Ledger;
use App\Services\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class CashReceiptResource extends Resource
{
    protected static ?int $navigationSort = 20;

    protected static ?string $model = CashReceipt::class;

    protected static ?string $pluralModelLabel = 'Передача наличных';

    protected static ?string $navigationLabel = 'Передача наличных';

    protected static string|\UnitEnum|null $navigationGroup = 'Финансы';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-inbox-arrow-down';

    protected static bool $isGloballySearchable = false;

    private const STATUSES = ['pending' => 'У сотрудника', 'accepted' => 'Принято кассиром', 'cancelled' => 'Отменено', 'reversed' => 'Оплата сторнирована'];

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->active;
    }

    public static function getEloquentQuery(): Builder
    {
        $actor = auth()->user();
        $query = CashReceipt::query()->with(['collector', 'cashier', 'order:id,number']);
        if (! $actor?->active) {
            return $query->whereRaw('1=0');
        }

        return match ($actor->role) {
            'owner','rop' => $query,
            'manager','designer' => $query->where(fn ($q) => $q->where('collector_id', $actor->id)->orWhere('manager_id', $actor->id)),
            'cashier' => $query->where(fn ($q) => $q->where('status', 'pending')->orWhere('cashier_id', $actor->id)),
            default => $query->whereRaw('1=0')
        };
    }

    public static function collectionAction(): Action
    {
        $recipient = fn (string $role, string $label) => Select::make($role.'_id')->label($label)->hidden(fn () => auth()->user()->role === 'designer')
            ->options(fn () => User::where('role', $role)->where('active', true)->pluck('name', 'id'))
            ->default(function () use ($role) {
                $ids = User::where('role', $role)->where('active', true)->limit(2)->pluck('id');

                return $ids->count() === 1 ? $ids->first() : null;
            });

        return Action::make('collect')->label('Записать наличные от клиента')->visible(fn () => in_array(auth()->user()->role, ['owner', 'rop', 'manager', 'designer'], true))
            ->schema([
                Hidden::make('idempotency_key')->default(fn () => (string) Str::uuid()),
                CrmForms::relation('order_id', Order::class, 'Заказ', 'number', true),
                CrmForms::money(),
                DatePicker::make('collected_at')->native(false)->displayFormat('d.m.Y')->label('Дата получения от клиента')->default(today())->maxDate(today())->required(),
                Select::make('collector_id')->label('Кто принял у клиента')->visible(fn () => auth()->user()->role === 'owner')
                    ->options(fn () => User::where('active', true)->whereIn('role', ['owner', 'rop', 'manager', 'designer'])->pluck('name', 'id'))->default(fn () => auth()->id())->required(),
                $recipient('owner', 'Владелец для начисления'), $recipient('rop', 'РОП для начисления'),
            ])->action(fn (array $data) => app(CashDesk::class)->collect($data));
    }

    public static function table(Table $table): Table
    {
        $table->columns([
            TextColumn::make('order.number')->label('Заказ')->searchable(),
            TextColumn::make('collected_at')->label('Получено от клиента')->date('d.m.Y')->sortable(),
            TextColumn::make('collector.name')->label('Кто принял деньги')->searchable()->sortable()->wrap(),
            TextColumn::make('amount_minor')->label('Сумма')->formatStateUsing(fn ($state) => Money::display($state)),
            TextColumn::make('status')->label('Статус')->badge()->formatStateUsing(fn ($state) => self::STATUSES[$state] ?? $state)->color(fn ($state) => CrmTableUi::color($state)),
            TextColumn::make('cashier.name')->label('Кассир')->searchable()->wrap(), TextColumn::make('handed_at')->label('Передано в SFP')->date('d.m.Y'),
            TextColumn::make('reason')->label('Причина корректировки')->toggleable(isToggledHiddenByDefault: true),
        ])->filters([SelectFilter::make('status')->label('Статус')->options(self::STATUSES), CrmTableUi::period('collected_at', 'Получено от клиента')])
            ->recordActions([
                Action::make('accept')->label('Подтвердить приём')->visible(fn (CashReceipt $record) => auth()->user()->role === 'cashier' && $record->status === 'pending')
                    ->modalHeading('Подтвердить приём наличных в SFP')
                    ->modalDescription(fn (CashReceipt $record) => 'От '.$record->collector->name.' по заказу '.$record->order->number.' — '.Money::display($record->amount_minor).'.')
                    ->schema([DatePicker::make('handed_at')->native(false)->displayFormat('d.m.Y')->label('Дата фактической передачи')->default(today())->maxDate(today())->required()])
                    ->action(fn (CashReceipt $record, array $data) => app(Ledger::class)->acceptCash($record, $data['handed_at'])),
                Action::make('cancelCash')->label('Отменить запись')->visible(fn (CashReceipt $record) => auth()->user()->role === 'owner' && $record->status === 'pending')
                    ->schema([Textarea::make('reason')->label('Причина')->required()->minLength(5)])
                    ->action(fn (CashReceipt $record, array $data) => app(CashDesk::class)->cancel($record, $data['reason'])),
            ])->defaultSort('id', 'desc');

        return CrmTableUi::configure($table, CashReceipt::class, static::collectionAction(), fn (): bool => auth()->user()->active && in_array(auth()->user()->role, ['owner', 'rop', 'manager', 'designer'], true));
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageCashReceipts::route('/')];
    }
}
