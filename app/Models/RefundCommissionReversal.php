<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['refund_id', 'accrual_id', 'amount_minor', 'recorded_at'])]
class RefundCommissionReversal extends Model
{
    use Audited, Immutable;

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'recorded_at' => 'immutable_datetime'];
    }

    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }

    public function accrual(): BelongsTo
    {
        return $this->belongsTo(Accrual::class);
    }
}
