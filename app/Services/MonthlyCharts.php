<?php

namespace App\Services;

use App\Models\Accrual;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class MonthlyCharts
{
    public function build(User $user, string $from, ?string $until, ?int $manager, string $interval): array
    {
        $end = CarbonImmutable::parse($until ?: today())->endOfDay();
        $start = CarbonImmutable::parse($from)->startOfDay();
        $daily = $interval === 'day';
        $length = $daily ? 10 : 7;
        $manager = $user->role === 'manager' ? $user->id : $manager;

        $orders = CrmAccess::query(Order::class, $user)
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->where('orders.currency', 'UZS')->whereBetween('orders.created_at', [$start, $end])
            ->when($manager, fn ($query) => $query->where('orders.manager_id', $manager));
        $payments = CrmAccess::query(Payment::class, $user)
            ->where('currency', 'UZS')->whereBetween('paid_at', [$start->toDateString(), $end->toDateString()])
            ->when($manager, fn ($query) => $query->where('manager_id', $manager));

        $obligations = CrmAccess::query(Accrual::class, $user)
            ->join('payments', 'payments.id', '=', 'accruals.payment_id')
            ->where('accruals.currency', 'UZS')->whereDate('payments.paid_at', '<=', $end)
            ->whereIn('accruals.kind', $user->role === 'manager' ? ['manager_owner', 'manager_rop'] : ['owner_gross', 'rop_gross'])
            ->when($manager, fn ($query) => $query->where('payments.manager_id', $manager));
        $settlements = (clone $obligations)
            ->join('allocations', 'allocations.accrual_id', '=', 'accruals.id')
            ->join('payouts', 'payouts.id', '=', 'allocations.payout_id')
            ->whereDate('payouts.paid_at', '<=', $end);

        $openingEarned = (clone $obligations)->whereDate('payments.paid_at', '<', $start)->sum('accruals.amount_minor');
        $openingPaid = (clone $settlements)->whereDate('payouts.paid_at', '<', $start)->sum('allocations.amount_minor');
        $balance = (int) $openingEarned - (int) $openingPaid;

        $sales = $this->groupTotals($orders, 'orders.created_at', 'order_items.quantity * order_items.price_minor', $length);
        $receipts = $this->groupTotals($payments, 'paid_at', 'amount_minor', $length);
        $earned = $this->groupTotals($obligations->whereDate('payments.paid_at', '>=', $start), 'payments.paid_at', 'accruals.amount_minor', $length);
        $paid = $this->groupTotals($settlements->whereDate('payouts.paid_at', '>=', $start), 'payouts.paid_at', 'allocations.amount_minor', $length);
        $months = [];
        for ($date = $daily ? $start : $start->startOfMonth(); $date <= $end; $date = $daily ? $date->addDay() : $date->addMonth()) {
            $key = $date->format($daily ? 'Y-m-d' : 'Y-m');
            $balance += $earned->get($key, 0) - $paid->get($key, 0);
            $months[$key] = ['label' => $date->locale('ru')->isoFormat($daily ? 'DD.MM.YY' : 'MMM YY'), 'orders' => $sales->get($key, 0), 'payments' => $receipts->get($key, 0),
                'earned' => $earned->get($key, 0), 'paid' => $paid->get($key, 0), 'balance' => $balance];
        }

        return $months;
    }

    private function groupTotals(Builder $query, string $dateColumn, string $amountExpression, int $length): Collection
    {
        return $query->selectRaw("substr($dateColumn, 1, $length) as month, sum($amountExpression) as total")
            ->groupBy('month')->pluck('total', 'month')->map(fn ($amount) => (int) $amount);
    }
}
