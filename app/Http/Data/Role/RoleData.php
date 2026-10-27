<?php

namespace App\Http\Data\Role;

use App\Models\Role\Role;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Si el rol no existe se devuelve success true y data null (el controller
 * responde 404). Las reglas de negocio incumplidas devuelven success false
 * con 'code' (409/422) para que el controller no las confunda con un 500.
 */
class RoleData
{
    private const ADMIN_ROLE = 'Administrador';

    /**
     * Listar los roles con la cantidad de usuarios de cada uno.
     */
    public static function getRoles(): array
    {
        try {
            $roles = Role::withCount('users')
                ->orderBy('id_rol')
                ->get()
                ->map(fn (Role $role) => self::format($role));

            return [
                'success' => true,
                'message' => 'Roles obtenidos correctamente.',
                'data' => $roles
            ];

        } catch (Throwable $e) {
            return [
                'success' => false,
                'message' => 'Error al obtener los roles.'
            ];
        }
    }


    /**
     * Crear rol (activo).
     */
    public static function createRole(array $data): array
    {
        DB::beginTransaction();

        try {
            $role = Role::create([
                'nombre' => $data['nombre'],
                'estado' => true,
            ]);

            $role->loadCount('users');

            DB::commit();

            return [
                'success' => true,
                'message' => 'Rol registrado correctamente.',
                'data' => self::format($role)
            ];

        } catch (Throwable $e) {
            DB::rollBack();

            return [
                'success' => false,
                'message' => 'Error al registrar el rol.'
            ];
        }
    }


    /**
     * Actualizar el nombre del rol.
     */
    public static function updateRole(int $idRole, array $data): array
    {
        DB::beginTransaction();

        try {
            $role = Role::find($idRole);

            if ($role) {
                $role->update(['nombre' => $data['nombre']]);
                $role->loadCount('users');
            }

            DB::commit();

            return [
                'success' => true,
                'message' => $role ? 'Rol actualizado correctamente.' : 'Rol no encontrado.',
                'data' => $role ? self::format($role) : null
            ];

        } catch (Throwable $e) {
            DB::rollBack();

            return [
                'success' => false,
                'message' => 'Error al actualizar el rol.'
            ];
        }
    }


    /**
     * Activar o desactivar el rol.
     *
     * El rol Administrador no se puede desactivar si ningún otro rol activo
     * tiene usuarios activos: el sistema quedaría sin nadie con acceso.
     */
    public static function updateRoleStatus(int $idRole, bool $status): array
    {
        DB::beginTransaction();

        try {
            $role = Role::lockForUpdate()->find($idRole);

            if (! $role) {
                DB::commit();

                return [
                    'success' => true,
                    'message' => 'Rol no encontrado.',
                    'data' => null
                ];
            }

            if (! $status && $role->estado && $role->nombre === self::ADMIN_ROLE) {
                $otherActiveRoles = Role::where('id_rol', '!=', $role->id_rol)
                    ->where('estado', true)
                    ->whereHas('users', fn ($query) => $query->where('acceso.usuario.estado', true))
                    ->count();

                if ($otherActiveRoles === 0) {
                    DB::rollBack();

                    return [
                        'success' => false,
                        'code' => 409,
                        'message' => 'No se puede desactivar el rol Administrador: es el único rol activo con usuarios activos.'
                    ];
                }
            }

            $role->update(['estado' => $status]);
            $role->loadCount('users');

            DB::commit();

            return [
                'success' => true,
                'message' => $status ? 'Rol activado correctamente.' : 'Rol desactivado correctamente.',
                'data' => self::format($role)
            ];

        } catch (Throwable $e) {
            DB::rollBack();

            return [
                'success' => false,
                'message' => 'Error al actualizar el estado del rol.'
            ];
        }
    }


    /**
     * Ids de los módulos asignados al rol.
     */
    public static function getRoleModules(int $idRole): array
    {
        try {
            $role = Role::find($idRole);

            return [
                'success' => true,
                'message' => $role ? 'Módulos del rol obtenidos correctamente.' : 'Rol no encontrado.',
                'data' => $role ? self::moduleIds($idRole) : null
            ];

        } catch (Throwable $e) {
            return [
                'success' => false,
                'message' => 'Error al obtener los módulos del rol.'
            ];
        }
    }


