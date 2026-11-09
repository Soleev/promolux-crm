<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['order_id', 'name', 'quantity', 'price_minor'])]
class OrderItem extends Model
{
    use Concerns\Audited;

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'price_minor' => 'integer'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
