<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['number', 'client_id', 'deal_id', 'manager_id', 'currency', 'status', 'due_at', 'description', 'attachment'])]
class Order extends Model
{
    use Concerns\Audited;

    protected function casts(): array
    {
        return ['due_at' => 'date'];
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function getTotalMinorAttribute(): int
    {
        return $this->items->sum(fn ($item) => $item->quantity * $item->price_minor);
    }

    public function cashReceipts(): HasMany
    {
        return $this->hasMany(CashReceipt::class);
    }

    public function getPaidMinorAttribute(): int
    {
        return (int) $this->payments()->sum('amount_minor') + (int) $this->cashReceipts()->where('status', 'pending')->sum('amount_minor');
    }

    public function getPaymentStatusAttribute(): string
    {
        $paid = $this->paid_minor;

        return $paid === 0 ? 'Не оплачен' : ($paid < $this->total_minor ? 'Частично оплачен' : ($paid === $this->total_minor ? 'Оплачен' : 'Переплата'));
    }
}
