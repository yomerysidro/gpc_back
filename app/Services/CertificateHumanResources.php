<?php

namespace App\Services;

use App\Http\Data\ActivityRequests\ActivityRequestData;
use App\Models\Certificate\Certificate;
use App\Models\CertificateDocument\CertificateDocument;
use App\Models\User\User;
use App\Services\RequestSchema as Schema;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CertificateHumanResources
{
    public function __construct(private CertificateDocumentVersions $versions, private CertificateDocumentGenerator $generator,
        private CertificateDocumentWorkflow $workflow) {}

    /** @return array<string, mixed>|null */
    public static function delivery(object $document): ?array
    {
        return isset($document->gestion_entrega) ? json_decode($document->gestion_entrega, true, 512, JSON_THROW_ON_ERROR) : null;
    }

    /** @return list<int> */
    private function areas(User $actor): array
    {
        abort_unless($actor->estado && Gate::forUser($actor)->allows('viewAny', Certificate::class)
            && Gate::forUser($actor)->allows('viewAny', CertificateDocument::class), 403, 'No autorizado para consultar certificados.');
        $result = ActivityRequestData::authorizedAreas((int) $actor->id_persona);
        abort_unless($result['success'], $result['status'] ?? 500, 'No se pudo comprobar el ámbito autorizado.');
        $areas = $result['data']->pluck('id_area')->map(fn (mixed $id): int => (int) $id)->all();
        abort_if($areas === [], 403, 'No dispone de un área laboral vigente para consultar la bandeja.');

        return $areas;
    }

    private function availableRecords(bool $includeActivityDocuments = false): Builder
    {
        $speakerOrigins = Schema::hasColumn('certificado.reconocimiento', 'id_actividad_ponente');
        $recognitionPerson = $speakerOrigins ? 'COALESCE(ponente_rrhh.id_persona,rt.id_persona)' : 'rt.id_persona';
        $areaExpression = $speakerOrigins ? 'COALESCE(a.id_area,pr.id_area,actividad_ponente_rrhh.id_area,rt.id_area)' : 'COALESCE(a.id_area,pr.id_area,rt.id_area)';
        $query = DB::table('certificado.certificado_documento as d')
            ->join('certificado.certificado as c', 'c.id_certificado', '=', 'd.id_certificado')
            ->leftJoin('certificado.participacion as participante', 'participante.id_participacion', '=', 'c.id_participacion')
            ->join('gestion.persona as p', 'p.id_persona', '=', 'c.id_persona')
            ->join('certificado.tipo_certificado as t', 't.id_tipo_certificado', '=', 'c.id_tipo_certificado')
            ->leftJoin('certificado.actividad_sesion as s', 's.id_actividad_sesion', '=', 'c.id_actividad_sesion')
            ->leftJoin('certificado.actividad as a', 'a.id_actividad', '=', DB::raw('COALESCE(s.id_actividad, participante.id_actividad)'))
            ->leftJoin('gestion.practica as pr', 'pr.id_practica', '=', 'c.id_practica')
            ->leftJoin('gestion.persona_practicante as pp', 'pp.id_persona_practicante', '=', 'pr.id_persona_practicante')
            ->leftJoin('certificado.reconocimiento as r', 'r.id_reconocimiento', '=', 'c.id_reconocimiento')
            ->leftJoin('gestion.persona_trabajador as rt', 'rt.id_persona_trabajador', '=', 'r.id_persona_trabajador')
            ->when($speakerOrigins, fn (Builder $query) => $query
                ->leftJoin('certificado.actividad_ponente as vinculo_ponente_rrhh', 'vinculo_ponente_rrhh.id_actividad_ponente', '=', 'r.id_actividad_ponente')
                ->leftJoin('gestion.persona_ponente as ponente_rrhh', 'ponente_rrhh.id_persona_ponente', '=', 'vinculo_ponente_rrhh.id_persona_ponente')
                ->leftJoin('certificado.actividad as actividad_ponente_rrhh', 'actividad_ponente_rrhh.id_actividad', '=', 'vinculo_ponente_rrhh.id_actividad'))
            ->where(function (Builder $origins) use ($includeActivityDocuments, $recognitionPerson): void {
                $origins->where(fn (Builder $training) => $training->whereNotNull('c.id_participacion')
                    ->whereColumn('c.id_persona', 'participante.id_persona')
                    ->where(fn (Builder $scope) => $scope->whereColumn('participante.id_actividad', 's.id_actividad')
                        ->when($includeActivityDocuments, fn (Builder $scope) => $scope->orWhereNull('c.id_actividad_sesion')))
                    ->whereNull('c.id_practica')->whereNull('c.id_reconocimiento'))
                    ->orWhere(fn (Builder $recognition) => $recognition->whereNull('c.id_participacion')->whereNull('c.id_actividad_sesion')
                        ->whereNull('c.id_practica')->whereNotNull('c.id_reconocimiento')->whereColumn(DB::raw($recognitionPerson), 'c.id_persona'))
                    ->orWhere(fn (Builder $practice) => $practice->whereNull('c.id_participacion')->whereNull('c.id_actividad_sesion')
                        ->whereNull('c.id_reconocimiento')->whereNotNull('c.id_practica')->whereColumn('pp.id_persona', 'c.id_persona'));
            })->where('d.estado', 'Vigente')
            ->whereIn('c.estado', [CertificateManagement::PREPARED, CertificateManagement::PENDING])->whereNull('c.fecha_emision')
            ->whereNotExists(function (Builder $other): void {
                $other->selectRaw('1')->from('certificado.certificado_documento as otra')
                    ->whereColumn('otra.id_certificado', 'd.id_certificado')->where('otra.estado', 'Vigente')
                    ->whereColumn('otra.id_certificado_documento', '<>', 'd.id_certificado_documento');
            })
            ->select('d.*', 'c.id_persona', 'c.id_participacion', 'c.id_tipo_certificado', 'c.id_practica', 'c.id_reconocimiento',
                'c.estado as estado_certificado', 'c.fecha_emision', 't.nombre_tipo as tipo_certificado',
                'p.nombres', 'p.apellido_paterno', 'p.apellido_materno', 'a.id_actividad', 'a.nombre as actividad',
                's.id_actividad_sesion as id_sesion', 's.nombre as sesion', 's.fecha as fecha_sesion')
            ->selectRaw($areaExpression.' as id_area');

        return $query;
    }

    private function records(User $actor, bool $own = false): Builder
    {
        $areas = $own ? [] : $this->areas($actor);
        abort_unless($actor->estado && $actor->id_persona, 403);
        $query = $this->availableRecords();
        if (! $own) {
            $areaExpression = Schema::hasColumn('certificado.reconocimiento', 'id_actividad_ponente')
                ? 'COALESCE(a.id_area,pr.id_area,actividad_ponente_rrhh.id_area,rt.id_area)' : 'COALESCE(a.id_area,pr.id_area,rt.id_area)';

            return $query->whereIn(DB::raw($areaExpression), $areas);
        }
        $query->where('c.id_persona', $actor->id_persona);
        if (! Schema::hasColumn('certificado.certificado_documento', 'gestion_entrega')) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('d.gestion_entrega->id_persona', $actor->id_persona)
            ->whereIn('d.gestion_entrega->estado_administrativo', ['DISPONIBLE_ENTREGA', 'ENTREGADO']);
    }

    /** @return \Generator<int, object> */
    public function publicConfirmedDocuments(int $personId, ?string $code = null): \Generator
    {
        $query = $this->availableRecords(includeActivityDocuments: true)->where('c.id_persona', $personId)
            ->where('c.estado', CertificateManagement::PENDING)
            ->when($code !== null, fn (Builder $query) => $query->where('d.codigo_verificacion', $code))
            ->orderByRaw('CASE WHEN d.fecha_generacion IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('d.fecha_generacion')
            ->orderByDesc('d.id_certificado_documento');
        foreach ($query->lazy(100) as $document) {
            try {
                $confirmed = $this->confirmed($document);
                if ($confirmed !== null) {
                    yield $confirmed;
                }
            } catch (HttpException) {
                continue;
            }
        }
    }

    /** @param array<string, int> $filters */
    private function filtered(User $actor, array $filters, bool $own = false): Builder
    {
        $query = $this->records($actor, $own);
        foreach (['id_actividad' => 'a.id_actividad', 'id_sesion' => 's.id_actividad_sesion', 'id_persona' => 'c.id_persona'] as $key => $column) {
            if (isset($filters[$key])) {
                if ($key === 'id_actividad') {
                    $this->filterActivityCandidates($query, (int) $filters[$key]);
                } elseif ($key === 'id_sesion') {
                    $query->where(fn (Builder $scope) => $scope->where($column, $filters[$key])->orWhereNotNull('c.id_reconocimiento'));
                } else {
                    $query->where($column, $filters[$key]);
                }
            }
        }

        return $query->orderBy('a.id_actividad')->orderBy('s.id_actividad_sesion')->orderBy('d.id_certificado_documento');
    }

    private function filterActivityCandidates(Builder $query, int $id): void
    {
        $query->where(fn (Builder $activity) => $activity->where('a.id_actividad', $id)->orWhereNotNull('c.id_reconocimiento'));
    }

    private function matchesActivity(object $document, ?int $id): bool
    {
        return $id === null || (int) ($this->present($document)['id_actividad'] ?? 0) === $id;
    }

    /** @param list<array<string, mixed>>|null $signers */
    private function confirmed(object $document, ?array $signers = null, ?object $verifiedArtifact = null): ?object
    {
        if ($document->estado !== 'Vigente' || $document->fecha_emision !== null) {
            return null;
        }
        $signers ??= $this->administrativeSigners([(int) $document->id_certificado_documento])[(int) $document->id_certificado_documento] ?? [];
        if ($signers === [] || ! in_array($document->modalidad_firma ?? null, ['PARALELA', 'SECUENCIAL'], true)
            || array_column($signers, 'orden_firma') !== range(1, count($signers))
            || count(array_unique(array_column($signers, 'id_persona'))) !== count($signers)) {
            return null;
        }
        $resolved = $verifiedArtifact ?? $this->versions->resolve($document, readOnly: true);
        $metadata = json_decode($document->motivo_reemision ?? '', true);
        $plan = $resolved->plan_pdf_aprobado ?? ((is_array($metadata) && ($metadata['v'] ?? null) === 0) ? ($metadata['p'] ?? null) : null);
        if (! is_string($plan) || ! hash_equals($plan, CertificateSignerRevision::planHash($signers, $document->modalidad_firma))) {
            return null;
        }
        if ($verifiedArtifact === null) {
            $this->generator->assertArtifact($document);
            $this->generator->assertArtifact($resolved);
        }
        $resolved->preparado_entrega = true;
        $resolved->responsables_administrativos = $signers;

        return $resolved;
    }

    /** @param list<int> $ids
     * @return array<int, list<array<string, mixed>>>
     */
    private function administrativeSigners(array $ids): array
    {
        $worker = Schema::hasColumn('certificado.certificado_firma', 'id_persona_trabajador')
            ? DB::raw('COALESCE(firma.id_persona_trabajador, credencial.id_persona_trabajador)') : 'credencial.id_persona_trabajador';

        return DB::table('certificado.certificado_firma as firma')
            ->leftJoin('certificado.firma as credencial', 'credencial.id_firma', '=', 'firma.id_firma')
            ->join('gestion.persona_trabajador as trabajador', 'trabajador.id_persona_trabajador', '=', $worker)
            ->join('gestion.persona as persona', 'persona.id_persona', '=', 'trabajador.id_persona')
            ->whereIn('firma.id_certificado_documento', $ids)->orderBy('firma.orden_firma')
            ->get(['firma.id_certificado_documento', 'firma.orden_firma', 'trabajador.id_persona_trabajador',
                'trabajador.id_persona', 'trabajador.cargo', 'persona.nombres', 'persona.apellido_paterno', 'persona.apellido_materno'])
            ->groupBy('id_certificado_documento')->map(fn (Collection $group): array => $group->map(fn (object $signer): array => [
                'id_persona_trabajador' => (int) $signer->id_persona_trabajador, 'id_persona' => (int) $signer->id_persona,
                'nombre' => trim(implode(' ', array_filter([$signer->nombres, $signer->apellido_paterno, $signer->apellido_materno]))),
                'cargo' => $signer->cargo, 'orden_firma' => (int) $signer->orden_firma,
            ])->all())->all();
    }

    /** @return \Generator<int, object> */
    private function rows(Builder $query, bool $historical = false, bool $includePending = false): \Generator
    {
        foreach ($query->lazy(100)->chunk(100) as $chunk) {
            $documents = collect($chunk->all());
            $plans = $this->administrativeSigners($documents->pluck('id_certificado_documento')->map(fn (mixed $id): int => (int) $id)->all());
            $currentCounts = DB::table('certificado.certificado_documento')->whereIn('id_certificado', $documents->pluck('id_certificado'))
                ->where('estado', 'Vigente')->groupBy('id_certificado')->selectRaw('id_certificado, COUNT(*) as total')->pluck('total', 'id_certificado');
            foreach ($documents as $document) {
                if ($document->id_certificado_documento === null) {
                    if ($historical && ! in_array($document->estado_certificado,
                        [CertificateManagement::PREPARED, CertificateManagement::PENDING, CertificateManagement::SIGNED], true)) {
                        yield $document;
                    }

                    continue;
                }
                $verifiedArtifact = null;
                if ($historical) {
                    try {
                        if ($document->estado === 'Vigente') {
                            $verifiedArtifact = $this->generator->authorizedArtifact($document, readOnly: true);
                            $verifiedArtifact->integridad_verificada = true;
                        } else {
                            $document = $this->versions->resolve($document, readOnly: true);
                        }
                    } catch (HttpException $exception) {
                        if ($exception->getStatusCode() !== 409) {
                            throw $exception;
                        }
                        $document->integridad_verificada = false;
                        yield $document;

                        continue;
                    }
                }
                if (in_array($document->estado_certificado, [CertificateManagement::PREPARED, CertificateManagement::PENDING], true)) {
                    if ((int) $currentCounts->get($document->id_certificado, 0) !== 1) {
                        continue;
                    }
                    if ($includePending && ($document->estado_certificado !== CertificateManagement::PENDING
                        || $document->estado !== 'Vigente' || $document->fecha_emision !== null)) {
                        continue;
                    }
                    $confirmed = $this->confirmed($document, $plans[(int) $document->id_certificado_documento] ?? [], $verifiedArtifact);
                    if ($confirmed !== null) {
                        yield $confirmed;
                    } elseif ($includePending) {
                        yield $verifiedArtifact ?? $this->generator->authorizedArtifact($document, readOnly: true);
                    }
                } elseif ($historical) {
                    yield $verifiedArtifact ?? $document;
                }
            }
        }
    }

    /** @return array<string, mixed> */
    public function activities(User $actor, int $page): array
    {
        $activities = [];
        foreach ($this->rows($this->filtered($actor, [])) as $document) {
            if ($document->id_actividad === null) {
                continue;
            }
            $id = (int) $document->id_actividad;
            $activities[$id] ??= ['id_actividad' => $id, 'actividad' => $document->actividad,
                'id_area' => (int) $document->id_area, 'total_certificados' => 0, 'sesiones' => []];
            $activities[$id]['total_certificados']++;
            $activities[$id]['sesiones'][(int) $document->id_sesion] = true;
        }
        $items = collect($activities)->map(function (array $activity): array {
            $activity['total_sesiones'] = count($activity['sesiones']);
            unset($activity['sesiones']);

            return $activity;
        })->values();

        return $this->paginate($items, $page);
    }

    /** @return array<string, mixed> */
    public function sessions(User $actor, int $activity, int $page): array
    {
        abort_unless($this->records($actor)->where('a.id_actividad', $activity)->exists(), 404, 'Actividad no encontrada en su ámbito.');
        $sessions = [];
        foreach ($this->rows($this->filtered($actor, ['id_actividad' => $activity])) as $document) {
            $id = (int) $document->id_sesion;
            $sessions[$id] ??= ['id_sesion' => $id, 'id_actividad' => $activity,
                'sesion' => $document->sesion, 'fecha_sesion' => $document->fecha_sesion, 'total_certificados' => 0];
            $sessions[$id]['total_certificados']++;
        }

        return $this->paginate(collect($sessions)->values(), $page);
    }

    /** @param array<string, int> $filters
     * @return array<string, mixed>
     */
    public function certificates(User $actor, int $session, array $filters): array
    {
        abort_unless($this->records($actor)->where('s.id_actividad_sesion', $session)->exists(), 404, 'Sesión no encontrada en su ámbito.');

        return $this->pageDocuments($actor, array_replace($filters, ['id_sesion' => $session]));
    }

    /** @param array<string, int> $filters
     * @return array<string, mixed>
     */
    private function pageDocuments(User $actor, array $filters, bool $own = false): array
    {
        $page = (int) ($filters['page'] ?? 1);
        $offset = ($page - 1) * 50;
        $total = 0;
        $items = [];
        foreach ($this->rows($this->filtered($actor, $filters, $own)) as $confirmed) {
            if (isset($filters['id_sesion']) && (int) $this->present($confirmed)['id_sesion'] !== (int) $filters['id_sesion']) {
                continue;
            }
            if (! $this->matchesActivity($confirmed, isset($filters['id_actividad']) ? (int) $filters['id_actividad'] : null)) {
                continue;
            }
            if ($total >= $offset && count($items) < 50) {
                $items[] = $this->present($confirmed);
            }
            $total++;
        }

        return ['data' => $items, 'current_page' => $page, 'per_page' => 50, 'total' => $total,
            'last_page' => max(1, (int) ceil($total / 50))];
    }

    /** @param array<string, int> $filters
     * @return array<string, mixed>
     */
    public function inbox(User $actor, array $filters, bool $own = false): array
    {
        $page = $this->pageDocuments($actor, $filters, $own);
        $activities = collect($page['data'])->whereNotNull('id_actividad')->whereNotNull('id_participacion')->groupBy('id_actividad')->map(fn (Collection $activity): array => [
            'id_actividad' => $activity->first()['id_actividad'], 'actividad' => $activity->first()['actividad'],
            'sesiones' => $activity->groupBy('id_sesion')->map(fn (Collection $session): array => [
                'id_sesion' => $session->first()['id_sesion'], 'sesion' => $session->first()['sesion'],
                'fecha_sesion' => $session->first()['fecha_sesion'], 'certificados' => $session->values()->all(),
            ])->values()->all(),
        ])->values()->all();

        $otherOrigins = collect($page['data'])->whereNull('id_participacion')->values()->all();

        return ['actividades' => $activities, 'pagina' => $page['current_page'], 'total' => $page['total'], 'por_pagina' => 50,
            ...($otherOrigins === [] ? [] : ['otros_origenes' => $otherOrigins])];
    }

    public function document(int $id, User $actor, bool $own = false): object
    {
        $document = $this->records($actor, $own)->where('d.id_certificado_documento', $id)->first();
        abort_unless($document, 404, 'Documento no encontrado en su ámbito.');
        $confirmed = $this->confirmed($document);
        abort_unless($confirmed, 409, 'El PDF todavía no tiene una preparación confirmada.');

        return $confirmed;
    }

    /** @return array<string, mixed> */
    public function detail(int $id, User $actor): array
    {
        return $this->present($this->document($id, $actor));
    }

    /** @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function catalog(User $actor, array $filters, bool $certificates = false): array
    {
        $query = $certificates ? $this->workflow->readableCertificates($actor) : $this->workflow->readableDocuments($actor);
        foreach (['id_actividad' => 'a.id_actividad', 'id_sesion' => 'sesion.id_actividad_sesion',
            'id_persona' => 'c.id_persona', 'id_certificado' => 'c.id_certificado',
            'id_tipo_certificado' => 'c.id_tipo_certificado', 'id_participacion' => 'c.id_participacion',
            'id_practica' => 'c.id_practica', 'id_reconocimiento' => 'c.id_reconocimiento',
            'codigo_verificacion' => 'd.codigo_verificacion', 'fecha_generacion' => 'd.fecha_generacion',
            'fecha_emision' => 'c.fecha_emision', 'notificado' => 'c.notificado',
            'fecha_notificacion' => 'c.fecha_notificacion', 'motivo_reemision' => 'd.motivo_reemision'] as $field => $column) {
            if (array_key_exists($field, $filters)) {
                if ($field === 'id_actividad') {
                    $this->filterActivityCandidates($query, (int) $filters[$field]);
                } elseif ($field === 'id_sesion') {
                    $query->where(fn (Builder $scope) => $scope->where($column, $filters[$field])->orWhereNotNull('c.id_reconocimiento'));
                } else {
                    $query->where($column, $filters[$field]);
                }
            }
        }
        if (isset($filters['estado']) && $filters['estado'] !== 'PREPARADO_ENTREGA') {
            $query->where($certificates ? 'c.estado' : 'd.estado', $filters['estado']);
        }
        if (isset($filters['estado_certificado'])) {
            $query->where('c.estado', $filters['estado_certificado']);
        }
        $page = (int) ($filters['page'] ?? 1);
        $perPage = (int) ($filters['per_page'] ?? 20);
        $onlyPrepared = ($filters['estado'] ?? null) === 'PREPARADO_ENTREGA'
            || ($filters['estado_preparacion'] ?? null) === 'PREPARADO_ENTREGA';
        $items = [];
        $seen = [];
        $total = 0;
        foreach ($this->rows($query->orderByDesc('d.id_certificado_documento')->orderByDesc('c.id_certificado'), historical: true, includePending: true) as $document) {
            if (isset($filters['id_sesion']) && (int) $this->present($document)['id_sesion'] !== (int) $filters['id_sesion']) {
                continue;
            }
            if (! $this->matchesActivity($document, isset($filters['id_actividad']) ? (int) $filters['id_actividad'] : null)) {
                continue;
            }
            if ($document->estado_certificado === CertificateManagement::SIGNED
                || ($onlyPrepared && ! ($document->preparado_entrega ?? false))) {
                continue;
            }
            if ($certificates && isset($seen[$document->id_certificado])) {
                continue;
            }
            $seen[$document->id_certificado] = true;
            if ($total >= ($page - 1) * $perPage && count($items) < $perPage) {
                $items[] = $certificates ? $this->certificateRow($document) : $this->present($document);
            }
            $total++;
        }

        return ['data' => $items, 'current_page' => $page, 'per_page' => $perPage, 'total' => $total,
            'last_page' => max(1, (int) ceil($total / $perPage)),
            'from' => $items === [] ? null : ($page - 1) * $perPage + 1,
            'to' => $items === [] ? null : ($page - 1) * $perPage + count($items)];
    }

    /** @return array<string, mixed> */
    public function preparedCertificate(int $id, User $actor): array
    {
        $query = $this->workflow->readableDocuments($actor)->where('c.id_certificado', $id)
            ->whereIn('c.estado', [CertificateManagement::PREPARED, CertificateManagement::PENDING]);
        foreach ($this->rows($query->orderByDesc('d.id_certificado_documento'), includePending: true) as $document) {
            return $this->certificateRow($document);
        }
        abort(404, 'Certificado preparado no encontrado en su ámbito.');
    }

    /** @return array<string, mixed> */
    private function certificateRow(object $document): array
    {
        return array_replace($this->present($document), ['estado' => $document->estado_certificado,
            'id_practica' => $document->id_practica ?? null, 'id_reconocimiento' => $document->id_reconocimiento ?? null,
            'id_actividad_sesion' => $document->id_sesion ?? null,
            'notificado' => (bool) ($document->notificado ?? false), 'fecha_notificacion' => $document->fecha_notificacion ?? null]);
    }

    public function artifact(int $id, User $actor, bool $own = false): object
    {
        return $this->generator->authorizedArtifact($this->document($id, $actor, $own), readOnly: true);
    }

    /** @return array<string, mixed> */
    public function send(int $id, int $destination, string $revision, User $actor): array
    {
        $this->areas($actor);
        abort(409, 'La bandeja es de solo lectura. No se registran envíos; consulte el certificado existente.');
    }

    /** @return array<string, mixed> */
    public function transition(int $id, User $actor, string $target): array
    {
        $this->document($id, $actor);
        abort(409, 'No existe un registro de recepción o entrega habilitado. No se modificó el certificado.');
    }

    /** @return array<string, mixed> */
    public function history(int $id, User $actor): array
    {
        $this->document($id, $actor);

        return ['disponible' => false, 'historial' => [], 'motivo' => 'No existe un historial de recepción o entrega registrado.'];
    }

    /** @param Collection<int, array<string, mixed>> $items
     * @return array<string, mixed>
     */
    private function paginate(Collection $items, int $page): array
    {
        return ['data' => $items->forPage($page, 50)->values()->all(), 'current_page' => $page,
            'per_page' => 50, 'total' => $items->count(), 'last_page' => max(1, (int) ceil($items->count() / 50))];
    }

    /** @return array<string, mixed> */
    private function present(object $document): array
    {
        $prepared = (bool) ($document->preparado_entrega ?? false);
        $integrity = $document->integridad_verificada ?? ($prepared ? true : null);
        $originData = [];
        $activityId = $document->id_actividad ?? null;
        $activityName = $document->actividad ?? null;
        if ($document->id_participacion === null && (($document->id_reconocimiento ?? null) !== null || ($document->id_practica ?? null) !== null)) {
            try {
                $documentContent = $this->versions->approvedContent($document);
                $approvedContext = $documentContent['context'];
                $approved = $approvedContext['datos_origen'] ?? [];
            } catch (HttpException) {
                $approved = [];
                $approvedContext = [];
            }
            $originData = ['origen' => $document->id_reconocimiento !== null ? 'reconocimiento' : 'practica',
                'id_reconocimiento' => $document->id_reconocimiento, 'id_practica' => $document->id_practica,
                'titulo_reconocimiento' => $approved['titulo'] ?? null, 'motivo' => $approved['motivo'] ?? null,
                'fecha_inicio_practica' => $approved['fecha_inicio'] ?? null, 'fecha_fin_practica' => $approved['fecha_fin'] ?? null];
            if (in_array($approvedContext['origen'] ?? null, ['ponente_actividad', 'ponente_sesion'], true)) {
                $activityId = $approvedContext['id_actividad'];
                $activityName = $approvedContext['actividad'];
                $originData['origen'] = $approvedContext['origen'];
                $originData['modalidad_reconocimiento'] = $approvedContext['modalidad_reconocimiento'];
                $originData['id_actividad_ponente'] = $approvedContext['id_actividad_ponente'];
                $originData['motivo'] = app(CertificateOrigins::class)->message((object) $approvedContext);
                $originData = array_replace($originData, app(CertificateOrigins::class)->savedSessionRecognitionText($documentContent));
            }
        }

        return [...$originData, 'id_certificado' => (int) $document->id_certificado,
            'id_certificado_documento' => $document->id_certificado_documento === null ? null : (int) $document->id_certificado_documento,
            'id_persona' => (int) $document->id_persona, 'id_participacion' => $document->id_participacion === null ? null : (int) $document->id_participacion,
            'nombre_completo' => trim(implode(' ', array_filter([$document->nombres, $document->apellido_paterno, $document->apellido_materno]))),
            'id_actividad' => $activityId === null ? null : (int) $activityId, 'actividad' => $activityName,
            'id_sesion' => isset($document->id_sesion) ? (int) $document->id_sesion : ($approvedContext['id_sesion'] ?? null),
            'sesion' => $document->sesion ?? ($approvedContext['sesion'] ?? null), 'fecha_sesion' => $document->fecha_sesion ?? ($approvedContext['fecha_sesion'] ?? null),
            'id_tipo_certificado' => (int) $document->id_tipo_certificado, 'tipo_certificado' => $document->tipo_certificado,
            'codigo_verificacion' => $document->codigo_verificacion,
            'hash_pdf' => $document->hash_certificado, 'hash_pdf_original' => $document->hash_certificado_original ?? $document->hash_certificado,
            'revision_pdf' => $document->revision_pdf ?? null, 'pdf_confirmado' => $prepared,
            'estado' => $document->estado, 'fecha_generacion' => $document->fecha_generacion,
            'hash_certificado' => $document->hash_certificado, 'motivo_reemision' => $document->motivo_reemision ?? null,
            'preparado_para_entrega' => $prepared, 'disponible_rrhh' => $prepared,
            'integridad_verificada' => $integrity,
            'estado_preparacion' => $prepared ? 'PREPARADO_ENTREGA' : null,
            'etiqueta_estado' => match (true) {
                $integrity === false => 'Documento no disponible',
                $prepared => 'Listo para entrega',
                $document->estado_certificado === CertificateManagement::PENDING => 'Pendiente de firma',
                default => $document->estado,
            },
            'responsables_administrativos' => $document->responsables_administrativos ?? [],
            'estado_documental' => $document->estado, 'estado_certificado' => $document->estado_certificado,
            'estado_firma' => $document->estado_certificado, 'fecha_emision' => $document->fecha_emision,
            'firma_verificada' => false, 'emision_oficial_verificada' => false, 'registro_entrega_disponible' => false];
    }
}
