<?php

namespace App\Http\Data\ParticipationType;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

class ParticipationTypeData
{
    public static function getParticipationTypes(): array
    {
        try {
            $participationTypes = DB::table('certificado.tipo_participacion')
                ->select(
                    'id_tipo_participacion',
                    'nombre',
                    'estado',
                )
                ->orderBy('id_tipo_participacion', 'desc')
                ->get();

            return [
                'success' => true,
                'message' => 'Tipos de participación obtenidos correctamente.',
                'data' => $participationTypes,
            ];
        } catch (Throwable $e) {
            report($e);

            return [
                'success' => false,
                'message' => 'Error al obtener los tipos de participación.',
                'status' => 500,
            ];
        }
    }

    public static function getParticipationTypeById(int $id): array
    {
        try {
            $participationType = DB::table('certificado.tipo_participacion')
                ->select(
                    'id_tipo_participacion',
                    'nombre',
                    'estado',
                )
                ->where('id_tipo_participacion', $id)
                ->first();

            if (! $participationType) {
                return [
                    'success' => false,
                    'message' => 'Tipo de participación no encontrado.',
                    'status' => 404,
                ];
            }

            return [
                'success' => true,
                'message' => 'Tipo de participación obtenido correctamente.',
                'data' => $participationType,
            ];
        } catch (Throwable $e) {
            report($e);

            return [
                'success' => false,
                'message' => 'Error al obtener el tipo de participación.',
                'status' => 500,
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function createParticipationType(array $data): array
    {
        $transactionLevel = DB::transactionLevel();

        try {
            DB::beginTransaction();

            $idParticipationType = DB::table('certificado.tipo_participacion')->insertGetId([
                'nombre' => $data['nombre'],
                'estado' => $data['estado'],
            ], 'id_tipo_participacion');

            $participationType = DB::table('certificado.tipo_participacion')
                ->select(
                    'id_tipo_participacion',
                    'nombre',
                    'estado',
                )
                ->where('id_tipo_participacion', $idParticipationType)
                ->first();

            DB::commit();

            return [
                'success' => true,
                'message' => 'Tipo de participación registrado correctamente.',
                'data' => $participationType,
            ];
        } catch (Throwable $e) {
            if (DB::transactionLevel() > $transactionLevel) {
                DB::rollBack();
            }

            report($e);

            return [
                'success' => false,
                'message' => 'Error al registrar el tipo de participación.',
                'status' => 500,
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function updateParticipationType(int $id, array $data): array
    {
        $transactionLevel = DB::transactionLevel();

        try {
            DB::beginTransaction();

            $participationType = DB::table('certificado.tipo_participacion')
                ->select('id_tipo_participacion', 'nombre', 'estado')
                ->where('id_tipo_participacion', $id)
                ->lockForUpdate()
                ->first();

            if (! $participationType) {
                DB::rollBack();

                return [
                    'success' => false,
                    'message' => 'Tipo de participación no encontrado.',
                    'status' => 404,
                ];
            }

            $updateData = array_intersect_key($data, array_flip([
                'nombre',
                'estado',
            ]));

            $updateData = array_filter($updateData, function ($value, string $field) use ($participationType): bool {
                $previous = $participationType->{strtolower($field)} ?? null;
                $currentValue = is_bool($value) ? (int) $value : $value;
                $previous = is_bool($previous) ? (int) $previous : $previous;

                return $currentValue === null ? $previous !== null : ($previous === null || (string) $currentValue !== (string) $previous);
            }, ARRAY_FILTER_USE_BOTH);
            if ($updateData !== []) {
                DB::table('certificado.tipo_participacion')
                    ->where('id_tipo_participacion', $id)
                    ->update($updateData);
            }

            $updatedParticipationType = DB::table('certificado.tipo_participacion')
                ->select(
                    'id_tipo_participacion',
                    'nombre',
                    'estado',
                )
                ->where('id_tipo_participacion', $id)
                ->first();

            DB::commit();

            return [
                'success' => true,
                'message' => 'Tipo de participación actualizado correctamente.',
                'data' => $updatedParticipationType,
            ];
        } catch (Throwable $e) {
            if (DB::transactionLevel() > $transactionLevel) {
                DB::rollBack();
            }

            report($e);

            return [
                'success' => false,
                'message' => 'Error al actualizar el tipo de participación.',
                'status' => 500,
            ];
        }
    }

    public static function deleteParticipationType(int $id): array
    {
        $transactionLevel = DB::transactionLevel();

        try {
            DB::beginTransaction();

            $participationType = DB::table('certificado.tipo_participacion')
                ->select('id_tipo_participacion', 'nombre', 'estado')
                ->where('id_tipo_participacion', $id)
                ->lockForUpdate()
                ->first();

            if (! $participationType) {
                DB::rollBack();

                return [
                    'success' => false,
                    'message' => 'Tipo de participación no encontrado.',
                    'status' => 404,
                ];
            }

            if (DB::table('certificado.participacion')->where('id_tipo_participacion', $id)->exists()) {
                DB::rollBack();

                return [
                    'success' => false,
                    'message' => 'No se puede eliminar el tipo de participación porque tiene participaciones relacionadas.',
                    'status' => 409,
                ];
            }

            DB::table('certificado.tipo_participacion')
                ->where('id_tipo_participacion', $id)
                ->delete();

            DB::commit();

            return [
                'success' => true,
                'message' => 'Tipo de participación eliminado correctamente.',
                'data' => null,
            ];
        } catch (Throwable $e) {
            if (DB::transactionLevel() > $transactionLevel) {
                DB::rollBack();
            }

            if ($e instanceof QueryException && (string) $e->getCode() === '23503') {
                return [
                    'success' => false,
                    'message' => 'No se puede eliminar el tipo de participación porque tiene registros relacionados.',
                    'status' => 409,
                ];
            }

            report($e);

            return [
                'success' => false,
                'message' => 'Error al eliminar el tipo de participación.',
                'status' => 500,
            ];
        }
    }
}
