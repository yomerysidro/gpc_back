<?php

namespace App\Http\Data\Report;

use App\Models\Activity\Activity;
use App\Models\Attendance\Attendance;
use App\Models\Certificate\Certificate;
use App\Models\Participation\Participation;
use Throwable;

class ReportIndicatorsData
{
    /** @var array<string, class-string<\Illuminate\Database\Eloquent\Model>> */
    public const MODELS = [
        'actividades' => Activity::class,
        'certificados' => Certificate::class,
        'participaciones' => Participation::class,
        'asistencias' => Attendance::class,
    ];

    /**
     * Counts principal records without joins or state/date filters.
     * Eloquent retains any model scopes; viewAny is checked by the controller.
     *
     * @return array{success: bool, message: string, data?: array{indicador: string, total: int, filtros: array}, status?: int}
     */
    public static function count(string $indicator): array
    {
        $model = self::MODELS[$indicator] ?? null;
        if ($model === null) {
            return ['success' => false, 'message' => 'Indicador no encontrado.', 'status' => 404];
        }

        try {
            $total = $model::query()->count();

            return [
                'success' => true,
                'message' => 'Conteo obtenido correctamente.',
                'data' => ['indicador' => $indicator, 'total' => $total, 'filtros' => []],
            ];
        } catch (Throwable $exception) {
            report($exception);

            return ['success' => false, 'message' => 'No se pudo consultar el total de registros.', 'status' => 500];
        }
    }
}
