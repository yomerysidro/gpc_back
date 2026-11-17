<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Services\ReportQueries;
use App\Traits\ApiResponse;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    use ApiResponse;

    public function __construct(private ReportQueries $queries) {}

    public function index(Request $request, string $recurso): JsonResponse|StreamedResponse
    {
        $this->authorizeResource($request, $recurso);
        $filters = $request->validate([
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => array_filter(['nullable', 'date_format:Y-m-d', $request->filled('desde') ? 'after_or_equal:desde' : null]),
            'estado' => ['nullable', 'string', 'max:40'],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
            'id_tipo_certificado' => ['nullable', 'integer', 'min:1'],
            'estado_documento' => ['nullable', 'string', 'max:40'],
            'id_actividad' => ['nullable', 'integer', 'min:1'],
            'id_actividad_sesion' => ['nullable', 'integer', 'min:1'],
            'exportar' => ['nullable', 'in:csv'],
        ]);
        if (isset($filters['exportar'])) {
            abort_unless(in_array('reportes.exportar', $request->user()->permissionKeys(), true), 403, 'No autorizado para exportar reportes.');
        }
        $filters['q'] = trim($filters['q'] ?? '');
        try {
            $query = match ($recurso) {
                'actividades' => $this->queries->activities($request->user(), $filters),
                'certificados' => $this->queries->certificates($request->user(), $filters),
                'asistencia' => $this->queries->attendances($request->user(), $filters),
            };
            $summary = $this->queries->summary($query, $recurso);
            $order = match ($recurso) {
                'actividades' => 'a.id_actividad', 'certificados' => 'c.id_certificado', 'asistencia' => 'asistencia.id_asistencia',
            };
            if (isset($filters['exportar'])) {
                return $this->csv($query->orderBy($order), $recurso);
            }
            $page = $query->orderByDesc($order)->paginate((int) ($filters['per_page'] ?? 20));
            $data = $page->toArray();
            $data['resumen'] = $summary;
            $data['filtros'] = array_diff_key($filters, array_flip(['page', 'per_page', 'exportar']));
            $data['criterio_fecha'] = match ($recurso) {
                'actividades' => 'fecha_inicio', 'certificados' => 'fecha_emision', 'asistencia' => 'fecha_sesion',
            };

            return $this->ok($data);
        } catch (QueryException $exception) {
            report($exception);

            return $this->error('No se pudo consultar el reporte.', 500);
        }
    }

    public function sessions(Request $request): JsonResponse
    {
        $this->authorizeResource($request, 'asistencia');
        $filters = $request->validate([
            'id_actividad' => ['required', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        return $this->ok($this->queries->sessions($request->user(), (int) $filters['id_actividad'])
            ->orderByDesc('s.fecha')->orderByDesc('s.id_actividad_sesion')->paginate((int) ($filters['per_page'] ?? 20))->toArray());
    }

    public function series(Request $request, string $indicador): JsonResponse
    {
        $this->authorizeResource($request, $indicador);
        $filters = $request->validate(['anio' => ['required', 'integer', 'between:1900,2100']]);

        return $this->ok($this->queries->series($request->user(), $indicador, (int) $filters['anio']));
    }

    private function authorizeResource(Request $request, string $resource): void
    {
        $permission = match ($resource) {
            'actividades' => 'actividades.ver', 'certificados' => 'emision.ver', 'asistencia' => 'participantes.ver', default => abort(404),
        };
        abort_unless(in_array($permission, $request->user()->permissionKeys(), true), 403, 'No autorizado para consultar este reporte.');
    }

    private function csv(Builder $query, string $resource): StreamedResponse
    {
        $columns = match ($resource) {
            'actividades' => ['id_actividad' => 'Código', 'nombre' => 'Actividad', 'area' => 'Área', 'tipo_actividad' => 'Tipo', 'fecha_inicio' => 'Inicio', 'fecha_fin' => 'Fin', 'estado' => 'Estado', 'sede' => 'Sede', 'sesiones' => 'Sesiones', 'participaciones' => 'Participaciones'],
            'certificados' => ['id_certificado' => 'Código', 'beneficiario' => 'Beneficiario', 'tipo_certificado' => 'Tipo', 'actividad' => 'Actividad', 'fecha_emision' => 'Fecha de emisión registrada', 'estado' => 'Estado del certificado', 'id_certificado_documento' => 'Último documento', 'estado_documento' => 'Estado del documento', 'codigo_verificacion' => 'Código de verificación'],
            'asistencia' => ['id_asistencia' => 'Código', 'participante' => 'Participante', 'actividad' => 'Actividad', 'sesion' => 'Sesión', 'fecha_sesion' => 'Fecha de sesión', 'hora_entrada' => 'Entrada', 'hora_salida' => 'Salida', 'estado' => 'Estado'],
        };

        return response()->streamDownload(function () use ($query, $columns): void {
            $stream = fopen('php://output', 'wb');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, array_values($columns), ',', '"', '', "\r\n");
            foreach ($query->lazy(500) as $row) {
                $cells = [];
                foreach (array_keys($columns) as $column) {
                    $value = (string) ($row->{$column} ?? '');
                    $cells[] = preg_match('/^[\s\x00-\x20]*[=+@-]|^[\t\r\n]/u', $value) ? "'".$value : $value;
                }
                fputcsv($stream, $cells, ',', '"', '', "\r\n");
            }
            fclose($stream);
        }, 'reporte-'.$resource.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }
}
