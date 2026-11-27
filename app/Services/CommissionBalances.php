<?php

namespace App\Services;

use App\Models\Accrual;
use App\Models\Allocation;
use App\Models\CommissionSettlement;
use App\Models\RefundCommissionReversal;

class CommissionBalances
{
    /**
     * Positive amounts are payable to the original beneficiary. Negative amounts
     * are owed to the original payer. Future accruals credit only this account.
     */
    public function balance(int $beneficiaryId, string $payer, string $currency, string $until): int
    {
        $accruals = Accrual::query()->where('beneficiary_id', $beneficiaryId)
            ->where('payer', $payer)->where('currency', $currency)
            ->whereHas('payment', fn ($payments) => $payments->whereDate('paid_at', '<=', $until));
        $accrualIds = (clone $accruals)->select('id');
        $paid = Allocation::query()->whereIn('accrual_id', clone $accrualIds)
            ->whereHas('payout', fn ($payouts) => $payouts->whereDate('paid_at', '<=', $until))->sum('amount_minor');
        $reversed = RefundCommissionReversal::query()->whereIn('accrual_id', $accrualIds)
            ->whereDate('recorded_at', '<=', $until)->sum('amount_minor');
        $settled = CommissionSettlement::query()->where('beneficiary_id', $beneficiaryId)
            ->where('payer', $payer)->where('currency', $currency)
            ->whereDate('settled_on', '<=', $until)->sum('amount_minor');

        return (int) $accruals->sum('amount_minor') - (int) $paid - (int) $reversed + (int) $settled;
    }
}
