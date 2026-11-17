<?php

namespace App\Http\Data\Practicing_Individual;

use App\Http\Data\GestionIntegrity;
use Illuminate\Support\Facades\DB;
use Throwable;

class Practicing_IndividualData
{
    /**
     * Listar personas practicantes.
     */
    public static function getPracticingIndividuals(): array
    {
        try {
            $practicantes = DB::table('gestion.persona_practicante')
                ->select(
                    'id_persona_practicante',
                    'id_persona',
                    'codigo',
                    'estado'
                )
                ->orderBy('id_persona_practicante', 'desc')
                ->get();

            return [
                'success' => true,
                'message' => 'Personas practicantes obtenidas correctamente.',
                'data' => $practicantes,
            ];
        } catch (Throwable $e) {
            report($e);

            return [
                'success' => false,
                'message' => 'Error al obtener las personas practicantes.',
                'status' => 500,
            ];
        }
    }

    /**
     * Obtener una persona practicante por ID.
     */
    public static function getPracticingIndividualById(int $id): array
    {
        try {
            $practicante = DB::table('gestion.persona_practicante')
                ->where('id_persona_practicante', $id)
                ->first();

            if (! $practicante) {
                return [
                    'success' => false,
                    'message' => 'Persona practicante no encontrada.',
                    'status' => 404,
                ];
            }

            return [
                'success' => true,
                'message' => 'Persona practicante obtenida correctamente.',
                'data' => $practicante,
            ];
        } catch (Throwable $e) {
            report($e);

            return [
                'success' => false,
                'message' => 'Error al obtener la persona practicante.',
                'status' => 500,
            ];
        }
    }

    /**
     * Registrar una persona practicante.
     */
    public static function createPracticingIndividual(array $data): array
    {
        try {
            $id = DB::table('gestion.persona_practicante')
                ->insertGetId($data, 'id_persona_practicante');

            $practicante = DB::table('gestion.persona_practicante')
                ->where('id_persona_practicante', $id)
                ->first();

            return [
                'success' => true,
                'message' => 'Persona practicante registrada correctamente.',
                'data' => $practicante,
            ];
        } catch (Throwable $e) {
            if ($failure = GestionIntegrity::failure($e)) {
                return $failure;
            }

            report($e);

            return [
                'success' => false,
                'message' => 'Error al registrar la persona practicante.',
                'status' => 500,
            ];
        }
    }

    /**
     * Actualizar una persona practicante.
     */
    public static function updatePracticingIndividual(
        int $id,
        array $data
    ): array {
        try {
            return DB::transaction(function () use ($id, $data): array {
                GestionIntegrity::assertOwnership('gestion.persona_practicante', $id, $data);
                $practicante = DB::table('gestion.persona_practicante')
                    ->where('id_persona_practicante', $id)
                    ->first();

                if (! $practicante) {
                    return [
                        'success' => false,
                        'message' => 'Persona practicante no encontrada.',
                        'status' => 404,
                    ];
                }

                $changes = GestionIntegrity::changes('gestion.persona_practicante', $data, $practicante);
                if ($changes !== []) {
                    DB::table('gestion.persona_practicante')
                        ->where('id_persona_practicante', $id)
                        ->update($changes);
                }

                $practicanteActualizado = DB::table('gestion.persona_practicante')
                    ->where('id_persona_practicante', $id)
                    ->first();

                return [
                    'success' => true,
                    'message' => 'Persona practicante actualizada correctamente.',
                    'data' => $practicanteActualizado,
                ];
            });
        } catch (Throwable $e) {
            if ($failure = GestionIntegrity::failure($e)) {
                return $failure;
            }

            report($e);

            return [
                'success' => false,
                'message' => 'Error al actualizar la persona practicante.',
                'status' => 500,
            ];
        }
    }

    /**
     * Eliminar una persona practicante.
     */
    public static function deletePracticingIndividual(int $id): array
    {
        try {
            return DB::transaction(function () use ($id): array {
                GestionIntegrity::assertCanDelete('gestion.persona_practicante', $id);
                $eliminados = DB::table('gestion.persona_practicante')
                    ->where('id_persona_practicante', $id)
                    ->delete();

                if ($eliminados === 0) {
                    return [
                        'success' => false,
                        'message' => 'Persona practicante no encontrada.',
                        'status' => 404,
                    ];
                }

                return [
                    'success' => true,
                    'message' => 'Persona practicante eliminada correctamente.',
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
                'message' => 'Error al eliminar la persona practicante.',
                'status' => 500,
            ];
        }
    }
}
