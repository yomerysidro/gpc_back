<?php

namespace App\Http\Controllers\ActivityRequests;

use App\Http\Controllers\Controller;
use App\Http\Data\ActivityRequests\ActivityRequestData;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ActivityRequestDecisionController extends Controller
{
    use ApiResponse;

    public function review(Request $request, int $requestId): JsonResponse
    {
        return $this->decide($request, $requestId, 'REVISION_CONFORME', ['observacion' => ['sometimes', 'nullable', 'string']]);
    }

    public function observe(Request $request, int $requestId): JsonResponse
    {
        return $this->decide($request, $requestId, 'OBSERVAR', ['observacion' => ['required', 'string']]);
    }

    public function derive(Request $request, int $requestId): JsonResponse
    {
        return $this->decide($request, $requestId, 'DERIVAR_GERENCIA', ['observacion' => ['sometimes', 'nullable', 'string']]);
    }

    public function approve(Request $request, int $requestId): JsonResponse
    {
        return $this->decide($request, $requestId, 'APROBAR', ['observacion' => ['sometimes', 'nullable', 'string']]);
    }

    public function reject(Request $request, int $requestId): JsonResponse
    {
        return $this->decide($request, $requestId, 'RECHAZAR', ['observacion' => ['required', 'string']]);
    }

    /** @param array<string, list<string>> $rules */
    private function decide(Request $request, int $requestId, string $decision, array $rules): JsonResponse
    {
        $identity = $request->user()?->getAuthIdentifier();
        if (! is_numeric($identity) || (int) $identity < 1) {
            return $this->information('No autenticado.', 401, null);
        }

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return $this->information('Datos de validación incorrectos.', 422, $validator->errors());
        }

        $result = ActivityRequestData::decide(
            (int) $identity,
            $requestId,
            $decision,
            $validator->validated()['observacion'] ?? null,
            $request->attributes->get('activity_request_areas'),
        );

        return response()->json($this->responsePayload($result), $result['status'] ?? ($result['success'] ? 200 : 500));
    }

    /** @param array<string, mixed> $result
     * @return array{success: bool, message: string|null, data: mixed}
     */
    private function responsePayload(array $result): array
    {
        return [
            'success' => $result['success'],
            'message' => $result['message'] ?? null,
            'data' => $result['data'] ?? null,
        ];
    }
}
