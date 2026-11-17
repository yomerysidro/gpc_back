<?php

namespace Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Tests\Fixtures\ModuleUser as User;

class ModulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->name === 'Authorized fixture';
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function view(User $user, Model $record): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Model $record): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->viewAny($user);
    }
}
