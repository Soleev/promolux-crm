<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role', 'active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ROLE_LABELS = ['owner' => 'Владелец', 'rop' => 'РОП', 'manager' => 'Менеджер', 'cashier' => 'Кассир', 'designer' => 'Дизайнер', 'sfp_screen' => 'Экран SFP'];

    public function save(array $options = [])
    {
        if ($this->exists && ! $this->isDirty('role')) {
            return parent::save($options);
        }

        return $this->getConnection()->transaction(function () use ($options): bool {
            $previous = $this->exists
                ? $this->newQuery()->whereKey($this->getKey())->lockForUpdate()->value('role')
                : null;
            if (! parent::save($options)) {
                return false;
            }

            $role = $this->role ?? $this->newQuery()->whereKey($this->getKey())->value('role');
            if ($role !== $previous) {
                $this->roleHistory()->create([
                    'previous_role' => $previous,
                    'role' => $role,
                    'changed_by' => auth()->id(),
                    'recorded_at' => now(),
                ]);
            }

            return true;
        });
    }

    public function roleHistory(): HasMany
    {
        return $this->hasMany(UserRoleHistory::class);
    }

    public function handlesOwnOrders(): bool
    {
        return in_array($this->role, ['manager', 'designer'], true);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->active && in_array($this->role, ['owner', 'rop', 'manager', 'cashier', 'designer'], true);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'active' => 'boolean',
        ];
    }
}
