<?php

namespace App\Http\Data\ActivitySession;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class ActivitySessionData
{
    public static function getActivitySessions(): array
    {
        try {
            $activitySessions = DB::table('certificado.actividad_sesion')
                ->select(
                    'id_actividad_sesion',
                    'id_actividad',
                    'nombre',
                    'fecha',
                    'hora_inicio',
                    'hora_fin',
                    'lugar',
                    'estado',
                )
                ->orderBy('id_actividad_sesion', 'desc')
                ->get();

            return ['success' => true, 'message' => 'Sesiones de actividad obtenidas correctamente.', 'data' => $activitySessions];
        } catch (Throwable $e) {
            report($e);

            return ['success' => false, 'message' => 'Error al obtener las sesiones de actividad.', 'status' => 500];
        }
    }

    public static function getActivitySessionById(int $id): array
    {
        try {
            $activitySession = DB::table('certificado.actividad_sesion')
                ->select(
                    'id_actividad_sesion',
                    'id_actividad',
                    'nombre',
                    'fecha',
                    'hora_inicio',
                    'hora_fin',
                    'lugar',
                    'estado',
                )
                ->where('id_actividad_sesion', $id)
                ->first();

            if (! $activitySession) {
                return ['success' => false, 'message' => 'Sesión de actividad no encontrada.', 'status' => 404];
            }

            return ['success' => true, 'message' => 'Sesión de actividad obtenida correctamente.', 'data' => $activitySession];
        } catch (Throwable $e) {
            report($e);

            return ['success' => false, 'message' => 'Error al obtener la sesión de actividad.', 'status' => 500];
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function createActivitySession(array $data): array
    {
        $transactionLevel = DB::transactionLevel();

        try {
            DB::beginTransaction();
            self::validateBusinessRules($data);

            $idActivitySession = DB::table('certificado.actividad_sesion')->insertGetId([
                'id_actividad' => $data['id_actividad'],
                'nombre' => $data['nombre'],
                'fecha' => $data['fecha'],
                'hora_inicio' => $data['hora_inicio'],
                'hora_fin' => $data['hora_fin'],
                'lugar' => $data['lugar'] ?? null,
                'estado' => $data['estado'],
            ], 'id_actividad_sesion');

            $activitySession = DB::table('certificado.actividad_sesion')
                ->select(
                    'id_actividad_sesion',
                    'id_actividad',
                    'nombre',
                    'fecha',
                    'hora_inicio',
                    'hora_fin',
                    'lugar',
                    'estado',
                )
                ->where('id_actividad_sesion', $idActivitySession)
                ->first();

            DB::commit();

            return ['success' => true, 'message' => 'Sesión de actividad registrada correctamente.', 'data' => $activitySession];
        } catch (Throwable $e) {
            if (DB::transactionLevel() > $transactionLevel) {
                DB::rollBack();
            }

            if ($e instanceof ValidationException) {
                return ['success' => false, 'message' => 'Datos de validación incorrectos.', 'status' => 422, 'errors' => $e->errors()];
            }

            if ($e instanceof QueryException && (string) $e->getCode() === '23503') {
                return ['success' => false, 'message' => 'La operación no se puede completar porque existen registros relacionados.', 'status' => 409];
            }

            report($e);

            return ['success' => false, 'message' => 'Error al registrar la sesión de actividad.', 'status' => 500];
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function updateActivitySession(int $id, array $data): array
    {
        $transactionLevel = DB::transactionLevel();

        try {
            DB::beginTransaction();

            $activitySession = DB::table('certificado.actividad_sesion')
                ->where('id_actividad_sesion', $id)
                ->lockForUpdate()
                ->first();

            if (! $activitySession) {
                DB::rollBack();

                return ['success' => false, 'message' => 'Sesión de actividad no encontrada.', 'status' => 404];
            }

            $updateData = array_intersect_key($data, array_flip([
                'id_actividad',
                'nombre',
                'fecha',
                'hora_inicio',
                'hora_fin',
                'lugar',
                'estado',
            ]));

            self::validateBusinessRules(array_replace((array) $activitySession, $updateData));

            if (! empty($updateData)) {
                DB::table('certificado.actividad_sesion')
                    ->where('id_actividad_sesion', $id)
                    ->update($updateData);
            }

            $updatedActivitySession = DB::table('certificado.actividad_sesion')
                ->select(
                    'id_actividad_sesion',
                    'id_actividad',
                    'nombre',
                    'fecha',
                    'hora_inicio',
                    'hora_fin',
                    'lugar',
                    'estado',
                )
                ->where('id_actividad_sesion', $id)
                ->first();

            DB::commit();

            return ['success' => true, 'message' => 'Sesión de actividad actualizada correctamente.', 'data' => $updatedActivitySession];
        } catch (Throwable $e) {
            if (DB::transactionLevel() > $transactionLevel) {
                DB::rollBack();
            }

            if ($e instanceof ValidationException) {
                return ['success' => false, 'message' => 'Datos de validación incorrectos.', 'status' => 422, 'errors' => $e->errors()];
            }

            if ($e instanceof QueryException && (string) $e->getCode() === '23503') {
                return ['success' => false, 'message' => 'La operación no se puede completar porque existen registros relacionados.', 'status' => 409];
            }

            report($e);

            return ['success' => false, 'message' => 'Error al actualizar la sesión de actividad.', 'status' => 500];
        }
    }

    public static function deleteActivitySession(int $id): array
    {
        $transactionLevel = DB::transactionLevel();

        try {
            DB::beginTransaction();

            $activitySession = DB::table('certificado.actividad_sesion')
                ->where('id_actividad_sesion', $id)
                ->lockForUpdate()
                ->first();

            if (! $activitySession) {
                DB::rollBack();

                return ['success' => false, 'message' => 'Sesión de actividad no encontrada.', 'status' => 404];
            }

            if (DB::table('certificado.asistencia')->where('id_actividad_sesion', $id)->exists()) {
                DB::rollBack();

                return ['success' => false, 'message' => 'No se puede eliminar la sesión porque tiene asistencias relacionadas.', 'status' => 409];
            }

            DB::table('certificado.actividad_sesion')->where('id_actividad_sesion', $id)->delete();

            DB::commit();

            return ['success' => true, 'message' => 'Sesión de actividad eliminada correctamente.', 'data' => null];
        } catch (Throwable $e) {
            if (DB::transactionLevel() > $transactionLevel) {
                DB::rollBack();
            }

            if ($e instanceof QueryException && (string) $e->getCode() === '23503') {
                return ['success' => false, 'message' => 'La operación no se puede completar porque existen registros relacionados.', 'status' => 409];
            }

            report($e);

            return ['success' => false, 'message' => 'Error al eliminar la sesión de actividad.', 'status' => 500];
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function validateBusinessRules(array $data): void
    {
        $errors = [];

        $activity = DB::table('certificado.actividad')
            ->select('id_actividad', 'fecha_inicio', 'fecha_fin')
            ->where('id_actividad', $data['id_actividad'])
            ->sharedLock()
            ->first();

        if (! $activity) {
            $errors['id_actividad'] = ['La actividad seleccionada no existe.'];
        }

        if ($activity) {
            $sessionDate = date('Y-m-d', strtotime($data['fecha']));

            if (
                (! empty($activity->fecha_inicio) && $sessionDate < $activity->fecha_inicio)
                || (! empty($activity->fecha_fin) && $sessionDate > $activity->fecha_fin)
            ) {
                $errors['fecha'] = ['La fecha de la sesión debe estar dentro del rango de fechas de la actividad.'];
            }
        }

        $start = strlen($data['hora_inicio']) === 5 ? $data['hora_inicio'].':00' : $data['hora_inicio'];
        $end = strlen($data['hora_fin']) === 5 ? $data['hora_fin'].':00' : $data['hora_fin'];

        if ($end < $start) {
            $errors['hora_fin'] = ['La hora de fin no puede ser anterior a la hora de inicio.'];
        }

        if (! empty($errors)) {
            throw ValidationException::withMessages($errors);
        }
    }
}
