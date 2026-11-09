<?php

namespace App\Services;

use App\Models\Accrual;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Deal;
use App\Models\Expense;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CrmAccess
{
    public static function query(string $model, ?User $user = null): Builder
    {
        $user ??= auth()->user();
        $q = $model::query();
        if (! $user?->active) {
            return $q->whereRaw('1 = 0');
        }
        if ($user->role === 'owner') {
            return $q;
        }

        if ($user->role === 'cashier') {
            return $q->whereRaw('1 = 0');
        }

        if ($user->role === 'designer' && ! in_array($model, [Client::class, Order::class, OrderItem::class, Task::class, Payment::class, User::class], true)) {
            return $q->whereRaw('1 = 0');
        }

        return match ($model) {
            Accrual::class => $q->where('beneficiary_id', $user->id),
            Payout::class => $q->where('recipient_id', $user->id)->whereDoesntHave('allocations.accrual', fn ($a) => $a->where('beneficiary_id', '!=', $user->id)),
            Expense::class, Activity::class => $q->whereRaw('1 = 0'),
            User::class => $user->role === 'rop' ? $q->where('role', 'manager') : $q->whereKey($user->id),
            OrderItem::class => $q->whereIn('order_id', self::query(Order::class, $user)->select('id')),
            Payment::class => $user->role === 'rop' ? $q : $q->where('manager_id', $user->id),
            Client::class,Deal::class,Order::class,Task::class => $user->role === 'rop' ? $q : $q->where('manager_id', $user->id),
            default => $q->whereRaw('1 = 0'),
        };
    }

    public static function salesAssignees(User $actor, ?Model $record = null): Builder
    {
        return User::where('active', true)
            ->where(function (Builder $eligible) use ($actor, $record) {
                $eligible->whereIn('role', ['manager', 'designer']);
                if ($actor->role === 'owner') {
                    $eligible->orWhere('role', 'rop');
                }
                if (in_array($actor->role, ['owner', 'rop'], true)) {
                    $eligible->orWhere('id', $actor->id);
                }
                if ($record?->manager_id && self::view($actor, $record)) {
                    $eligible->orWhere('id', $record->manager_id);
                }
            })
            ->when($actor->handlesOwnOrders(), fn (Builder $query) => $query->whereKey($actor->id));
    }

    public static function view(User $user, Model $record): bool
    {
        return self::query($record::class, $user)->whereKey($record->id)->exists();
    }

    public static function manage(User $user, string $model): bool
    {
        if (! $user->active) {
            return false;
        }
        if ($user->role === 'owner') {
            return true;
        }
        if ($user->role === 'designer') {
            return in_array($model, [Client::class, Order::class, Task::class, Payment::class], true);
        }
        if (in_array($model, [Client::class, Deal::class, Order::class, Task::class])) {
            return in_array($user->role, ['rop', 'manager']);
        }

        return $model === User::class && $user->role === 'rop';
    }
}
