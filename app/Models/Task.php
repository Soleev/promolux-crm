<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['title', 'manager_id', 'client_id', 'deal_id', 'order_id', 'due_at', 'status', 'notes'])]
class Task extends Model
{
    use Concerns\Audited;

    protected function casts(): array
    {
        return ['due_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $task) {
            if (auth()->id() !== null && self::supportsDelegation()) {
                $task->created_by = auth()->id();
            }
        });
        static::updating(function (self $task) {
            if ($task->isDirty('created_by')) {
                throw \Illuminate\Validation\ValidationException::withMessages(['created_by' => 'Постановщика задачи изменить нельзя.']);
            }
        });
    }

    public static function supportsDelegation(): bool
    {
        // The local legacy stand also serves this checkout, with its original schema.
        return once(fn () => \Illuminate\Support\Facades\Schema::hasColumn('tasks', 'created_by'));
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function statusOnlyFor(User $actor): bool
    {
        return $actor->role === 'designer' && $this->created_by !== null && (int) $this->created_by !== (int) $actor->id;
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

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
