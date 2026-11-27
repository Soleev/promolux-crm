<?php

namespace App\Filament\Resources\Orders;

use App\Filament\CrmForms;
use App\Filament\CrmTableUi;
use App\Filament\FinancialTermsActions;
use App\Filament\Resources\CrmResource;
use App\Models\Order;
use App\Services\OrderCancellationService;
use App\Services\RefundAccess;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Filament\Actions\ActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OrderResource extends CrmResource
{
    protected static ?string $model = Order::class;

    protected static ?string $modelLabel = 'Заказ';

    protected static ?string $pluralModelLabel = 'Заказы';

    protected static string|\UnitEnum|null $navigationGroup = 'Продажи';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shopping-bag';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withPaymentTotals()->with(['client', 'manager', 'financialTerms', 'currentCancellation'])
            ->withExists(['refunds as has_blocking_refunds' => fn (Builder $query) => $query
                ->whereIn('status', OrderCancellationService::RESTORATION_BLOCKING_REFUND_STATUSES)]);
    }

    public static function table(Table $table): Table
    {
        $table = parent::table($table)->description(null);
        $actions = $table->getRecordActions()[0]->getFlatActions();

        return $table->recordActions([ActionGroup::make([
            ...array_values($actions), FinancialTermsActions::confirmOrder(), FinancialTermsActions::showOrder(),
        ])->label('Действия')->icon('heroicon-m-ellipsis-vertical')->tooltip('Действия заказа')]);
    }

    public static function orderColumns(callable $text): array
    {
        $showRefunds = RefundAccess::canViewAny(auth()->user());
        $paid = fn (Order $order) => $showRefunds ? $order->net_paid_minor : $order->paid_minor;
        $balance = fn (Order $order) => $order->status === 'cancelled' ? 0 : $order->total_minor - $paid($order);

        return [
            $text('number', 'Номер заказа')->searchable(['number', 'id'])->sortable(['id'])
                ->formatStateUsing(fn (Order $record) => '№ '.$record->id)
                ->tooltip(fn (Order $record) => $record->number),
            $text('client.name', 'Клиент')->weight('bold'),
            $text('manager.name', 'Ответственный')->toggleable(isToggledHiddenByDefault: true),
            $text('status', 'Производство')
                ->formatStateUsing(fn ($state) => CrmForms::orderStatuses()[$state] ?? $state)
                ->badge()->color(fn ($state) => CrmTableUi::color($state)),
            TextColumn::make('ordered_minor')->label('Общая стоимость')->sortable()->alignEnd()
                ->state(fn (Order $record) => $record->total_minor)
                ->formatStateUsing(fn (Order $record) => $record->displayAmount($record->total_minor)),
            TextColumn::make('net_paid_minor')->label('Оплачено')->alignEnd()
                ->state($paid)->formatStateUsing(fn (int $state, Order $record) => $record->displayAmount($state))
                ->description(function (Order $record) use ($paid, $showRefunds): string {
                    $percent = $record->total_minor > 0
                        ? str_replace('.', ',', (string) BigDecimal::of($paid($record))->multipliedBy(100)
                            ->dividedBy($record->total_minor, 1, RoundingMode::HalfUp)).'%'
                        : '—';

                    return implode(' · ', array_filter([$record->paymentStatusFor($paid($record)), $showRefunds ? $record->refund_status : null, $percent]));
                })
                ->tooltip($showRefunds ? 'За вычетом выполненных возвратов, включая наличные у сотрудника до приёма кассиром' : 'Включая наличные у сотрудника до приёма кассиром'),
            TextColumn::make('balance_minor')->label('Осталось получить')->alignEnd()
                ->state($balance)
                ->formatStateUsing(fn (int $state, Order $record) => $record->displayAmount(abs($state)))
                ->description(fn (Order $record) => $balance($record) < 0 ? 'Переплата' : null),
            TextColumn::make('due_at')->label('Срок выполнения')->date('d.m.Y')->sortable()->placeholder('Не указан'),
            $text('currency', 'Валюта')->toggleable(isToggledHiddenByDefault: true),
        ];
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageOrders::route('/')];
    }
}
