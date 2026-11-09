<?php

namespace App\Policies;

use App\Models\User;
use App\Services\CrmAccess;
use Illuminate\Database\Eloquent\Model;

class CrmPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->active;
    }

    public function view(User $user, Model $record): bool
    {
        return CrmAccess::view($user, $record);
    }

    public function update(User $user, Model $record): bool
    {
        return CrmAccess::manage($user, $record::class) && CrmAccess::view($user, $record);
    }

    public function delete(User $user, Model $record): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