    /**
     * Reemplazar los módulos del rol por $moduleIds.
     * Los permisos de los módulos que se quitan también se quitan.
     */
    public static function syncRoleModules(int $idRole, array $moduleIds): array
    {
        DB::beginTransaction();

        try {
            $role = Role::lockForUpdate()->find($idRole);

            if (! $role) {
                DB::commit();

                return [
                    'success' => true,
                    'message' => 'Rol no encontrado.',
                    'data' => null
                ];
            }

            $role->modules()->sync($moduleIds);

            DB::table('acceso.rol_modulo_permiso')
                ->where('id_rol', $idRole)
                ->whereNotIn('id_modulo', $moduleIds)
                ->delete();

            DB::commit();

            return [
                'success' => true,
                'message' => 'Módulos del rol actualizados correctamente.',
                'data' => self::moduleIds($idRole)
            ];

        } catch (Throwable $e) {
            DB::rollBack();

            return [
                'success' => false,
                'message' => 'Error al actualizar los módulos del rol.'
            ];
        }
    }


    /**
     * Permisos asignados al rol: [{idModulo, idPermiso}].
     */
    public static function getRolePermissions(int $idRole): array
    {
        try {
            $role = Role::find($idRole);

            return [
                'success' => true,
                'message' => $role ? 'Permisos del rol obtenidos correctamente.' : 'Rol no encontrado.',
                'data' => $role ? self::permissionPairs($idRole) : null
            ];

        } catch (Throwable $e) {
            return [
                'success' => false,
                'message' => 'Error al obtener los permisos del rol.'
            ];
        }
    }


    /**
     * Reemplazar los permisos del rol por $pairs ([{idModulo, idPermiso}]).
     *
     * Cada permiso debe pertenecer a su módulo y el módulo debe estar
     * asignado al rol; si alguno no cumple no se guarda nada (422).
     */
    public static function syncRolePermissions(int $idRole, array $pairs): array
    {
        DB::beginTransaction();

        try {
            $role = Role::lockForUpdate()->find($idRole);

            if (! $role) {
                DB::commit();

                return [
                    'success' => true,
                    'message' => 'Rol no encontrado.',
                    'data' => null
                ];
            }

            $pairs = collect($pairs)
                ->map(fn (array $pair) => [
                    'idModulo' => (int) $pair['idModulo'],
                    'idPermiso' => (int) $pair['idPermiso'],
                ])
                ->unique(fn (array $pair) => $pair['idModulo'].'-'.$pair['idPermiso'])
                ->values();

            $assignedModules = self::moduleIds($idRole);

            $permissionModules = DB::table('acceso.permiso')
                ->whereIn('id_permiso', $pairs->pluck('idPermiso'))
                ->pluck('id_modulo', 'id_permiso');

            $errors = [];

            foreach ($pairs as $index => $pair) {
                if (($permissionModules[$pair['idPermiso']] ?? null) !== $pair['idModulo']) {
                    $errors["permisos.$index"] = "El permiso {$pair['idPermiso']} no pertenece al módulo {$pair['idModulo']}.";
                } elseif (! in_array($pair['idModulo'], $assignedModules, true)) {
                    $errors["permisos.$index"] = "El módulo {$pair['idModulo']} no está asignado al rol.";
                }
            }

            if ($errors) {
                DB::rollBack();

                return [
                    'success' => false,
                    'code' => 422,
                    'message' => 'Hay permisos que no se pueden asignar al rol.',
                    'data' => $errors
                ];
            }

            DB::table('acceso.rol_modulo_permiso')
                ->where('id_rol', $idRole)
                ->delete();

            DB::table('acceso.rol_modulo_permiso')->insert(
                $pairs->map(fn (array $pair) => [
                    'id_rol' => $idRole,
                    'id_modulo' => $pair['idModulo'],
                    'id_permiso' => $pair['idPermiso'],
                ])->all()
            );

            DB::commit();

            return [
                'success' => true,
                'message' => 'Permisos del rol actualizados correctamente.',
                'data' => self::permissionPairs($idRole)
            ];

        } catch (Throwable $e) {
            DB::rollBack();

            return [
                'success' => false,
                'message' => 'Error al actualizar los permisos del rol.'
            ];
        }
    }


    private static function moduleIds(int $idRole): array
    {
        return DB::table('acceso.rol_modulo')
            ->where('id_rol', $idRole)
            ->orderBy('id_modulo')
            ->pluck('id_modulo')
            ->all();
    }

    private static function permissionPairs(int $idRole): array
    {
        return DB::table('acceso.rol_modulo_permiso')
            ->where('id_rol', $idRole)
            ->orderBy('id_modulo')
            ->orderBy('id_permiso')
            ->get(['id_modulo', 'id_permiso'])
            ->map(fn ($row) => [
                'idModulo' => $row->id_modulo,
                'idPermiso' => $row->id_permiso,
            ])
            ->all();
    }

    private static function format(Role $role): array
    {
        return [
            'id' => $role->id_rol,
            'nombre' => $role->nombre,
            'estado' => $role->estado,
            'usuarios' => $role->users_count,
        ];
    }
}
