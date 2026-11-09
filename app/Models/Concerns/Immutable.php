<?php

namespace App\Models\Concerns;

use Illuminate\Validation\ValidationException;

trait Immutable
{
    protected static function bootImmutable(): void
    {
        static::updating(function ($record) {
            if ($record->isDirty()) {
                throw ValidationException::withMessages(['record' => 'Проведённые операции неизменяемы. Создайте корректировку.']);
            }
        });
        static::deleting(function () {
            throw ValidationException::withMessages(['record' => 'Удаление проведённых операций запрещено.']);
        });
    }
}
