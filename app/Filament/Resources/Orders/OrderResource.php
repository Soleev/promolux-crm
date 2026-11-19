<?php

namespace App\Filament\Resources\Orders;

use App\Filament\CrmForms;
use App\Filament\CrmTableUi;
use App\Filament\Resources\CrmResource;
use App\Models\Order;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
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
        return parent::getEloquentQuery()->withPaymentTotals()->with(['client', 'manager']);
    }

    public static function table(Table $table): Table
    {
        return parent::table($table)->description(null);
    }

    public static function orderColumns(callable $text): array
    {
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
            TextColumn::make('paid_minor')->label('Оплачено')->alignEnd()
                ->formatStateUsing(fn (Order $record) => $record->displayAmount($record->paid_minor))
                ->description(function (Order $record): string {
                    $percent = $record->total_minor > 0
                        ? str_replace('.', ',', (string) BigDecimal::of($record->paid_minor)->multipliedBy(100)
                            ->dividedBy($record->total_minor, 1, RoundingMode::HalfUp)).'%'
                        : '—';

                    return $record->payment_status.' · '.$percent;
                })
                ->tooltip('Включая наличные у сотрудника до приёма кассиром'),
            TextColumn::make('balance_minor')->label('Осталось получить')->alignEnd()
                ->state(fn (Order $record) => $record->total_minor - $record->paid_minor)
                ->formatStateUsing(fn (Order $record) => $record->displayAmount(abs($record->total_minor - $record->paid_minor)))
                ->description(fn (Order $record) => $record->paid_minor > $record->total_minor ? 'Переплата' : null),
            TextColumn::make('due_at')->label('Срок выполнения')->date('d.m.Y')->sortable()->placeholder('Не указан'),
            $text('currency', 'Валюта')->toggleable(isToggledHiddenByDefault: true),
        ];
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageOrders::route('/')];
    }
}
