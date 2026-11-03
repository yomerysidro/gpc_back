<?php

namespace App\Http\Controllers\Report;

use App\Http\Controllers\Controller;
use App\Http\Data\Report\ActivityReportData;
use App\Models\Activity\Activity;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ActivityReportController extends Controller
{
    use ApiResponse;

    public function list(Request $request): JsonResponse
    {
        if (Gate::denies('viewAny', Activity::class)) {
            return $this->error('No tienes permiso para consultar actividades.', 403);
        }
        $response = ActivityReportData::list($request->query());
        if (! $response['success']) {
            if ($response['status'] === 422) {
                return response()->json(['success' => false, 'message' => $response['message'], 'errors' => $response['errors'], 'data' => []], 422);
            }

            return $this->error($response['message'], $response['status']);
        }

        return $this->ok($response['data'], $response['message']);
    }
}
