<?php

namespace App\Filament\Resources\Orders;

use App\Filament\CrmForms;
use App\Filament\Resources\CrmResource;
use App\Models\Order;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
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

    public static function orderColumns(callable $text): array
    {
        return [
            Split::make([
                Stack::make([
                    $text('number', 'Заказ')->searchable(['number', 'id'])->sortable(['id'])->formatStateUsing(fn (Order $record) => '№ '.$record->id)->tooltip(fn (Order $record) => $record->number),
                    $text('client.name', 'Клиент')->weight('bold'),
                    $text('manager.name', 'Менеджер')->prefix('Ответственный: '),
                    $text('status', 'Производство')->formatStateUsing(fn ($state) => CrmForms::orderStatuses()[$state] ?? $state)->badge(),
                    TextColumn::make('due_at')->label('Срок')->date('d.m.Y')->prefix('Срок: ')->sortable(),
                    $text('currency', 'Валюта')->toggleable(isToggledHiddenByDefault: true),
                ])->space(1),
                ViewColumn::make('payment_status')->label('Оплата')->view('filament.order-payment'),
            ])->from('md'),
        ];
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageOrders::route('/')];
    }
}
