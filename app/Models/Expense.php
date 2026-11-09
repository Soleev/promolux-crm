<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['paid_at', 'category', 'amount_minor', 'currency', 'payer', 'order_id', 'notes'])]
class Expense extends Model
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
}
