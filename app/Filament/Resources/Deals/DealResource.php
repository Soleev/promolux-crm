<?php

namespace App\Filament\Resources\Deals;

use App\Filament\CrmForms;
use App\Filament\CrmTableUi;
use App\Filament\Resources\CrmResource;
use App\Models\Deal;
use App\Models\Order;
use App\Models\Task;
use App\Services\CrmAccess;
use App\Services\DealStages;
use App\Services\RecordDetails;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class DealResource extends CrmResource
{
    protected static ?string $model = Deal::class;

    protected static ?string $modelLabel = 'Сделка';

    protected static ?string $pluralModelLabel = 'Сделки';

    protected static string|\UnitEnum|null $navigationGroup = 'Продажи';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-funnel';

    public static function table(Table $table): Table
    {
        $table = parent::table($table);
        $table->getAction('convert')->visible(fn (Deal $record) => $record->status === 'won' && ! $record->order_exists);
        $table->columns([...$table->getColumns(),
            TextColumn::make('production_status')->label('Производство заказа')
                ->state(fn (Deal $record) => static::productionLabel($record))->badge()
                ->color(fn (Deal $record) => $record->order ? CrmTableUi::color($record->order->status) : 'gray'),
        ]);

        return $table->modifyQueryUsing(fn (Builder $query) => $query->withExists('order')->with([
            'client', 'manager',
            'order' => fn ($order) => $order->whereIn('id', CrmAccess::query(Order::class)->select('id')),
            'tasks' => fn ($tasks) => $tasks->whereIn('id', CrmAccess::query(Task::class)->select('id'))
                ->whereIn('status', ['open', 'in_progress'])->orderBy('due_at')->orderBy('id')->limit(1),
        ]))->content(fn ($livewire) => $livewire->viewMode === 'kanban' ? view('filament.deals.kanban') : null)
            ->recordActions([...$table->getRecordActions(), static::stageAction(), static::openOrderAction()]);
    }

    public static function productionLabel(Deal $deal): string
    {
        return $deal->order
            ? (CrmForms::orderStatuses()[$deal->order->status] ?? $deal->order->status)
            : ($deal->order_exists ? 'Нет доступа к заказу' : 'Заказ не создан');
    }

    public static function openOrderAction(): Action
    {
        return Action::make('openOrder')->label('Открыть заказ')->icon('heroicon-o-arrow-top-right-on-square')
            ->visible(fn (Deal $record) => $record->order !== null)
            ->authorize(fn (Deal $record) => Gate::allows('view', $record) && $record->order && Gate::allows('view', $record->order))
            ->modalHeading('Связанный заказ')->modalSubmitAction(false)->modalCancelActionLabel('Закрыть')
            ->modalContent(function (Deal $record) {
                Gate::authorize('view', $record);
                $order = $record->order()->firstOrFail();
                Gate::authorize('view', $order);

                return view('filament.record', ['record' => $order, 'details' => RecordDetails::get($order)]);
            });
    }

    public static function stageAction(): Action
    {
        return Action::make('changeStage')->label('Изменить этап')->icon('heroicon-o-arrows-right-left')
            ->authorize(fn (Deal $record) => ! request()->attributes->get('preview_applied') && Gate::allows('update', $record))
            ->visible(fn (Deal $record) => count(DealStages::targets($record)) > 0)
            ->modalHeading('Изменение этапа сделки')->modalSubmitActionLabel('Сохранить этап')
            ->fillForm(fn (Deal $record, array $arguments) => [
                'status' => $arguments['target'] ?? null,
                'expected' => $arguments['expected'] ?? $record->status,
                'lost_reason' => null,
            ])->schema([
                Select::make('status')->label('Новый этап')->required()->live()->native(false)
                    ->options(fn (Deal $record) => array_intersect_key(CrmForms::dealStatuses(), array_flip(DealStages::targets($record))))
                    ->helperText('Закрытую сделку можно вернуть в «Уточнение». После создания заказа этап зафиксирован.'),
                Hidden::make('expected')->required()->rules(['string']),
                Textarea::make('lost_reason')->label('Причина проигрыша')->maxLength(5000)
                    ->visible(fn (Get $get) => $get('status') === 'lost')->required(fn (Get $get) => $get('status') === 'lost'),
            ])->action(function (Deal $record, array $data, $livewire): void {
                try {
                    app(DealStages::class)->move($record->id, $data['status'], $data['expected'], $data['lost_reason'] ?? null);
                } catch (ValidationException $exception) {
                    Notification::make()->title(collect($exception->errors())->flatten()->first())->danger()->send();
                    throw $exception;
                }
                $livewire->flushCachedTableRecords();
                Notification::make()->title('Этап сделки изменён')->success()->send();
            });
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageDeals::route('/')];
    }
}
