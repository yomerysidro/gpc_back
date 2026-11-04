<?php

namespace App\Http\Controllers\ActivityRequests;

use App\Http\Controllers\Controller;
use App\Http\Data\ActivityRequests\ActivityRequestData;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ActivityRequestController extends Controller
{
    use ApiResponse;

    private const WRITABLE_FIELDS = [
        'id_area',
        'id_tipo_actividad',
        'nombre',
        'descripcion',
        'justificacion',
        'fecha_inicio_propuesta',
        'fecha_fin_propuesta',
        'sede_propuesta',
    ];

    public function authorizedAreas(Request $request): JsonResponse
    {
        $userId = $this->authenticatedUserId($request);
        if ($userId instanceof JsonResponse) {
            return $userId;
        }

        return $this->dataResponse(ActivityRequestData::authorizedRequestAreas($request->user()));
    }

    public function store(Request $request): JsonResponse
    {
        $userId = $this->authenticatedUserId($request);
        if ($userId instanceof JsonResponse) {
            return $userId;
        }

        $result = ActivityRequestData::createDraft($userId, $request->only(self::WRITABLE_FIELDS));
        if ($result['success'] && $request->user()->canRepresentAllRequestAreas()) {
            Log::info('solicitud.representacion_administrativa', ['id_solicitud_actividad' => $result['data']->id_solicitud_actividad,
                'id_usuario_registrador' => $userId, 'id_area_solicitante' => $result['data']->id_area]);
        }

        return $this->dataResponse($result);
    }

    public function update(Request $request, int $requestId): JsonResponse
    {
        $userId = $this->authenticatedUserId($request);
        if ($userId instanceof JsonResponse) {
            return $userId;
        }

        return $this->dataResponse(ActivityRequestData::updateDraft($userId, $requestId, $request->only(self::WRITABLE_FIELDS), $request->attributes->get('activity_request_areas')));
    }

    public function submit(Request $request, int $requestId): JsonResponse
    {
        $userId = $this->authenticatedUserId($request);
        if ($userId instanceof JsonResponse) {
            return $userId;
        }

        return $this->dataResponse(ActivityRequestData::submit($userId, $requestId, $request->attributes->get('activity_request_areas')));
    }

    public function mine(Request $request): JsonResponse
    {
        $userId = $this->authenticatedUserId($request);
        if ($userId instanceof JsonResponse) {
            return $userId;
        }

        try {
            return $this->dataResponse(ActivityRequestData::ownRequests($userId, $request->query(), $request->attributes->get('activity_request_areas')));
        } catch (ValidationException $exception) {
            return $this->information('Datos de validación incorrectos.', 422, $exception->errors());
        }
    }

    public function index(Request $request): JsonResponse
    {
        $userId = $this->authenticatedUserId($request);
        if ($userId instanceof JsonResponse) {
            return $userId;
        }

        try {
            return $this->dataResponse(ActivityRequestData::list($request->query(), null, $request->attributes->get('activity_request_areas'), $request->attributes->get('activity_request_states')));
        } catch (ValidationException $exception) {
            return $this->information('Datos de validación incorrectos.', 422, $exception->errors());
        }
    }

    public function show(Request $request, int $requestId): JsonResponse
    {
        $userId = $this->authenticatedUserId($request);
        if ($userId instanceof JsonResponse) {
            return $userId;
        }

        return $this->dataResponse(($request->attributes->get('activity_request_owner_only', true) ? ActivityRequestData::detail($userId, $requestId) : ActivityRequestData::reviewDetail($requestId)));
    }

    public function reviewDetail(Request $request, int $requestId): JsonResponse
    {
        $userId = $this->authenticatedUserId($request);
        if ($userId instanceof JsonResponse) {
            return $userId;
        }

        return $this->dataResponse(ActivityRequestData::reviewDetail($requestId));
    }

    public function history(Request $request, int $requestId): JsonResponse
    {
        $userId = $this->authenticatedUserId($request);
        if ($userId instanceof JsonResponse) {
            return $userId;
        }

        return $this->dataResponse(ActivityRequestData::history($requestId, $request->attributes->get('activity_request_owner_only', true) ? $userId : null));
    }

    public function decisionHistory(Request $request): JsonResponse
    {
        $userId = $this->authenticatedUserId($request);
        if ($userId instanceof JsonResponse) {
            return $userId;
        }

        try {
            return $this->dataResponse(ActivityRequestData::decisionHistory($request->query(), $request->attributes->get('activity_request_areas'), $request->attributes->get('activity_request_states')));
        } catch (ValidationException $exception) {
            return $this->information('Datos de validación incorrectos.', 422, $exception->errors());
        }
    }

    private function authenticatedUserId(Request $request): int|JsonResponse
    {
        $identity = $request->user()?->getAuthIdentifier();
        if (! is_numeric($identity) || (int) $identity < 1) {
            return $this->information('No autenticado.', 401, null);
        }

        return (int) $identity;
    }

    /** @param array<string, mixed> $result */
    private function dataResponse(array $result): JsonResponse
    {
        $status = $result['status'] ?? ($result['success'] ? 200 : 500);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'] ?? null,
            'data' => $result['data'] ?? null,
        ], $status);
    }
}
