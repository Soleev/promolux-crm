<?php

namespace App\Services;

use App\Filament\CrmForms;
use App\Models\Accrual;
use App\Models\CommissionSettlement;
use App\Models\Expense;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\RefundCommissionReversal;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class Report
{
    public function build(User $user, ?string $from, ?string $until, ?int $manager = null): array
    {
        abort_unless($user->active && in_array($user->role, ['owner', 'rop', 'manager'], true), 403);
        $from = $from ? CarbonImmutable::parse($from)->toDateString() : null;
        $until = CarbonImmutable::parse($until ?: today())->toDateString();
        if ($user->role === 'manager') {
            $manager = $user->id;
        }
        $orders = CrmAccess::query(Order::class, $user)->when($manager, fn ($q) => $q->where('manager_id', $manager));
        $payments = Payment::query()->whereIn('order_id', (clone $orders)->select('id'));
        $accruals = CrmAccess::query(Accrual::class, $user)->when($manager, fn ($q) => $q->whereHas('payment', fn ($p) => $p->where('manager_id', $manager)));
        $within = fn (Builder $q, string $date) => $q->when($from, fn ($q) => $q->whereDate($date, '>=', $from))->whereDate($date, '<=', $until);
        $a = (clone $accruals)->where('currency', 'UZS')->whereHas('payment', fn ($q) => $q->whereDate('paid_at', '<=', $until))
            ->with(['payment', 'allocations.payout', 'refundCommissionReversals' => fn ($rows) => $rows->whereDate('recorded_at', '<=', $until)])->get();
        $period = $a->filter(fn ($a) => ! $from || $a->payment->paid_at->toDateString() >= $from);
        $gross = $a->whereIn('kind', ['owner_gross', 'rop_gross']);
        $managerAccruals = $a->whereIn('kind', ['manager_owner', 'manager_rop']);
        $paid = fn ($rows, ?string $since = null) => $rows->sum(fn ($a) => $a->allocations->filter(fn ($x) => $x->payout->paid_at->toDateString() <= $until && (! $since || $x->payout->paid_at->toDateString() >= $since))->sum('amount_minor'));
        $storno = fn ($rows, ?string $since = null) => (int) $rows->sum(fn ($a) => $a->refundCommissionReversals
            ->filter(fn ($row) => $since === null || $row->recorded_at->toDateString() >= $since)->sum('amount_minor'));
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
        $contributionStorno = RefundCommissionReversal::query()->whereDate('recorded_at', '<=', $until)
            ->when($from, fn ($query) => $query->whereDate('recorded_at', '>=', $from))
            ->whereHas('accrual', fn ($query) => $query->where('currency', 'UZS')->whereIn('kind', ['manager_owner', 'manager_rop'])
                ->when($manager, fn ($query) => $query->whereHas('payment', fn ($payment) => $payment->where('manager_id', $manager)))
                ->when($user->role !== 'owner', fn ($query) => $query->where('payer', $user->role.':'.$user->id)))
            ->with('accrual:id,kind')->get()->groupBy('accrual.kind')->map->sum('amount_minor');

        $settlements = CommissionSettlement::where('currency', 'UZS')->whereDate('settled_on', '<=', $until)
            ->when($manager !== null && $user->role === 'owner', fn ($query) => $query->whereRaw('1 = 0'))
            ->when($user->role !== 'owner', fn ($query) => $query->where('beneficiary_id', $user->id))
            ->get()->groupBy(fn ($row) => $row->payer.'|'.$row->beneficiary_id)->map->sum('amount_minor');
        $balances = fn ($rows) => $rows->groupBy(fn ($row) => $row->payer.'|'.$row->beneficiary_id)
            ->map(fn ($account, $key) => (int) $account->sum('amount_minor') - (int) $paid($account) - $storno($account) + (int) $settlements->get($key, 0));
        $sfpBalances = $balances($gross);
        $managerBalances = $balances($managerAccruals);
        $orderPeriod = $within((clone $orders)->where('currency', 'UZS'), 'created_at')->with('items')->get();
        $refunds = Refund::where('currency', 'UZS')->whereIn('order_id', (clone $orders)->select('id'));
        $received = (int) $within((clone $payments)->where('currency', 'UZS'), 'paid_at')->sum('amount_minor');
        $returned = (int) $within((clone $refunds)->where('status', Refund::COMPLETED), 'executed_at')->sum('amount_minor');
        $refundDue = (int) $refunds->whereDate('approved_at', '<=', $until)->where(function ($rows) use ($until) {
            $rows->where('status', Refund::APPROVED)
                ->orWhere(fn ($rows) => $rows->where('status', Refund::COMPLETED)->whereDate('executed_at', '>', $until))
                ->orWhere(fn ($rows) => $rows->where('status', Refund::CANCELLED)->whereDate('closed_at', '>', $until));
        })->sum('amount_minor');
        $expense = fn ($payer) => (int) $within(Expense::where('currency', 'UZS')->where('payer', $payer)->when($manager, fn ($q) => $q->whereHas('order', fn ($o) => $o->where('manager_id', $manager))), 'paid_at')->sum('amount_minor');
        $totals = [
            'order_total' => (int) $orderPeriod->where('status', '!=', 'cancelled')->sum('total_minor'),
            'cancelled_order_total' => (int) $orderPeriod->where('status', 'cancelled')->sum('total_minor'),
            'client_payments' => $received, 'client_refunds' => $returned, 'client_net_payments' => $received - $returned, 'client_refund_due' => $refundDue,
            'owner_gross' => $ownerGross, 'rop_gross' => $ropGross,
            'owner_net' => $ownerGross - $storno($gross->where('kind', 'owner_gross'), $from) - (int) $ownContribution + (int) $contributionStorno->get('manager_owner', 0),
            'rop_net' => $ropGross - $storno($gross->where('kind', 'rop_gross'), $from) - (int) $ropContribution + (int) $contributionStorno->get('manager_rop', 0),
            'manager_accrued' => (int) $period->whereIn('kind', ['manager_owner', 'manager_rop'])->sum('amount_minor'),
            'sfp_storno' => $storno($gross, $from), 'manager_storno' => $storno($managerAccruals, $from),
            'sfp_paid' => (int) $paid($gross, $from), 'sfp_remaining' => (int) $sfpBalances->sum(),
            'manager_paid' => (int) $paid($managerAccruals, $from), 'manager_remaining' => (int) $managerBalances->sum(),
            'sfp_debt' => (int) -$sfpBalances->filter(fn ($value) => $value < 0)->sum(),
            'employee_debt' => (int) -$managerBalances->filter(fn ($value) => $value < 0)->sum(),
            'sfp_expenses' => $user->role === 'owner' ? $expense('SFP') : 0,
            'promolux_expenses' => $user->role === 'owner' ? $expense('Promolux') : 0,
        ];

        if ($user->role === 'owner') {
            $register = $this->register($user, $from, $until, $manager);
            foreach (['sfp' => true, 'manager' => false] as $prefix => $external) {
                $rows = $register->filter(fn ($row) => ($row['payer'] === 'SFP') === $external);
                foreach (['opening', 'paid', 'storno', 'repaid', 'written_off', 'remaining'] as $metric) {
                    $totals[$prefix.'_'.$metric] = (int) $rows->sum($metric);
                }
            }
        }

        if ($user->role !== 'owner') {
            $personal = match ($user->role) {
                'manager' => ['manager_accrued', 'manager_storno', 'manager_paid', 'manager_remaining', 'employee_debt'],
                'rop' => ['rop_net'],
                default => [],
            };
            $totals = array_intersect_key($totals, array_flip(['order_total', 'cancelled_order_total', 'client_payments', 'client_refunds', 'client_net_payments', 'client_refund_due', ...$personal]));
        }

        return [
            'currencies' => ['UZS' => $totals],
            'orders' => (clone $orders)->count(),
            'period_payment_count' => $within((clone $payments)->whereNull('reversal_of'), 'paid_at')->count(),
            'active_orders' => (clone $orders)->whereNotIn('status', ['delivered', 'cancelled'])->count(),
            'cancelled_orders' => (clone $orders)->where('status', 'cancelled')->count(),
            'overdue_tasks' => CrmAccess::query(Task::class, $user)->when($manager, fn ($q) => $q->where('manager_id', $manager))->whereNotIn('status', ['done', 'cancelled'])->where('due_at', '<', now())->count(),
            'unresolved' => $user->role !== 'owner' ? 0 : (clone $payments)->where('calculation_status', 'needs_clarification')->whereNull('reversal_of')->whereNotIn('id', Payment::whereNotNull('reversal_of')->select('reversal_of'))->count(),
        ];
    }

    public function register(User $user, ?string $from, ?string $until, ?int $manager = null): Collection
    {
        abort_unless($user->active && $user->role === 'owner', 403);
        $from = $from ? CarbonImmutable::parse($from)->toDateString() : null;
        $until = CarbonImmutable::parse($until ?: today())->toDateString();

        $rows = collect();
        $accruals = Accrual::query()->where('currency', 'UZS')
            ->whereHas('payment', fn ($q) => $q->whereDate('paid_at', '<=', $until)
                ->when($manager, fn ($q) => $q->where('manager_id', $manager)))
            ->with(['payment.order', 'beneficiary', 'allocations.payout', 'refundCommissionReversals'])
            ->orderBy('id')->get();
        $groups = $accruals->groupBy(fn ($a) => ($a->payment->reversal_of ?? $a->payment_id).'|'.$a->beneficiary_id.'|'.$a->payer.'|'.$a->kind);
        foreach ($groups as $group) {
            $a = $group->first();
            $row = ['month' => $a->payment->paid_at->format('Y-m'), 'payment_id' => $a->payment->reversal_of ?? $a->payment_id,
                'order' => $a->payment->order->number, 'beneficiary' => $a->beneficiary->name,
                'kind' => CrmForms::accrualLabel($a), 'currency' => 'UZS', 'payer' => $a->payer,
                'opening' => 0, 'accrued' => 0, 'paid' => 0, 'storno' => 0, 'repaid' => 0, 'written_off' => 0, 'remaining' => 0];
            $movement = false;
            $add = function (string $date, string $column, int $amount, int $sign = 1) use (&$row, &$movement, $from, $until): void {
                if ($date > $until) {
                    return;
                }
                if ($from && $date < $from) {
                    $row['opening'] += $sign * $amount;
                } else {
                    $row[$column] += $amount;
                    $movement = true;
                }
            };
            foreach ($group as $entry) {
                $add($entry->payment->paid_at->toDateString(), 'accrued', $entry->amount_minor);
                foreach ($entry->allocations as $allocation) {
                    $add($allocation->payout->paid_at->toDateString(), 'paid', $allocation->amount_minor, -1);
                }
                foreach ($entry->refundCommissionReversals as $reversal) {
                    $add($reversal->recorded_at->toDateString(), 'storno', $reversal->amount_minor, -1);
                }
            }
            $row['remaining'] = $row['opening'] + $row['accrued'] - $row['paid'] - $row['storno'];
            if ($row['opening'] !== 0 || $movement) {
                $rows->push($row);
            }
        }
        if ($manager === null) {
            $settlements = CommissionSettlement::where('currency', 'UZS')->whereDate('settled_on', '<=', $until)
                ->with('beneficiary')->orderBy('settled_on')->orderBy('id')->get();
            foreach ($settlements as $settlement) {
                $opening = $from && $settlement->settled_on->toDateString() < $from ? $settlement->amount_minor : 0;
                $rows->push(['month' => $settlement->settled_on->format('Y-m'), 'payment_id' => null, 'order' => 'Без привязки',
                    'beneficiary' => $settlement->beneficiary->name, 'kind' => $settlement->kind === CommissionSettlement::REPAYMENT ? 'Погашение долга' : 'Списание долга',
                    'currency' => 'UZS', 'payer' => $settlement->payer, 'opening' => $opening, 'accrued' => 0, 'paid' => 0, 'storno' => 0,
                    'repaid' => $opening === 0 && $settlement->kind === CommissionSettlement::REPAYMENT ? $settlement->amount_minor : 0,
                    'written_off' => $opening === 0 && $settlement->kind === CommissionSettlement::WRITE_OFF ? $settlement->amount_minor : 0,
                    'remaining' => $settlement->amount_minor]);
            }
        }

        return $rows;
    }

    public function refundRegister(User $user, ?string $from, ?string $until, ?int $manager): Collection
    {
        abort_unless($user->active && $user->role === 'owner', 403);
        $from = $from ? CarbonImmutable::parse($from)->toDateString() : null;
        $until = CarbonImmutable::parse($until ?: today())->toDateString();

        return Refund::query()->where('status', Refund::COMPLETED)->whereDate('executed_at', '<=', $until)
            ->when($from, fn ($query) => $query->whereDate('executed_at', '>=', $from))
            ->when($manager, fn ($query) => $query->whereHas('order', fn ($orders) => $orders->where('manager_id', $manager)))
            ->with('order:id,number')->orderBy('executed_at')->orderBy('id')->get()
            ->map(fn (Refund $refund) => [
                'date' => $refund->executed_at->toDateString(), 'number' => $refund->number, 'order' => $refund->order->number,
                'source' => $refund->source, 'method' => $refund->method === 'cash' ? 'Наличные' : 'Безналичные',
                'currency' => $refund->currency, 'amount' => Money::format($refund->amount_minor),
            ]);
    }
}
