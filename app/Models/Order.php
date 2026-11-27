<?php

namespace App\Models;

use App\Services\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

#[Fillable(['number', 'client_id', 'deal_id', 'manager_id', 'currency', 'status', 'due_at', 'description', 'attachment'])]
class Order extends Model
{
    use Concerns\Audited;

    protected static function booted(): void
    {
        static::updating(function (Order $order): void {
            if ($order->isDirty(['initial_seller_id', 'initial_seller_role'])) {
                throw ValidationException::withMessages(['initial_seller_id' => 'Первоначальный продавец заказа неизменяем.']);
            }
        });
    }

    protected function casts(): array
    {
        return ['due_at' => 'date'];
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function initialSeller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initial_seller_id');
    }

    public function financialTerms(): HasOne
    {
        return $this->hasOne(OrderFinancialTerms::class);
    }

    public function currentCancellation(): HasOne
    {
        return $this->hasOne(OrderCancellation::class)->latestOfMany()->whereNull('order_cancellations.restored_at');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
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
            ->withSum(['cashReceipts as pending_minor' => fn (Builder $cash) => $cash->where('status', 'pending')], 'amount_minor')
            ->withSum(['refunds as returned_minor' => fn (Builder $refunds) => $refunds->whereColumn('refunds.currency', 'orders.currency')->where('status', Refund::COMPLETED)], 'amount_minor')
            ->withSum(['refunds as approved_refund_minor' => fn (Builder $refunds) => $refunds->whereColumn('refunds.currency', 'orders.currency')->where('status', Refund::APPROVED)], 'amount_minor');
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
        $pending = array_key_exists('pending_minor', $this->attributes) ? $this->pending_minor : $this->cashReceipts()->where('status', 'pending')->sum('amount_minor');

        return $this->confirmed_paid_minor + (int) $pending;
    }

    public function getConfirmedPaidMinorAttribute(): int
    {
        if (array_key_exists('confirmed_minor', $this->attributes)) {
            return (int) $this->attributes['confirmed_minor'];
        }

        return (int) $this->payments()->where('currency', $this->currency)->sum('amount_minor');
    }

    public function getReturnedMinorAttribute(): int
    {
        if (array_key_exists('returned_minor', $this->attributes)) {
            return (int) $this->attributes['returned_minor'];
        }

        return (int) $this->refunds()->where('currency', $this->currency)->where('status', Refund::COMPLETED)->sum('amount_minor');
    }

    public function getNetPaidMinorAttribute(): int
    {
        return $this->paid_minor - $this->returned_minor;
    }

    public function getReceivableMinorAttribute(): int
    {
        return $this->status === 'cancelled' ? 0 : $this->total_minor - $this->net_paid_minor;
    }

    public function getRefundStatusAttribute(): ?string
    {
        if ($this->returned_minor === 0) {
            return null;
        }

        return $this->returned_minor < $this->confirmed_paid_minor ? 'Частичный возврат' : 'Полный возврат';
    }

    public function displayAmount(int $minor): string
    {
        return $this->currency === 'UZS' ? Money::display($minor) : Money::format($minor).' '.$this->currency;
    }

    public function getPaymentStatusAttribute(): string
    {
        return $this->paymentStatusFor($this->net_paid_minor);
    }

    public function paymentStatusFor(int $paid): string
    {
        return $paid === 0 ? 'Не оплачен' : ($paid < $this->total_minor ? 'Частично оплачен' : ($paid === $this->total_minor ? 'Оплачен' : 'Переплата'));
    }
}
