<?php

namespace App\Http\Data\Activity;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class ActivityData
{
    public static function getActivities(): array
    {
        try {
            $activities = DB::table('certificado.actividad')
                ->select(
                    'id_actividad',
                    'id_area',
                    'id_tipo_actividad',
                    'nombre',
                    'descripcion',
                    'fecha_inicio',
                    'fecha_fin',
                    'sede',
                    'estado',
                )
                ->orderBy('id_actividad', 'desc')
                ->get();

            return ['success' => true, 'message' => 'Actividades obtenidas correctamente.', 'data' => $activities];
        } catch (Throwable $e) {
            report($e);

            return ['success' => false, 'message' => 'Error al obtener las actividades.', 'status' => 500];
        }
    }

    public static function getActivityById(int $id): array
    {
        try {
            $activity = DB::table('certificado.actividad')
                ->select(
                    'id_actividad',
                    'id_area',
                    'id_tipo_actividad',
                    'nombre',
                    'descripcion',
                    'fecha_inicio',
                    'fecha_fin',
                    'sede',
                    'estado',
                )
                ->where('id_actividad', $id)
                ->first();

            if (! $activity) {
                return ['success' => false, 'message' => 'Actividad no encontrada.', 'status' => 404];
            }

            return ['success' => true, 'message' => 'Actividad obtenida correctamente.', 'data' => $activity];
        } catch (Throwable $e) {
            report($e);

            return ['success' => false, 'message' => 'Error al obtener la actividad.', 'status' => 500];
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function createActivity(array $data): array
    {
        $transactionLevel = DB::transactionLevel();

        try {
            DB::beginTransaction();
            self::validateBusinessRules($data);

            $idActivity = DB::table('certificado.actividad')->insertGetId([
                'id_area' => $data['id_area'],
                'id_tipo_actividad' => $data['id_tipo_actividad'],
                'nombre' => $data['nombre'],
                'descripcion' => $data['descripcion'] ?? null,
                'fecha_inicio' => $data['fecha_inicio'],
                'fecha_fin' => $data['fecha_fin'] ?? null,
                'sede' => $data['sede'] ?? null,
                'estado' => $data['estado'],
            ], 'id_actividad');

            $activity = DB::table('certificado.actividad')
                ->select(
                    'id_actividad',
                    'id_area',
                    'id_tipo_actividad',
                    'nombre',
                    'descripcion',
                    'fecha_inicio',
                    'fecha_fin',
                    'sede',
                    'estado',
                )
                ->where('id_actividad', $idActivity)
                ->first();

            DB::commit();

            return ['success' => true, 'message' => 'Actividad registrada correctamente.', 'data' => $activity];
        } catch (Throwable $e) {
            if (DB::transactionLevel() > $transactionLevel) {
                DB::rollBack();
            }

            if ($e instanceof ValidationException) {
                return ['success' => false, 'message' => 'Datos de validación incorrectos.', 'status' => 422, 'errors' => $e->errors()];
            }

            if ($e instanceof QueryException && (
                (string) $e->getCode() === '23503'
                || in_array((int) ($e->errorInfo[1] ?? 0), [1451, 1452, 547, 787], true)
                || ((string) $e->getCode() === '23000' && str_contains(strtolower($e->getPrevious()?->getMessage() ?? ''), 'foreign key'))
            )) {
                return ['success' => false, 'message' => 'La operación no se puede completar porque existen registros relacionados.', 'status' => 409];
            }

            report($e);

            return ['success' => false, 'message' => 'Error al registrar la actividad.', 'status' => 500];
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function updateActivity(int $id, array $data): array
    {
        $transactionLevel = DB::transactionLevel();

        try {
            DB::beginTransaction();

            $activity = DB::table('certificado.actividad')->where('id_actividad', $id)->lockForUpdate()->first();

            if (! $activity) {
                DB::rollBack();

                return ['success' => false, 'message' => 'Actividad no encontrada.', 'status' => 404];
            }

            $updateData = array_intersect_key($data, array_flip([
                'id_area',
                'id_tipo_actividad',
                'nombre',
                'descripcion',
                'fecha_inicio',
                'fecha_fin',
                'sede',
                'estado',
            ]));

            self::validateBusinessRules(array_replace((array) $activity, $updateData));

            if (! empty($updateData)) {
                DB::table('certificado.actividad')->where('id_actividad', $id)->update($updateData);
            }

            $updatedActivity = DB::table('certificado.actividad')
                ->select(
                    'id_actividad',
                    'id_area',
                    'id_tipo_actividad',
                    'nombre',
                    'descripcion',
                    'fecha_inicio',
                    'fecha_fin',
                    'sede',
                    'estado',
                )
                ->where('id_actividad', $id)
                ->first();

            DB::commit();

            return ['success' => true, 'message' => 'Actividad actualizada correctamente.', 'data' => $updatedActivity];
        } catch (Throwable $e) {
            if (DB::transactionLevel() > $transactionLevel) {
                DB::rollBack();
            }

            if ($e instanceof ValidationException) {
                return ['success' => false, 'message' => 'Datos de validación incorrectos.', 'status' => 422, 'errors' => $e->errors()];
            }

            if ($e instanceof QueryException && (
                (string) $e->getCode() === '23503'
                || in_array((int) ($e->errorInfo[1] ?? 0), [1451, 1452, 547, 787], true)
                || ((string) $e->getCode() === '23000' && str_contains(strtolower($e->getPrevious()?->getMessage() ?? ''), 'foreign key'))
            )) {
                return ['success' => false, 'message' => 'La operación no se puede completar porque existen registros relacionados.', 'status' => 409];
            }

            report($e);

            return ['success' => false, 'message' => 'Error al actualizar la actividad.', 'status' => 500];
        }
    }

    public static function deleteActivity(int $id): array
    {
        $transactionLevel = DB::transactionLevel();

        try {
            DB::beginTransaction();

            $activity = DB::table('certificado.actividad')->where('id_actividad', $id)->lockForUpdate()->first();

            if (! $activity) {
                DB::rollBack();

                return ['success' => false, 'message' => 'Actividad no encontrada.', 'status' => 404];
            }

            if (
                DB::table('certificado.participacion')->where('id_actividad', $id)->exists()
                || DB::table('certificado.actividad_sesion')->where('id_actividad', $id)->exists()
            ) {
                DB::rollBack();

                return ['success' => false, 'message' => 'No se puede eliminar la actividad porque tiene participaciones o sesiones relacionadas.', 'status' => 409];
            }

            DB::table('certificado.actividad')->where('id_actividad', $id)->delete();

            DB::commit();

            return ['success' => true, 'message' => 'Actividad eliminada correctamente.', 'data' => null];
        } catch (Throwable $e) {
            if (DB::transactionLevel() > $transactionLevel) {
                DB::rollBack();
            }

            if ($e instanceof QueryException && (
                (string) $e->getCode() === '23503'
                || in_array((int) ($e->errorInfo[1] ?? 0), [1451, 1452, 547, 787], true)
                || ((string) $e->getCode() === '23000' && str_contains(strtolower($e->getPrevious()?->getMessage() ?? ''), 'foreign key'))
            )) {
                return ['success' => false, 'message' => 'La operación no se puede completar porque existen registros relacionados.', 'status' => 409];
            }

            report($e);

            return ['success' => false, 'message' => 'Error al eliminar la actividad.', 'status' => 500];
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function validateBusinessRules(array $data): void
    {
        $errors = [];

        if (! DB::table('gestion.area')->where('id_area', $data['id_area'])->exists()) {
            $errors['id_area'] = ['El área seleccionada no existe.'];
        }

        if (! DB::table('certificado.tipo_actividad')->where('id_tipo_actividad', $data['id_tipo_actividad'])->exists()) {
            $errors['id_tipo_actividad'] = ['El tipo de actividad seleccionado no existe.'];
        }

        if (
            ! empty($data['fecha_inicio'])
            && ! empty($data['fecha_fin'])
            && strtotime($data['fecha_fin']) < strtotime($data['fecha_inicio'])
        ) {
            $errors['fecha_fin'] = ['La fecha de fin no puede ser anterior a la fecha de inicio.'];
        }

        if (! empty($errors)) {
            throw ValidationException::withMessages($errors);
        }
    }
}
