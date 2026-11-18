<?php

namespace App\Http\Controllers\ActivitySession;

use App\Http\Controllers\Controller;
use App\Http\Data\ActivitySession\ActivitySessionData;
use App\Models\ActivitySession\ActivitySession;
use App\Services\CertificationReadiness;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

class ActivitySessionController extends Controller
{
    use ApiResponse;

    public function list(Request $request): JsonResponse
    {
        $response = ActivitySessionData::getActivitySessions($request->attributes->get('activity_module_areas'));

        if (! $response['success']) {
            return $this->error($response['message'], $response['status']);
        }

        $blockers = app(CertificationReadiness::class)->closureBlockersForSessions($response['data']->all());
        foreach ($response['data'] as $session) {
            $this->addClosureCapabilities($session, $blockers[$session->id_actividad_sesion]);
        }

        return $this->ok($response['data'], $response['message']);
    }

    public function detail(int $idActivitySession): JsonResponse
    {
        $response = ActivitySessionData::getActivitySessionById($idActivitySession);

        if (! $response['success']) {
            return $this->error($response['message'], $response['status']);
        }

        $session = $response['data'];
        $this->addClosureCapabilities($session);

        return $this->ok($session, $response['message']);
    }

    /** @param list<string>|null $blockers */
    private function addClosureCapabilities(object $session, ?array $blockers = null): void
    {
        $readiness = app(CertificationReadiness::class);
        $blockers ??= $readiness->closureBlockers($session);
        $model = (new ActivitySession)->newFromBuilder((array) $session);
        $session->finalizada = $session->estado === CertificationReadiness::FINAL_SESSION_STATE;
        if (! $session->finalizada && Gate::denies('update', $model)) {
            $blockers[] = 'No dispone del permiso efectivo para finalizar esta sesión.';
        }
        $session->puede_finalizarse = ! $session->finalizada && $blockers === [];
        $session->motivos_bloqueo_finalizacion = $blockers;
        $session->puede_certificarse = false;
        $session->motivos_bloqueo_certificacion = [CertificationReadiness::OFFICIAL_ISSUANCE_BLOCKER];
        if ($readiness->usesDevelopmentPolicy()) {
            $session->motivos_bloqueo_certificacion[] = 'La preparación de desarrollo no habilita emisión oficial ni firma digital.';
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'id_actividad' => ['bail', 'required', 'integer', Rule::exists(config('database.default').'.certificado.actividad', 'id_actividad')],
                'nombre' => ['required', 'string', 'max:150'],
                'fecha' => ['required', 'date'],
                'hora_inicio' => ['required', 'date_format:H:i,H:i:s'],
                'hora_fin' => ['required', 'date_format:H:i,H:i:s'],
                'lugar' => ['nullable', 'string', 'max:200'],
                'estado' => ['required', 'string', 'max:30'],
            ]);

            if ($validator->fails()) {
                return $this->information('Invalid validation data.', 422, $validator->errors());
            }

            $response = ActivitySessionData::createActivitySession($validator->validated());

            if (! $response['success']) {
                if ($response['status'] === 422) {
                    return $this->information($response['message'], 422, $response['errors']);
                }

                return $this->error($response['message'], $response['status']);
            }

            return $this->ok($response['data'], $response['message'], 201);
        } catch (Throwable $e) {
            report($e);

            return $this->error('Error al registrar la sesión de actividad.', 500);
        }
    }

    public function update(Request $request, int $idActivitySession): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'id_actividad' => ['sometimes', 'bail', 'required', 'integer', Rule::exists(config('database.default').'.certificado.actividad', 'id_actividad')],
                'nombre' => ['sometimes', 'required', 'string', 'max:150'],
                'fecha' => ['sometimes', 'required', 'date'],
                'hora_inicio' => ['sometimes', 'required', 'date_format:H:i,H:i:s'],
                'hora_fin' => ['sometimes', 'required', 'date_format:H:i,H:i:s'],
                'lugar' => ['sometimes', 'nullable', 'string', 'max:200'],
                'estado' => ['sometimes', 'required', 'string', 'max:30'],
            ]);

            if ($validator->fails()) {
                return $this->information('Invalid validation data.', 422, $validator->errors());
            }

            $response = ActivitySessionData::updateActivitySession($idActivitySession, $validator->validated());

            if (! $response['success']) {
                if ($response['status'] === 422) {
                    return $this->information($response['message'], 422, $response['errors']);
                }

                return $this->error($response['message'], $response['status']);
            }

            return $this->ok($response['data'], $response['message']);
        } catch (Throwable $e) {
            report($e);

            return $this->error('Error al actualizar la sesión de actividad.', 500);
        }
    }

    public function destroy(int $idActivitySession): JsonResponse
    {
        $response = ActivitySessionData::deleteActivitySession($idActivitySession);

        if (! $response['success']) {
            return $this->error($response['message'], $response['status']);
        }

        return $this->ok($response['data'], $response['message']);
    }
}
