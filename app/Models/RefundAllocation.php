<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['refund_id', 'payment_id', 'amount_minor'])]
class RefundAllocation extends Model
{
    use Audited, Immutable;

    protected function casts(): array
    {
        return ['amount_minor' => 'integer'];
    }

    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
