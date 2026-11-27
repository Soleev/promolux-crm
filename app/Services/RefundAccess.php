<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class RefundAccess
{
    public static function canViewAny(?User $actor): bool
    {
        return $actor?->active === true && in_array($actor->role, ['owner', 'rop', 'manager', 'cashier'], true);
    }

    public static function canRequest(?User $actor): bool
    {
        return $actor?->active === true && in_array($actor->role, ['owner', 'rop'], true);
    }

    public static function query(?User $actor = null): Builder
    {
        $actor ??= auth()->user();
        $refunds = Refund::query();

        if (! self::canViewAny($actor)) {
            return $refunds->whereRaw('1 = 0');
        }

        return match ($actor->role) {
            'owner', 'rop' => $refunds,
            'manager' => $refunds->whereIn('order_id', CrmAccess::query(Order::class, $actor)->select('id')),
            'cashier' => $refunds->where('method', 'cash')->where(fn (Builder $cash) => $cash
                ->where('status', Refund::APPROVED)
                ->orWhere(fn (Builder $completed) => $completed
                    ->where('status', Refund::COMPLETED)->where('executed_by', $actor->id))),
        };
    }
}
