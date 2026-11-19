<?php

namespace App\Filament\Resources\Orders;

use App\Models\Order;
use App\Models\OrderItem;
use App\Services\CrmAccess;

class OrderOverview
{
    public const OPEN = ['new', 'design', 'production', 'ready'];

    public static function counts(): array
    {
        return CrmAccess::query(Order::class)->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')->pluck('aggregate', 'status')->map(fn ($count) => (int) $count)->all();
    }

    public static function openValue(): int
    {
        return (int) OrderItem::query()->whereIn('order_id', CrmAccess::query(Order::class)
            ->whereIn('status', self::OPEN)->where('currency', 'UZS')->select('id'))
            ->selectRaw('COALESCE(SUM(quantity * price_minor), 0) as aggregate')->value('aggregate');
    }

    public static function foreignOpenCount(): int
    {
        return CrmAccess::query(Order::class)->whereIn('status', self::OPEN)->where('currency', '!=', 'UZS')->count();
    }
}
