<?php

namespace App\Http\Data\Attendance;

use App\Http\Data\GestionIntegrity;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class AttendanceData
{
    /**
     * Listar todas las asistencias.
     */
    public static function getAttendances(): array
    {
        try {
            $attendances = self::attendanceQuery()
                ->orderBy('asistencia.id_asistencia', 'desc')
                ->get();

            return [
                'success' => true,
                'message' => 'Asistencias obtenidas correctamente.',
                'data' => $attendances,
            ];

        } catch (Throwable $e) {
            report($e);

            return [
                'success' => false,
                'message' => 'Error al obtener las asistencias.',
                'status' => 500,
            ];
        }
    }

    /**
     * Obtener una asistencia por ID.
     */
    public static function getAttendanceById(int $id): array
    {
        try {
            $attendance = self::attendanceQuery()
                ->where('id_asistencia', $id)
                ->first();

            if (! $attendance) {
                return [
                    'success' => false,
                    'message' => 'Asistencia no encontrada.',
                    'status' => 404,
                ];
            }

            return [
                'success' => true,
                'message' => 'Asistencia obtenida correctamente.',
                'data' => $attendance,
            ];

        } catch (Throwable $e) {
            report($e);

            return [
                'success' => false,
                'message' => 'Error al obtener la asistencia.',
                'status' => 500,
            ];
        }
    }

    /**
     * Crear asistencia.
     */
    public static function createAttendance(array $data): array
    {
        DB::beginTransaction();

        try {
            self::validateBusinessRules($data);

            if (DB::table('certificado.certificado')->where('id_participacion', $data['ID_PARTICIPACION'])->lockForUpdate()->exists()) {
                DB::rollBack();

                return ['success' => false, 'message' => 'La participación ya sustenta certificados; sus asistencias deben conservarse.', 'status' => 409];
            }

            self::validateUnique($data);

            $idAttendance = DB::table('certificado.asistencia')
                ->insertGetId([
                    'id_participacion' => $data['ID_PARTICIPACION'],
                    'id_actividad_sesion' => $data['ID_ACTIVIDAD_SESION'],
                    'hora_entrada' => $data['HORA_ENTRADA'] ?? null,
                    'hora_salida' => $data['HORA_SALIDA'] ?? null,
                    'estado' => $data['ESTADO'],
                ], 'id_asistencia');

            $attendance = self::attendanceQuery()
                ->where('id_asistencia', $idAttendance)
                ->first();

            DB::commit();

            return [
                'success' => true,
                'message' => 'Asistencia registrada correctamente.',
                'data' => $attendance,
            ];

        } catch (Throwable $e) {
            DB::rollBack();

            if ($e instanceof ValidationException) {
                return [
                    'success' => false,
                    'message' => 'Datos de validación incorrectos.',
                    'status' => 422,
                    'errors' => $e->errors(),
                ];
            }

            if ($failure = GestionIntegrity::failure($e)) {
                return $failure;
            }

            report($e);

            return [
                'success' => false,
                'message' => 'Error al registrar la asistencia.',
                'status' => 500,
            ];
        }
    }

    /**
     * Actualizar asistencia.
     */
    public static function updateAttendance(int $id, array $data): array
    {
        DB::beginTransaction();

        try {
            $attendance = DB::table('certificado.asistencia')
                ->where('id_asistencia', $id)
                ->lockForUpdate()
                ->first();

            if (! $attendance) {
                DB::rollBack();

                return [
                    'success' => false,
                    'message' => 'Asistencia no encontrada.',
                    'status' => 404,
                ];
            }

            $updateData = array_intersect_key(
                $data,
                array_flip([
                    'ID_PARTICIPACION',
                    'ID_ACTIVIDAD_SESION',
                    'HORA_ENTRADA',
                    'HORA_SALIDA',
                    'ESTADO',
                ])
            );

            $finalData = array_replace(
                array_change_key_case((array) $attendance, CASE_UPPER),
                $updateData
            );

            self::validateBusinessRules($finalData);

            if (DB::table('certificado.certificado')->whereIn('id_participacion', [$attendance->id_participacion, $finalData['ID_PARTICIPACION']])->lockForUpdate()->exists()) {
                DB::rollBack();

                return ['success' => false, 'message' => 'La asistencia sustenta certificados y debe conservarse.', 'status' => 409];
            }

            self::validateUnique($finalData, $id);

            $updateData = array_filter($updateData, function ($value, string $field) use ($attendance): bool {
                $previous = $attendance->{strtolower($field)} ?? null;
                $currentValue = is_bool($value) ? (int) $value : $value;
                $previous = is_bool($previous) ? (int) $previous : $previous;

                return $currentValue === null ? $previous !== null : ($previous === null || (string) $currentValue !== (string) $previous);
            }, ARRAY_FILTER_USE_BOTH);
            if (! empty($updateData)) {
                DB::table('certificado.asistencia')
                    ->where('id_asistencia', $id)
                    ->update(array_change_key_case($updateData, CASE_LOWER));
            }

            $updatedAttendance = self::attendanceQuery()
                ->where('id_asistencia', $id)
                ->first();

            DB::commit();

            return [
                'success' => true,
                'message' => 'Asistencia actualizada correctamente.',
                'data' => $updatedAttendance,
            ];

        } catch (Throwable $e) {
            DB::rollBack();

            if ($e instanceof ValidationException) {
                return [
                    'success' => false,
                    'message' => 'Datos de validación incorrectos.',
                    'status' => 422,
                    'errors' => $e->errors(),
                ];
            }

            if ($failure = GestionIntegrity::failure($e)) {
                return $failure;
            }

            report($e);

            return [
                'success' => false,
                'message' => 'Error al actualizar la asistencia.',
                'status' => 500,
            ];
        }
    }

    /**
     * Eliminar asistencia.
     */
    public static function deleteAttendance(int $id): array
    {
        DB::beginTransaction();

        try {
            $attendance = DB::table('certificado.asistencia')
                ->where('id_asistencia', $id)
                ->lockForUpdate()
                ->first();

            if (! $attendance) {
                DB::rollBack();

                return [
                    'success' => false,
                    'message' => 'Asistencia no encontrada.',
                    'status' => 404,
                ];
            }

            DB::table('certificado.participacion')->where('id_participacion', $attendance->id_participacion)->lockForUpdate()->first();
            if (DB::table('certificado.certificado')->where('id_participacion', $attendance->id_participacion)->lockForUpdate()->exists()) {
                DB::rollBack();

                return ['success' => false, 'message' => 'La asistencia sustenta certificados y debe conservarse.', 'status' => 409];
            }
            DB::table('certificado.asistencia')
                ->where('id_asistencia', $id)
                ->delete();

            DB::commit();

            return [
                'success' => true,
                'message' => 'Asistencia eliminada correctamente.',
                'data' => null,
            ];

        } catch (Throwable $e) {
            DB::rollBack();

            if ($failure = GestionIntegrity::failure($e)) {
                return $failure;
            }

            report($e);

            return [
                'success' => false,
                'message' => 'Error al eliminar la asistencia.',
                'status' => 500,
            ];
        }
    }

    /**
     * Validar las relaciones y las horas de asistencia.
     */
    private static function validateBusinessRules(array $data): void
    {
        $errors = [];

        $participation = DB::table('certificado.participacion')
            ->where('id_participacion', $data['ID_PARTICIPACION'])
            ->lockForUpdate()
            ->first();

        $activitySession = DB::table('certificado.actividad_sesion')
            ->where('id_actividad_sesion', $data['ID_ACTIVIDAD_SESION'])
            ->sharedLock()
            ->first();

        if (! $participation) {
            $errors['ID_PARTICIPACION'] = [
                'La participación seleccionada no existe.',
            ];
        }

        if (! $activitySession) {
            $errors['ID_ACTIVIDAD_SESION'] = [
                'La sesión seleccionada no existe.',
            ];
        }

        /**
         * Verificar que la participación y la sesión
         * pertenezcan a la misma actividad.
         */
        if ($participation && $activitySession) {
            if (
                (string) $participation->id_actividad !==
                (string) $activitySession->id_actividad
            ) {
                $errors['ID_ACTIVIDAD_SESION'] = [
                    'La participación y la sesión deben pertenecer a la misma actividad.',
                ];
            }
        }

        /**
         * Validar las horas de entrada y salida.
         */
        $entry = $data['HORA_ENTRADA'] ?? null;
        $exit = $data['HORA_SALIDA'] ?? null;

        if ($entry !== null && $exit !== null) {
            $entry = strlen($entry) === 5
                ? $entry.':00'
                : $entry;

            $exit = strlen($exit) === 5
                ? $exit.':00'
                : $exit;

            if ($exit < $entry) {
                $errors['HORA_SALIDA'] = [
                    'La hora de salida no puede ser anterior a la hora de entrada.',
                ];
            }
        }

        if (! empty($errors)) {
            throw ValidationException::withMessages($errors);
        }
    }

    private static function validateUnique(array $data, ?int $exceptId = null): void
    {
        if (DB::table('certificado.asistencia')
            ->where('id_participacion', $data['ID_PARTICIPACION'])
            ->where('id_actividad_sesion', $data['ID_ACTIVIDAD_SESION'])
            ->when($exceptId !== null, fn (Builder $query) => $query->where('id_asistencia', '<>', $exceptId))
            ->exists()) {
            throw ValidationException::withMessages(['ID_ACTIVIDAD_SESION' => 'Ya existe una asistencia para esta participación y sesión.']);
        }
    }

    private static function attendanceQuery(): Builder
    {
        return DB::table('certificado.asistencia as asistencia')
            ->leftJoin('certificado.participacion as participacion', 'participacion.id_participacion', '=', 'asistencia.id_participacion')
            ->leftJoin('certificado.actividad_sesion as sesion', 'sesion.id_actividad_sesion', '=', 'asistencia.id_actividad_sesion')
            ->select(
                'asistencia.id_asistencia as ID_ASISTENCIA',
                'asistencia.id_participacion as ID_PARTICIPACION',
                'asistencia.id_actividad_sesion as ID_ACTIVIDAD_SESION',
                'asistencia.hora_entrada as HORA_ENTRADA',
                'asistencia.hora_salida as HORA_SALIDA',
                'asistencia.estado as ESTADO',
                'participacion.id_persona as ID_PERSONA',
                'participacion.id_actividad as ID_ACTIVIDAD_PARTICIPACION',
                'sesion.id_actividad as ID_ACTIVIDAD_SESION_ACTIVIDAD',
            );
    }
}
