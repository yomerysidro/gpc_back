<?php

namespace App\Http\Data\Supervisory_internship;

use App\Http\Data\GestionIntegrity;
use Illuminate\Support\Facades\DB;
use Throwable;

class Supervisory_internshipData
{
    /**
     * Listar todos los registros.
     */
    public static function getSupervisoryInternships(): array
    {
        try {
            $registros = DB::table('gestion.practica_supervisor')
                ->select(
                    'id_practica_supervisor',
                    'id_practica',
                    'id_persona_trabajador',
                    'tipo_supervision',
                    'fecha_inicio',
                    'fecha_fin',
                    'estado'
                )
                ->orderBy('id_practica_supervisor', 'desc')
                ->get();

            return [
                'success' => true,
                'message' => 'Registros obtenidos correctamente.',
                'data' => $registros,
            ];
        } catch (Throwable $e) {
            report($e);

            return [
                'success' => false,
                'message' => 'Error al obtener los registros.',
                'status' => 500,
            ];
        }
    }

    /**
     * Obtener un registro por ID.
     */
    public static function getSupervisoryInternshipById(int $id): array
    {
        try {
            $registro = DB::table('gestion.practica_supervisor')
                ->where('id_practica_supervisor', $id)
                ->first();

            if (! $registro) {
                return [
                    'success' => false,
                    'message' => 'Registro no encontrado.',
                    'status' => 404,
                ];
            }

            return [
                'success' => true,
                'message' => 'Registro obtenido correctamente.',
                'data' => $registro,
            ];
        } catch (Throwable $e) {
            report($e);

            return [
                'success' => false,
                'message' => 'Error al obtener el registro.',
                'status' => 500,
            ];
        }
    }

    /**
     * Crear un registro.
     */
    public static function createSupervisoryInternship(array $data): array
    {
        try {
            $id = DB::table('gestion.practica_supervisor')
                ->insertGetId($data, 'id_practica_supervisor');

            $registro = DB::table('gestion.practica_supervisor')
                ->where('id_practica_supervisor', $id)
                ->first();

            return [
                'success' => true,
                'message' => 'Registro creado correctamente.',
                'data' => $registro,
            ];
        } catch (Throwable $e) {
            if ($failure = GestionIntegrity::failure($e)) {
                return $failure;
            }

            report($e);

            return [
                'success' => false,
                'message' => 'Error al crear el registro.',
                'status' => 500,
            ];
        }
    }

    /**
     * Actualizar un registro.
     */
    public static function updateSupervisoryInternship(
        int $id,
        array $data
    ): array {
        try {
            return DB::transaction(function () use ($id, $data): array {
                $registro = DB::table('gestion.practica_supervisor')
                    ->where('id_practica_supervisor', $id)
                    ->lockForUpdate()->first();

                if (! $registro) {
                    return [
                        'success' => false,
                        'message' => 'Registro no encontrado.',
                        'status' => 404,
                    ];
                }

                $changes = GestionIntegrity::changes('gestion.practica_supervisor', $data, $registro);
                GestionIntegrity::assertHistoricalUpdate('gestion.practica_supervisor', $registro, $changes);
                if ($changes !== []) {
                    DB::table('gestion.practica_supervisor')
                        ->where('id_practica_supervisor', $id)
                        ->update($changes);
                }

                $registroActualizado = DB::table('gestion.practica_supervisor')
                    ->where('id_practica_supervisor', $id)
                    ->first();

                return [
                    'success' => true,
                    'message' => 'Registro actualizado correctamente.',
                    'data' => $registroActualizado,
                ];
            });
        } catch (Throwable $e) {
            if ($failure = GestionIntegrity::failure($e)) {
                return $failure;
            }

            report($e);

            return [
                'success' => false,
                'message' => 'Error al actualizar el registro.',
                'status' => 500,
            ];
        }
    }

    /**
     * Eliminar un registro.
     */
    public static function deleteSupervisoryInternship(int $id): array
    {
        try {
            return DB::transaction(function () use ($id): array {
                GestionIntegrity::assertCanDelete('gestion.practica_supervisor', $id);
                $eliminados = DB::table('gestion.practica_supervisor')
                    ->where('id_practica_supervisor', $id)
                    ->delete();

                if ($eliminados === 0) {
                    return [
                        'success' => false,
                        'message' => 'Registro no encontrado.',
                        'status' => 404,
                    ];
                }

                return [
                    'success' => true,
                    'message' => 'Registro eliminado correctamente.',
                    'data' => null,
                ];
            });
        } catch (Throwable $e) {
            if ($failure = GestionIntegrity::failure($e)) {
                return $failure;
            }

            report($e);

            return [
                'success' => false,
                'message' => 'Error al eliminar el registro.',
                'status' => 500,
            ];
        }
    }
}
