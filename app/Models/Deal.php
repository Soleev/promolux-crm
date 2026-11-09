<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['title', 'client_id', 'manager_id', 'status', 'currency', 'description', 'lost_reason', 'items'])]
class Deal extends Model
{
    use Concerns\Audited;

    protected function casts(): array
    {
        return ['items' => 'array'];
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function order(): HasOne
    {
        return $this->hasOne(Order::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }
}
