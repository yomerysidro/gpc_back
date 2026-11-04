<?php

namespace Tests\Fixtures;

use App\Models\User\User;

class ModuleUser extends User
{
    protected $fillable = ['name'];

    protected $attributes = ['estado' => true, 'id_persona' => 1];

    public function permissionKeys(): array
    {
        return [];
    }
}
