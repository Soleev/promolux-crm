<?php

namespace App\Models;

use App\Services\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

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

    public function scopeWithPaymentTotals(Builder $query): Builder
    {
        return $query
            ->withSum('items as ordered_minor', DB::raw('quantity * price_minor'))
            ->withSum(['payments as confirmed_minor' => fn (Builder $payments) => $payments->whereColumn('payments.currency', 'orders.currency')], 'amount_minor')
            ->withSum(['cashReceipts as pending_minor' => fn (Builder $cash) => $cash->where('status', 'pending')], 'amount_minor');
    }

    public function getTotalMinorAttribute(): int
    {
        if (array_key_exists('ordered_minor', $this->attributes)) {
            return (int) $this->ordered_minor;
        }

        return $this->items->sum(fn ($item) => $item->quantity * $item->price_minor);
    }

    public function cashReceipts(): HasMany
    {
        return $this->hasMany(CashReceipt::class);
    }

    public function getPaidMinorAttribute(): int
    {
        if (array_key_exists('confirmed_minor', $this->attributes)) {
            return (int) $this->confirmed_minor + (int) $this->pending_minor;
        }

        return (int) $this->payments()->where('currency', $this->currency)->sum('amount_minor') + (int) $this->cashReceipts()->where('status', 'pending')->sum('amount_minor');
    }

    public function displayAmount(int $minor): string
    {
        return $this->currency === 'UZS' ? Money::display($minor) : Money::format($minor).' '.$this->currency;
    }

    public function getPaymentStatusAttribute(): string
    {
        $paid = $this->paid_minor;

        return $paid === 0 ? 'Не оплачен' : ($paid < $this->total_minor ? 'Частично оплачен' : ($paid === $this->total_minor ? 'Оплачен' : 'Переплата'));
    }
}
