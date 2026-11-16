<?php

namespace App\Http\Data\ActivitySpeaker;

use App\Services\CertificateDocumentVersions;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class ActivitySpeakerData
{
    public static function getActivitySpeakers(?array $areaIds = null): array
    {
        try {
            $activitySpeakers = DB::table('certificado.actividad_ponente')
                ->select('*')
                ->when($areaIds !== null, fn ($query) => $query->whereIn('id_actividad', DB::table('certificado.actividad')->select('id_actividad')->whereIn('id_area', $areaIds)))
                ->orderBy('id_actividad_ponente', 'desc')
                ->get();

            return [
                'success' => true,
                'message' => 'Ponentes de actividades obtenidos correctamente.',
                'data' => $activitySpeakers,
            ];
        } catch (Throwable $e) {
            report($e);

            return ['success' => false, 'message' => 'Error al obtener los ponentes de actividades.', 'status' => 500];
        }
    }

    public static function getActivitySpeakerById(int $id): array
    {
        try {
            $activitySpeaker = DB::table('certificado.actividad_ponente')
                ->select('*')
                ->where('id_actividad_ponente', $id)
                ->first();

            if (! $activitySpeaker) {
                return ['success' => false, 'message' => 'Ponente de actividad no encontrado.', 'status' => 404];
            }

            return [
                'success' => true,
                'message' => 'Ponente de actividad obtenido correctamente.',
                'data' => $activitySpeaker,
            ];
        } catch (Throwable $e) {
            report($e);

            return ['success' => false, 'message' => 'Error al obtener el ponente de actividad.', 'status' => 500];
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function createActivitySpeaker(array $data): array
    {
        $transactionLevel = DB::transactionLevel();

        try {
            DB::beginTransaction();
            self::validateBusinessRules($data);

            if (DB::table('certificado.actividad_ponente')
                ->where('id_actividad', $data['id_actividad'])
                ->where('id_persona_ponente', $data['id_persona_ponente'])
                ->when(Schema::hasColumn('certificado.actividad_ponente', 'id_actividad_sesion'), fn ($query) => $query->whereNull('id_actividad_sesion'))
                ->exists()) {
                DB::rollBack();

                return ['success' => false, 'message' => 'El ponente ya está asignado a esta actividad.', 'status' => 409];
            }

            $idActivitySpeaker = DB::table('certificado.actividad_ponente')->insertGetId([
                'id_actividad' => $data['id_actividad'],
                'id_persona_ponente' => $data['id_persona_ponente'],
            ], 'id_actividad_ponente');

            $activitySpeaker = DB::table('certificado.actividad_ponente')
                ->select('*')
                ->where('id_actividad_ponente', $idActivitySpeaker)
                ->first();

            DB::commit();

            return [
                'success' => true,
                'message' => 'Ponente asignado a la actividad correctamente.',
                'data' => $activitySpeaker,
            ];
        } catch (Throwable $e) {
            if (DB::transactionLevel() > $transactionLevel) {
                DB::rollBack();
            }

            if ($e instanceof ValidationException) {
                return ['success' => false, 'message' => 'Datos de validación incorrectos.', 'status' => 422, 'errors' => $e->errors()];
            }

            if ($e instanceof QueryException && (string) $e->getCode() === '23505') {
                return ['success' => false, 'message' => 'El ponente ya está asignado a esta actividad.', 'status' => 409];
            }

            if ($e instanceof QueryException && in_array((string) $e->getCode(), ['23503', '23514', '23502'], true)) {
                return ['success' => false, 'message' => 'La operación no se puede completar porque existen registros relacionados.', 'status' => 409];
            }

            report($e);

            return ['success' => false, 'message' => 'Error al asignar el ponente a la actividad.', 'status' => 500];
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function updateActivitySpeaker(int $id, array $data): array
    {
        $transactionLevel = DB::transactionLevel();

        try {
            DB::beginTransaction();

            $activitySpeaker = DB::table('certificado.actividad_ponente')
                ->select('*')
                ->where('id_actividad_ponente', $id)
                ->lockForUpdate()
                ->first();

            if (! $activitySpeaker) {
                DB::rollBack();

                return ['success' => false, 'message' => 'Ponente de actividad no encontrado.', 'status' => 404];
            }

            if (($activitySpeaker->id_actividad_sesion ?? null) !== null || self::supportsDocument($activitySpeaker)) {
                DB::rollBack();

                return ['success' => false, 'message' => 'El vínculo de sesión o documental debe conservarse.', 'status' => 409];
            }
            $updateData = array_intersect_key($data, array_flip([
                'id_actividad',
                'id_persona_ponente',
            ]));

            $finalData = array_replace((array) $activitySpeaker, $updateData);
            self::validateBusinessRules($finalData);

            if (DB::table('certificado.actividad_ponente')
                ->where('id_actividad', $finalData['id_actividad'])
                ->where('id_persona_ponente', $finalData['id_persona_ponente'])
                ->when(Schema::hasColumn('certificado.actividad_ponente', 'id_actividad_sesion'), fn ($query) => $query->whereNull('id_actividad_sesion'))
                ->where('id_actividad_ponente', '!=', $id)
                ->exists()) {
                DB::rollBack();

                return ['success' => false, 'message' => 'El ponente ya está asignado a esta actividad.', 'status' => 409];
            }

            $updateData = array_filter($updateData, function ($value, string $field) use ($activitySpeaker): bool {
                $previous = $activitySpeaker->{strtolower($field)} ?? null;
                $currentValue = is_bool($value) ? (int) $value : $value;
                $previous = is_bool($previous) ? (int) $previous : $previous;

                return $currentValue === null ? $previous !== null : ($previous === null || (string) $currentValue !== (string) $previous);
            }, ARRAY_FILTER_USE_BOTH);
            if (! empty($updateData)) {
                DB::table('certificado.actividad_ponente')
                    ->where('id_actividad_ponente', $id)
                    ->update($updateData);
            }

            $updatedActivitySpeaker = DB::table('certificado.actividad_ponente')
                ->select('*')
                ->where('id_actividad_ponente', $id)
                ->first();

            DB::commit();

            return [
                'success' => true,
                'message' => 'Ponente de actividad actualizado correctamente.',
                'data' => $updatedActivitySpeaker,
            ];
        } catch (Throwable $e) {
            if (DB::transactionLevel() > $transactionLevel) {
                DB::rollBack();
            }

            if ($e instanceof ValidationException) {
                return ['success' => false, 'message' => 'Datos de validación incorrectos.', 'status' => 422, 'errors' => $e->errors()];
            }

            if ($e instanceof QueryException && (string) $e->getCode() === '23505') {
                return ['success' => false, 'message' => 'El ponente ya está asignado a esta actividad.', 'status' => 409];
            }

            if ($e instanceof QueryException && in_array((string) $e->getCode(), ['23503', '23514', '23502'], true)) {
                return ['success' => false, 'message' => 'La operación no se puede completar porque existen registros relacionados.', 'status' => 409];
            }

            report($e);

            return ['success' => false, 'message' => 'Error al actualizar el ponente de actividad.', 'status' => 500];
        }
    }

    public static function deleteActivitySpeaker(int $id): array
    {
        $transactionLevel = DB::transactionLevel();

        try {
            DB::beginTransaction();

            $activitySpeaker = DB::table('certificado.actividad_ponente')
                ->select('*')
                ->where('id_actividad_ponente', $id)
                ->lockForUpdate()
                ->first();

            if (! $activitySpeaker) {
                DB::rollBack();

                return ['success' => false, 'message' => 'Ponente de actividad no encontrado.', 'status' => 404];
            }

            if (($activitySpeaker->id_actividad_sesion ?? null) !== null || self::supportsDocument($activitySpeaker)) {
                DB::rollBack();

                return ['success' => false, 'message' => 'El vínculo de sesión o documental debe conservarse.', 'status' => 409];
            }
            DB::table('certificado.actividad_ponente')->where('id_actividad_ponente', $id)->delete();

            DB::commit();

            return [
                'success' => true,
                'message' => 'Ponente desvinculado de la actividad correctamente.',
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
                return ['success' => false, 'message' => 'El ponente ya está asignado a esta actividad.', 'status' => 409];
            }

            if ($e instanceof QueryException && in_array((string) $e->getCode(), ['23503', '23514', '23502'], true)) {
                return ['success' => false, 'message' => 'La operación no se puede completar porque existen registros relacionados.', 'status' => 409];
            }

            report($e);

            return ['success' => false, 'message' => 'Error al desvincular el ponente de la actividad.', 'status' => 500];
        }
    }

    private static function supportsDocument(object $assignment): bool
    {
        if (! Schema::hasTable('certificado.certificado_documento')) {
            return false;
        }
        $documents = DB::table('certificado.certificado_documento as d')
            ->join('certificado.certificado as c', 'c.id_certificado', '=', 'd.id_certificado')
            ->where('c.id_persona', DB::table('gestion.persona_ponente')->where('id_persona_ponente', $assignment->id_persona_ponente)->value('id_persona'))
            ->whereNotNull('c.id_reconocimiento')->get(['d.*']);
        foreach ($documents as $document) {
            try {
                $context = app(CertificateDocumentVersions::class)->approvedContent($document)['context'];
                if ((int) ($context['id_actividad_ponente'] ?? 0) === (int) $assignment->id_actividad_ponente) {
                    return true;
                }
            } catch (HttpException) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $data */
    private static function validateBusinessRules(array $data): void
    {
        $errors = [];

        if (! DB::table('certificado.actividad')->where('id_actividad', $data['id_actividad'])->exists()) {
            $errors['id_actividad'] = ['La actividad seleccionada no existe.'];
        }

        if (! DB::table('gestion.persona_ponente')->where('id_persona_ponente', $data['id_persona_ponente'])->exists()) {
            $errors['id_persona_ponente'] = ['El ponente seleccionado no existe.'];
        }

        if (! empty($errors)) {
            throw ValidationException::withMessages($errors);
        }
    }
}
