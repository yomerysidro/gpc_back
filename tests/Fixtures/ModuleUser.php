<?php

namespace Tests\Fixtures;

use App\Models\User\User;

class ModuleUser extends User
{
    protected $fillable = ['name'];

    protected $attributes = ['estado' => true];

    public function permissionKeys(): array
    {
        return [];
    }
}
