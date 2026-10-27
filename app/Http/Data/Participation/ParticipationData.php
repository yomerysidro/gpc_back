<?php

namespace App\Http\Data\Participation;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class ParticipationData
{
    public static function getParticipations(): array
    {
        try {
            $participations = DB::table('certificado.participacion')
                ->select('id_participacion', 'id_persona', 'id_actividad', 'id_tipo_participacion', 'fecha_inscripcion', 'estado')
                ->orderBy('id_participacion', 'desc')
                ->get();

            return [
                'success' => true,
                'message' => 'Participaciones obtenidas correctamente.',
                'data' => $participations,
            ];
        } catch (Throwable $e) {
            report($e);

            return ['success' => false, 'message' => 'Error al obtener las participaciones.', 'status' => 500];
        }
    }

    public static function getParticipationById(int $id): array
    {
        try {
            $participation = DB::table('certificado.participacion')
                ->select('id_participacion', 'id_persona', 'id_actividad', 'id_tipo_participacion', 'fecha_inscripcion', 'estado')
                ->where('id_participacion', $id)
                ->first();

            if (! $participation) {
                return ['success' => false, 'message' => 'Participación no encontrada.', 'status' => 404];
            }

            return [
                'success' => true,
                'message' => 'Participación obtenida correctamente.',
                'data' => $participation,
            ];
        } catch (Throwable $e) {
            report($e);

            return ['success' => false, 'message' => 'Error al obtener la participación.', 'status' => 500];
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function createParticipation(array $data): array
    {
        $transactionLevel = DB::transactionLevel();

        try {
            DB::beginTransaction();
            self::validateBusinessRules($data);

            if (DB::table('certificado.participacion')
                ->where('id_actividad', $data['id_actividad'])
                ->where('id_persona', $data['id_persona'])
                ->exists()) {
                DB::rollBack();

                return ['success' => false, 'message' => 'La persona ya está inscrita en esta actividad.', 'status' => 409];
            }

            $idParticipation = DB::table('certificado.participacion')->insertGetId([
                'id_actividad' => $data['id_actividad'],
                'id_persona' => $data['id_persona'],
                'id_tipo_participacion' => $data['id_tipo_participacion'],
                'fecha_inscripcion' => $data['fecha_inscripcion'],
                'estado' => $data['estado'],
            ], 'id_participacion');

            $participation = DB::table('certificado.participacion')
                ->select('id_participacion', 'id_persona', 'id_actividad', 'id_tipo_participacion', 'fecha_inscripcion', 'estado')
                ->where('id_participacion', $idParticipation)
                ->first();

            DB::commit();

            return [
                'success' => true,
                'message' => 'Participación registrada correctamente.',
                'data' => $participation,
            ];
        } catch (Throwable $e) {
            if (DB::transactionLevel() > $transactionLevel) {
                DB::rollBack();
            }

            if ($e instanceof ValidationException) {
                return ['success' => false, 'message' => 'Datos de validación incorrectos.', 'status' => 422, 'errors' => $e->errors()];
            }

            if ($e instanceof QueryException && (string) $e->getCode() === '23505') {
                return ['success' => false, 'message' => 'La persona ya está inscrita en esta actividad.', 'status' => 409];
            }

            if ($e instanceof QueryException && (string) $e->getCode() === '23503') {
                return ['success' => false, 'message' => 'La operación no se puede completar porque existen registros relacionados.', 'status' => 409];
            }

            report($e);

            return ['success' => false, 'message' => 'Error al registrar la participación.', 'status' => 500];
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function updateParticipation(int $id, array $data): array
    {
        $transactionLevel = DB::transactionLevel();

        try {
            DB::beginTransaction();

            $participation = DB::table('certificado.participacion')
                ->select('id_participacion', 'id_persona', 'id_actividad', 'id_tipo_participacion', 'fecha_inscripcion', 'estado')
                ->where('id_participacion', $id)
                ->lockForUpdate()
                ->first();

            if (! $participation) {
                DB::rollBack();

                return ['success' => false, 'message' => 'Participación no encontrada.', 'status' => 404];
            }

            $updateData = array_intersect_key($data, array_flip([
                'id_actividad',
                'id_persona',
                'id_tipo_participacion',
                'fecha_inscripcion',
                'estado',
            ]));

            $finalData = array_replace((array) $participation, $updateData);
            self::validateBusinessRules($finalData);

            $protectedChanged = false;
            foreach (['id_persona', 'id_actividad', 'id_tipo_participacion', 'fecha_inscripcion'] as $field) {
                $protectedChanged = $protectedChanged || (string) $finalData[$field] !== (string) $participation->$field;
            }
            if ($protectedChanged && DB::table('certificado.certificado')->where('id_participacion', $id)->lockForUpdate()->exists()) {
                DB::rollBack();

                return ['success' => false, 'message' => 'La participación sustenta certificados y sus datos de origen deben conservarse.', 'status' => 409];
            }
            if (((string) $finalData['id_persona'] !== (string) $participation->id_persona || (string) $finalData['id_actividad'] !== (string) $participation->id_actividad)
                && DB::table('certificado.asistencia')->where('id_participacion', $id)->lockForUpdate()->exists()) {
                DB::rollBack();

                return ['success' => false, 'message' => 'No se puede reasignar una participación con asistencias registradas.', 'status' => 409];
            }

            if (DB::table('certificado.participacion')
                ->where('id_actividad', $finalData['id_actividad'])
                ->where('id_persona', $finalData['id_persona'])
                ->where('id_participacion', '!=', $id)
                ->exists()) {
                DB::rollBack();

                return ['success' => false, 'message' => 'La persona ya está inscrita en esta actividad.', 'status' => 409];
            }

            $updateData = array_filter($updateData, function ($value, string $field) use ($participation): bool {
                $previous = $participation->{strtolower($field)} ?? null;
                $currentValue = is_bool($value) ? (int) $value : $value;
                $previous = is_bool($previous) ? (int) $previous : $previous;

                return $currentValue === null ? $previous !== null : ($previous === null || (string) $currentValue !== (string) $previous);
            }, ARRAY_FILTER_USE_BOTH);
            if (! empty($updateData)) {
                DB::table('certificado.participacion')
                    ->where('id_participacion', $id)
                    ->update($updateData);
            }

            $updatedParticipation = DB::table('certificado.participacion')
                ->select('id_participacion', 'id_persona', 'id_actividad', 'id_tipo_participacion', 'fecha_inscripcion', 'estado')
                ->where('id_participacion', $id)
                ->first();

            DB::commit();

            return [
                'success' => true,
                'message' => 'Participación actualizada correctamente.',
                'data' => $updatedParticipation,
            ];
        } catch (Throwable $e) {
            if (DB::transactionLevel() > $transactionLevel) {
                DB::rollBack();
            }

            if ($e instanceof ValidationException) {
                return ['success' => false, 'message' => 'Datos de validación incorrectos.', 'status' => 422, 'errors' => $e->errors()];
            }

            if ($e instanceof QueryException && (string) $e->getCode() === '23505') {
                return ['success' => false, 'message' => 'La persona ya está inscrita en esta actividad.', 'status' => 409];
            }

            if ($e instanceof QueryException && (string) $e->getCode() === '23503') {
                return ['success' => false, 'message' => 'La operación no se puede completar porque existen registros relacionados.', 'status' => 409];
            }

            report($e);

            return ['success' => false, 'message' => 'Error al actualizar la participación.', 'status' => 500];
        }
    }

    public static function deleteParticipation(int $id): array
    {
        $transactionLevel = DB::transactionLevel();

        try {
            DB::beginTransaction();

            $participation = DB::table('certificado.participacion')
                ->select('id_participacion', 'id_persona', 'id_actividad', 'id_tipo_participacion', 'fecha_inscripcion', 'estado')
                ->where('id_participacion', $id)
                ->lockForUpdate()
                ->first();

            if (! $participation) {
                DB::rollBack();

                return ['success' => false, 'message' => 'Participación no encontrada.', 'status' => 404];
            }

            if (
                DB::table('certificado.asistencia')->where('id_participacion', $id)->exists()
                || DB::table('certificado.certificado')->where('id_participacion', $id)->exists()
            ) {
                DB::rollBack();

                return ['success' => false, 'message' => 'No se puede eliminar la participación porque tiene asistencias o certificados relacionados.', 'status' => 409];
            }
            DB::table('certificado.participacion')
                ->where('id_participacion', $id)
                ->delete();

            DB::commit();

            return [
                'success' => true,
                'message' => 'Participación eliminada correctamente.',
                'data' => null,
            ];
        } catch (Throwable $e) {
            if (DB::transactionLevel() > $transactionLevel) {
                DB::rollBack();
            }

            if ($e instanceof ValidationException) {
                return ['success' => false, 'message' => 'Datos de validación incorrectos.', 'status' => 422, 'errors' => $e->errors()];
            }

            if ($e instanceof QueryException && (string) $e->getCode() === '23505') {
                return ['success' => false, 'message' => 'La persona ya está inscrita en esta actividad.', 'status' => 409];
            }

            if ($e instanceof QueryException && (string) $e->getCode() === '23503') {
                return ['success' => false, 'message' => 'La operación no se puede completar porque existen registros relacionados.', 'status' => 409];
            }

            report($e);

            return ['success' => false, 'message' => 'Error al eliminar la participación.', 'status' => 500];
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function validateBusinessRules(array $data): void
    {
        $errors = [];

        if (! DB::table('certificado.actividad')->where('id_actividad', $data['id_actividad'])->exists()) {
            $errors['id_actividad'] = ['La actividad seleccionada no existe.'];
        }

        if (! DB::table('gestion.persona')->where('id_persona', $data['id_persona'])->exists()) {
            $errors['id_persona'] = ['La persona seleccionada no existe.'];
        }

        if (! DB::table('certificado.tipo_participacion')->where('id_tipo_participacion', $data['id_tipo_participacion'])->exists()) {
            $errors['id_tipo_participacion'] = ['El tipo de participación seleccionado no existe.'];
        }

        if (! empty($errors)) {
            throw ValidationException::withMessages($errors);
        }
    }
}
