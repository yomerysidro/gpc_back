<?php

namespace App\Http\Data\Person_Worker;

use Illuminate\Support\Facades\DB;
use Throwable;

class Person_WorkerData
{
    /**
     * Listar trabajadores.
     */
    public static function getPersonWorkers(): array
    {
        try {
            $trabajadores = DB::table('persona_trabajador')
                ->select(
                    'id_persona_trabajador',
                    'id_persona',
                    'id_area',
                    'cargo',
                    'fecha_ingreso',
                    'fecha_fin',
                    'estado'
                )
                ->orderBy('id_persona_trabajador', 'desc')
                ->get();

            return [
                'success' => true,
                'message' => 'Trabajadores obtenidos correctamente.',
                'data' => $trabajadores,
            ];
        } catch (Throwable $e) {
            report($e);

            return [
                'success' => false,
                'message' => 'Error al obtener los trabajadores.',
                'status' => 500,
            ];
        }
    }

    /**
     * Obtener un trabajador por ID.
     */
    public static function getPersonWorkerById(int $id): array
    {
        try {
            $trabajador = DB::table('persona_trabajador')
                ->where('id_persona_trabajador', $id)
                ->first();

            if (! $trabajador) {
                return [
                    'success' => false,
                    'message' => 'Trabajador no encontrado.',
                    'status' => 404,
                ];
            }

            return [
                'success' => true,
                'message' => 'Trabajador obtenido correctamente.',
                'data' => $trabajador,
            ];
        } catch (Throwable $e) {
            report($e);

            return [
                'success' => false,
                'message' => 'Error al obtener el trabajador.',
                'status' => 500,
            ];
        }
    }

    /**
     * Registrar un trabajador.
     */
    public static function createPersonWorker(array $data): array
    {
        try {
            $id = DB::table('persona_trabajador')
                ->insertGetId($data, 'id_persona_trabajador');

            $trabajador = DB::table('persona_trabajador')
                ->where('id_persona_trabajador', $id)
                ->first();

            return [
                'success' => true,
                'message' => 'Trabajador registrado correctamente.',
                'data' => $trabajador,
            ];
        } catch (Throwable $e) {
            report($e);

            return [
                'success' => false,
                'message' => 'Error al registrar el trabajador.',
                'status' => 500,
            ];
        }
    }

    /**
     * Actualizar un trabajador.
     */
    public static function updatePersonWorker(int $id, array $data): array
    {
        try {
            $trabajador = DB::table('persona_trabajador')
                ->where('id_persona_trabajador', $id)
                ->first();

            if (! $trabajador) {
                return [
                    'success' => false,
                    'message' => 'Trabajador no encontrado.',
                    'status' => 404,
                ];
            }

            DB::table('persona_trabajador')
                ->where('id_persona_trabajador', $id)
                ->update($data);

            $trabajadorActualizado = DB::table('persona_trabajador')
                ->where('id_persona_trabajador', $id)
                ->first();

            return [
                'success' => true,
                'message' => 'Trabajador actualizado correctamente.',
                'data' => $trabajadorActualizado,
            ];
        } catch (Throwable $e) {
            report($e);

            return [
                'success' => false,
                'message' => 'Error al actualizar el trabajador.',
                'status' => 500,
            ];
        }
    }

    /**
     * Eliminar un trabajador.
     */
    public static function deletePersonWorker(int $id): array
    {
        try {
            $eliminados = DB::table('persona_trabajador')
                ->where('id_persona_trabajador', $id)
                ->delete();

            if ($eliminados === 0) {
                return [
                    'success' => false,
                    'message' => 'Trabajador no encontrado.',
                    'status' => 404,
                ];
            }

            return [
                'success' => true,
                'message' => 'Trabajador eliminado correctamente.',
                'data' => null,
            ];
        } catch (Throwable $e) {
            report($e);

            return [
                'success' => false,
                'message' => 'Error al eliminar el trabajador.',
                'status' => 500,
            ];
        }
    }
}
