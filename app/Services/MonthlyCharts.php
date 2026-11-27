<?php

namespace App\Services;

use App\Models\Accrual;
use App\Models\CommissionSettlement;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\RefundCommissionReversal;
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
        $orderIds = CrmAccess::query(Order::class, $user)->when($manager, fn ($query) => $query->where('manager_id', $manager))->select('id');
        $payments = Payment::query()->whereIn('order_id', clone $orderIds)
            ->where('currency', 'UZS')->whereDate('paid_at', '>=', $start)->whereDate('paid_at', '<=', $end);

        $obligations = CrmAccess::query(Accrual::class, $user)
            ->join('payments', 'payments.id', '=', 'accruals.payment_id')
            ->where('accruals.currency', 'UZS')->whereDate('payments.paid_at', '<=', $end)
            ->whereIn('accruals.kind', $user->role === 'manager' ? ['manager_owner', 'manager_rop'] : ['owner_gross', 'rop_gross'])
            ->when($manager, fn ($query) => $query->where('payments.manager_id', $manager));
        $settlements = (clone $obligations)
            ->join('allocations', 'allocations.accrual_id', '=', 'accruals.id')
            ->join('payouts', 'payouts.id', '=', 'allocations.payout_id')
            ->whereDate('payouts.paid_at', '<=', $end);
        $refunds = Refund::query()->where('currency', 'UZS')->where('status', Refund::COMPLETED)
            ->whereIn('order_id', $orderIds)
            ->whereDate('executed_at', '>=', $start)->whereDate('executed_at', '<=', $end);
        $reversals = RefundCommissionReversal::query()->whereIn('accrual_id', (clone $obligations)->select('accruals.id'))
            ->whereDate('recorded_at', '<=', $end);
        $recoveries = CommissionSettlement::query()->where('currency', 'UZS')->whereDate('settled_on', '<=', $end)
            ->when($user->role === 'rop', fn ($query) => $query->where('beneficiary_id', $user->id))
            ->when($user->role === 'manager', fn ($query) => $query->where('beneficiary_id', $user->id)->where('payer', '!=', 'SFP'),
                fn ($query) => $query->where('payer', 'SFP')->when($manager !== null, fn ($query) => $query->whereRaw('1 = 0')));

        $openingEarned = (clone $obligations)->whereDate('payments.paid_at', '<', $start)->sum('accruals.amount_minor');
        $openingPaid = (clone $settlements)->whereDate('payouts.paid_at', '<', $start)->sum('allocations.amount_minor');
        $openingReversed = (clone $reversals)->whereDate('recorded_at', '<', $start)->sum('amount_minor');
        $openingRecovered = (clone $recoveries)->whereDate('settled_on', '<', $start)->sum('amount_minor');
        $balance = (int) $openingEarned - (int) $openingPaid - (int) $openingReversed + (int) $openingRecovered;

        $sales = $this->groupTotals((clone $orders)->where('orders.status', '!=', 'cancelled'), 'orders.created_at', 'order_items.quantity * order_items.price_minor', $length);
        $cancelledSales = $this->groupTotals($orders->where('orders.status', 'cancelled'), 'orders.created_at', 'order_items.quantity * order_items.price_minor', $length);
        $receipts = $this->groupTotals($payments, 'paid_at', 'amount_minor', $length);
        $returned = $this->groupTotals($refunds, 'executed_at', 'amount_minor', $length);
        $earned = $this->groupTotals($obligations->whereDate('payments.paid_at', '>=', $start), 'payments.paid_at', 'accruals.amount_minor', $length);
        $paid = $this->groupTotals($settlements->whereDate('payouts.paid_at', '>=', $start), 'payouts.paid_at', 'allocations.amount_minor', $length);
        $storno = $this->groupTotals($reversals->whereDate('recorded_at', '>=', $start), 'recorded_at', 'amount_minor', $length);
        $recovered = $this->groupTotals($recoveries->whereDate('settled_on', '>=', $start), 'settled_on', 'amount_minor', $length);
        $months = [];
        for ($date = $daily ? $start : $start->startOfMonth(); $date <= $end; $date = $daily ? $date->addDay() : $date->addMonth()) {
            $key = $date->format($daily ? 'Y-m-d' : 'Y-m');
            $netEarned = $earned->get($key, 0) - $storno->get($key, 0);
            $balance += $netEarned - $paid->get($key, 0) + $recovered->get($key, 0);
            $months[$key] = ['label' => $date->locale('ru')->isoFormat($daily ? 'DD.MM.YY' : 'MMM YY'), 'orders' => $sales->get($key, 0), 'payments' => $receipts->get($key, 0),
                'cancelled_orders' => $cancelledSales->get($key, 0), 'refunds' => $returned->get($key, 0),
                'earned' => $netEarned, 'paid' => $paid->get($key, 0), 'balance' => $balance];
        }

        return $months;
    }

    private function groupTotals(Builder $query, string $dateColumn, string $amountExpression, int $length): Collection
    {
        return $query->selectRaw("substr($dateColumn, 1, $length) as month, sum($amountExpression) as total")
            ->groupBy('month')->pluck('total', 'month')->map(fn ($amount) => (int) $amount);
    }
}
