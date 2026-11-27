<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

#[Fillable(['order_id', 'previous_status', 'reason', 'cancelled_by', 'cancelled_at', 'idempotency_key'])]
class OrderCancellation extends Model
{
    protected function casts(): array
    {
        return [
            'order_id' => 'integer',
            'cancelled_by' => 'integer',
            'cancelled_at' => 'datetime',
            'restored_by' => 'integer',
            'restored_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $cancellation): void {
            if ($cancellation->getOriginal('restored_at') !== null
                || array_diff(array_keys($cancellation->getDirty()), ['restored_by', 'restored_at', 'restore_idempotency_key', 'updated_at']) !== []) {
                throw ValidationException::withMessages(['record' => 'Сведения об отмене заказа нельзя изменить.']);
            }
        });

        static::deleting(function (): never {
            throw ValidationException::withMessages(['record' => 'Удаление сведений об отмене запрещено.']);
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
