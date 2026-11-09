<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['idempotency_key', 'order_id', 'manager_id', 'paid_at', 'amount_minor', 'currency', 'recipient', 'method', 'notes', 'document', 'calculation_status', 'reversal_of'])]
class Payment extends Model
{
    use Concerns\Audited;
    use Concerns\Immutable;

    protected function casts(): array
    {
        return ['paid_at' => 'date', 'amount_minor' => 'integer'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function accruals(): HasMany
    {
        return $this->hasMany(Accrual::class);
    }
}
