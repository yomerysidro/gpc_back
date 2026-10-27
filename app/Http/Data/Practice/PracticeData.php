<?php

namespace App\Http\Data\Practice;

use Illuminate\Support\Facades\DB;
use Throwable;

class PracticeData
{
    /**
     * Listar todas las prácticas.
     */
    public static function getPractices(): array
    {
        try {
            $practicas = DB::table('gestion.practica')
                ->select(
                    'id_practica',
                    'id_persona_practicante',
                    'id_institucion',
                    'id_area',
                    'carrera',
                    'fecha_inicio',
                    'fecha_fin',
                    'estado'
                )
                ->orderBy('id_practica', 'desc')
                ->get();

            return [
                'success' => true,
                'message' => 'Prácticas obtenidas correctamente.',
                'data' => $practicas,
            ];
        } catch (Throwable $e) {
            report($e);

            return [
                'success' => false,
                'message' => 'Error al obtener las prácticas.',
                'status' => 500,
            ];
        }
    }

    /**
     * Obtener una práctica por ID.
     */
    public static function getPracticeById(int $id): array
    {
        try {
            $practica = DB::table('gestion.practica')
                ->where('id_practica', $id)
                ->first();

            if (! $practica) {
                return [
                    'success' => false,
                    'message' => 'Práctica no encontrada.',
                    'status' => 404,
                ];
            }

            return [
                'success' => true,
                'message' => 'Práctica obtenida correctamente.',
                'data' => $practica,
            ];
        } catch (Throwable $e) {
            report($e);

            return [
                'success' => false,
                'message' => 'Error al obtener la práctica.',
                'status' => 500,
            ];
        }
    }

    /**
     * Registrar una práctica.
     */
    public static function createPractice(array $data): array
    {
        try {
            $id = DB::table('gestion.practica')
                ->insertGetId($data, 'id_practica');

            $practica = DB::table('gestion.practica')
                ->where('id_practica', $id)
                ->first();

            return [
                'success' => true,
                'message' => 'Práctica registrada correctamente.',
                'data' => $practica,
            ];
        } catch (Throwable $e) {
            report($e);

            return [
                'success' => false,
                'message' => 'Error al registrar la práctica.',
                'status' => 500,
            ];
        }
    }

    /**
     * Actualizar una práctica.
     */
    public static function updatePractice(int $id, array $data): array
    {
        try {
            $practica = DB::table('gestion.practica')
                ->where('id_practica', $id)
                ->first();

            if (! $practica) {
                return [
                    'success' => false,
                    'message' => 'Práctica no encontrada.',
                    'status' => 404,
                ];
            }

            DB::table('gestion.practica')
                ->where('id_practica', $id)
                ->update($data);

            $practicaActualizada = DB::table('gestion.practica')
                ->where('id_practica', $id)
                ->first();

            return [
                'success' => true,
                'message' => 'Práctica actualizada correctamente.',
                'data' => $practicaActualizada,
            ];
        } catch (Throwable $e) {
            report($e);

            return [
                'success' => false,
                'message' => 'Error al actualizar la práctica.',
                'status' => 500,
            ];
        }
    }

    /**
     * Eliminar una práctica.
     */
    public static function deletePractice(int $id): array
    {
        try {
            $eliminados = DB::table('gestion.practica')
                ->where('id_practica', $id)
                ->delete();

            if ($eliminados === 0) {
                return [
                    'success' => false,
                    'message' => 'Práctica no encontrada.',
                    'status' => 404,
                ];
            }

            return [
                'success' => true,
                'message' => 'Práctica eliminada correctamente.',
                'data' => null,
            ];
        } catch (Throwable $e) {
            report($e);

            return [
                'success' => false,
                'message' => 'Error al eliminar la práctica.',
                'status' => 500,
            ];
        }
    }
}
