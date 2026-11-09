<?php

namespace App\Services;

use App\Filament\CrmForms;
use App\Models\Accrual;
use App\Models\Expense;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class Report
{
    public function build(User $user, ?string $from, ?string $until, ?int $manager = null): array
    {
        $until ??= today()->toDateString();
        if ($user->role === 'manager') {
            $manager = $user->id;
        }
        $orders = CrmAccess::query(Order::class, $user)->when($manager, fn ($q) => $q->where('manager_id', $manager));
        $payments = CrmAccess::query(Payment::class, $user)->when($manager, fn ($q) => $q->where('manager_id', $manager));
        $accruals = CrmAccess::query(Accrual::class, $user)->when($manager, fn ($q) => $q->whereHas('payment', fn ($p) => $p->where('manager_id', $manager)));
        $within = fn (Builder $q, string $date) => $q->when($from, fn ($q) => $q->whereDate($date, '>=', $from))->whereDate($date, '<=', $until);
        $a = (clone $accruals)->where('currency', 'UZS')->whereHas('payment', fn ($q) => $q->whereDate('paid_at', '<=', $until))->with(['payment', 'allocations.payout'])->get();
        $period = $a->filter(fn ($a) => ! $from || $a->payment->paid_at->toDateString() >= $from);
        $gross = $a->whereIn('kind', ['owner_gross', 'rop_gross']);
        $managerAccruals = $a->whereIn('kind', ['manager_owner', 'manager_rop']);
        $paid = fn ($rows, ?string $since = null) => $rows->sum(fn ($a) => $a->allocations->filter(fn ($x) => $x->payout->paid_at->toDateString() <= $until && (! $since || $x->payout->paid_at->toDateString() >= $since))->sum('amount_minor'));
        $ownerGross = (int) $period->where('kind', 'owner_gross')->sum('amount_minor');
        $ropGross = (int) $period->where('kind', 'rop_gross')->sum('amount_minor');
        $contributions = Accrual::where('currency', 'UZS')->whereIn('kind', ['manager_owner', 'manager_rop'])
            ->whereHas('payment', function ($q) use ($from, $until, $manager) {
                $q->whereDate('paid_at', '<=', $until)->when($from, fn ($q) => $q->whereDate('paid_at', '>=', $from))->when($manager, fn ($q) => $q->where('manager_id', $manager));
            });
        if ($user->role !== 'owner') {
            $contributions->where('payer', $user->role.':'.$user->id);
        }
        $ownContribution = (clone $contributions)->where('kind', 'manager_owner')->sum('amount_minor');
        $ropContribution = (clone $contributions)->where('kind', 'manager_rop')->sum('amount_minor');
        $orderPeriod = $within((clone $orders)->where('currency', 'UZS'), 'created_at')->with('items')->get();
        $expense = fn ($payer) => (int) $within(Expense::where('currency', 'UZS')->where('payer', $payer)->when($manager, fn ($q) => $q->whereHas('order', fn ($o) => $o->where('manager_id', $manager))), 'paid_at')->sum('amount_minor');
        $totals = [
            'order_total' => (int) $orderPeriod->sum('total_minor'),
            'client_payments' => (int) $within((clone $payments)->where('currency', 'UZS'), 'paid_at')->sum('amount_minor'),
            'owner_gross' => $ownerGross, 'rop_gross' => $ropGross,
            'owner_net' => $ownerGross - (int) $ownContribution, 'rop_net' => $ropGross - (int) $ropContribution,
            'manager_accrued' => (int) $period->whereIn('kind', ['manager_owner', 'manager_rop'])->sum('amount_minor'),
            'sfp_paid' => (int) $paid($gross, $from), 'sfp_remaining' => (int) $gross->sum('amount_minor') - (int) $paid($gross),
            'manager_paid' => (int) $paid($managerAccruals, $from), 'manager_remaining' => (int) $managerAccruals->sum('amount_minor') - (int) $paid($managerAccruals),
            'sfp_expenses' => $user->role === 'owner' ? $expense('SFP') : 0,
            'promolux_expenses' => $user->role === 'owner' ? $expense('Promolux') : 0,
        ];

        return [
            'currencies' => ['UZS' => $totals],
            'orders' => (clone $orders)->count(),
            'period_payment_count' => $within((clone $payments)->whereNull('reversal_of'), 'paid_at')->count(),
            'active_orders' => (clone $orders)->whereNotIn('status', ['delivered', 'cancelled'])->count(),
            'overdue_tasks' => CrmAccess::query(Task::class, $user)->when($manager, fn ($q) => $q->where('manager_id', $manager))->whereNotIn('status', ['done', 'cancelled'])->where('due_at', '<', now())->count(),
            'unresolved' => (clone $payments)->where('calculation_status', 'needs_clarification')->whereNull('reversal_of')->whereNotIn('id', Payment::whereNotNull('reversal_of')->select('reversal_of'))->count(),
        ];
    }

    public function register(User $user, ?string $from, ?string $until, ?int $manager = null): Collection
    {
        $until ??= today()->toDateString();

        return CrmAccess::query(Accrual::class, $user)->whereHas('payment', fn ($q) => $q->whereDate('paid_at', '<=', $until)->when($from, fn ($q) => $q->whereDate('paid_at', '>=', $from))->when($manager, fn ($q) => $q->where('manager_id', $manager)))
            ->with(['payment.order', 'beneficiary', 'allocations.payout'])->orderBy('id')->get()->map(function ($a) use ($until) {
                $paid = (int) $a->allocations->filter(fn ($x) => $x->payout->paid_at->toDateString() <= $until)->sum('amount_minor');

                return ['month' => $a->payment->paid_at->format('Y-m'), 'payment_id' => $a->payment_id, 'order' => $a->payment->order->number, 'beneficiary' => $a->beneficiary->name, 'kind' => CrmForms::kindLabels()[$a->kind], 'currency' => $a->currency, 'accrued' => $a->amount_minor, 'paid' => $paid, 'remaining' => $a->amount_minor - $paid];
            });
    }
}
