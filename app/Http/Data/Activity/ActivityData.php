<?php

namespace App\Http\Data\Activity;

use App\Models\Activity\Activity;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class ActivityData
{
    public const DIRECT_CREATION_DISABLED = 'Toda actividad nueva debe originarse en una solicitud revisada y aprobada. Use el flujo de Solicitudes.';

    public static function getActivities(?array $areaIds = null): array
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
                ->when($areaIds !== null, fn ($query) => $query->whereIn('id_area', $areaIds))
                ->orderBy('id_actividad', 'desc')
                ->get();
            self::addOrigins($activities->all());

            return ['success' => true, 'message' => 'Actividades obtenidas correctamente.', 'data' => $activities];
        } catch (Throwable $e) {
            report($e);

            return ['success' => false, 'message' => 'Error al obtener las actividades.', 'status' => 500];
        }
    }

    public static function getActivityById(int $id, ?array $areaIds = null): array
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
                ->when($areaIds !== null, fn ($query) => $query->whereIn('id_area', $areaIds))
                ->first();

            if (! $activity) {
                return ['success' => false, 'message' => 'Actividad no encontrada.', 'status' => 404];
            }
            self::addOrigins([$activity]);

            return ['success' => true, 'message' => 'Actividad obtenida correctamente.', 'data' => $activity];
        } catch (Throwable $e) {
            report($e);

            return ['success' => false, 'message' => 'Error al obtener la actividad.', 'status' => 500];
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function createActivity(array $data, ?array $areaIds = null): array
    {
        return ['success' => false, 'message' => self::DIRECT_CREATION_DISABLED, 'status' => 403];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function updateActivity(int $id, array $data, ?array $areaIds = null): array
    {
        $transactionLevel = DB::transactionLevel();

        try {
            DB::beginTransaction();

            $activity = DB::table('certificado.actividad')->where('id_actividad', $id)->lockForUpdate()->first();

            if (! $activity) {
                DB::rollBack();

                return ['success' => false, 'message' => 'Actividad no encontrada.', 'status' => 404];
            }
            abort_if($areaIds !== null && ! in_array((int) $activity->id_area, $areaIds, true), 403, 'Actividad fuera del ámbito autorizado.');

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

            $finalData = array_replace((array) $activity, $updateData);
            abort_if($areaIds !== null && ! in_array((int) $finalData['id_area'], $areaIds, true), 403, 'Área no autorizada para la actividad.');
            self::validateBusinessRules($finalData);
            if ((string) $finalData['fecha_inicio'] !== (string) $activity->fecha_inicio || (string) ($finalData['fecha_fin'] ?? '') !== (string) ($activity->fecha_fin ?? '')) {
                $outside = DB::table('certificado.actividad_sesion')->where('id_actividad', $id)
                    ->where(function ($query) use ($finalData) {
                        $query->where('fecha', '<', date('Y-m-d', strtotime($finalData['fecha_inicio'])));
                        if (! empty($finalData['fecha_fin'])) {
                            $query->orWhere('fecha', '>', date('Y-m-d', strtotime($finalData['fecha_fin'])));
                        }
                    })->lockForUpdate()->exists();
                if ($outside) {
                    throw ValidationException::withMessages(['fecha_inicio' => 'El periodo debe incluir todas las sesiones existentes.', 'fecha_fin' => 'El periodo debe incluir todas las sesiones existentes.']);
                }
            }
            $historicalChanges = array_intersect_key($updateData, array_flip(['id_area', 'id_tipo_actividad', 'nombre', 'descripcion', 'fecha_inicio', 'fecha_fin', 'sede']));
            $historicalChanges = array_filter($historicalChanges, fn ($value, $field) => $value !== ($activity->$field ?? null)
                && ($value === null || ($activity->$field ?? null) === null || (string) $value !== (string) $activity->$field), ARRAY_FILTER_USE_BOTH);
            if ($historicalChanges !== [] && DB::table('certificado.solicitud_actividad')->where('id_actividad', $id)->exists()) {
                DB::rollBack();

                return ['success' => false, 'message' => 'Los datos autorizados de la actividad deben conservarse. Se requiere un procedimiento de modificación de la autorización.', 'status' => 409];
            }
            if ($historicalChanges !== [] && DB::table('certificado.participacion as participacion')
                ->join('certificado.certificado as certificado', 'certificado.id_participacion', '=', 'participacion.id_participacion')
                ->where('participacion.id_actividad', $id)->exists()) {
                DB::rollBack();

                return ['success' => false, 'message' => 'La actividad sustenta certificados y sus datos históricos deben conservarse.', 'status' => 409];
            }

            $updateData = array_filter($updateData, function ($value, string $field) use ($activity): bool {
                $previous = $activity->{strtolower($field)} ?? null;
                $currentValue = is_bool($value) ? (int) $value : $value;
                $previous = is_bool($previous) ? (int) $previous : $previous;

                return $currentValue === null ? $previous !== null : ($previous === null || (string) $currentValue !== (string) $previous);
            }, ARRAY_FILTER_USE_BOTH);
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

            self::addOrigins([$updatedActivity]);
            DB::commit();

            return ['success' => true, 'message' => 'Actividad actualizada correctamente.', 'data' => $updatedActivity];
        } catch (Throwable $e) {
            if (DB::transactionLevel() > $transactionLevel) {
                DB::rollBack();
            }

            if ($e instanceof ValidationException) {
                return ['success' => false, 'message' => 'Datos de validación incorrectos.', 'status' => 422, 'errors' => $e->errors()];
            }

            if ($e instanceof HttpException) {
                return ['success' => false, 'message' => $e->getMessage(), 'status' => $e->getStatusCode()];
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
                DB::table('certificado.solicitud_actividad')->where('id_actividad', $id)->exists()
                || DB::table('certificado.participacion')->where('id_actividad', $id)->exists()
                || DB::table('certificado.actividad_sesion')->where('id_actividad', $id)->exists()
                || DB::table('certificado.actividad_ponente')->where('id_actividad', $id)->exists()
                || DB::table('certificado.actividad_firma')->where('id_actividad', $id)->exists()
            ) {
                DB::rollBack();

                return ['success' => false, 'message' => 'No se puede eliminar la actividad porque tiene una solicitud, participaciones o sesiones relacionadas.', 'status' => 409];
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
        if (! in_array($data['estado'] ?? null, ['Activa', 'Cerrada'], true)) {
            $errors['estado'] = ['El estado debe ser Activa o Cerrada.'];
        }

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

    /** @param list<object> $activities */
    private static function addOrigins(array $activities): void
    {
        $models = Activity::hydrate($activities)->load('sourceRequest:id_solicitud_actividad,id_actividad');
        foreach ($activities as $index => $activity) {
            $activity->id_solicitud_actividad = $models[$index]->sourceRequest?->id_solicitud_actividad;
            $activity->origen = $activity->id_solicitud_actividad === null ? 'sin_solicitud' : 'solicitud';
        }
    }
}
