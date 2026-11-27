<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AccrualOverview
{
    private function rows(User $actor, bool $personal = false): Builder
    {
        abort_unless($actor->active && in_array($actor->role, ['owner', 'rop', 'manager'], true), 403);

        $paid = DB::table('allocations')->select('accrual_id')->selectRaw('SUM(amount_minor) as paid')->groupBy('accrual_id');
        $reversed = DB::table('refund_commission_reversals')->select('accrual_id')->selectRaw('SUM(amount_minor) as reversed')->groupBy('accrual_id');

        return DB::table('accruals as a')
            ->join('payments as p', 'p.id', '=', 'a.payment_id')
            ->join('payments as original', fn (JoinClause $join) => $join->on('original.id', '=', DB::raw('COALESCE(p.reversal_of, p.id)')))
            ->join('orders as o', 'o.id', '=', 'p.order_id')
            ->join('users as u', 'u.id', '=', 'a.beneficiary_id')
            ->leftJoinSub($paid, 'paid', 'paid.accrual_id', '=', 'a.id')
            ->leftJoinSub($reversed, 'reversed', 'reversed.accrual_id', '=', 'a.id')
            ->when($personal || $actor->role === 'manager', fn (Builder $query) => $query->where('a.beneficiary_id', $actor->id));
    }

    private function amounts(Builder $query): Builder
    {
        return $query->selectRaw('SUM(a.amount_minor - COALESCE(reversed.reversed, 0)) as accrued')
            ->selectRaw('SUM(COALESCE(paid.paid, 0)) as paid')
            ->selectRaw('SUM(a.amount_minor - COALESCE(reversed.reversed, 0) - COALESCE(paid.paid, 0)) as remaining');
    }

    public function orders(User $actor, string $search = ''): Builder
    {
        return $this->amounts($this->rows($actor))
            ->addSelect('o.id as order_id', 'o.number', 'a.beneficiary_id', 'u.name as beneficiary', 'a.currency')
            ->when($search !== '', fn (Builder $query) => $query->where('o.number', 'like', '%'.$search.'%'))
            ->groupBy('o.id', 'o.number', 'a.beneficiary_id', 'u.name', 'a.currency')
            ->orderByDesc('o.id')->orderBy('a.beneficiary_id')->orderBy('a.currency');
    }

    public function payments(User $actor, int $orderId, int $beneficiaryId, string $currency): Collection
    {
        $rows = $this->amounts($this->rows($actor))
            ->where('o.id', $orderId)->where('a.beneficiary_id', $beneficiaryId)->where('a.currency', $currency)
            ->addSelect('original.id as payment_id', 'original.paid_at', 'original.amount_minor as payment_amount', 'a.currency')
            ->groupBy('original.id', 'original.paid_at', 'original.amount_minor', 'a.currency')
            ->orderBy('original.paid_at')->orderBy('original.id')->get();
        abort_if($rows->isEmpty(), 404);

        return $rows;
    }

    /** @return Collection<int, array{currency: string, accrued: int, paid: int, settled: int, remaining: int}> */
    public function summary(User $actor): Collection
    {
        $totals = $this->amounts($this->rows($actor, personal: true))->addSelect('a.currency')->groupBy('a.currency')->get()->keyBy('currency');
        $settlements = DB::table('commission_settlements')
            ->where('beneficiary_id', $actor->id)
            ->select('currency')->selectRaw('SUM(amount_minor) as settled')->groupBy('currency')->get()->keyBy('currency');

        return $totals->keys()->merge($settlements->keys())->push('UZS')->unique()->sort()->values()
            ->map(function (string $currency) use ($totals, $settlements): array {
                $total = $totals->get($currency);
                $settled = (int) ($settlements->get($currency)?->settled ?? 0);

                return ['currency' => $currency, 'accrued' => (int) ($total?->accrued ?? 0),
                    'paid' => (int) ($total?->paid ?? 0), 'settled' => $settled,
                    'remaining' => (int) ($total?->remaining ?? 0) + $settled];
            });
    }

    /**
     * @return Collection<int, array{
     *     currency: string,
     *     obligations: array<string, array{currency: string, accrued: int, paid: int, settled: int, remaining: int}>,
     *     sfp_remaining: int,
     *     employees: list<array{beneficiary_id: int, name: string, remaining: int}>,
     *     remuneration: array{owner: int, rop: int},
     *     unassigned_sfp_settled: int
     * }>
     */
    public function fundingSummary(User $actor): Collection
    {
        abort_unless($actor->active && in_array($actor->role, ['owner', 'rop'], true), 403);

        $totals = $this->amounts($this->rows($actor))
            ->addSelect('a.currency', 'a.kind')->groupBy('a.currency', 'a.kind')->get()->groupBy('currency');
        $settlements = $this->fundingSettlements()->get()->groupBy('currency');
        $employees = $this->employeeBalances($actor);

        return $totals->keys()->merge($settlements->keys())->push('UZS')->unique()->sort()->values()
            ->map(function (string $currency) use ($totals, $settlements, $employees): array {
                $rows = $totals->get($currency, collect())->keyBy('kind');
                $settled = $settlements->get($currency, collect())->keyBy('category');
                $obligations = [];
                foreach (['owner_gross' => ['owner_gross'], 'rop_gross' => ['rop_gross'], 'internal' => ['manager_owner', 'manager_rop']] as $category => $kinds) {
                    $amounts = $rows->only($kinds);
                    $adjustment = (int) ($settled->get($category)?->settled ?? 0);
                    $obligations[$category] = ['currency' => $currency,
                        'accrued' => (int) $amounts->sum('accrued'), 'paid' => (int) $amounts->sum('paid'),
                        'settled' => $adjustment, 'remaining' => (int) $amounts->sum('remaining') + $adjustment];
                }

                $unassignedSettled = (int) ($settled->get('unassigned')?->settled ?? 0);

                return ['currency' => $currency, 'obligations' => $obligations,
                    'sfp_remaining' => $obligations['owner_gross']['remaining'] + $obligations['rop_gross']['remaining'] + $unassignedSettled,
                    'employees' => $employees->get($currency, collect())->values()->all(),
                    'remuneration' => [
                        'owner' => $obligations['owner_gross']['accrued'] - (int) ($rows->get('manager_owner')?->accrued ?? 0),
                        'rop' => $obligations['rop_gross']['accrued'] - (int) ($rows->get('manager_rop')?->accrued ?? 0),
                    ],
                    'unassigned_sfp_settled' => $unassignedSettled];
            });
    }

    /** @return Collection<string, Collection<int, array{beneficiary_id: int, name: string, remaining: int}>> */
    private function employeeBalances(User $actor): Collection
    {
        $balances = $this->amounts($this->rows($actor)->whereIn('a.kind', ['manager_owner', 'manager_rop']))
            ->addSelect('a.currency', 'a.beneficiary_id', 'u.name')
            ->groupBy('a.currency', 'a.beneficiary_id', 'u.name');
        $settlements = DB::table('commission_settlements')->where('payer', '!=', 'SFP')
            ->select('currency', 'beneficiary_id')->selectRaw('SUM(amount_minor) as settled')->groupBy('currency', 'beneficiary_id');

        return DB::query()->fromSub($balances, 'balances')
            ->leftJoinSub($settlements, 'settlements', fn (JoinClause $join) => $join
                ->on('settlements.beneficiary_id', '=', 'balances.beneficiary_id')->on('settlements.currency', '=', 'balances.currency'))
            ->select('balances.currency', 'balances.beneficiary_id', 'balances.name')
            ->selectRaw('balances.remaining + COALESCE(settlements.settled, 0) as remaining')
            ->orderBy('balances.name')->orderBy('balances.beneficiary_id')->get()->groupBy('currency')
            ->map(fn (Collection $rows): Collection => $rows->map(fn (object $row): array => [
                'beneficiary_id' => (int) $row->beneficiary_id, 'name' => $row->name, 'remaining' => (int) $row->remaining,
            ]));
    }

    private function fundingSettlements(): Builder
    {
        /** Settlements identify an account, not an accrual. Never assign a shared historical account to its current role. */
        $accounts = DB::table('accruals')->where('payer', 'SFP')->whereIn('kind', ['owner_gross', 'rop_gross'])
            ->select('beneficiary_id', 'currency')
            ->selectRaw('CASE WHEN COUNT(DISTINCT kind) = 1 THEN MIN(kind) ELSE NULL END as kind')
            ->groupBy('beneficiary_id', 'currency');

        return DB::table('commission_settlements as s')
            ->leftJoinSub($accounts, 'accounts', fn (JoinClause $join) => $join
                ->on('accounts.beneficiary_id', '=', 's.beneficiary_id')->on('accounts.currency', '=', 's.currency'))
            ->select('s.currency')
            ->selectRaw("CASE WHEN s.payer = 'SFP' THEN COALESCE(accounts.kind, 'unassigned') ELSE 'internal' END as category")
            ->selectRaw('SUM(s.amount_minor) as settled')->groupBy('s.currency', 'category');
    }
}
