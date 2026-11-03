<?php

namespace App\Http\Data\Report;

use App\Models\Activity\Activity;
use App\Models\ActivityType\ActivityType;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class ActivityReportData
{
    /** @return array{success: bool, message: string, data?: array, status?: int, errors?: array} */
    public static function list(array $filters = []): array
    {
        try {
            $rules = [
                'desde' => ['nullable', 'date_format:Y-m-d'],
                'hasta' => ['nullable', 'date_format:Y-m-d'],
                'estado' => ['nullable', 'string', 'max:20'],
                'q' => ['nullable', 'string', 'max:100'],
                'page' => ['sometimes', 'integer', 'min:1'],
                'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            ];
            if (! empty($filters['desde']) && ! empty($filters['hasta'])) {
                $rules['hasta'][] = 'after_or_equal:desde';
            }
            $filters = Validator::make($filters, $rules, [
                'hasta.after_or_equal' => 'La fecha de inicio no puede ser posterior a la fecha de fin.',
                'desde.date_format' => 'La fecha de inicio debe tener formato AAAA-MM-DD.',
                'hasta.date_format' => 'La fecha de fin debe tener formato AAAA-MM-DD.',
            ])->validate();

            $query = Activity::query()->select(['id_actividad', 'nombre', 'fecha_inicio', 'fecha_fin', 'estado', 'sede'])
                ->addSelect(['tipo_actividad' => ActivityType::query()->select('nombre')
                    ->whereColumn('certificado.tipo_actividad.id_tipo_actividad', 'certificado.actividad.id_tipo_actividad')->limit(1)]);

            if (! empty($filters['desde'])) {
                $query->whereDate('fecha_inicio', '>=', $filters['desde']);
            }
            if (! empty($filters['hasta'])) {
                $query->whereDate('fecha_inicio', '<=', $filters['hasta']);
            }
            $state = trim($filters['estado'] ?? '');
            if ($state !== '') {
                $query->where('estado', $state);
            }
            $search = trim($filters['q'] ?? '');
            if ($search !== '') {
                $literal = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search));
                $code = filter_var($search, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
                $query->where(function ($query) use ($literal, $code): void {
                    $query->whereRaw("LOWER(nombre) LIKE ? ESCAPE '!'", ['%'.$literal.'%']);
                    if ($code !== false) {
                        $query->orWhere('id_actividad', $code);
                    }
                });
            }

            $page = $query->orderByDesc('id_actividad')->paginate($filters['per_page'] ?? 20, ['*'], 'page', $filters['page'] ?? 1);

            return ['success' => true, 'message' => 'Reporte de actividades obtenido correctamente.', 'data' => [
                'data' => $page->items(), 'total' => $page->total(),
                'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(),
                'filtros' => ['desde' => $filters['desde'] ?? null, 'hasta' => $filters['hasta'] ?? null, 'estado' => $state, 'q' => $search],
                'criterio_fecha' => 'fecha_inicio',
            ]];
        } catch (ValidationException $exception) {
            return ['success' => false, 'message' => 'Revisa los filtros del reporte.', 'status' => 422, 'errors' => $exception->errors()];
        } catch (Throwable $exception) {
            report($exception);

            return ['success' => false, 'message' => 'No se pudo consultar el reporte de actividades.', 'status' => 500];
        }
    }
}
