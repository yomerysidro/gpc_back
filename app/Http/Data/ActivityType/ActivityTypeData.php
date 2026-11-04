<?php

namespace App\Http\Data\ActivityType;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

class ActivityTypeData
{
    public static function getActivityTypes(?bool $active = null): array
    {
        try {
            $query = DB::table('certificado.tipo_actividad')
                ->select(
                    'id_tipo_actividad',
                    'nombre',
                    'descripcion',
                    'estado',
                )
                ->orderBy('id_tipo_actividad', 'desc');
            if ($active !== null) {
                $query->where('estado', $active);
            }
            $activityTypes = $query->get();

            return [
                'success' => true,
                'message' => 'Tipos de actividad obtenidos correctamente.',
                'data' => $activityTypes,
            ];
        } catch (Throwable $e) {
            report($e);

            return [
                'success' => false,
                'message' => 'Error al obtener los tipos de actividad.',
                'status' => 500,
            ];
        }
    }

    public static function getActivityTypeById(int $id): array
    {
        try {
            $activityType = DB::table('certificado.tipo_actividad')
                ->select(
                    'id_tipo_actividad',
                    'nombre',
                    'descripcion',
                    'estado',
                )
                ->where('id_tipo_actividad', $id)
                ->first();

            if (! $activityType) {
                return [
                    'success' => false,
                    'message' => 'Tipo de actividad no encontrado.',
                    'status' => 404,
                ];
            }

            return [
                'success' => true,
                'message' => 'Tipo de actividad obtenido correctamente.',
                'data' => $activityType,
            ];
        } catch (Throwable $e) {
            report($e);

            return [
                'success' => false,
                'message' => 'Error al obtener el tipo de actividad.',
                'status' => 500,
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function createActivityType(array $data): array
    {
        $transactionLevel = DB::transactionLevel();

        try {
            DB::beginTransaction();

            $idActivityType = DB::table('certificado.tipo_actividad')->insertGetId([
                'nombre' => $data['nombre'],
                'descripcion' => $data['descripcion'] ?? null,
                'estado' => $data['estado'],
            ], 'id_tipo_actividad');

            $activityType = DB::table('certificado.tipo_actividad')
                ->select(
                    'id_tipo_actividad',
                    'nombre',
                    'descripcion',
                    'estado',
                )
                ->where('id_tipo_actividad', $idActivityType)
                ->first();

            DB::commit();

            return [
                'success' => true,
                'message' => 'Tipo de actividad registrado correctamente.',
                'data' => $activityType,
            ];
        } catch (Throwable $e) {
            if (DB::transactionLevel() > $transactionLevel) {
                DB::rollBack();
            }

            report($e);

            return [
                'success' => false,
                'message' => 'Error al registrar el tipo de actividad.',
                'status' => 500,
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function updateActivityType(int $id, array $data): array
    {
        $transactionLevel = DB::transactionLevel();

        try {
            DB::beginTransaction();

            $activityType = DB::table('certificado.tipo_actividad')
                ->select('id_tipo_actividad', 'nombre', 'descripcion', 'estado')
                ->where('id_tipo_actividad', $id)
                ->lockForUpdate()
                ->first();

            if (! $activityType) {
                DB::rollBack();

                return [
                    'success' => false,
                    'message' => 'Tipo de actividad no encontrado.',
                    'status' => 404,
                ];
            }

            $updateData = array_intersect_key($data, array_flip([
                'nombre',
                'descripcion',
                'estado',
            ]));

            $updateData = array_filter($updateData, function ($value, string $field) use ($activityType): bool {
                $previous = $activityType->{strtolower($field)} ?? null;
                $currentValue = is_bool($value) ? (int) $value : $value;
                $previous = is_bool($previous) ? (int) $previous : $previous;

                return $currentValue === null ? $previous !== null : ($previous === null || (string) $currentValue !== (string) $previous);
            }, ARRAY_FILTER_USE_BOTH);
            if (! empty($updateData)) {
                DB::table('certificado.tipo_actividad')
                    ->where('id_tipo_actividad', $id)
                    ->update($updateData);
            }

            $updatedActivityType = DB::table('certificado.tipo_actividad')
                ->select(
                    'id_tipo_actividad',
                    'nombre',
                    'descripcion',
                    'estado',
                )
                ->where('id_tipo_actividad', $id)
                ->first();

            DB::commit();

            return [
                'success' => true,
                'message' => 'Tipo de actividad actualizado correctamente.',
                'data' => $updatedActivityType,
            ];
        } catch (Throwable $e) {
            if (DB::transactionLevel() > $transactionLevel) {
                DB::rollBack();
            }

            report($e);

            return [
                'success' => false,
                'message' => 'Error al actualizar el tipo de actividad.',
                'status' => 500,
            ];
        }
    }

    public static function deleteActivityType(int $id): array
    {
        $transactionLevel = DB::transactionLevel();

        try {
            DB::beginTransaction();

            $activityType = DB::table('certificado.tipo_actividad')
                ->select('id_tipo_actividad', 'nombre', 'descripcion', 'estado')
                ->where('id_tipo_actividad', $id)
                ->lockForUpdate()
                ->first();

            if (! $activityType) {
                DB::rollBack();

                return [
                    'success' => false,
                    'message' => 'Tipo de actividad no encontrado.',
                    'status' => 404,
                ];
            }

            if (DB::table('certificado.actividad')->where('id_tipo_actividad', $id)->exists()) {
                DB::rollBack();

                return [
                    'success' => false,
                    'message' => 'No se puede eliminar el tipo de actividad porque tiene actividades relacionadas.',
                    'status' => 409,
                ];
            }

            DB::table('certificado.tipo_actividad')
                ->where('id_tipo_actividad', $id)
                ->delete();

            DB::commit();

            return [
                'success' => true,
                'message' => 'Tipo de actividad eliminado correctamente.',
                'data' => null,
            ];
        } catch (Throwable $e) {
            if (DB::transactionLevel() > $transactionLevel) {
                DB::rollBack();
            }

            if ($e instanceof QueryException && (string) $e->getCode() === '23503') {
                return [
                    'success' => false,
                    'message' => 'No se puede eliminar el tipo de actividad porque tiene registros relacionados.',
                    'status' => 409,
                ];
            }

            report($e);

            return [
                'success' => false,
                'message' => 'Error al eliminar el tipo de actividad.',
                'status' => 500,
            ];
        }
    }
}
