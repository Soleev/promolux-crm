<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
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

    public function getOutstandingMinorAttribute(): int
    {
        return $this->amount_minor - (int) $this->allocations()->sum('amount_minor');
    }
}
