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
        if (! $user->estado) {
            return false;
        }

        $permission = is_array($module)
            ? ($module[$action] ?? null)
            : (is_string($module) && $module !== '' ? $module.'.'.$action : null);

        return is_string($permission) && $permission !== ''
            && in_array($permission, $user->permissionKeys(), true);
    }
}
