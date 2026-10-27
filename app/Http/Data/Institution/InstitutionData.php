<?php

namespace App\Http\Data\Institution;

use Illuminate\Support\Facades\DB;
use Throwable;

class InstitutionData
{
    /**
     * Listar todas las instituciones.
     */
    public static function getInstitutions(): array
    {
        try {
            $instituciones = DB::table('institucion')
                ->select(
                    'id_institucion',
                    'nombre_institucion',
                    'pais',
                    'tipo'
                )
                ->orderBy('id_institucion', 'desc')
                ->get();

            return [
                'success' => true,
                'message' => 'Instituciones obtenidas correctamente.',
                'data' => $instituciones,
            ];
        } catch (Throwable $e) {
            report($e);

            return [
                'success' => false,
                'message' => 'Error al obtener las instituciones.',
                'status' => 500,
            ];
        }
    }

    /**
     * Obtener una institución por ID.
     */
    public static function getInstitutionById(int $id): array
    {
        try {
            $institucion = DB::table('institucion')
                ->where('id_institucion', $id)
                ->first();

            if (! $institucion) {
                return [
                    'success' => false,
                    'message' => 'Institución no encontrada.',
                    'status' => 404,
                ];
            }

            return [
                'success' => true,
                'message' => 'Institución obtenida correctamente.',
                'data' => $institucion,
            ];
        } catch (Throwable $e) {
            report($e);

            return [
                'success' => false,
                'message' => 'Error al obtener la institución.',
                'status' => 500,
            ];
        }
    }

    /**
     * Registrar una institución.
     */
    public static function createInstitution(array $data): array
    {
        try {
            $id = DB::table('institucion')
                ->insertGetId($data, 'id_institucion');

            $institucion = DB::table('institucion')
                ->where('id_institucion', $id)
                ->first();

            return [
                'success' => true,
                'message' => 'Institución registrada correctamente.',
                'data' => $institucion,
            ];
        } catch (Throwable $e) {
            report($e);

            return [
                'success' => false,
                'message' => 'Error al registrar la institución.',
                'status' => 500,
            ];
        }
    }

    /**
     * Actualizar una institución.
     */
    public static function updateInstitution(int $id, array $data): array
    {
        try {
            $institucion = DB::table('institucion')
                ->where('id_institucion', $id)
                ->first();

            if (! $institucion) {
                return [
                    'success' => false,
                    'message' => 'Institución no encontrada.',
                    'status' => 404,
                ];
            }

            DB::table('institucion')
                ->where('id_institucion', $id)
                ->update($data);

            $institucionActualizada = DB::table('institucion')
                ->where('id_institucion', $id)
                ->first();

            return [
                'success' => true,
                'message' => 'Institución actualizada correctamente.',
                'data' => $institucionActualizada,
            ];
        } catch (Throwable $e) {
            report($e);

            return [
                'success' => false,
                'message' => 'Error al actualizar la institución.',
                'status' => 500,
            ];
        }
    }

    /**
     * Eliminar una institución.
     */
    public static function deleteInstitution(int $id): array
    {
        try {
            $eliminados = DB::table('institucion')
                ->where('id_institucion', $id)
                ->delete();

            if ($eliminados === 0) {
                return [
                    'success' => false,
                    'message' => 'Institución no encontrada.',
                    'status' => 404,
                ];
            }

            return [
                'success' => true,
                'message' => 'Institución eliminada correctamente.',
                'data' => null,
            ];
        } catch (Throwable $e) {
            report($e);

            return [
                'success' => false,
                'message' => 'Error al eliminar la institución.',
                'status' => 500,
            ];
        }
    }
}
