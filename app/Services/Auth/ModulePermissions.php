<?php

namespace App\Services\Auth;

use App\Models\Activity\Activity;
use App\Models\ActivityType\ActivityType;
use App\Models\Area\Area;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Model;

class ModulePermissions
{
    /**
     * UI capabilities describe permissions; record ownership, state and area remain server-side checks.
     *
     * @param  list<string>  $permissions
     * @return array{consultar_revision: bool, consultar_gerencia: bool, consultar_historial: bool, consulta_administrativa_global: bool, revisar: bool, derivar: bool, aprobar: bool, rechazar: bool, decidir_ambito_institucional: bool, decidir_propias: bool, representar_areas: bool}
     */
    public static function activityRequestCapabilities(User $user, array $permissions): array
    {
        $canConsult = $user->estado && in_array('solicitudes.consultar', $permissions, true);
        $canReview = $user->estado && in_array('solicitudes.revisar', $permissions, true);
        $canApprove = $user->estado && in_array('solicitudes.aprobar', $permissions, true);
        $canReject = $user->estado && in_array('solicitudes.rechazar', $permissions, true);
        $administrator = $user->isSystemAdministrator();
        $institutionalDecision = $administrator && config('module_permissions.administrator_request_institutional_scope', false)
            && ($canApprove || $canReject);

        return [
            'consultar_revision' => $canConsult && $canReview,
            'consultar_gerencia' => $canConsult && ($administrator || $canApprove || $canReject),
            'consultar_historial' => $user->estado && in_array('solicitudes.historial', $permissions, true),
            'consulta_administrativa_global' => $canConsult && $administrator,
            'revisar' => $canReview,
            'derivar' => $canReview && in_array('solicitudes.derivar', $permissions, true),
            'aprobar' => $canApprove,
            'rechazar' => $canReject,
            'decidir_ambito_institucional' => $institutionalDecision,
            'decidir_propias' => $institutionalDecision,
            'representar_areas' => $user->canRepresentAllRequestAreas(),
        ];
    }

    public static function allows(User $user, Model|string $target, string $action): bool
    {
        $class = is_string($target) ? $target : $target::class;
        $module = config('module_permissions.models.'.$class);
        if (! $user->estado || ! is_string($module) || $module === '') {
            return false;
        }

        if ($class === Activity::class && $action === 'crear') {
            return false;
        }

        $request = request();
        $permissionKey = self::class.'.permissions.'.spl_object_id($user);
        $permissions = $request->route() !== null ? $request->attributes->get($permissionKey) : null;
        if ($permissions === null) {
            $permissions = $user->permissionKeys();
            if ($request->route() !== null) {
                $request->attributes->set($permissionKey, $permissions);
            }
        }
        if ($action === 'ver' && in_array($class, [Area::class, ActivityType::class], true)
            && array_intersect(['solicitudes.crear', 'solicitudes.propias', 'solicitudes.consultar'], $permissions) !== []) {
            return true;
        }

        $action = config('module_permissions.action_aliases.'.$class.'.'.$action, $action);

        return in_array($module.'.'.$action, $permissions, true);
    }
}
