<?php

namespace App\Services;

use App\Services\ActivityRequests\DevelopmentDatabase;
use App\Services\RequestSchema as Schema;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class CertificationReadiness
{
    public const FINAL_SESSION_STATE = 'Finalizada';

    public const SESSION_IDENTITY_BLOCKER = 'La identidad persistente del certificado por sesión requiere la columna id_actividad_sesion, la relación compuesta con asistencia y la unicidad por participación, sesión y tipo.';

    public const OFFICIAL_ISSUANCE_BLOCKER = 'La emisión oficial y la firma digital requieren el procedimiento institucional integrado; la evaluación provisional no las habilita.';

    /**
     * @param  list<object>  $sessions
     */
    public function addAttendanceCounters(array $sessions): void
    {
        if ($sessions === []) {
            return;
        }

        $policy = config('certification.session_closure');
        $activeStates = $this->validPolicy($policy)
            ? $policy['active_participation_states']
            : config('certification.attendance_summary.active_participation_states', []);
        $presentStates = config('certification.attendance_summary.present_states', []);
        $absentStates = config('certification.attendance_summary.absent_states', []);
        $validMapping = $this->validStates($activeStates, false)
            && $this->validStates($presentStates, false) && $this->validStates($absentStates, false)
            && array_intersect($presentStates, $absentStates) === [];
        $normalize = fn (?string $state): string => strtoupper(trim($state ?? ''));
        $normalizedActiveStates = $validMapping ? array_map($normalize, $activeStates) : [];
        $normalizedPresentStates = $validMapping ? array_map($normalize, $presentStates) : [];
        $normalizedAbsentStates = $validMapping ? array_map($normalize, $absentStates) : [];

        $rows = DB::table('certificado.actividad_sesion as s')
            ->join('certificado.participacion as p', 'p.id_actividad', '=', 's.id_actividad')
            ->leftJoin('certificado.asistencia as a', function ($join): void {
                $join->on('a.id_participacion', '=', 'p.id_participacion')
                    ->on('a.id_actividad_sesion', '=', 's.id_actividad_sesion');
            })
            ->whereIn('s.id_actividad_sesion', array_map(fn (object $session): int => (int) $session->id_actividad_sesion, $sessions))
            ->select('s.id_actividad_sesion', 'p.id_persona', 'p.estado as estado_inscripcion', 'a.id_asistencia', 'a.estado as estado_asistencia')
            ->get()->groupBy('id_actividad_sesion');

        foreach ($sessions as $session) {
            $participants = $rows->get($session->id_actividad_sesion, collect())->groupBy('id_persona');
            $counters = [
                'inscritos_actividad_total' => $participants->count(),
                'inscritos_actividad_vigentes' => $validMapping ? 0 : null,
                'participantes_sesion' => $validMapping ? 0 : null,
                'presentes' => $validMapping ? 0 : null, 'ausentes' => $validMapping ? 0 : null,
                'pendientes' => $validMapping ? 0 : null, 'por_regularizar' => null,
                'asistencias_duplicadas' => 0,
                'configuracion_conteo_valida' => $validMapping,
                'estados_inscripcion_vigentes' => $validMapping ? $activeStates : [],
                'motivo_regularizaciones' => 'No existe un registro institucional de regularizaciones; no se puede determinar su cantidad.',
            ];
            foreach ($participants as $records) {
                $active = $validMapping ? $records->filter(fn (object $record): bool => in_array($normalize($record->estado_inscripcion), $normalizedActiveStates, true)) : collect();
                if ($active->isEmpty()) {
                    continue;
                }
                $counters['inscritos_actividad_vigentes']++;
                $attendances = $active->whereNotNull('id_asistencia')->unique('id_asistencia');
                if ($attendances->isEmpty()) {
                    continue;
                }
                $counters['participantes_sesion']++;
                $counters['asistencias_duplicadas'] += max(0, $attendances->count() - 1);
                if ($attendances->count() !== 1) {
                    $counters['pendientes']++;
                } elseif (in_array($normalize($attendances->first()->estado_asistencia), $normalizedPresentStates, true)) {
                    $counters['presentes']++;
                } elseif (in_array($normalize($attendances->first()->estado_asistencia), $normalizedAbsentStates, true)) {
                    $counters['ausentes']++;
                } else {
                    $counters['pendientes']++;
                }
            }
            $session->contadores = $counters;
        }
    }

    /** @return list<string> */
    public function closureBlockers(object $session): array
    {
        return $this->closureBlockersForSessions([$session])[$session->id_actividad_sesion];
    }

    /**
     * @param  list<object>  $sessions
     * @return array<int, list<string>>
     */
    public function closureBlockersForSessions(array $sessions): array
    {
        $results = [];
        $openSessions = [];
        $developmentBlockers = $this->developmentClosureBlockers();
        foreach ($sessions as $session) {
            $results[$session->id_actividad_sesion] = $developmentBlockers;
            if ($session->estado !== self::FINAL_SESSION_STATE) {
                $openSessions[] = $session;
            }
        }
        if ($openSessions === [] || $developmentBlockers !== []) {
            return $results;
        }
        $policy = config('certification.session_closure');
        $configurationBlockers = [];
        if (! $this->validPolicy($policy) || ! $this->validStates($policy['open_states'] ?? null, false)) {
            $configurationBlockers[] = match (true) {
                ! is_string($policy['reference'] ?? null), trim($policy['reference']) === '' => 'Falta una política aprobada de cierre, estados de asistencia y regularizaciones.',
                ($policy['regularizations'] ?? null) !== 'not_applicable' => 'Falta definir el tratamiento aprobado de regularizaciones. Si deben consultarse, falta un registro institucional compatible.',
                ! is_bool($policy['require_entry'] ?? null), ! is_bool($policy['require_exit'] ?? null) => 'La política debe indicar expresamente si exige registrar la entrada y la salida.',
                default => 'La política debe definir estados de origen, inscripciones vigentes y estados de asistencia consolidados sin superposición.',
            };
        } elseif (($policy['final_state'] ?? null) !== self::FINAL_SESSION_STATE
            || in_array(self::FINAL_SESSION_STATE, $policy['open_states'], true)) {
            $configurationBlockers[] = 'La política debe aprobar Finalizada como estado de destino y excluirlo de los estados de origen.';
        }
        if ($configurationBlockers !== []) {
            foreach ($openSessions as $session) {
                $results[$session->id_actividad_sesion] = $configurationBlockers;
            }

            return $results;
        }
        $sessionIds = array_map(fn (object $session): int => (int) $session->id_actividad_sesion, $openSessions);
        $activityIds = array_map(fn (object $session): int => (int) $session->id_actividad, $openSessions);
        $activities = DB::table('certificado.actividad')->whereIn('id_actividad', $activityIds)->pluck('estado', 'id_actividad');
        $approved = DB::table('certificado.solicitud_actividad')->whereIn('id_actividad', $activityIds)
            ->where('estado', 'Aprobada')->pluck('id_actividad')->all();
        $attendance = DB::table('certificado.actividad_sesion as s')
            ->join('certificado.participacion as p', 'p.id_actividad', '=', 's.id_actividad')
            ->join('certificado.asistencia as a', function ($join): void {
                $join->on('a.id_participacion', '=', 'p.id_participacion')
                    ->on('a.id_actividad_sesion', '=', 's.id_actividad_sesion');
            })
            ->whereIn('s.id_actividad_sesion', $sessionIds)->whereIn('p.estado', $policy['active_participation_states']);
        $pending = (clone $attendance)
            ->where(function (Builder $query) use ($policy): void {
                $query->whereNull('a.estado')
                    ->orWhereNotIn('a.estado', array_merge($policy['eligible_attendance_states'], $policy['ineligible_attendance_states']));
            })->distinct()->pluck('s.id_actividad_sesion')->all();
        $missingEntries = $policy['require_entry'] ? (clone $attendance)->whereNull('a.hora_entrada')->distinct()->pluck('s.id_actividad_sesion')->all() : [];
        $missingExits = $policy['require_exit'] ? (clone $attendance)->whereNull('a.hora_salida')->distinct()->pluck('s.id_actividad_sesion')->all() : [];
        $duplicates = (clone $attendance)->whereNotNull('a.id_asistencia')
            ->groupBy('s.id_actividad_sesion', 'p.id_persona')->havingRaw('COUNT(*) > 1')
            ->pluck('s.id_actividad_sesion')->all();
        $enrolledSessions = (clone $attendance)->distinct()->pluck('s.id_actividad_sesion')->all();
        foreach ($openSessions as $session) {
            $blockers = [];
            if (! in_array($session->id_actividad_sesion, $enrolledSessions)) {
                $blockers[] = 'La sesión debe tener al menos un participante seleccionado con inscripción vigente para finalizarse.';
            }
            if (! in_array($session->estado, $policy['open_states'], true)) {
                $blockers[] = 'El estado actual de la sesión no admite finalización.';
            }
            if ($activities->get($session->id_actividad) !== 'Activa') {
                $blockers[] = 'La actividad debe estar Activa para finalizar una sesión.';
            }
            if (! in_array($session->id_actividad, $approved)) {
                $blockers[] = 'La actividad no tiene una solicitud aprobada vinculada.';
            }
            if (in_array($session->id_actividad_sesion, $pending)) {
                $blockers[] = 'Existen asistencias pendientes de consolidación según la política aprobada.';
            }
            if (in_array($session->id_actividad_sesion, $missingEntries)) {
                $blockers[] = 'Faltan horas de entrada exigidas por la política aprobada.';
            }
            if (in_array($session->id_actividad_sesion, $missingExits)) {
                $blockers[] = 'Faltan horas de salida exigidas por la política aprobada.';
            }
            if (in_array($session->id_actividad_sesion, $duplicates)) {
                $blockers[] = 'Existen asistencias duplicadas; se requiere revisar la integridad antes de finalizar.';
            }
            $results[$session->id_actividad_sesion] = $blockers;
        }

        return $results;
    }

    /** @param list<int>|null $areaIds
     * @return array<string, mixed>
     */
    public function finalize(int $id, int $userId, ?array $areaIds): array
    {
        $developmentBlockers = $this->developmentClosureBlockers();
        if ($developmentBlockers !== []) {
            return ['finalizada' => false, 'sesion' => null, 'motivos_bloqueo' => $developmentBlockers];
        }

        return DB::transaction(function () use ($id, $userId, $areaIds): array {
            $session = DB::table('certificado.actividad_sesion')->where('id_actividad_sesion', $id)->lockForUpdate()->first();
            abort_unless($session, 404, 'Sesión no encontrada.');
            $activity = DB::table('certificado.actividad')->where('id_actividad', $session->id_actividad)->lockForUpdate()->first();
            abort_unless($activity && ($areaIds === null || in_array((int) $activity->id_area, $areaIds, true)), 403, 'Actividad fuera del ámbito autorizado.');
            $blockers = $this->closureBlockers($session);
            $this->addAttendanceCounters([$session]);
            if ($blockers !== []) {
                return ['finalizada' => false, 'sesion' => $session, 'motivos_bloqueo' => $blockers];
            }
            if ($session->estado !== self::FINAL_SESSION_STATE) {
                DB::table('certificado.actividad_sesion')->where('id_actividad_sesion', $id)->update(['estado' => self::FINAL_SESSION_STATE]);
                $session->estado = self::FINAL_SESSION_STATE;
                DB::afterCommit(fn () => Log::info('actividad_sesion.finalizada', [
                    'id_usuario' => $userId, 'id_actividad' => (int) $session->id_actividad,
                    'id_actividad_sesion' => $id, 'politica' => config('certification.session_closure.reference'),
                ]));
            }

            return ['finalizada' => true, 'sesion' => $session, 'estado_actividad' => $activity->estado, 'motivos_bloqueo' => []];
        }, 3);
    }

    /** @param array<string, mixed> $filters
     * @param  list<int>|null  $areaIds
     * @return array<string, mixed>
     */
    public function pending(array $filters, ?array $areaIds): array
    {
        $origin = $filters['origen'] ?? 'sesion';
        $query = DB::table('certificado.actividad as actividad')
            ->join('gestion.area as area', 'area.id_area', '=', 'actividad.id_area')
            ->select('actividad.id_actividad', 'actividad.nombre as nombre_actividad', 'actividad.estado as estado_actividad', 'area.id_area', 'area.nombre as area_municipal')
            ->when($areaIds !== null, fn (Builder $query) => $query->whereIn('actividad.id_area', $areaIds))
            ->when(isset($filters['id_actividad']), fn (Builder $query) => $query->where('actividad.id_actividad', $filters['id_actividad']));
        if ($origin === 'sesion') {
            $query->join('certificado.actividad_sesion as sesion', 'sesion.id_actividad', '=', 'actividad.id_actividad')
                ->addSelect('sesion.id_actividad_sesion as id_sesion', 'sesion.nombre as nombre_sesion', 'sesion.estado as estado_sesion')
                ->when(isset($filters['id_sesion']), fn (Builder $query) => $query->where('sesion.id_actividad_sesion', $filters['id_sesion']))
                ->orderBy('sesion.id_actividad_sesion');
        } else {
            $query->orderBy('actividad.id_actividad');
        }
        if (isset($filters['estado_certificacion']) && $filters['estado_certificacion'] !== 'Bloqueada') {
            $query->whereRaw('1 = 0');
        }
        $page = $query->paginate($filters['per_page'] ?? 20, ['*'], 'page', $filters['page'] ?? 1)
            ->withPath('/api/certificaciones/pendientes')->appends($filters);
        $facts = $this->facts($page->getCollection()->pluck('id_actividad')->unique()->all());
        $baseBlockers = $this->preparationBlockers($origin);
        $page->setCollection($page->getCollection()->map(function (object $row) use ($origin, $filters, $facts, $baseBlockers): array {
            $participants = $facts['participants']->where('id_actividad', $row->id_actividad);
            $counts = ['Elegible' => 0, 'No elegible' => 0, 'Pendiente de validación' => 0];
            foreach ($participants as $participant) {
                $evaluation = $this->evaluate($participant, $row, $origin, $filters['id_tipo_certificado'] ?? null, $facts);
                $counts[$evaluation['estado_elegibilidad']]++;
            }
            $certificates = $facts['certificates']->whereIn('id_participacion', $participants->pluck('id_participacion')->all());
            $compatible = $this->compatibleType($origin, $filters['id_tipo_certificado'] ?? null, $facts['types'], $facts['development_verified']);
            $blockers = $baseBlockers;
            if ($origin === 'sesion' && $this->usesDevelopmentPolicy()) {
                if (strtoupper(trim($row->estado_sesion)) !== 'FINALIZADA') {
                    $blockers[] = 'La sesión debe estar Finalizada para preparar certificados.';
                }
                if ($compatible !== true) {
                    $blockers[] = 'Seleccione un tipo vigente autorizado para la preparación provisional por sesión.';
                }
                if ($counts['Elegible'] === 0) {
                    $blockers[] = 'No existen beneficiarios elegibles para preparar.';
                }
            }

            return array_merge((array) $row, [
                'origen' => $origin, 'id_sesion' => $row->id_sesion ?? null,
                'tipo_certificacion' => $origin,
                'tipos_certificado_permitidos' => $facts['types']->filter(fn (mixed $id): bool => $this->compatibleType($origin, (int) $id, $facts['types'], $facts['development_verified']) === true)->values()->all(),
                'tipo_certificado_compatible' => $this->compatibleType($origin, $filters['id_tipo_certificado'] ?? null, $facts['types'], $facts['development_verified']),
                'total_participantes_inscritos' => $participants->count(),
                'total_elegibles' => $counts['Elegible'], 'total_no_elegibles' => $counts['No elegible'],
                'total_pendientes' => $counts['Pendiente de validación'],
                'estado_preparacion' => $blockers === [] ? 'Disponible' : 'Bloqueada',
                'preparaciones' => [],
                'modo_certificacion' => $origin === 'sesion' && $this->usesDevelopmentPolicy() ? 'desarrollo_provisional' : 'institucional',
                'puede_preparar' => $origin === 'sesion' && $this->usesDevelopmentPolicy() && $blockers === [],
                'puede_consultar_beneficiarios' => true, 'preparacion_persistida' => false,
                'puede_certificarse' => false, 'puede_generar_pdf' => false, 'puede_emitir' => false,
                'certificados_registrados_participaciones' => $certificates->count(),
                'identidad_sesion_disponible' => $facts['identity_available'],
                'puede_emitir_demostracion' => $origin === 'sesion' && $facts['identity_available'] && $this->usesDevelopmentPolicy() && $blockers === [],
                'codigo_bloqueo_preparacion' => null,
                'certificados_atribuidos_sesion' => $origin === 'sesion' && $facts['identity_available']
                    ? $certificates->where('id_actividad_sesion', $row->id_sesion)->count() : null,
                'certificados_emitidos_verificados' => null,
                'motivos_bloqueo' => $blockers,
                'motivos_bloqueo_emision' => $origin === 'sesion' && ! $facts['identity_available'] ? [self::SESSION_IDENTITY_BLOCKER, self::OFFICIAL_ISSUANCE_BLOCKER] : [self::OFFICIAL_ISSUANCE_BLOCKER],
            ]);
        }));

        return $page->toArray();
    }

    /** @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function beneficiaries(object $context, string $origin, array $filters): array
    {
        $query = DB::table('certificado.participacion as p')
            ->join('gestion.persona as persona', 'persona.id_persona', '=', 'p.id_persona')
            ->select('p.id_participacion', 'p.id_persona', 'p.id_actividad', 'p.estado', 'persona.nombres', 'persona.apellido_paterno', 'persona.apellido_materno')
            ->where('p.id_actividad', $context->id_actividad)->orderBy('p.id_participacion');
        if ($origin === 'sesion') {
            $query->join('certificado.actividad_sesion as s', 's.id_actividad', '=', 'p.id_actividad')
                ->where('s.id_actividad_sesion', $context->id_sesion)
                ->whereRaw("UPPER(TRIM(s.estado)) = 'FINALIZADA'")
                ->whereRaw("UPPER(TRIM(p.estado)) = 'ACTIVO'")
                ->whereExists(function (Builder $attendance): void {
                    $attendance->selectRaw('1')->from('certificado.asistencia as a')
                        ->whereColumn('a.id_participacion', 'p.id_participacion')
                        ->whereColumn('a.id_actividad_sesion', 's.id_actividad_sesion')
                        ->whereRaw("UPPER(TRIM(a.estado)) = 'PRESENTE'");
                });
        }
        $path = $origin === 'sesion' ? '/api/activity-session/'.$context->id_sesion.'/beneficiarios'
            : '/api/activity/'.$context->id_actividad.'/beneficiarios-certificacion';
        $page = $query->paginate($filters['per_page'] ?? 20, ['*'], 'page', $filters['page'] ?? 1)
            ->withPath($path)->appends($filters);
        $facts = $this->facts([(int) $context->id_actividad]);
        $page->setCollection($page->getCollection()->map(function (object $participant) use ($context, $origin, $filters, $facts): array {
            $evaluation = $this->evaluate($participant, $context, $origin, $filters['id_tipo_certificado'] ?? null, $facts);
            $attendance = $facts['attendances']->where('id_participacion', $participant->id_participacion);
            if ($origin === 'sesion') {
                $attendance = $attendance->where('id_actividad_sesion', $context->id_sesion);
            }
            $sessionCertificates = $origin === 'sesion' && $facts['identity_available']
                ? $facts['certificates']->where('id_participacion', $participant->id_participacion)->where('id_actividad_sesion', $context->id_sesion)
                    ->when(isset($filters['id_tipo_certificado']), fn (Collection $rows): Collection => $rows->where('id_tipo_certificado', $filters['id_tipo_certificado']))
                : collect();
            $certificate = $sessionCertificates->count() === 1 ? $sessionCertificates->first() : null;
            $document = $certificate ? $facts['documents']->firstWhere('id_certificado', $certificate->id_certificado) : null;

            return array_merge([
                'id_participacion' => (int) $participant->id_participacion, 'id_persona' => (int) $participant->id_persona,
                'nombre_completo' => trim(implode(' ', array_filter([$participant->nombres, $participant->apellido_paterno, $participant->apellido_materno]))),
                'id_actividad' => (int) $participant->id_actividad, 'id_sesion' => $context->id_sesion ?? null,
                'id_actividad_sesion' => $context->id_sesion ?? null,
                'nombre_sesion' => $context->nombre_sesion ?? null,
                'id_asistencia' => $origin === 'sesion' && $attendance->count() === 1 ? (int) $attendance->first()->id_asistencia : null,
                'estado_asistencia' => $origin === 'sesion' && $attendance->count() === 1 ? $attendance->first()->estado : null,
                'estado_inscripcion' => $participant->estado, 'asistencias' => $attendance->values()->all(),
                'id_tipo_certificado' => $filters['id_tipo_certificado'] ?? null,
                'certificados_registrados_participacion' => $facts['certificates']->where('id_participacion', $participant->id_participacion)->values()->all(),
                'id_preparacion' => null, 'estado_preparacion' => null,
                'certificado_sesion' => $certificate ? (int) $certificate->id_certificado : null,
                'certificados_sesion' => $sessionCertificates->values()->all(),
                'id_certificado' => $certificate ? (int) $certificate->id_certificado : null, 'estado_certificado' => $certificate?->estado,
                'estado_generacion' => $origin === 'sesion' && ! $facts['identity_available'] ? 'No determinable por esquema' : ($document ? 'Documento generado' : 'No generado'),
                'id_certificado_documento' => $document ? (int) $document->id_certificado_documento : null, 'estado_documento' => $document?->estado,
                'modo_elegibilidad' => $origin === 'sesion' && $this->usesDevelopmentPolicy() ? 'desarrollo_provisional' : 'institucional',
            ], $evaluation);
        }));

        $blockers = $this->preparationBlockers($origin);
        $compatible = $this->compatibleType($origin, $filters['id_tipo_certificado'] ?? null, $facts['types'], $facts['development_verified']);
        $eligible = $facts['participants']->filter(fn (object $participant): bool => $this->evaluate($participant, $context,
            $origin, $filters['id_tipo_certificado'] ?? null, $facts)['estado_elegibilidad'] === 'Elegible');
        $eligibleCount = $eligible->count();
        if ($origin === 'sesion' && $this->usesDevelopmentPolicy()) {
            if (strtoupper(trim($context->estado_sesion)) !== 'FINALIZADA') {
                $blockers[] = 'La sesión debe estar Finalizada para preparar certificados.';
            }
            if ($compatible !== true) {
                $blockers[] = 'Seleccione un tipo vigente autorizado para la preparación provisional por sesión.';
            }
            if ($eligibleCount === 0) {
                $blockers[] = 'No existen beneficiarios elegibles para preparar.';
            }
        }

        return [
            'contexto' => (array) $context, 'origen' => $origin, 'beneficiarios' => $page->toArray(),
            'estado' => $origin === 'sesion' ? $context->estado_sesion : $context->estado_actividad,
            'finalizada' => $origin === 'sesion' ? strtoupper(trim($context->estado_sesion)) === 'FINALIZADA' : null,
            'tipo_certificado_compatible' => $compatible,
            'total_elegibles' => $eligibleCount,
            'identidad_sesion_disponible' => $facts['identity_available'],
            'puede_emitir_demostracion' => $origin === 'sesion' && $facts['identity_available'] && $this->usesDevelopmentPolicy() && $blockers === [],
            'codigo_bloqueo_preparacion' => null,
            'preparaciones' => [],
            'modo_certificacion' => $origin === 'sesion' && $this->usesDevelopmentPolicy() ? 'desarrollo_provisional' : 'institucional',
            'puede_preparar' => $origin === 'sesion' && $this->usesDevelopmentPolicy() && $blockers === [],
            'puede_consultar_beneficiarios' => true,
            'preparacion_persistida' => false,
            'puede_generar_pdf' => false, 'puede_emitir' => false, 'puede_firmar' => false,
            'motivos_bloqueo' => $blockers,
            'motivos_bloqueo_emision' => $origin === 'sesion' && ! $facts['identity_available'] ? [self::SESSION_IDENTITY_BLOCKER, self::OFFICIAL_ISSUANCE_BLOCKER] : [self::OFFICIAL_ISSUANCE_BLOCKER],
        ];
    }

    private function compatibleType(string $origin, ?int $typeId, Collection $activeTypes, bool $developmentVerified = false): ?bool
    {
        if ($typeId === null) {
            return null;
        }
        if (! $activeTypes->contains($typeId)) {
            return false;
        }
        if ($origin === 'sesion' && $this->usesDevelopmentPolicy()) {
            return $developmentVerified && in_array($typeId, config('certification.development_session_type_ids', []), true);
        }
        $policy = $this->eligibilityPolicy($origin, $typeId, $developmentVerified);

        return $this->validPolicy($policy) && is_bool($policy['require_closed_activity'] ?? null)
            && ($origin !== 'actividad' || ($policy['required_sessions'] ?? null) === 'all') ? true : null;
    }

    /** @return list<string> */
    public function preparationBlockers(string $origin): array
    {
        if ($origin === 'sesion' && $this->usesDevelopmentPolicy()) {
            return $this->developmentClosureBlockers();
        }
        $reasons = ['No existe un procedimiento oficial integrado de preparación y emisión ni una política institucional configurada por defecto.'];
        if ($origin === 'sesion') {
            $reasons[] = 'El certificado no dispone de una relación persistente con la sesión; requiere extensión de esquema autorizada.';
        }

        return $reasons;
    }

    /** @param list<int> $activityIds
     * @param  list<int>|null  $participationIds
     * @return array{participants: Collection, sessions: Collection, attendances: Collection, certificates: Collection, types: Collection, authorized: Collection, development_verified: bool, development_blockers: list<string>, identity_available: bool, documents: Collection}
     */
    private function facts(array $activityIds, ?array $participationIds = null): array
    {
        $participants = DB::table('certificado.participacion')->whereIn('id_actividad', $activityIds)
            ->when($participationIds !== null, fn (Builder $query) => $query->whereIn('id_participacion', $participationIds))->get();
        $ids = $participants->pluck('id_participacion')->all();
        $developmentBlockers = $this->usesDevelopmentPolicy() ? $this->developmentClosureBlockers() : [];
        $identityAvailable = app(SessionCertificatePreparation::class)->identityAvailable();

        return [
            'participants' => $participants,
            'sessions' => DB::table('certificado.actividad_sesion')->whereIn('id_actividad', $activityIds)->get(),
            'attendances' => DB::table('certificado.asistencia')->whereIn('id_participacion', $ids)
                ->select('id_asistencia', 'id_participacion', 'id_actividad_sesion', 'estado', 'hora_entrada', 'hora_salida')->get(),
            'certificates' => DB::table('certificado.certificado')->whereIn('id_participacion', $ids)
                ->whereExists(function (Builder $participation): void {
                    $participation->selectRaw('1')->from('certificado.participacion as verified')
                        ->whereColumn('verified.id_participacion', 'certificado.certificado.id_participacion')
                        ->whereColumn('verified.id_persona', 'certificado.certificado.id_persona');
                })
                ->select('id_certificado', 'id_participacion', 'id_tipo_certificado', 'estado', 'fecha_emision')
                ->when($identityAvailable, fn (Builder $query) => $query->addSelect('id_actividad_sesion'))->get(),
            'types' => DB::table('certificado.tipo_certificado')->where('estado', true)->pluck('id_tipo_certificado'),
            'authorized' => DB::table('certificado.solicitud_actividad')->whereIn('id_actividad', $activityIds)->where('estado', 'Aprobada')->pluck('id_actividad'),
            'development_verified' => $this->usesDevelopmentPolicy() && $developmentBlockers === [],
            'development_blockers' => $developmentBlockers,
            'identity_available' => $identityAvailable,
            'documents' => $identityAvailable && Schema::hasTable('certificado.certificado_documento')
                ? DB::table('certificado.certificado_documento')->whereIn('id_certificado', DB::table('certificado.certificado')->select('id_certificado')->whereIn('id_participacion', $ids))
                    ->select('id_certificado_documento', 'id_certificado', 'estado')->orderByDesc('id_certificado_documento')->get() : collect(),
        ];
    }

    /** @param array<string, mixed> $facts
     * @return array{estado_elegibilidad: string, motivos: list<string>}
     */
    private function evaluate(object $participant, object $context, string $origin, ?int $typeId, array $facts): array
    {
        $pending = fn (string $reason): array => ['estado_elegibilidad' => 'Pendiente de validación', 'motivos' => [$reason]];
        $ineligible = fn (string $reason): array => ['estado_elegibilidad' => 'No elegible', 'motivos' => [$reason]];
        if ($origin === 'sesion' && $this->usesDevelopmentPolicy() && ! $facts['development_verified']) {
            return $pending(implode(' ', $facts['development_blockers']));
        }
        if (! $facts['authorized']->contains($context->id_actividad)) {
            return $pending('La actividad no tiene una solicitud aprobada vinculada.');
        }
        if ($origin === 'sesion' && strtoupper(trim($context->estado_sesion)) !== 'FINALIZADA') {
            return $ineligible('La sesión no está finalizada.');
        }
        if ($typeId === null) {
            return $pending('Falta seleccionar un tipo de certificado con política institucional aprobada.');
        }
        if (! $facts['types']->contains($typeId)) {
            return $ineligible('El tipo de certificado no está vigente.');
        }
        if ($origin === 'sesion' && $this->usesDevelopmentPolicy()
            && ! in_array($typeId, config('certification.development_session_type_ids', []), true)) {
            return $ineligible('El tipo no está autorizado para la certificación provisional por sesión.');
        }
        $policy = $this->eligibilityPolicy($origin, $typeId, $facts['development_verified']);
        if (! $this->validPolicy($policy) || ! is_bool($policy['require_closed_activity'] ?? null)
            || ($origin === 'actividad' && ($policy['required_sessions'] ?? null) !== 'all')) {
            return $pending('Falta la política aprobada de elegibilidad, alcance del tipo de certificado y tratamiento de regularizaciones.');
        }
        $normalize = fn (string $state): string => strtoupper(trim($state));
        if (! in_array($normalize($participant->estado), array_map($normalize, $policy['active_participation_states']), true)) {
            return $ineligible('La inscripción no está vigente según la política aprobada.');
        }
        if ($policy['require_closed_activity'] && $context->estado_actividad !== 'Cerrada') {
            return $pending('La política seleccionada exige el cierre global de la actividad.');
        }
        $sessions = $facts['sessions']->where('id_actividad', $context->id_actividad);
        if ($origin === 'sesion') {
            $sessions = $sessions->where('id_actividad_sesion', $context->id_sesion);
        }
        if ($sessions->isEmpty()) {
            return $pending('No existen sesiones requeridas que permitan evaluar el cumplimiento.');
        }
        $reasons = [];
        foreach ($sessions as $session) {
            if ($normalize($session->estado) !== 'FINALIZADA') {
                $reasons[] = 'Existen sesiones requeridas sin finalizar.';
            }
            $attendances = $facts['attendances']->where('id_participacion', $participant->id_participacion)->where('id_actividad_sesion', $session->id_actividad_sesion);
            if ($attendances->count() > 1) {
                return $pending('Existen asistencias duplicadas para la persona en la sesión.');
            }
            $attendance = $attendances->first();
            if (! $attendance) {
                $reasons[] = 'Falta registrar asistencia de una sesión requerida.';

                continue;
            }
            if (in_array($normalize($attendance->estado ?? ''), array_map($normalize, $policy['ineligible_attendance_states']), true)) {
                return $ineligible('La asistencia no cumple la política aprobada.');
            }
            if (! in_array($normalize($attendance->estado ?? ''), array_map($normalize, $policy['eligible_attendance_states']), true)) {
                $reasons[] = 'Estado de asistencia pendiente de validación o regularización.';
            }
            if (($policy['require_entry'] && $attendance->hora_entrada === null) || ($policy['require_exit'] && $attendance->hora_salida === null)) {
                $reasons[] = 'Falta entrada o salida exigida por la política aprobada.';
            }
        }

        return $reasons === [] ? ['estado_elegibilidad' => 'Elegible', 'motivos' => []]
            : ['estado_elegibilidad' => 'Pendiente de validación', 'motivos' => array_values(array_unique($reasons))];
    }

    private function validPolicy(mixed $policy): bool
    {
        return is_array($policy) && is_string($policy['reference'] ?? null) && trim($policy['reference']) !== ''
            && $this->validStates($policy['active_participation_states'] ?? null, false)
            && $this->validStates($policy['eligible_attendance_states'] ?? null, false)
            && $this->validStates($policy['ineligible_attendance_states'] ?? null, true)
            && array_intersect($policy['eligible_attendance_states'], $policy['ineligible_attendance_states']) === []
            && is_bool($policy['require_entry'] ?? null) && is_bool($policy['require_exit'] ?? null)
            && ($policy['regularizations'] ?? null) === 'not_applicable';
    }

    public function usesDevelopmentPolicy(): bool
    {
        return config('certification.development_closure_enabled') === true
            && config('certification.session_closure.scope') === 'development';
    }

    /** @return array<string, mixed>|null */
    private function eligibilityPolicy(string $origin, int $typeId, bool $developmentVerified): ?array
    {
        if ($origin === 'sesion' && $this->usesDevelopmentPolicy()) {
            if (! $developmentVerified || ! in_array($typeId, config('certification.development_session_type_ids', []), true)) {
                return null;
            }

            return [...config('certification.session_closure'), 'require_closed_activity' => false];
        }

        return config("certification.eligibility.{$origin}.{$typeId}");
    }

    /** @return array{compatible: ?bool, beneficiaries: Collection} */
    public function sessionPreparationCandidates(object $context, int $typeId): array
    {
        $facts = $this->facts([(int) $context->id_actividad]);

        return [
            'compatible' => $this->compatibleType('sesion', $typeId, $facts['types'], $facts['development_verified']),
            'beneficiaries' => $facts['participants']->map(fn (object $participant): array => [
                'id_participacion' => (int) $participant->id_participacion,
                'id_persona' => (int) $participant->id_persona,
                ...$this->evaluate($participant, $context, 'sesion', $typeId, $facts),
            ]),
        ];
    }

    public function assertDevelopmentClosureEnvironment(): void
    {
        abort_unless(config('certification.development_closure_enabled') === true
            && config('certification.session_closure.scope') === 'development', 409,
            'La generación provisional requiere habilitar explícitamente el entorno aislado de desarrollo.');
        $blockers = $this->developmentClosureBlockers();
        abort_if($blockers !== [], 409, implode(' ', $blockers));
    }

    /** @return list<string> */
    public function developmentClosureBlockers(): array
    {
        $enabled = config('certification.development_closure_enabled') === true;
        $developmentPolicy = config('certification.session_closure.scope') === 'development';
        if (! $enabled && ! $developmentPolicy) {
            return [];
        }
        if (! $enabled || ! $developmentPolicy || ! app()->environment('local', 'testing')) {
            return ['El cierre provisional solo se admite con activación explícita en local/testing aislado.'];
        }
        try {
            $authorized = config('certification.development_connection');
            if (is_array($authorized) && ($authorized['authorized'] ?? false) === true) {
                if (config('database.default') !== ($authorized['connection'] ?? null)) {
                    return ['La conexión actual no es la conexión de desarrollo autorizada.'];
                }
                $connection = DB::connection();
                if ($connection->getDriverName() !== 'pgsql' || $connection->getConfig('read') || $connection->getConfig('write')) {
                    return ['La conexión autorizada debe usar PostgreSQL sin redirecciones de lectura/escritura.'];
                }
                $identity = $connection->selectOne('SELECT current_database() AS database, current_user AS username, inet_server_addr()::text AS server_address, inet_server_port() AS server_port', [], false);
                foreach (['database', 'username', 'server_address', 'server_port'] as $field) {
                    if (! isset($authorized[$field]) || (string) $authorized[$field] === ''
                        || (string) $identity->$field !== (string) $authorized[$field]) {
                        return ['La identidad real de PostgreSQL no coincide con la conexión de desarrollo autorizada.'];
                    }
                }

                return [];
            }
            app(DevelopmentDatabase::class)->assertConnected();
            $connection = DB::connection();
            $configuration = $connection->getConfig();
            if (! empty($configuration['read']) || ! empty($configuration['write'])
                || ($configuration['host'] ?? null) !== config('database.connections.activity_requests_test.host')
                || ($configuration['database'] ?? null) !== 'certificados_morales_test'
                || ($configuration['username'] ?? null) !== 'certificados_morales_test_app') {
                return ['La conexión efectiva de desarrollo no coincide con la base exclusiva autorizada.'];
            }
            $marker = $connection->selectOne("SELECT shobj_description(oid, 'pg_database') AS marker FROM pg_database WHERE datname = current_database()", [], false);
            if (($marker->marker ?? null) !== 'gpc-isolated-session-closure:v1') {
                return ['La base no tiene la marca de aislamiento requerida para probar el cierre de sesiones.'];
            }
        } catch (Throwable) {
            return ['Cierre de desarrollo bloqueado: no se pudo verificar PostgreSQL local exclusivo y sin acceso institucional.'];
        }

        return [];
    }

    private function validStates(mixed $states, bool $allowEmpty): bool
    {
        if (! is_array($states) || ! array_is_list($states) || (! $allowEmpty && $states === [])) {
            return false;
        }
        foreach ($states as $state) {
            if (! is_string($state) || trim($state) === '' || mb_strlen($state) > 30) {
                return false;
            }
        }

        return true;
    }
}
