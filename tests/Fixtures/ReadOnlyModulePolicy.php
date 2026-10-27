<?php

namespace Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Tests\Fixtures\ModuleUser as User;

class ReadOnlyModulePolicy extends ModulePolicy
{
    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Model $record): bool
    {
        return false;
    }

    public function delete(User $user, Model $record): bool
    {
        return false;
    }
}
