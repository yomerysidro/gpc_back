<?php

namespace App\Services\Auth;

use App\Models\User\User;
use Illuminate\Database\Eloquent\Model;

class ModulePermissions
{
    public static function allows(User $user, Model|string $target, string $action): bool
    {
        $class = is_string($target) ? $target : $target::class;
        $modules = config('module_permissions.models.'.$class);
        if (! $user->estado || empty($modules)) {
            return false;
        }

        $permissions = $user->permissionKeys();
        foreach ((array) $modules as $module) {
            if (is_string($module) && $module !== '' && in_array($module.'.'.$action, $permissions, true)) {
                return true;
            }
        }

        return false;
    }
}
