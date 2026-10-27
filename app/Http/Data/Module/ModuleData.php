<?php

namespace App\Http\Data\Module;

use App\Http\Data\Permission\PermissionData;
use App\Models\Module\Module;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Si el módulo no existe se devuelve success true y data null (el controller
 * responde 404). Las reglas de negocio incumplidas devuelven success false
 * con 'code' (409) para que el controller no las confunda con un 500.
 */
class ModuleData
{
    /**
     * Listar los módulos con sus permisos.
     */
    public static function getModules(): array
    {
        try {
            $modules = Module::with(['permissions' => fn ($query) => $query->orderBy('id_permiso')])
                ->orderBy('id_modulo')
                ->get()
                ->map(fn (Module $module) => self::format($module));

            return [
                'success' => true,
                'message' => 'Módulos obtenidos correctamente.',
                'data' => $modules,
            ];

        } catch (Throwable $e) {
            return [
                'success' => false,
                'message' => 'Error al obtener los módulos.',
            ];
        }
    }

    /**
     * Comprobar si existe el módulo.
     */
    public static function moduleExists(int $idModule): array
    {
        try {
            return [
                'success' => true,
                'message' => 'Módulo verificado correctamente.',
                'data' => Module::whereKey($idModule)->exists(),
            ];

        } catch (Throwable $e) {
            return [
                'success' => false,
                'message' => 'Error al obtener el módulo.',
            ];
        }
    }

    /**
     * Crear módulo (activo).
     */
    public static function createModule(array $data): array
    {
        DB::beginTransaction();

        try {
            $module = Module::create([
                'nombre' => $data['nombre'],
                'url' => $data['url'],
                'imagen' => $data['imagen'] ?? null,
                'estado' => true,
            ]);

            DB::commit();

            return [
                'success' => true,
                'message' => 'Módulo registrado correctamente.',
                'data' => self::format($module),
            ];

        } catch (Throwable $e) {
            DB::rollBack();

            return [
                'success' => false,
                'message' => 'Error al registrar el módulo.',
            ];
        }
    }

    /**
     * Actualizar nombre, url e imagen del módulo.
     *
     * La url no puede cambiar si el módulo tiene permisos: sus claves llevan
     * el slug de la url como prefijo (PermissionRequest).
     */
    public static function updateModule(int $idModule, array $data): array
    {
        DB::beginTransaction();

        try {
            $module = Module::lockForUpdate()->find($idModule);

            if ($module && $data['url'] !== $module->url && $module->permissions()->exists()) {
                DB::rollBack();

                return [
                    'success' => false,
                    'code' => 409,
                    'message' => 'No se puede cambiar la URL de un módulo que tiene permisos: las claves de permiso dependen de ella',
                ];
            }

            if ($module) {
                $module->update([
                    'nombre' => $data['nombre'],
                    'url' => $data['url'],
                    'imagen' => $data['imagen'] ?? null,
                ]);
            }

            DB::commit();

            return [
                'success' => true,
                'message' => $module ? 'Módulo actualizado correctamente.' : 'Módulo no encontrado.',
                'data' => $module ? self::format($module) : null,
            ];

        } catch (Throwable $e) {
            DB::rollBack();

            return [
                'success' => false,
                'message' => 'Error al actualizar el módulo.',
            ];
        }
    }

    /**
     * Activar o desactivar el módulo.
     */
    public static function updateModuleStatus(int $idModule, bool $status): array
    {
        DB::beginTransaction();

        try {
            $module = Module::find($idModule);

            $module?->update(['estado' => $status]);

            DB::commit();

            return [
                'success' => true,
                'message' => match (true) {
                    ! $module => 'Módulo no encontrado.',
                    $status => 'Módulo activado correctamente.',
                    default => 'Módulo desactivado correctamente.',
                },
                'data' => $module ? self::format($module) : null,
            ];

        } catch (Throwable $e) {
            DB::rollBack();

            return [
                'success' => false,
                'message' => 'Error al actualizar el estado del módulo.',
            ];
        }
    }

    private static function format(Module $module): array
    {
        return [
            'id' => $module->id_modulo,
            'nombre' => $module->nombre,
            'url' => $module->url,
            'imagen' => $module->imagen,
            'estado' => $module->estado,
            'permisos' => $module->permissions
                ->map(fn ($permission) => PermissionData::format($permission))
                ->all(),
        ];
    }
}
