<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['refund_id', 'actor_id', 'idempotency_key', 'reason'])]
class RefundExecutionIssue extends Model
{
    use Audited, Immutable;

    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
