<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['idempotency_key', 'beneficiary_id', 'payer', 'currency', 'amount_minor', 'kind', 'method', 'reason', 'settled_on', 'recorded_by'])]
class CommissionSettlement extends Model
{
    use Audited, Immutable;

    public const REPAYMENT = 'repayment';
    public const WRITE_OFF = 'write_off';

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'settled_on' => 'immutable_date'];
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
