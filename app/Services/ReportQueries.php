<?php

namespace App\Services;

use App\Http\Data\ActivityRequests\ActivityRequestData;
use App\Models\User\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class ReportQueries
{
    public static function institutional(User $actor): bool
    {
        $keys = $actor->permissionKeys();

        return in_array('gerencia.ver', $keys, true) && in_array('reportes.ver', $keys, true);
    }

    public const PERSON_NAME = "TRIM(COALESCE(persona.nombres, '') || ' ' || COALESCE(persona.apellido_paterno, '') || ' ' || COALESCE(persona.apellido_materno, ''))";

    /** @return list<int>|null */
    public function areas(User $actor): ?array
    {
        if ($actor->isSystemAdministrator() || self::institutional($actor)) {
            return null;
        }
        $result = ActivityRequestData::authorizedAreas((int) $actor->id_persona);
        abort_unless($result['success'], $result['status'] ?? 500, 'No se pudo comprobar el ámbito autorizado.');

        return $result['data']->pluck('id_area')->map(fn (mixed $id): int => (int) $id)->all();
    }

    public function activities(User $actor, array $filters): Builder
    {
        $areas = $this->areas($actor);
        $query = DB::table('certificado.actividad as a')
            ->leftJoin('certificado.tipo_actividad as tipo', 'tipo.id_tipo_actividad', '=', 'a.id_tipo_actividad')
            ->leftJoin('gestion.area as area', 'area.id_area', '=', 'a.id_area')
            ->when($areas !== null, fn (Builder $query) => $query->whereIn('a.id_area', $areas))
            ->select('a.id_actividad', 'a.nombre', 'a.fecha_inicio', 'a.fecha_fin', 'a.estado', 'a.sede',
                'a.id_area', 'area.nombre as area', 'tipo.nombre as tipo_actividad')
            ->selectSub(DB::table('certificado.actividad_sesion as s')->selectRaw('COUNT(*)')->whereColumn('s.id_actividad', 'a.id_actividad'), 'sesiones')
            ->selectSub(DB::table('certificado.participacion as p')->selectRaw('COUNT(*)')->whereColumn('p.id_actividad', 'a.id_actividad'), 'participaciones');
        $this->dates($query, 'a.fecha_inicio', $filters);
        $this->exact($query, 'a.estado', $filters['estado'] ?? null);
        $this->search($query, ['a.nombre', 'CAST(a.id_actividad AS TEXT)', 'area.nombre'], $filters['q'] ?? '');

        return $query;
    }

    public function certificates(User $actor, array $filters): Builder
    {
        $query = DB::table('certificado.certificado as c')
            ->leftJoin('gestion.persona as persona', 'persona.id_persona', '=', 'c.id_persona')
            ->leftJoin('certificado.tipo_certificado as tipo', 'tipo.id_tipo_certificado', '=', 'c.id_tipo_certificado')
            ->leftJoin('certificado.participacion as p', 'p.id_participacion', '=', 'c.id_participacion')
            ->leftJoin('certificado.actividad as a', 'a.id_actividad', '=', 'p.id_actividad')
            ->leftJoin('certificado.certificado_documento as d', 'd.id_certificado_documento', '=',
                DB::raw('(SELECT MAX(version.id_certificado_documento) FROM certificado.certificado_documento AS version WHERE version.id_certificado = c.id_certificado)'))
            ->select('c.id_certificado', 'c.id_persona', 'c.id_tipo_certificado', 'c.fecha_emision', 'c.estado',
                'tipo.nombre_tipo as tipo_certificado', 'a.id_actividad', 'a.nombre as actividad',
                'd.id_certificado_documento', 'd.codigo_verificacion', 'd.estado as estado_documento', 'd.fecha_generacion')
            ->selectRaw(self::PERSON_NAME.' as beneficiario');
        if (! $actor->isSystemAdministrator() && ! self::institutional($actor)) {
            $authorized = app(CertificateDocumentWorkflow::class)->readableCertificates($actor)
                ->reorder()->select('c.id_certificado')->distinct();
            $query->whereIn('c.id_certificado', $authorized);
        }
        if (RequestSchema::hasColumn('certificado.reconocimiento', 'id_actividad_ponente')) {
            $query->leftJoin('certificado.reconocimiento as r', 'r.id_reconocimiento', '=', 'c.id_reconocimiento')
                ->leftJoin('certificado.actividad_ponente as ap', 'ap.id_actividad_ponente', '=', 'r.id_actividad_ponente')
                ->leftJoin('certificado.actividad as actividad_ponente', 'actividad_ponente.id_actividad', '=', 'ap.id_actividad')
                ->selectRaw('COALESCE(a.id_actividad, actividad_ponente.id_actividad) as id_actividad')
                ->selectRaw('COALESCE(a.nombre, actividad_ponente.nombre) as actividad');
        }
        $this->dates($query, 'c.fecha_emision', $filters);
        $this->exact($query, 'c.estado', $filters['estado'] ?? null);
        $this->exact($query, 'c.id_tipo_certificado', $filters['id_tipo_certificado'] ?? null);
        $this->exact($query, 'd.estado', $filters['estado_documento'] ?? null);
        $columns = [self::PERSON_NAME, 'CAST(c.id_certificado AS TEXT)', 'tipo.nombre_tipo', 'a.nombre', 'd.codigo_verificacion'];
        if (RequestSchema::hasColumn('certificado.reconocimiento', 'id_actividad_ponente')) {
            $columns[] = 'actividad_ponente.nombre';
        }
        $this->search($query, $columns, $filters['q'] ?? '');

        return $query;
    }

    public function sessions(User $actor, int $activity): Builder
    {
        $this->authorizeActivity($actor, $activity);

        return DB::table('certificado.actividad_sesion as s')->where('s.id_actividad', $activity)
            ->select('s.id_actividad_sesion', 's.id_actividad', 's.nombre', 's.fecha', 's.estado');
    }

    public function authorizeActivity(User $actor, int $activity): void
    {
        $areas = $this->areas($actor);
        abort_unless(DB::table('certificado.actividad')->where('id_actividad', $activity)
            ->when($areas !== null, fn (Builder $query) => $query->whereIn('id_area', $areas))->exists(),
            404, 'Actividad no encontrada en su ámbito autorizado.');
    }

    public function attendances(User $actor, array $filters): Builder
    {
        $areas = $this->areas($actor);
        if (! empty($filters['id_actividad'])) {
            $this->authorizeActivity($actor, (int) $filters['id_actividad']);
        }
        if (! empty($filters['id_actividad_sesion'])) {
            $session = DB::table('certificado.actividad_sesion as s')
                ->join('certificado.actividad as a', 'a.id_actividad', '=', 's.id_actividad')
                ->when($areas !== null, fn (Builder $query) => $query->whereIn('a.id_area', $areas))
                ->where('s.id_actividad_sesion', $filters['id_actividad_sesion'])->first(['s.id_actividad']);
            abort_unless($session, 404, 'Sesión no encontrada en su ámbito autorizado.');
            abort_if(! empty($filters['id_actividad']) && (int) $session->id_actividad !== (int) $filters['id_actividad'],
                422, 'La sesión no pertenece a la actividad seleccionada.');
        }
        $query = DB::table('certificado.asistencia as asistencia')
            ->join('certificado.participacion as p', 'p.id_participacion', '=', 'asistencia.id_participacion')
            ->join('certificado.actividad as a', 'a.id_actividad', '=', 'p.id_actividad')
            ->join('certificado.actividad_sesion as s', function ($join): void {
                $join->on('s.id_actividad_sesion', '=', 'asistencia.id_actividad_sesion')->on('s.id_actividad', '=', 'p.id_actividad');
            })
            ->leftJoin('gestion.persona as persona', 'persona.id_persona', '=', 'p.id_persona')
            ->when($areas !== null, fn (Builder $query) => $query->whereIn('a.id_area', $areas))
            ->select('asistencia.id_asistencia', 'asistencia.id_participacion', 'p.id_persona', 'a.id_actividad',
                'a.nombre as actividad', 's.id_actividad_sesion', 's.nombre as sesion', 's.fecha as fecha_sesion',
                'asistencia.hora_entrada', 'asistencia.hora_salida', 'asistencia.estado')
            ->selectRaw(self::PERSON_NAME.' as participante');
        $this->dates($query, 's.fecha', $filters);
        $this->exact($query, 'a.id_actividad', $filters['id_actividad'] ?? null);
        $this->exact($query, 's.id_actividad_sesion', $filters['id_actividad_sesion'] ?? null);
        $this->exact($query, 'asistencia.estado', $filters['estado'] ?? null);
        $this->search($query, [self::PERSON_NAME, 'a.nombre', 's.nombre', 'CAST(asistencia.id_asistencia AS TEXT)'], $filters['q'] ?? '');

        return $query;
    }

    public function summary(Builder $query, string $resource): array
    {
        $base = DB::query()->fromSub(clone $query, 'filas');
        $expression = match ($resource) {
            'actividades' => 'COUNT(*) as total, COALESCE(SUM(sesiones), 0) as sesiones, COALESCE(SUM(participaciones), 0) as participaciones',
            'certificados' => 'COUNT(*) as total, COUNT(fecha_emision) as con_fecha_emision, COUNT(DISTINCT id_persona) as personas_unicas',
            'asistencia' => "COUNT(*) as total, COUNT(DISTINCT id_persona) as personas_unicas, COUNT(CASE WHEN UPPER(TRIM(estado)) = 'PRESENTE' THEN 1 END) as presentes",
        };

        return array_map(fn (mixed $value): int => (int) $value, (array) $base->selectRaw($expression)->first());
    }

    public function series(User $actor, string $indicator, int $year): array
    {
        $filters = ['desde' => $year.'-01-01', 'hasta' => $year.'-12-31'];
        $query = $indicator === 'actividades' ? $this->activities($actor, $filters) : $this->certificates($actor, $filters);
        $date = $indicator === 'actividades' ? 'fecha_inicio' : 'fecha_emision';
        $month = 'SUBSTR(CAST('.$date.' AS TEXT), 1, 7)';
        $counts = DB::query()->fromSub($query, 'filas')->selectRaw($month.' as mes, COUNT(*) as total')
            ->groupByRaw($month)->pluck('total', 'mes');
        $months = [];
        for ($number = 1; $number <= 12; $number++) {
            $key = sprintf('%04d-%02d', $year, $number);
            $months[] = ['mes' => $key, 'total' => (int) ($counts[$key] ?? 0)];
        }

        return ['indicador' => $indicator, 'anio' => $year, 'criterio_fecha' => $date, 'meses' => $months];
    }

    private function dates(Builder $query, string $column, array $filters): void
    {
        if (! empty($filters['desde'])) {
            $query->whereDate($column, '>=', $filters['desde']);
        }
        if (! empty($filters['hasta'])) {
            $query->whereDate($column, '<=', $filters['hasta']);
        }
    }

    private function exact(Builder $query, string $column, mixed $value): void
    {
        if ($value !== null && $value !== '') {
            $query->where($column, $value);
        }
    }

    private function search(Builder $query, array $columns, string $term): void
    {
        if ($term === '') {
            return;
        }
        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(trim($term))).'%';
        $query->where(function (Builder $search) use ($columns, $pattern): void {
            foreach ($columns as $column) {
                $search->orWhereRaw('LOWER('.$column.") LIKE ? ESCAPE '!'", [$pattern]);
            }
        });
    }
}
