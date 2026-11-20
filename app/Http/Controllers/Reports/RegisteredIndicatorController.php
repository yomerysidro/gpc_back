<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Http\Data\ActivityRequests\ActivityRequestData;
use App\Models\Activity\Activity;
use App\Models\Attendance\Attendance;
use App\Models\Certificate\Certificate;
use App\Models\Participation\Participation;
use App\Services\CertificateDocumentWorkflow;
use App\Services\ReportQueries;
use App\Traits\ApiResponse;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RegisteredIndicatorController extends Controller
{
    use ApiResponse;

    public function __invoke(Request $request, string $indicador): JsonResponse
    {
        $permission = match ($indicador) {
            'certificados' => 'emision.ver',
            'actividades' => 'actividades.ver',
            'participaciones', 'asistencias' => 'participantes.ver',
            default => abort(404),
        };
        $user = $request->user();
        $institutional = ReportQueries::institutional($user);
        if (! $institutional && ! in_array($permission, $user->permissionKeys(), true)) {
            return $this->error('No autorizado para consultar este indicador.', 403);
        }

        try {
            if ($indicador === 'certificados') {
                $total = $institutional || $user->isSystemAdministrator()
                    ? Certificate::query()->count()
                    : app(CertificateDocumentWorkflow::class)->readableCertificates($user)->distinct()->count('c.id_certificado');
            } else {
                $areas = null;
                if (! $institutional && ! $user->isSystemAdministrator()) {
                    $result = ActivityRequestData::authorizedAreas((int) $user->id_persona);
                    if (! $result['success']) {
                        return $this->error($result['message'], $result['status']);
                    }
                    $areas = $result['data']->pluck('id_area')->all();
                }

                $activities = Activity::query()->select('id_actividad');
                $participations = Participation::query()->select('id_participacion');
                $attendances = Attendance::query();
                if ($areas !== null) {
                    $activities->whereIn('id_area', $areas);
                    $participations->whereIn('id_actividad', $activities);
                    $attendances->whereIn('id_participacion', $participations);
                }
                $total = match ($indicador) {
                    'actividades' => $activities->count(),
                    'participaciones' => $participations->count(),
                    'asistencias' => $attendances->count(),
                };
            }

            return $this->ok(['indicador' => $indicador, 'total' => (int) $total, 'filtros' => []]);
        } catch (QueryException $exception) {
            report($exception);

            return $this->error('No se pudo consultar el total de registros.', 500);
        }
    }
}
