<?php

namespace App\Services\Auth;

use App\Models\User\User;
use Illuminate\Database\Eloquent\Model;

class ModulePermissions
{
    public static function allows(User $user, Model|string $target, string $action): bool
    {
        $class = is_string($target) ? $target : $target::class;
        $module = config('module_permissions.models.'.$class);
        if (! $user->estado || ! is_string($module) || $module === '') {
            return false;
        }

        return in_array($module.'.'.$action, $user->permissionKeys(), true);
    }
}
