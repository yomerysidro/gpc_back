<?php

namespace App\Services;

use App\Http\Data\ActivityRequests\ActivityRequestData;
use App\Models\Certificate\Certificate;
use App\Models\CertificateDocument\CertificateDocument;
use App\Models\User\User;
use App\Services\RequestSchema as Schema;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CertificateDocumentWorkflow
{
    public function __construct(private CertificateDocumentGenerator $generator) {}

    private function authorize(User $actor): void
    {
        abort_unless(Gate::forUser($actor)->allows('viewAny', Certificate::class)
            && Gate::forUser($actor)->allows('viewAny', CertificateDocument::class), 403, 'No autorizado para consultar documentos.');
    }

    /** @return list<int>|null */
    private function areas(User $actor): ?array
    {
        if ($actor->isSystemAdministrator()) {
            return null;
        }
        $result = ActivityRequestData::authorizedAreas((int) $actor->id_persona);
        abort_unless($result['success'], $result['status'] ?? 500, 'No se pudo comprobar el ámbito autorizado.');

        return $result['data']->pluck('id_area')->map(fn (mixed $id): int => (int) $id)->all();
    }

    public function readableDocuments(User $actor): Builder
    {
        $this->authorize($actor);

        return $this->documents($actor);
    }

    public function readableCertificates(User $actor): Builder
    {
        $this->authorize($actor);

        return $this->documents($actor, includeWithoutDocument: true);
    }

    private function documents(User $actor, bool $includeWithoutDocument = false): Builder
    {
        $areas = $this->areas($actor);
        $speakerOrigins = Schema::hasColumn('certificado.reconocimiento', 'id_actividad_ponente');
        $sessionSpeakerOrigins = $speakerOrigins && Schema::hasColumn('certificado.actividad_ponente', 'id_actividad_sesion');
        $recognitionPerson = $speakerOrigins ? 'COALESCE(ponente.id_persona,trabajador.id_persona)' : 'trabajador.id_persona';
        $areaExpression = $speakerOrigins ? 'COALESCE(a.id_area,pr.id_area,actividad_ponente.id_area,trabajador.id_area)' : 'COALESCE(a.id_area,pr.id_area,trabajador.id_area)';

        $query = $includeWithoutDocument
            ? DB::table('certificado.certificado as c')->leftJoin('certificado.certificado_documento as d', 'd.id_certificado', '=', 'c.id_certificado')
            : DB::table('certificado.certificado_documento as d')->join('certificado.certificado as c', 'c.id_certificado', '=', 'd.id_certificado');
        $query->join('gestion.persona as beneficiario', 'beneficiario.id_persona', '=', 'c.id_persona')
            ->join('certificado.tipo_certificado as tipo', 'tipo.id_tipo_certificado', '=', 'c.id_tipo_certificado')
            ->leftJoin('certificado.participacion as p', 'p.id_participacion', '=', 'c.id_participacion')
            ->leftJoin('certificado.actividad as a', 'a.id_actividad', '=', 'p.id_actividad')
            ->leftJoin('gestion.practica as pr', 'pr.id_practica', '=', 'c.id_practica')
            ->leftJoin('gestion.persona_practicante as practicante', 'practicante.id_persona_practicante', '=', 'pr.id_persona_practicante')
            ->leftJoin('certificado.reconocimiento as r', 'r.id_reconocimiento', '=', 'c.id_reconocimiento')
            ->leftJoin('gestion.persona_trabajador as trabajador', 'trabajador.id_persona_trabajador', '=', 'r.id_persona_trabajador')
            ->when($speakerOrigins, fn (Builder $query) => $query
                ->leftJoin('certificado.actividad_ponente as vinculo_ponente', 'vinculo_ponente.id_actividad_ponente', '=', 'r.id_actividad_ponente')
                ->leftJoin('gestion.persona_ponente as ponente', 'ponente.id_persona_ponente', '=', 'vinculo_ponente.id_persona_ponente')
                ->leftJoin('certificado.actividad as actividad_ponente', 'actividad_ponente.id_actividad', '=', 'vinculo_ponente.id_actividad'))
            ->when($sessionSpeakerOrigins, fn (Builder $query) => $query
                ->leftJoin('certificado.actividad_sesion as sesion_ponente', 'sesion_ponente.id_actividad_sesion', '=', 'vinculo_ponente.id_actividad_sesion')
                ->where(fn (Builder $context) => $context->whereNull('vinculo_ponente.id_actividad_sesion')
                    ->orWhereColumn('sesion_ponente.id_actividad', 'vinculo_ponente.id_actividad')))
            ->where(function (Builder $query) use ($recognitionPerson): void {
                $query->where(function (Builder $activity): void {
                    $activity->whereNotNull('c.id_participacion')->whereNull('c.id_practica')->whereNull('c.id_reconocimiento')
                        ->whereColumn('p.id_persona', 'c.id_persona');
                })->orWhere(function (Builder $practice): void {
                    $practice->whereNotNull('c.id_practica')->whereNull('c.id_participacion')->whereNull('c.id_reconocimiento')
                        ->whereColumn('practicante.id_persona', 'c.id_persona');
                })->orWhere(function (Builder $recognition) use ($recognitionPerson): void {
                    $recognition->whereNotNull('c.id_reconocimiento')->whereNull('c.id_participacion')->whereNull('c.id_practica')
                        ->whereColumn(DB::raw($recognitionPerson), 'c.id_persona');
                });
            })
            ->when($areas !== null, fn (Builder $query) => $query->whereIn(DB::raw($areaExpression), $areas))
            ->select('d.*', 'c.id_certificado', 'c.id_persona', 'c.id_tipo_certificado', 'c.id_participacion', 'c.id_practica', 'c.id_reconocimiento',
                'c.estado as estado_certificado', 'c.fecha_emision', 'c.notificado', 'c.fecha_notificacion',
                'tipo.nombre_tipo as tipo_certificado', 'tipo.estado as tipo_activo', 'beneficiario.nombres', 'beneficiario.apellido_paterno', 'beneficiario.apellido_materno')
            ->selectRaw($areaExpression.' as id_area')
            ->selectRaw(($speakerOrigins ? 'COALESCE(a.id_actividad,actividad_ponente.id_actividad)' : 'a.id_actividad').' as id_actividad')
            ->selectRaw(($speakerOrigins ? 'COALESCE(a.nombre,actividad_ponente.nombre)' : 'a.nombre').' as actividad');
        if (Schema::hasColumn('certificado.certificado', 'id_actividad_sesion')) {
            $query->leftJoin('certificado.actividad_sesion as sesion', 'sesion.id_actividad_sesion', '=', 'c.id_actividad_sesion')
                ->leftJoin('certificado.asistencia as asistencia', function (JoinClause $join): void {
                    $join->on('asistencia.id_participacion', '=', 'c.id_participacion')->on('asistencia.id_actividad_sesion', '=', 'c.id_actividad_sesion');
                })
                ->where(fn (Builder $context) => $context->whereNull('c.id_actividad_sesion')->orWhereColumn('sesion.id_actividad', 'p.id_actividad'))
                ->addSelect('c.id_actividad_sesion as id_sesion', 'sesion.nombre as sesion', 'sesion.fecha as fecha_sesion',
                    'sesion.estado as estado_sesion', 'p.estado as estado_participacion', 'asistencia.estado as estado_asistencia');
        }

        return $query;
    }

    public function document(int $id, User $actor): object
    {
        $this->authorize($actor);
        $key = self::class.'.document.'.hash('sha256', serialize([$id, $actor->getAuthIdentifier(),
            $actor->id_persona, $actor->estado, config('module_permissions')]));
        $document = RequestReadMemo::remember($key,
            fn (): ?object => $this->documents($actor)->where('d.id_certificado_documento', $id)->first());
        abort_unless($document, 404, 'Documento no encontrado en su ámbito.');
        abort_unless(Gate::forUser($actor)->allows('view', (new CertificateDocument)->newFromBuilder((array) $document)), 403, 'No autorizado.');

        return app(CertificateDocumentVersions::class)->resolve($document, readOnly: true);
    }

    /** @param Collection<int, object> $documents
     * @return array<int, array<string, mixed>>
     */
    private function describe(Collection $documents): array
    {
        $activityIds = $documents->pluck('id_actividad')->filter()->unique()->all();
        $assignments = DB::table('certificado.actividad_firma as af')
            ->join('certificado.firma as f', 'f.id_firma', '=', 'af.id_firma')
            ->join('gestion.persona_trabajador as t', 't.id_persona_trabajador', '=', 'f.id_persona_trabajador')
            ->join('gestion.persona as persona', 'persona.id_persona', '=', 't.id_persona')
            ->whereIn('af.id_actividad', $activityIds)->orderBy('af.orden_firma')->orderBy('af.id_actividad_firma')
            ->get(['af.id_actividad', 'af.orden_firma', 'f.id_firma', 't.id_persona', 't.cargo', 't.estado as trabajador_activo',
                't.fecha_ingreso', 't.fecha_fin', 'persona.nombres', 'persona.apellido_paterno', 'persona.apellido_materno'])
            ->groupBy('id_actividad');
        $worker = Schema::hasColumn('certificado.certificado_firma', 'id_persona_trabajador')
            ? DB::raw('COALESCE(cf.id_persona_trabajador, f.id_persona_trabajador)') : 'f.id_persona_trabajador';
        $registered = DB::table('certificado.certificado_firma as cf')
            ->leftJoin('certificado.firma as f', 'f.id_firma', '=', 'cf.id_firma')
            ->join('gestion.persona_trabajador as t', 't.id_persona_trabajador', '=', $worker)
            ->join('gestion.persona as persona', 'persona.id_persona', '=', 't.id_persona')
            ->whereIn('cf.id_certificado_documento', $documents->pluck('id_certificado_documento'))
            ->orderBy('cf.orden_firma')->orderBy('cf.id_certificado_firma')
            ->get(['cf.*', 't.id_persona_trabajador', 't.id_persona', 't.cargo', 't.estado as trabajador_activo',
                't.fecha_ingreso', 't.fecha_fin', 'persona.nombres', 'persona.apellido_paterno', 'persona.apellido_materno'])
            ->groupBy('id_certificado_documento');
        $result = [];
        foreach ($documents as $document) {
            $id = (int) $document->id_certificado_documento;
            $access = $this->administrativeFileAccess($document);
            $applications = $registered->get($id, collect());
            $signers = $assignments->get($document->id_actividad, collect())->map(function (object $signer) use ($applications): array {
                $application = $applications->firstWhere('id_firma', $signer->id_firma);

                return ['id_firma' => (int) $signer->id_firma, 'id_persona' => (int) $signer->id_persona,
                    'nombre' => $this->name($signer), 'cargo' => $signer->cargo, 'orden_firma' => (int) $signer->orden_firma,
                    'configuracion_vigente' => (bool) $signer->trabajador_activo && $signer->fecha_ingreso <= today()->toDateString()
                        && ($signer->fecha_fin === null || $signer->fecha_fin >= today()->toDateString()),
                    'estado' => $application?->fecha_firma !== null ? 'Registrada' : 'Pendiente', 'fecha_firma' => $application?->fecha_firma,
                    'firma_verificada' => false];
            })->values();
            if ($applications->isNotEmpty() && (($document->modalidad_firma ?? null) !== null || $signers->isEmpty())) {
                $signers = $applications->map(fn (object $signer): array => [
                    'id_firma' => $signer->id_firma === null ? null : (int) $signer->id_firma,
                    'id_persona_trabajador' => (int) $signer->id_persona_trabajador,
                    'id_persona' => (int) $signer->id_persona, 'nombre' => $this->name($signer),
                    'cargo' => $signer->cargo, 'orden_firma' => (int) $signer->orden_firma,
                    'estado' => $signer->fecha_firma === null ? 'Pendiente' : 'Registrada',
                    'fecha_firma' => $signer->fecha_firma, 'firma_verificada' => false,
                    'configuracion_vigente' => (bool) $signer->trabajador_activo && $signer->fecha_ingreso <= today()->toDateString()
                        && ($signer->fecha_fin === null || $signer->fecha_fin >= today()->toDateString()),
                ])->values();
            }
            if (($document->id_sesion ?? null) !== null && $applications->isEmpty()) {
                $signers = collect();
            }
            $result[$id] = [
                'id_certificado_documento' => $id, 'id_certificado' => (int) $document->id_certificado,
                'codigo_verificacion' => $document->codigo_verificacion, 'hash_pdf' => $document->hash_certificado,
                'estado_documento' => $document->estado, 'estado_certificado' => $document->estado_certificado,
                'fecha_emision' => $document->fecha_emision, 'fecha_generacion' => $document->fecha_generacion,
                'origen' => ($document->id_sesion ?? null) !== null ? 'sesion'
                    : ($document->id_participacion !== null ? 'actividad' : ($document->id_practica !== null ? 'practica' : 'reconocimiento')),
                'beneficiario' => ['id_persona' => (int) $document->id_persona, 'nombre' => $this->name($document)],
                'id_tipo_certificado' => (int) $document->id_tipo_certificado, 'tipo_certificado' => $document->tipo_certificado,
                'id_actividad' => $document->id_actividad, 'actividad' => $document->actividad,
                'id_sesion' => $document->id_sesion ?? null, 'sesion' => $document->sesion ?? null,
                'fecha_sesion' => $document->fecha_sesion ?? null, 'identidad_sesion_disponible' => ($document->id_sesion ?? null) !== null,
                'mensaje_aprobado' => null,
                'mensaje_estructurado_disponible' => false, 'firmantes' => $signers->all(),
                'configuracion_firmantes_historica' => false,
                'asignacion_firmantes_pendiente' => $signers->isEmpty(),
                'advertencias' => $signers->isEmpty() ? ['Documento remitible para revisión administrativa; falta configurar los responsables de firma. No está firmado ni habilitado para entrega.'] : [],
                'firmas' => ['requeridas_configuradas' => $signers->count(), 'pendientes' => $signers->where('estado', 'Pendiente')->count(),
                    'registradas' => $applications->whereNotNull('fecha_firma')->count(), 'verificadas' => null],
                'firmas_registradas' => $applications->whereNotNull('fecha_firma')->map(fn (object $application): array => [
                    'id_certificado_firma' => (int) $application->id_certificado_firma, 'id_firma' => (int) $application->id_firma,
                    'id_persona' => (int) $application->id_persona, 'nombre' => $this->name($application), 'cargo' => $application->cargo,
                    'fecha_firma' => $application->fecha_firma, 'firma_verificada' => false,
                ])->values()->all(),
                'remision' => null, 'historial_remision_disponible' => false, 'firma_verificada' => false,
                ...$access,
                'facultades' => ['ver' => true, 'visualizar' => $access['puede_visualizar'], 'descargar' => $access['puede_descargar'],
                    'remitir' => false,
                    'firmar' => false, 'emitir' => false, 'entregar' => false],
            ];
            if ($document->id_practica !== null && $document->id_participacion === null) {
                try {
                    $approved = app(CertificateDocumentVersions::class)->approvedContent($document);
                    $result[$id] = array_replace($result[$id], app(CertificateOrigins::class)->savedPracticeText($approved));
                } catch (HttpException) {
                }
            }
            if ($document->id_reconocimiento !== null && $document->id_participacion === null) {
                try {
                    $approved = app(CertificateDocumentVersions::class)->approvedContent($document);
                    $context = $approved['context'];
                    if (($context['origen'] ?? null) === 'reconocimiento') {
                        $result[$id] = array_replace($result[$id], app(CertificateOrigins::class)->savedSessionRecognitionText($approved), [
                            'modalidad_reconocimiento' => 'individual',
                            'modalidad_preparacion' => $context['modalidad_preparacion'] ?? 'institucional',
                        ]);
                    }
                    if (in_array($context['origen'] ?? null, ['ponente_actividad', 'ponente_sesion'], true)) {
                        $result[$id] = array_replace($result[$id], array_intersect_key($context, array_flip([
                            'origen', 'modalidad_reconocimiento', 'id_actividad_ponente', 'id_actividad', 'actividad',
                            'id_sesion', 'sesion', 'fecha_sesion',
                        ])), ['id_reconocimiento' => (int) $document->id_reconocimiento,
                            'identidad_sesion_disponible' => ($context['id_sesion'] ?? null) !== null]);
                        $result[$id] = array_replace($result[$id], app(CertificateOrigins::class)->savedSessionRecognitionText($approved));
                    }
                } catch (HttpException) {
                }
            }
        }

        return $result;
    }

    private function name(object $person): string
    {
        return trim(implode(' ', array_filter([$person->nombres, $person->apellido_paterno, $person->apellido_materno])));
    }

    /** @return array{url_documento: ?string, puede_visualizar: bool, puede_descargar: bool, motivo_documento: ?string, integridad_verificada: ?bool} */
    private function administrativeFileAccess(object $document): array
    {
        $reason = null;
        $integrity = null;
        if ($document->estado !== 'Vigente') {
            $reason = 'El documento no está vigente para visualizar o descargar su PDF.';
        } else {
            try {
                $this->generator->authorizedArtifact($document, readOnly: true);
                $integrity = true;
            } catch (HttpException $exception) {
                if ($exception->getStatusCode() !== 409) {
                    throw $exception;
                }
                $integrity = false;
                $reason = 'El PDF registrado no está disponible o su integridad no coincide con el registro.';
            }
        }

        return ['url_documento' => $reason === null
            ? route('certificates.document.download', ['id' => $document->id_certificado_documento], false).'?flujo=firmas' : null,
            'puede_visualizar' => $reason === null, 'puede_descargar' => $reason === null, 'motivo_documento' => $reason,
            'integridad_verificada' => $integrity];
    }

    /**
     * @param  array<string, mixed>  $originContext
     * @return array<string, mixed>
     */
    public function recognitionSummary(int $id, User $actor, array $originContext): array
    {
        $allowed = Gate::forUser($actor)->allows('viewAny', Certificate::class)
            && Gate::forUser($actor)->allows('viewAny', CertificateDocument::class);
        $accessReason = 'No tiene permiso para consultar documentos administrativos.';
        if ($allowed && $originContext['origen'] !== 'reconocimiento') {
            $areas = $this->areas($actor);
            if ($areas !== null && ! DB::table('certificado.actividad')->where('id_actividad', $originContext['id_actividad'])
                ->whereIn('id_area', $areas)->exists()) {
                $allowed = false;
                $accessReason = 'El documento original está fuera del ámbito autorizado.';
            }
        }
        $documents = $allowed ? $this->readableDocuments($actor)->where('c.id_reconocimiento', $id)
            ->orderBy('d.id_certificado_documento')->get()
            ->filter(fn (object $document): bool => Gate::forUser($actor)->allows('view',
                (new CertificateDocument)->newFromBuilder((array) $document))) : collect();
        $summaries = $documents->map(function (object $document) use ($id, $originContext): array {
            $access = $this->administrativeFileAccess($document);

            return ['id_reconocimiento' => $id, 'origen' => $originContext['origen'],
                'id_origen' => $originContext['id_origen'],
                'modalidad_reconocimiento' => $originContext['origen'] === 'reconocimiento' ? 'individual'
                    : ($originContext['origen'] === 'ponente_sesion' ? 'sesion' : 'actividad'),
                'id_actividad' => $originContext['id_actividad'], 'id_sesion' => $originContext['id_sesion'],
                'id_actividad_ponente' => $originContext['id_actividad_ponente'],
                'beneficiario' => ['id_persona' => (int) $document->id_persona, 'nombre' => $this->name($document)],
                'id_certificado' => (int) $document->id_certificado,
                'id_certificado_documento' => (int) $document->id_certificado_documento,
                'estado_certificado' => $document->estado_certificado, 'estado_documento' => $document->estado,
                'fecha_emision' => $document->fecha_emision, 'puede_preparar_como_individual' => false,
                ...$access,
                'facultades' => ['ver' => true, 'visualizar' => $access['puede_visualizar'], 'descargar' => $access['puede_descargar']]];
        })->values();
        $current = $summaries->where('estado_documento', 'Vigente');
        $single = $current->count() === 1 ? $current->first() : ($summaries->count() === 1 ? $summaries->first() : null);
        $reason = ! $allowed ? $accessReason
            : ($summaries->isEmpty() ? 'No existe un documento consultable en el ámbito autorizado.'
                : ($single === null ? 'Hay varios documentos; seleccione el documento por su identificador.' : $single['motivo_documento']));

        return ['documentos_existentes' => $summaries->all(), 'documento_existente' => $single,
            'id_certificado' => $single['id_certificado'] ?? null,
            'id_certificado_documento' => $single['id_certificado_documento'] ?? null,
            'estado_certificado' => $single['estado_certificado'] ?? null, 'estado_documento' => $single['estado_documento'] ?? null,
            'url_documento' => $single['url_documento'] ?? null,
            'puede_visualizar' => $single['puede_visualizar'] ?? false, 'puede_descargar' => $single['puede_descargar'] ?? false,
            'puede_consultar_documento_original' => $summaries->isNotEmpty(), 'motivo_documento' => $reason];
    }

    private function canRemit(object $document, User $actor): bool
    {
        $originAvailable = false;
        if ($document->id_participacion === null && (($document->id_reconocimiento ?? null) !== null || ($document->id_practica ?? null) !== null)) {
            try {
                app(CertificateOrigins::class)->documentContext($document);
                $originAvailable = true;
            } catch (HttpException) {
                $originAvailable = false;
            }
        }

        return in_array($document->estado_certificado, [CertificateManagement::PREPARED, CertificateManagement::PENDING], true)
            && (($document->id_sesion ?? null) === null || ($document->fecha_emision === null
                && $document->estado_sesion === 'Finalizada'
                && $document->estado_participacion === 'Activo' && $document->estado_asistencia === 'PRESENTE'
                && in_array((int) $document->id_tipo_certificado, config('certification.preparation.session_type_ids', []), true)))
            && $document->estado === 'Vigente'
            && (bool) $document->tipo_activo
            && ! (bool) $document->notificado && $document->fecha_notificacion === null
            && ($document->id_participacion !== null || $originAvailable)
            && ! str_starts_with($document->ruta_archivo, 'certificados/desarrollo/')
            && ! str_starts_with($document->ruta_archivo, 'certificados/demostracion/')
            && Gate::forUser($actor)->allows('update', (new CertificateDocument)->newFromBuilder((array) $document))
            && Gate::forUser($actor)->allows('update', (new Certificate)->newFromBuilder([
                'id_certificado' => $document->id_certificado, 'id_persona' => $document->id_persona,
                'id_participacion' => $document->id_participacion, 'estado' => $document->estado_certificado,
            ]));
    }

    private function authorizeRemission(object $document, User $actor): void
    {
        abort_unless(Gate::forUser($actor)->allows('update', (new CertificateDocument)->newFromBuilder((array) $document))
            && Gate::forUser($actor)->allows('update', (new Certificate)->newFromBuilder([
                'id_certificado' => $document->id_certificado, 'id_persona' => $document->id_persona,
                'id_participacion' => $document->id_participacion, 'estado' => $document->estado_certificado,
            ])), 403, 'No autorizado para remitir documentos.');
    }

    /** @param array<string, mixed> $detail */
    private function fingerprint(array $detail): string
    {
        return hash('sha256', json_encode(array_intersect_key($detail, array_flip([
            'id_certificado', 'id_certificado_documento', 'codigo_verificacion', 'hash_pdf', 'beneficiario',
            'id_tipo_certificado', 'tipo_certificado', 'id_actividad', 'actividad', 'fecha_emision', 'firmantes',
            'id_sesion', 'sesion', 'fecha_sesion',
        ])), JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    public function review(int $id, User $actor): array
    {
        $document = $this->document($id, $actor);
        $this->authorizeRemission($document, $actor);
        abort_unless($this->canRemit($document, $actor), 409, 'El documento no es un preparado por actividad remitible; no se altera una emisión anterior.');
        $this->generator->assertArtifact($document);
        $detail = $this->detail($id, $actor);
        $this->assertRequiredSigners($detail);
        $bytes = Storage::disk('local')->get($document->ruta_archivo);
        abort_unless(is_string($bytes) && hash_equals($document->hash_certificado, hash('sha256', $bytes)), 409, 'El PDF cambió durante la revisión.');
        $expires = now()->addMinutes(30);
        $token = Crypt::encryptString(json_encode([
            'document' => $id, 'actor' => (int) $actor->getAuthIdentifier(), 'expires' => $expires->timestamp,
            'fingerprint' => $this->fingerprint($detail),
        ], JSON_THROW_ON_ERROR));

        return ['token_revision' => $token, 'expires_at' => $expires->toIso8601String(),
            'documento' => $detail, 'vista_previa_pdf' => ['contenido_base64' => base64_encode($bytes),
                'tipo_contenido' => 'application/pdf', 'nombre_archivo' => 'certificado-'.$document->codigo_verificacion.'.pdf',
                'persistido' => true]];
    }

    /** @param array<string, mixed> $detail */
    private function assertRequiredSigners(array $detail): void
    {
        $signers = collect($detail['firmantes']);
        abort_unless($signers->count() === $signers->unique('id_persona')->count(),
            409, 'Los responsables no deben repetir a la misma persona.');
        abort_unless($signers->every(fn (array $signer): bool => $signer['configuracion_vigente']
            && trim($signer['nombre']) !== '' && trim((string) $signer['cargo']) !== ''), 409,
            'Los responsables deben estar vigentes y tener nombre y cargo válidos.');
        abort_unless($detail['firmas']['registradas'] === 0, 409, 'El documento ya tiene firmas registradas; requiere revisión criptográfica antes de otra remisión.');
    }

    /** @return array<string, mixed> */
    public function remit(int $id, string $token, User $actor): array
    {
        $this->authorizeRemission($this->document($id, $actor), $actor);
        try {
            $confirmation = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            abort(422, 'La confirmación no es válida; revise nuevamente el PDF.');
        }
        abort_unless(is_array($confirmation) && isset($confirmation['document'], $confirmation['actor'], $confirmation['expires'], $confirmation['fingerprint']),
            422, 'La confirmación está incompleta.');
        abort_unless($confirmation['actor'] === (int) $actor->getAuthIdentifier(), 403, 'La revisión pertenece a otro usuario.');
        abort_unless($confirmation['document'] === $id, 422, 'La revisión pertenece a otro documento.');
        abort_unless($confirmation['expires'] > now()->timestamp, 409, 'La revisión caducó; revise nuevamente el PDF.');

        $result = DB::transaction(function () use ($id, $confirmation, $actor): array {
            $document = $this->document($id, $actor);
            if ($document->id_participacion === null) {
                $origin = $document->id_reconocimiento !== null ? 'reconocimiento' : 'practica';
                app(CertificateOrigins::class)->lock($origin, (int) ($document->id_reconocimiento ?? $document->id_practica));
            }
            DB::table('certificado.certificado')->where('id_certificado', $document->id_certificado)->lockForUpdate()->first();
            if (($document->id_sesion ?? null) !== null) {
                DB::table('certificado.participacion')->where('id_participacion', $document->id_participacion)->lockForUpdate()->first();
                DB::table('certificado.actividad_sesion')->where('id_actividad_sesion', $document->id_sesion)->lockForUpdate()->first();
            }
            DB::table('certificado.actividad')->where('id_actividad', $document->id_actividad)->lockForUpdate()->first();
            if (($document->id_sesion ?? null) !== null) {
                DB::table('certificado.asistencia')->where('id_participacion', $document->id_participacion)
                    ->where('id_actividad_sesion', $document->id_sesion)->lockForUpdate()->first();
            }
            DB::table('certificado.certificado_documento')->where('id_certificado_documento', $id)->lockForUpdate()->first();
            DB::table('certificado.actividad_firma')->where('id_actividad', $document->id_actividad)->lockForUpdate()->get();
            $document = $this->document($id, $actor);
            $this->authorizeRemission($document, $actor);
            abort_unless($this->canRemit($document, $actor), 409, 'El documento no se encuentra preparado para remisión.');
            $this->generator->assertArtifact($document);
            $detail = $this->detail($id, $actor);
            abort_unless(hash_equals($confirmation['fingerprint'], $this->fingerprint($detail)), 409, 'El documento, contexto o responsables cambiaron; revise nuevamente el PDF.');
            $this->assertRequiredSigners($detail);
            abort_unless(DB::table('certificado.certificado_documento')->where('id_certificado', $document->id_certificado)->where('estado', 'Vigente')->count() === 1,
                409, 'El certificado tiene varias versiones vigentes; no se puede determinar el documento a remitir.');
            $repeated = $document->estado_certificado === CertificateManagement::PENDING;
            if (! $repeated) {
                DB::table('certificado.certificado')->where('id_certificado', $document->id_certificado)
                    ->where('estado', CertificateManagement::PREPARED)->update(['estado' => CertificateManagement::PENDING]);
            }

            return ['enviado' => true, 'reintento' => $repeated, 'estado_flujo' => CertificateManagement::PENDING,
                'historial_en_bd' => false, 'fecha_envio_en_bd' => null, 'documento' => $this->detail($id, $actor)];
        });
        $result['auditoria_log'] = null;
        if (! $result['reintento']) {
            try {
                Log::info('certificado_documento.remitido_gerencia', [
                    'id_certificado_documento' => $id, 'id_certificado' => $result['documento']['id_certificado'],
                    'id_usuario' => (int) $actor->getAuthIdentifier(), 'fecha' => now()->toIso8601String(),
                    'hash_pdf' => $result['documento']['hash_pdf'], 'estado' => CertificateManagement::PENDING,
                ]);
                $result['auditoria_log'] = true;
            } catch (\Throwable) {
                $result['auditoria_log'] = false;
                $result['advertencias'] = ['El estado se guardó, pero no se pudo escribir el log de remisión.'];
            }
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public function detail(int $id, User $actor): array
    {
        $document = $this->document($id, $actor);
        $detail = $this->describe(collect([$document]))[$id];
        $detail['facultades']['remitir'] = $detail['integridad_verificada'] === true
            && $document->estado_certificado === CertificateManagement::PREPARED
            && $this->canRemit($document, $actor)
            && $detail['firmas']['registradas'] === 0
            && collect($detail['firmantes'])->unique('id_persona')->count() === count($detail['firmantes'])
            && collect($detail['firmantes'])->every(fn (array $signer): bool => $signer['configuracion_vigente']
                && trim($signer['nombre']) !== '' && trim((string) $signer['cargo']) !== '');

        return $detail;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, array<string, mixed>>
     */
    public function detailsForDocuments(array $ids, User $actor): array
    {
        $this->authorize($actor);
        $documents = $this->documents($actor)->whereIn('d.id_certificado_documento', $ids)->get()
            ->filter(fn (object $document): bool => Gate::forUser($actor)->allows('view',
                (new CertificateDocument)->newFromBuilder((array) $document)));

        return $this->describe($documents);
    }

    /** @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function pending(User $actor, array $filters): array
    {
        $this->authorize($actor);
        $query = $this->documents($actor)->where('d.estado', 'Vigente')
            ->whereIn('c.estado', [CertificateManagement::PENDING]);
        if (isset($filters['estado_flujo'])) {
            $query->where('c.estado', $filters['estado_flujo']);
        }
        if (isset($filters['id_actividad'])) {
            $activity = Schema::hasColumn('certificado.reconocimiento', 'id_actividad_ponente')
                ? 'COALESCE(a.id_actividad,actividad_ponente.id_actividad)' : 'a.id_actividad';
            $query->where(DB::raw($activity), (int) $filters['id_actividad']);
        }
        $page = $query->orderByDesc('d.id_certificado_documento')->paginate($filters['per_page'] ?? 20, ['*'], 'page', $filters['page'] ?? 1);
        foreach ($page->getCollection() as $document) {
            abort_unless(Gate::forUser($actor)->allows('view', (new CertificateDocument)->newFromBuilder((array) $document)), 403, 'No autorizado.');
        }
        $page->setCollection(collect(array_values($this->describe($page->getCollection()))));

        return $page->toArray();
    }

    /** @return array<string, mixed> */
    public function history(int $id, User $actor): array
    {
        $document = $this->document($id, $actor);

        return ['disponible' => false, 'remision' => null, 'fecha_generacion' => $document->fecha_generacion,
            'motivo' => 'La base conserva generación y firmas registradas; no tiene historial de remisiones vinculado al documento.'];
    }
}
