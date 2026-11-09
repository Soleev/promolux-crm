<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['idempotency_key', 'order_id', 'manager_id', 'managed_sale', 'collector_id', 'owner_id', 'rop_id', 'cashier_id', 'payment_id', 'collected_at', 'handed_at', 'amount_minor', 'status', 'reason'])]
class CashReceipt extends Model
{
    use Concerns\Audited;

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'managed_sale' => 'boolean', 'collected_at' => 'date', 'handed_at' => 'date'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function collector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collector_id');
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }
}
