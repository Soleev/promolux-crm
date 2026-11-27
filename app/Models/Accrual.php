<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['payment_id', 'beneficiary_id', 'kind', 'payer', 'rate_bps', 'amount_minor', 'currency'])]
class Accrual extends Model
{
    use Concerns\Audited;
    use Concerns\Immutable;

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'rate_bps' => 'integer'];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(User::class, 'beneficiary_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(Allocation::class);
    }

    public function refundCommissionReversals(): HasMany
    {
        return $this->hasMany(RefundCommissionReversal::class);
    }

    public function scopeWithOutstandingAmounts(Builder $query): Builder
    {
        return $query->withSum('allocations as allocated_minor', 'amount_minor')
            ->withSum('refundCommissionReversals as refund_reversed_minor', 'amount_minor');
    }

    public function getOutstandingMinorAttribute(): int
    {
        $paid = array_key_exists('allocated_minor', $this->attributes) ? $this->attributes['allocated_minor'] : $this->allocations()->sum('amount_minor');
        $reversed = array_key_exists('refund_reversed_minor', $this->attributes)
            ? $this->attributes['refund_reversed_minor'] : $this->refundCommissionReversals()->sum('amount_minor');

        return $this->amount_minor - (int) $paid - (int) $reversed;
    }
}
