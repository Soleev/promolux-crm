<?php

namespace App\Models\Concerns;

use App\Models\Activity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

trait Audited
{
    protected static function bootAudited(): void
    {
        static::created(fn (Model $record) => $record->recordActivity('created', $record->getAttributes()));
        static::updated(fn (Model $record) => $record->recordActivity('updated', ['before' => array_intersect_key($record->getRawOriginal(), $record->getChanges()), 'after' => $record->getChanges()]));
        static::deleting(function () {
            throw ValidationException::withMessages(['record' => 'Удаление записей запрещено. Используйте статус или корректировку.']);
        });
    }

    public function recordActivity(string $event, array $changes): void
    {
        Activity::create(['actor_id' => auth()->id(), 'subject_type' => static::class, 'subject_id' => $this->id, 'event' => $event, 'changes' => $changes]);
    }
}
