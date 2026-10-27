<?php

namespace Tests\Fixtures;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

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
