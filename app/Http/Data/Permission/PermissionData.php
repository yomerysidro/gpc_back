<?php

namespace App\Http\Data\Permission;

use App\Models\Permission\Permission;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Si el permiso no existe se devuelve success true y data null:
 * el controller responde 404.
 */
class PermissionData
{
    /**
     * Crear un permiso (activo) en el módulo.
     */
    public static function createPermission(int $idModule, array $data): array
    {
        DB::beginTransaction();

        try {
            $permission = Permission::create([
                'id_modulo' => $idModule,
                'nombre' => $data['nombre'],
                'clave' => $data['clave'],
                'valor' => $data['valor'] ?? null,
                'comentario' => $data['comentario'] ?? null,
                'estado' => true,
            ]);

            DB::commit();

            return [
                'success' => true,
                'message' => 'Permiso registrado correctamente.',
                'data' => self::format($permission)
            ];

        } catch (Throwable $e) {
            DB::rollBack();

            return [
                'success' => false,
                'message' => 'Error al registrar el permiso.'
            ];
        }
    }


    /**
     * Actualizar el permiso. El módulo al que pertenece no cambia.
     */
    public static function updatePermission(int $idPermission, array $data): array
    {
        DB::beginTransaction();

        try {
            $permission = Permission::find($idPermission);

            $permission?->update([
                'nombre' => $data['nombre'],
                'clave' => $data['clave'],
                'valor' => $data['valor'] ?? null,
                'comentario' => $data['comentario'] ?? null,
            ]);

            DB::commit();

            return [
                'success' => true,
                'message' => $permission ? 'Permiso actualizado correctamente.' : 'Permiso no encontrado.',
                'data' => $permission ? self::format($permission) : null
            ];

        } catch (Throwable $e) {
            DB::rollBack();

            return [
                'success' => false,
                'message' => 'Error al actualizar el permiso.'
            ];
        }
    }


    /**
     * Activar o desactivar el permiso.
     */
    public static function updatePermissionStatus(int $idPermission, bool $status): array
    {
        DB::beginTransaction();

        try {
            $permission = Permission::find($idPermission);

            $permission?->update(['estado' => $status]);

            DB::commit();

            return [
                'success' => true,
                'message' => match (true) {
                    ! $permission => 'Permiso no encontrado.',
                    $status => 'Permiso activado correctamente.',
                    default => 'Permiso desactivado correctamente.',
                },
                'data' => $permission ? self::format($permission) : null
            ];

        } catch (Throwable $e) {
            DB::rollBack();

            return [
                'success' => false,
                'message' => 'Error al actualizar el estado del permiso.'
            ];
        }
    }


    public static function format(Permission $permission): array
    {
        return [
            'id' => $permission->id_permiso,
            'idModulo' => $permission->id_modulo,
            'nombre' => $permission->nombre,
            'clave' => $permission->clave,
            'valor' => $permission->valor,
            'comentario' => $permission->comentario,
            'estado' => $permission->estado,
        ];
    }
}
