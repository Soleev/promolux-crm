<?php

namespace App\Filament\Resources\Orders;

use App\Filament\Resources\CrmResource;
use App\Models\Order;

class OrderResource extends CrmResource
{
    protected static ?string $model = Order::class;

    protected static ?string $modelLabel = 'Заказ';

    protected static ?string $pluralModelLabel = 'Заказы';

    protected static string|\UnitEnum|null $navigationGroup = 'Продажи';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shopping-bag';

    public static function getPages(): array
    {
        return ['index' => Pages\ManageOrders::route('/')];
    }
}
