<?php

namespace App\Http\Controllers\Report;

use App\Http\Controllers\Controller;
use App\Http\Data\Report\ReportIndicatorsData;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ReportIndicatorsController extends Controller
{
    use ApiResponse;

    public function count(Request $request, string $indicador): JsonResponse
    {
        $model = ReportIndicatorsData::MODELS[$indicador] ?? null;
        if ($model === null) {
            return $this->error('Indicador no encontrado.', 404);
        }

        if (Gate::denies('viewAny', $model)) {
            return $this->error('No tienes permiso para consultar los registros de este indicador.', 403);
        }

        if ($request->query() !== []) {
            return $this->error('Los indicadores generales no admiten filtros. Consulta sin parámetros para obtener el total general autorizado.', 422);
        }

        $response = ReportIndicatorsData::count($indicador);
        if (! $response['success']) {
            return $this->error($response['message'], $response['status']);
        }

        return $this->ok($response['data'], $response['message']);
    }
}
