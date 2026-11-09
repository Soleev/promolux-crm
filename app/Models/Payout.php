<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['idempotency_key', 'payer', 'recipient_id', 'paid_at', 'amount_minor', 'currency', 'notes', 'reversal_of'])]
class Payout extends Model
{
    use Concerns\Audited;
    use Concerns\Immutable;

    protected function casts(): array
    {
        return ['paid_at' => 'date', 'amount_minor' => 'integer'];
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(Allocation::class);
    }
}
