<?php

namespace App\Services;

use App\Models\Accrual;
use App\Models\Refund;
use App\Models\RefundAllocation;
use App\Models\RefundCommissionReversal;
use App\Models\User;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class RefundCommissionReversals
{
    /**
     * Post in the same transaction as refund execution, after its order and payments are locked.
     */
    public function record(Refund $refund): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Commission reversal requires a refund execution transaction.');
        }
        $refund = Refund::query()->lockForUpdate()->findOrFail($refund->id);
        if ($refund->status !== Refund::COMPLETED || $refund->executed_at === null) {
            throw new LogicException('Commission reversal requires a recorded payment to the customer.');
        }

        foreach ($this->calculate($refund, lock: true) as $row) {
            RefundCommissionReversal::create([
                'refund_id' => $refund->id,
                'accrual_id' => $row['accrual_id'],
                'amount_minor' => $row['amount_minor'],
                'recorded_at' => $refund->executed_at,
            ]);
        }
    }

    /**
     * RefundService authorizes access to these commission details.
     */
    public function preview(Refund $refund): array
    {
        if (! in_array($refund->status, [Refund::CREATED, Refund::APPROVED], true)) {
            throw ValidationException::withMessages(['record' => 'Предварительный расчёт доступен для открытой заявки.']);
        }

        return $this->calculate($refund);
    }

    private function calculate(Refund $refund, bool $lock = false): array
    {
        $allocations = $refund->allocations()->with('payment:id,order_id,currency,amount_minor,reversal_of')->get();
        if ($allocations->isEmpty() || $allocations->sum('amount_minor') !== $refund->amount_minor) {
            throw ValidationException::withMessages(['amount' => 'Распределение возврата должно совпадать с суммой заявки.']);
        }

        $paymentIds = $allocations->pluck('payment_id');
        $completed = RefundAllocation::query()
            ->join('refunds', 'refunds.id', '=', 'refund_allocations.refund_id')
            ->where('refunds.status', Refund::COMPLETED)->whereIn('refund_allocations.payment_id', $paymentIds)
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->get(['refund_allocations.payment_id', 'refund_allocations.amount_minor'])
            ->groupBy('payment_id')->map->sum('amount_minor');

        $ratios = [];
        foreach ($allocations as $allocation) {
            $payment = $allocation->payment;
            $returned = (int) $completed->get($payment->id, 0);
            if ($refund->status !== Refund::COMPLETED) {
                $returned += $allocation->amount_minor;
            }
            if ((int) $payment->order_id !== (int) $refund->order_id || $payment->currency !== $refund->currency
                || $payment->reversal_of !== null || $allocation->amount_minor <= 0 || $returned > $payment->amount_minor) {
                throw ValidationException::withMessages(['amount' => 'Проверьте доступную сумму, валюту и исходную оплату возврата.']);
            }
            $ratios[$payment->id] = [$returned, $payment->amount_minor];
        }

        $sourceAccruals = Accrual::query()->whereIn('payment_id', $paymentIds)->where('amount_minor', '>', 0);
        if ($lock) {
            $beneficiaries = (clone $sourceAccruals)->distinct()->pluck('beneficiary_id')->all();
            User::query()->whereKey($beneficiaries)->orderBy('id')->lockForUpdate()->get(['id']);
            $sourceAccruals->lockForUpdate();
        }
        $accruals = $sourceAccruals->orderBy('id')->get();
        $previous = RefundCommissionReversal::query()->whereIn('accrual_id', $accruals->modelKeys())
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->get(['accrual_id', 'amount_minor'])->groupBy('accrual_id')->map->sum('amount_minor');

        $rows = [];
        foreach ($accruals as $accrual) {
            if ($accrual->currency !== $refund->currency) {
                throw ValidationException::withMessages(['currency' => 'Валюта начисления отличается от валюты возврата.']);
            }
            [$returned, $received] = $ratios[$accrual->payment_id];
            $target = BigInteger::of($accrual->amount_minor)->multipliedBy($returned)
                ->dividedBy($received, RoundingMode::HalfUp)->toInt();
            $delta = $target - (int) $previous->get($accrual->id, 0);
            if ($delta > 0) {
                $rows[] = [
                    'accrual_id' => $accrual->id,
                    'payment_id' => $accrual->payment_id,
                    'beneficiary_id' => $accrual->beneficiary_id,
                    'kind' => $accrual->kind,
                    'payer' => $accrual->payer,
                    'rate_bps' => $accrual->rate_bps,
                    'currency' => $accrual->currency,
                    'amount_minor' => $delta,
                ];
            }
        }

        return $rows;
    }
}
