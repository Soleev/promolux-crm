<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

#[Fillable(['number', 'idempotency_key', 'order_id', 'client_id', 'amount_minor', 'currency', 'reason', 'method', 'created_by'])]
class Refund extends Model
{
    use Audited;

    public const CREATED = 'created';

    public const APPROVED = 'approved';

    public const COMPLETED = 'completed';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    public const METHODS = ['cash', 'bank_transfer'];

    public const SOURCES = ['SFP', 'Promolux'];

    protected $attributes = ['status' => self::CREATED];

    protected $hidden = ['idempotency_key', 'approval_key', 'execution_key', 'closure_key', 'document', 'document_hash'];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'approved_at' => 'immutable_datetime',
            'executed_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $refund): void {
            if ($refund->getOriginal('status') === self::COMPLETED
                || $refund->isDirty(['number', 'idempotency_key', 'order_id', 'client_id', 'amount_minor', 'currency', 'reason', 'method', 'created_by'])) {
                throw ValidationException::withMessages(['record' => 'Данные проведённого возврата и исходные реквизиты заявки неизменяемы.']);
            }
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function executor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(RefundAllocation::class);
    }

    public function executionIssues(): HasMany
    {
        return $this->hasMany(RefundExecutionIssue::class);
    }
}
