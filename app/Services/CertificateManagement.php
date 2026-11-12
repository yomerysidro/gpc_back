<?php

namespace App\Services;

use App\Http\Data\ActivityRequests\ActivityRequestData;
use App\Models\ActivitySession\ActivitySession;
use App\Models\Certificate\Certificate;
use App\Models\CertificateDocument\CertificateDocument;
use App\Models\Participation\Participation;
use App\Models\User\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class CertificateManagement
{
    public const PREPARED = 'PREPARADO';

    public const PENDING = 'PENDIENTE_FIRMA';

    public const SIGNED = 'FIRMADO';

    public const ISSUED = 'EMITIDO';

    private bool $schemaVerified = false;

    public function __construct(private CertificateDocumentGenerator $generator) {}

    public function assertSchema(): void
    {
        if ($this->schemaVerified) {
            return;
        }
        abort_unless(app(SessionCertificatePreparation::class)->identityAvailable(), 409, CertificationReadiness::SESSION_IDENTITY_BLOCKER);
        if ($this->usesMinimalSchema()) {
            abort_unless(collect(Schema::getColumns('certificado.certificado'))->contains(fn (array $column): bool => $column['name'] === 'fecha_emision' && $column['nullable']),
                409, 'La preparación por sesión requiere fecha_emision nullable; no se registrará una fecha de emisión ficticia.');
            abort_unless(Schema::hasIndex('certificado.certificado_documento', ['codigo_verificacion'], 'unique'),
                409, 'El documento requiere un código de verificación único.');
            $this->schemaVerified = true;

            return;
        }
        foreach ([
            'certificado.certificado' => ['id_actividad_sesion'],
            'certificado.certificado_documento' => ['mensaje_aprobado', 'estado_flujo', 'modalidad_firma'],
            'certificado.certificado_firma' => ['estado_firma', 'id_persona', 'nombre_aprobado', 'cargo_aprobado'],
            'certificado.solicitud_decision' => ['id_certificado_documento', 'clave_operacion', 'datos'],
        ] as $table => $columns) {
            abort_unless(Schema::hasColumns($table, $columns), 409, 'Faltan columnas necesarias para la Etapa A; no se ha modificado el esquema.');
        }
        foreach (['certificado.certificado' => 'fecha_emision', 'certificado.certificado_firma' => 'fecha_firma',
            'certificado.solicitud_decision' => 'id_solicitud_actividad'] as $table => $column) {
            abort_unless(collect(Schema::getColumns($table))->contains(fn (array $item): bool => $item['name'] === $column && $item['nullable']),
                409, 'El esquema aún no permite preparación sin emisión ni firma.');
        }
        foreach ([
            'certificado.certificado_documento' => ['codigo_verificacion'],
            'certificado.certificado_firma' => ['id_certificado_documento', 'id_persona'],
            'certificado.solicitud_decision' => ['id_certificado_documento', 'clave_operacion'],
        ] as $table => $columns) {
            abort_unless(Schema::hasIndex($table, $columns, 'unique'), 409, 'Faltan restricciones de identidad e idempotencia de la Etapa A.');
        }
        $this->schemaVerified = true;
    }

    private function usesMinimalSchema(): bool
    {
        return ! Schema::hasColumn('certificado.certificado_documento', 'estado_flujo');
    }

    /** @return array{disponible: bool, motivos: list<string>} */
    public function preparationAvailability(): array
    {
        try {
            $this->assertSchema();

            return ['disponible' => true, 'motivos' => []];
        } catch (HttpException $exception) {
            return ['disponible' => false, 'motivos' => [$exception->getMessage()]];
        }
    }

    /** @return list<int>|null */
    private function areas(User $actor): ?array
    {
        if ($actor->isSystemAdministrator()) {
            return null;
        }
        $result = ActivityRequestData::authorizedAreas((int) $actor->id_persona);
        abort_unless($result['success'], $result['status'] ?? 500, 'No se pudo comprobar el ámbito.');

        return $result['data']->pluck('id_area')->map(fn (mixed $id): int => (int) $id)->all();
    }

    private function authorizePreparation(User $actor): void
    {
        foreach ([Certificate::class, CertificateDocument::class] as $model) {
            abort_unless(Gate::forUser($actor)->allows('create', $model), 403, 'No autorizado para preparar documentos.');
        }
        foreach ([Certificate::class, CertificateDocument::class, Participation::class, ActivitySession::class] as $model) {
            abort_unless(Gate::forUser($actor)->allows('viewAny', $model), 403, 'No autorizado para consultar el contexto.');
        }
    }

    private function context(int $sessionId, int $participationId, int $typeId, User $actor): object
    {
        $context = DB::table('certificado.actividad_sesion as s')
            ->join('certificado.actividad as a', 'a.id_actividad', '=', 's.id_actividad')
            ->join('certificado.participacion as p', 'p.id_actividad', '=', 'a.id_actividad')
            ->join('gestion.persona as persona', 'persona.id_persona', '=', 'p.id_persona')
            ->where('s.id_actividad_sesion', $sessionId)->where('p.id_participacion', $participationId)
            ->first(['a.id_actividad', 'a.id_area', 'a.nombre as actividad', 's.id_actividad_sesion as id_sesion',
                's.nombre as sesion', 's.estado as estado_sesion', 's.fecha as fecha_sesion', 's.lugar',
                'p.id_participacion', 'p.id_persona', 'p.estado as estado_participacion',
                'persona.nombres', 'persona.apellido_paterno', 'persona.apellido_materno']);
        abort_unless($context, 422, 'La participación no pertenece a la sesión.');
        $areas = $this->areas($actor);
        abort_unless($areas === null || in_array((int) $context->id_area, $areas, true), 403, 'Actividad fuera del ámbito autorizado.');
        abort_unless(Gate::forUser($actor)->allows('view', ActivitySession::findOrFail($sessionId)), 403, 'No autorizado para la sesión.');
        abort_unless($context->estado_sesion === 'Finalizada' && $context->estado_participacion === 'Activo', 409, 'La preparación requiere sesión Finalizada y participación Activo.');
        abort_unless(DB::table('certificado.asistencia')->where('id_participacion', $participationId)
            ->where('id_actividad_sesion', $sessionId)->where('estado', 'PRESENTE')->exists(), 409, 'La preparación requiere asistencia PRESENTE de esta sesión.');
        abort_unless(DB::table('certificado.solicitud_actividad')->where('id_actividad', $context->id_actividad)->where('estado', 'Aprobada')->exists(), 409, 'La actividad no tiene una solicitud aprobada.');
        $type = DB::table('certificado.tipo_certificado')->where('id_tipo_certificado', $typeId)->where('estado', true)->first();
        abort_unless($type && trim($type->nombre_tipo) !== '' && in_array($typeId, config('certification.preparation.session_type_ids', []), true),
            422, 'El tipo no está vigente o no está configurado para certificados por sesión.');
        $context->id_tipo_certificado = $typeId;
        $context->tipo_certificado = $type->nombre_tipo;

        return $context;
    }

    /** @return list<array<string, mixed>> */
    private function signers(int $activityId): array
    {
        $configured = DB::table('certificado.actividad_firma')->where('id_actividad', $activityId)->count();
        $signers = $this->generator->signers($activityId);
        abort_unless($configured === count($signers), 422, 'Configure responsables reales y vigentes; no se omiten asignaciones inválidas.');
        foreach ($signers as $signer) {
            abort_unless(trim($signer['nombre']) !== '' && trim((string) $signer['cargo']) !== '' && $signer['orden_firma'] > 0, 422, 'Cada responsable requiere nombre, cargo y orden válidos.');
        }

        return array_map(fn (array $signer): array => array_intersect_key($signer, array_flip(['id_firma', 'id_persona', 'id_persona_trabajador', 'nombre', 'cargo', 'orden_firma'])), $signers);
    }

    /**
     * @param  list<array{id_persona_trabajador: int, orden_firma: int}>|null  $workers
     * @return array<string, mixed>
     */
    public function review(int $sessionId, int $participationId, int $typeId, string $message, ?string $mode, User $actor, ?array $workers = null): array
    {
        $this->authorizePreparation($actor);
        $this->assertSchema();
        $context = $this->context($sessionId, $participationId, $typeId, $actor);
        $signers = $workers === null ? $this->signers((int) $context->id_actividad) : $this->selectedSigners($context, $workers);
        abort_if($workers !== null && $mode === null, 422, 'Seleccione una modalidad para los funcionarios.');
        $token = bin2hex(random_bytes(32));
        $key = hash('sha256', $token);
        $code = bin2hex(random_bytes(15));
        $pdf = $this->generator->reviewPdf($context, $message, $code, $signers);
        $manifest = [
            'actor' => (int) $actor->getAuthIdentifier(), 'expires' => now()->addMinutes(30)->timestamp,
            'context' => (array) $context, 'message' => $message, 'mode' => $mode, 'signers' => $signers,
            'workers' => $workers,
            'code' => $code, 'hash' => hash('sha256', $pdf), 'template_hash' => $this->templateHash(),
        ];
        $path = 'certificados/revisiones/'.$key;
        $created = [];
        try {
            $this->writeExclusive($path.'.pdf', $pdf, function () use (&$created, $path): void {
                $created[] = $path.'.pdf';
            });
            $this->writeExclusive($path.'.manifest', Crypt::encryptString(json_encode($manifest, JSON_THROW_ON_ERROR)), function () use (&$created, $path): void {
                $created[] = $path.'.manifest';
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($created);
            throw $exception;
        }

        return ['accion' => 'revisar_documento', 'modo' => 'administrativo', 'token_revision' => $token,
            'expires_at' => date(DATE_ATOM, $manifest['expires']), 'mensaje' => $message, 'hash_pdf' => $manifest['hash'],
            'firmantes' => $signers, 'asignacion_firmantes_pendiente' => $signers === [], 'persistido' => false, 'emitida' => false,
            'vista_previa_pdf' => ['contenido_base64' => base64_encode($pdf), 'tipo_contenido' => 'application/pdf',
                'nombre_archivo' => 'certificado-para-confirmar.pdf', 'persistido' => false]];
    }

    private function templateHash(): string
    {
        return hash('sha256', hash_file('sha256', resource_path('views/certificates/demonstration.blade.php'))
            .hash_file('sha256', resource_path('images/escudo-morales.png')));
    }

    /**
     * @param  list<array{id_persona_trabajador: int, orden_firma: int}>  $workers
     * @return list<array<string, mixed>>
     */
    private function selectedSigners(object $context, array $workers): array
    {
        $requirements = app(CertificateSigningStageB::class)->requirements();
        abort_unless($this->usesMinimalSchema() && $requirements['asignacion_sin_credencial']
            && $requirements['firmantes_pendientes_individuales'] && $requirements['modalidad_documental'],
            409, 'Falta aplicar la propuesta de asignación pendiente por trabajador. No se guardó el plan ni se creó una credencial.');
        usort($workers, fn (array $left, array $right): int => $left['orden_firma'] <=> $right['orden_firma']);
        abort_unless($workers !== [] && array_column($workers, 'orden_firma') === range(1, count($workers)),
            422, 'Los órdenes deben ser consecutivos desde uno.');
        $available = collect(app(AdministrativeSigners::class)->workers([(int) $context->id_area], (int) $context->id_actividad, authorizedOnly: false));
        $signers = [];
        foreach ($workers as $worker) {
            $signer = $available->firstWhere('id_persona_trabajador', (int) $worker['id_persona_trabajador']);
            abort_unless($signer, 422, 'El funcionario no tiene identidad, cargo, competencia o autorización vigente.');
            $signers[] = ['id_firma' => null, 'id_persona' => $signer['id_persona'],
                'id_persona_trabajador' => $signer['id_persona_trabajador'], 'nombre' => $signer['nombre'],
                'cargo' => $signer['cargo'], 'orden_firma' => (int) $worker['orden_firma']];
        }
        abort_unless(count($signers) === count(array_unique(array_column($signers, 'id_persona'))),
            422, 'No se permite repetir a la misma persona.');

        return $signers;
    }

    /** @return array<string, mixed> */
    public function confirm(int $sessionId, string $token, User $actor, bool $send = false): array
    {
        $this->authorizePreparation($actor);
        $this->assertSchema();
        $minimal = $this->usesMinimalSchema();
        abort_if($minimal && $send, 422, 'Confirme la preparación y luego use revisar-envio y enviar-gerencia con el documento registrado.');
        $key = hash('sha256', $token);
        $path = 'certificados/revisiones/'.$key;
        abort_unless(Storage::disk('local')->exists($path.'.manifest'), 409, 'La revisión no existe o caducó.');
        try {
            $manifest = json_decode(Crypt::decryptString(Storage::disk('local')->get($path.'.manifest')), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            abort(409, 'El manifiesto de revisión no es válido.');
        }
        abort_unless($manifest['actor'] === (int) $actor->getAuthIdentifier(), 403, 'La revisión pertenece a otro usuario.');
        abort_unless((int) $manifest['context']['id_sesion'] === $sessionId, 422, 'La revisión pertenece a otra sesión.');
        $createdPath = null;
        $commitMayHaveSucceeded = false;
        try {
            return DB::transaction(function () use ($sessionId, $actor, $send, $minimal, $manifest, $path, $key, &$createdPath, &$commitMayHaveSucceeded): array {
                $participationId = (int) $manifest['context']['id_participacion'];
                DB::table('certificado.participacion')->where('id_participacion', $participationId)->lockForUpdate()->first();
                DB::table('certificado.actividad_sesion')->where('id_actividad_sesion', $sessionId)->lockForUpdate()->first();
                DB::table('certificado.actividad')->where('id_actividad', $manifest['context']['id_actividad'])->lockForUpdate()->first();
                DB::table('certificado.asistencia')->where('id_participacion', $participationId)->where('id_actividad_sesion', $sessionId)->lockForUpdate()->get();
                $existing = DB::table('certificado.certificado_documento')->where('codigo_verificacion', $manifest['code'])->first();
                if ($existing) {
                    $registered = $this->document((int) $existing->id_certificado_documento, $actor);
                    if ($minimal) {
                        abort_unless((int) $registered->id_sesion === $sessionId
                            && (int) $registered->id_participacion === $participationId
                            && (int) $registered->id_tipo_certificado === (int) $manifest['context']['id_tipo_certificado']
                            && (int) $registered->id_persona === (int) $manifest['context']['id_persona']
                            && hash_equals($existing->hash_certificado, $manifest['hash']), 409, 'El documento existente no corresponde a esta revisión.');
                        $this->generator->assertArtifact($existing);

                        return [...$this->detail((int) $existing->id_certificado_documento, $actor),
                            'creado' => false, 'mensaje_aprobado' => $manifest['message'], 'mensaje_persistido_en_pdf' => true,
                            'estado_flujo' => $registered->estado_certificado];
                    }
                    $event = DB::table('certificado.solicitud_decision')->where('id_certificado_documento', $existing->id_certificado_documento)->where('tipo_decision', 'PREPARAR')->first();
                    $data = $event ? json_decode($event->datos, true, 512, JSON_THROW_ON_ERROR) : [];
                    abort_unless(($data['revision'] ?? null) === $key && hash_equals($existing->hash_certificado, $manifest['hash']), 409, 'El código corresponde a otra revisión.');
                    $this->generator->assertArtifact($existing);

                    return ['creado' => false, ...($send ? $this->remit((int) $existing->id_certificado_documento, $actor, $sessionId) : $this->detail((int) $existing->id_certificado_documento, $actor))];
                }
                abort_unless($manifest['expires'] > now()->timestamp, 409, 'La revisión caducó; revise de nuevo el documento.');
                DB::table('certificado.tipo_certificado')->where('id_tipo_certificado', $manifest['context']['id_tipo_certificado'])->lockForUpdate()->first();
                DB::table('certificado.actividad_firma')->where('id_actividad', $manifest['context']['id_actividad'])->lockForUpdate()->get();
                DB::table('certificado.firma')->whereIn('id_firma', array_column($manifest['signers'], 'id_firma'))->lockForUpdate()->get();
                DB::table('gestion.persona_trabajador')->whereIn('id_persona_trabajador', array_column($manifest['signers'], 'id_persona_trabajador'))->lockForUpdate()->get();
                DB::table('gestion.persona')->whereIn('id_persona', [$manifest['context']['id_persona'], ...array_column($manifest['signers'], 'id_persona')])->lockForUpdate()->get();
                $context = $this->context($sessionId, $participationId, (int) $manifest['context']['id_tipo_certificado'], $actor);
                $signers = ($manifest['workers'] ?? null) === null ? $this->signers((int) $context->id_actividad)
                    : $this->selectedSigners($context, $manifest['workers']);
                abort_unless((array) $context === $manifest['context'] && $signers === $manifest['signers']
                    && hash_equals($manifest['template_hash'], $this->templateHash()), 409, 'El contexto o los firmantes cambiaron; revise un nuevo PDF.');
                abort_if(DB::table('certificado.certificado')->where('id_participacion', $participationId)->where('id_tipo_certificado', $context->id_tipo_certificado)
                    ->where(fn (Builder $query) => $query->whereNull('id_actividad_sesion')->orWhere('id_actividad_sesion', $sessionId))->exists(),
                    409, 'Ya existe un certificado de este tipo para la sesión o un antecedente sin sesión.');
                $pdf = Storage::disk('local')->get($path.'.pdf');
                abort_unless(is_string($pdf) && str_starts_with($pdf, '%PDF-') && hash_equals($manifest['hash'], hash('sha256', $pdf)), 409, 'El archivo revisado fue alterado o no está disponible.');
                $candidatePath = 'certificados/preparados/'.$manifest['code'].'.pdf';
                $this->writeExclusive($candidatePath, $pdf, function () use (&$createdPath, $candidatePath): void {
                    $createdPath = $candidatePath;
                });
                $certificateId = DB::table('certificado.certificado')->insertGetId([
                    'id_persona' => $context->id_persona, 'id_participacion' => $participationId, 'id_actividad_sesion' => $sessionId,
                    'id_tipo_certificado' => $context->id_tipo_certificado, 'id_practica' => null, 'id_reconocimiento' => null,
                    'fecha_emision' => null, 'estado' => self::PREPARED, 'notificado' => false, 'fecha_notificacion' => null,
                ], 'id_certificado');
                $documentData = [
                    'id_certificado' => $certificateId, 'codigo_verificacion' => $manifest['code'], 'ruta_archivo' => $createdPath,
                    'hash_certificado' => $manifest['hash'], 'fecha_generacion' => now(), 'estado' => 'Vigente',
                ];
                if (! $minimal) {
                    $documentData += ['mensaje_aprobado' => $manifest['message'], 'estado_flujo' => self::PREPARED, 'modalidad_firma' => $manifest['mode']];
                } elseif (($manifest['workers'] ?? null) !== null) {
                    $documentData['modalidad_firma'] = mb_strtoupper($manifest['mode']);
                    $documentData['motivo_reemision'] = json_encode(['v' => 0,
                        'p' => CertificateSignerRevision::planHash($manifest['signers'], $documentData['modalidad_firma']),
                        'u' => (int) $actor->getAuthIdentifier()], JSON_THROW_ON_ERROR);
                }
                $documentId = DB::table('certificado.certificado_documento')->insertGetId($documentData, 'id_certificado_documento');
                if ($minimal) {
                    if (($manifest['workers'] ?? null) !== null) {
                        foreach ($manifest['signers'] as $signer) {
                            DB::table('certificado.certificado_firma')->insert([
                                'id_certificado_documento' => $documentId, 'id_firma' => null,
                                'id_persona_trabajador' => $signer['id_persona_trabajador'],
                                'orden_firma' => $signer['orden_firma'], 'fecha_firma' => null,
                            ]);
                        }
                    }
                    $this->generator->assertArtifact($this->document($documentId, $actor));
                    $result = $this->detail($documentId, $actor);
                    $commitMayHaveSucceeded = true;

                    return [...$result, 'creado' => true, 'mensaje_aprobado' => $manifest['message'],
                        'mensaje_persistido_en_pdf' => true, 'enviado' => false, 'estado_flujo' => self::PREPARED,
                        'siguiente_paso' => 'revisar-envio'];
                }
                foreach ($manifest['signers'] as $signer) {
                    DB::table('certificado.certificado_firma')->insert([
                        'id_certificado_documento' => $documentId, 'id_firma' => $signer['id_firma'], 'id_persona' => $signer['id_persona'],
                        'nombre_aprobado' => $signer['nombre'], 'cargo_aprobado' => $signer['cargo'], 'orden_firma' => $signer['orden_firma'],
                        'estado_firma' => 'Pendiente', 'fecha_firma' => null,
                    ]);
                }
                $this->event($documentId, $actor, 'PREPARAR', 'SinRegistro', self::PREPARED,
                    ['revision' => $key, 'hash_pdf' => $manifest['hash'], 'contexto' => $manifest['context']]);
                $this->generator->assertArtifact($this->document($documentId, $actor));
                $result = $send ? $this->remit($documentId, $actor, $sessionId) : $this->detail($documentId, $actor);
                $commitMayHaveSucceeded = true;

                return ['creado' => true, ...$result];
            });
        } catch (Throwable $exception) {
            $this->generator->discardFailedArtifact($createdPath, $exception, $commitMayHaveSucceeded);
            throw $exception;
        }
    }

    private function writeExclusive(string $path, string $bytes, ?callable $opened = null): void
    {
        $disk = Storage::disk('local');
        abort_unless($disk->makeDirectory(dirname($path)), 503, 'No se pudo preparar el almacenamiento privado.');
        $directory = realpath($disk->path(dirname($path)));
        $base = realpath($disk->path(''));
        abort_unless($directory && $base && str_starts_with(strtolower($directory), strtolower($base.DIRECTORY_SEPARATOR)),
            409, 'El archivo debe permanecer dentro del almacenamiento privado.');
        foreach ([public_path(), storage_path('app/public')] as $publicDirectory) {
            $publicRoot = realpath($publicDirectory);
            abort_if($publicRoot && ($directory === $publicRoot || str_starts_with(strtolower($directory), strtolower($publicRoot.DIRECTORY_SEPARATOR))),
                409, 'No se permite guardar revisiones en almacenamiento público.');
        }
        $stream = @fopen($disk->path($path), 'x+b');
        abort_unless(is_resource($stream), 409, 'No se pudo reservar un archivo nuevo sin sobrescribir.');
        if ($opened !== null) {
            $opened();
        }
        try {
            $offset = 0;
            while ($offset < strlen($bytes)) {
                $written = fwrite($stream, substr($bytes, $offset));
                abort_unless(is_int($written) && $written > 0, 503, 'No se pudo guardar el documento completo.');
                $offset += $written;
            }
            abort_unless(fflush($stream), 503, 'No se pudo finalizar el archivo.');
        } finally {
            fclose($stream);
        }
    }

    public function document(int $id, User $actor): object
    {
        if (! Schema::hasColumn('certificado.certificado_documento', 'estado_flujo')) {
            return app(CertificateDocumentWorkflow::class)->document($id, $actor);
        }
        abort_unless(Gate::forUser($actor)->allows('viewAny', CertificateDocument::class) && Gate::forUser($actor)->allows('viewAny', Certificate::class), 403, 'No autorizado para consultar documentos.');
        $this->assertSchema();
        $document = $this->documents($actor)->where('d.id_certificado_documento', $id)->first();
        abort_unless($document, 404, 'Documento no encontrado en su ámbito.');
        abort_unless(Gate::forUser($actor)->allows('view', (new CertificateDocument)->newFromBuilder((array) $document)), 403, 'No autorizado.');

        return $document;
    }

    private function documents(User $actor): Builder
    {
        $areas = $this->areas($actor);

        return DB::table('certificado.certificado_documento as d')
            ->join('certificado.certificado as c', 'c.id_certificado', '=', 'd.id_certificado')
            ->join('certificado.participacion as p', 'p.id_participacion', '=', 'c.id_participacion')
            ->join('certificado.actividad_sesion as s', 's.id_actividad_sesion', '=', 'c.id_actividad_sesion')
            ->join('certificado.actividad as a', 'a.id_actividad', '=', 's.id_actividad')
            ->whereNotNull('d.estado_flujo')->whereColumn('p.id_actividad', 's.id_actividad')->whereColumn('p.id_persona', 'c.id_persona')
            ->when($areas !== null, fn (Builder $query) => $query->whereIn('a.id_area', $areas))
            ->select('d.*', 'c.id_actividad_sesion', 'c.id_participacion', 'c.id_persona', 'c.id_tipo_certificado', 'c.fecha_emision', 'c.estado as estado_certificado', 'a.id_actividad', 'a.id_area');
    }

    /** @return array<string, mixed> */
    public function remit(int $id, User $actor, ?int $sessionId = null): array
    {
        $document = $this->document($id, $actor);
        abort_unless(Gate::forUser($actor)->allows('update', (new CertificateDocument)->newFromBuilder((array) $document)), 403, 'No autorizado para remitir documentos.');
        abort_unless(Schema::hasColumn('certificado.certificado_documento', 'estado_flujo'), 409,
            'La remisión por sesión no dispone de identidad persistente. Para documentos ya registrados use revisar-envio y enviar-gerencia del módulo documental.');
        abort_if($sessionId !== null && (int) $document->id_actividad_sesion !== $sessionId, 422, 'El documento pertenece a otra sesión.');

        return DB::transaction(function () use ($id, $actor): array {
            $document = DB::table('certificado.certificado_documento')->where('id_certificado_documento', $id)->lockForUpdate()->first();
            $this->generator->assertArtifact($document);
            abort_unless($document->estado === 'Vigente', 409, 'El documento está anulado.');
            $previous = DB::table('certificado.solicitud_decision')->where('id_certificado_documento', $id)->where('tipo_decision', 'REMITIR')->first();
            if (! $previous) {
                abort_unless($document->estado_flujo === self::PREPARED, 409, 'El documento no está preparado para remisión.');
                $signers = DB::table('certificado.certificado_firma')->where('id_certificado_documento', $id)->lockForUpdate()->get();
                abort_unless($signers->every(fn (object $signer): bool => $signer->estado_firma === 'Pendiente'
                    && $signer->fecha_firma === null && $signer->id_persona !== null && trim($signer->nombre_aprobado) !== '' && trim($signer->cargo_aprobado) !== '')
                    && $signers->unique('id_persona')->count() === $signers->count(), 409, 'Los responsables pendientes no son válidos.');
                $current = DB::table('certificado.firma as f')->join('gestion.persona_trabajador as t', 't.id_persona_trabajador', '=', 'f.id_persona_trabajador')
                    ->whereIn('f.id_firma', $signers->pluck('id_firma'))->where('t.estado', true)->whereDate('t.fecha_ingreso', '<=', today())
                    ->where(fn (Builder $query) => $query->whereNull('t.fecha_fin')->orWhereDate('t.fecha_fin', '>=', today()))
                    ->get(['f.id_firma', 't.id_persona'])->keyBy('id_firma');
                abort_unless($signers->every(fn (object $signer): bool => isset($current[$signer->id_firma])
                    && (int) $current[$signer->id_firma]->id_persona === (int) $signer->id_persona),
                    409, 'Un responsable ya no tiene una asignación laboral vigente; revise el expediente.');
                DB::table('certificado.certificado_documento')->where('id_certificado_documento', $id)->update(['estado_flujo' => self::PENDING]);
                $this->event($id, $actor, 'REMITIR', self::PREPARED, self::PENDING, ['hash_pdf' => $document->hash_certificado]);
            }

            return ['enviado' => true, 'reintento' => $previous !== null, ...$this->detail($id, $actor)];
        });
    }

    /** @param array<string, mixed> $data */
    private function event(int $documentId, User $actor, string $type, string $before, string $after, array $data): void
    {
        DB::table('certificado.solicitud_decision')->insert([
            'id_solicitud_actividad' => null, 'id_certificado_documento' => $documentId, 'id_usuario_responsable' => $actor->getAuthIdentifier(),
            'tipo_decision' => $type, 'estado_anterior' => $before, 'estado_nuevo' => $after, 'fecha_decision' => now(),
            'clave_operacion' => (string) Str::uuid(), 'datos' => json_encode($data, JSON_THROW_ON_ERROR),
        ]);
    }

    /** @return array<string, mixed> */
    public function detail(int $id, User $actor): array
    {
        if (! Schema::hasColumn('certificado.certificado_documento', 'estado_flujo')) {
            return app(CertificateDocumentWorkflow::class)->detail($id, $actor);
        }
        $document = $this->document($id, $actor);

        return $this->present($document, $actor, $this->signerRows([$id])->get($id, collect()), $this->eventRows([$id])->get($id, collect())->all());
    }

    /** @param Collection<int, object> $signers
     * @param  list<array<string, mixed>>  $history
     * @return array<string, mixed>
     */
    private function present(object $document, User $actor, Collection $signers, array $history): array
    {
        $id = (int) $document->id_certificado_documento;
        $preparation = collect($history)->firstWhere('tipo_decision', 'PREPARAR');
        $remission = collect($history)->firstWhere('tipo_decision', 'REMITIR');

        return ['id_certificado' => (int) $document->id_certificado, 'id_certificado_documento' => $id,
            'codigo_verificacion' => $document->codigo_verificacion, 'hash_pdf' => $document->hash_certificado,
            'estado' => $document->estado, 'estado_flujo' => $document->estado_flujo, 'estado_certificado' => $document->estado_certificado,
            'fecha_emision' => $document->fecha_emision, 'emitida' => false, 'firma_verificada' => false,
            'mensaje_aprobado' => $document->mensaje_aprobado, 'modalidad_firma' => $document->modalidad_firma,
            'contexto' => $preparation['datos']['contexto'] ?? null, 'firmantes' => $signers->all(),
            'asignacion_firmantes_pendiente' => $signers->isEmpty(),
            'firmas' => ['requeridas' => $signers->count(), 'pendientes' => $signers->where('estado_firma', 'Pendiente')->count(), 'verificadas' => 0],
            'remision' => $remission, 'historial' => $history, 'url_documento' => route('certificates.document.download', ['id' => $id], false),
            'facultades' => ['ver' => true, 'descargar' => $document->estado === 'Vigente', 'remitir' => $document->estado === 'Vigente'
                && $document->estado_flujo === self::PREPARED && Gate::forUser($actor)->allows('update', (new CertificateDocument)->newFromBuilder((array) $document)),
                'firmar' => false, 'emitir' => false, 'entregar' => false]];
    }

    /** @return list<array<string, mixed>> */
    public function history(int $id, User $actor): array
    {
        if (! Schema::hasColumn('certificado.certificado_documento', 'estado_flujo')) {
            return app(CertificateDocumentWorkflow::class)->history($id, $actor);
        }
        $this->document($id, $actor);

        return $this->eventRows([$id])->get($id, collect())->all();
    }

    /** @param list<int> $ids
     * @return Collection<int, Collection<int, object>>
     */
    private function signerRows(array $ids): Collection
    {
        return DB::table('certificado.certificado_firma')->whereIn('id_certificado_documento', $ids)->orderBy('orden_firma')
            ->orderBy('id_certificado_firma')->get(['id_certificado_documento', 'id_certificado_firma', 'id_firma', 'id_persona',
                'nombre_aprobado', 'cargo_aprobado', 'estado_firma', 'fecha_firma', 'orden_firma'])->groupBy('id_certificado_documento');
    }

    /** @param list<int> $ids
     * @return Collection<int, Collection<int, array<string, mixed>>>
     */
    private function eventRows(array $ids): Collection
    {
        return DB::table('certificado.solicitud_decision')->whereIn('id_certificado_documento', $ids)->orderBy('fecha_decision')->orderBy('id_solicitud_decision')
            ->get(['id_certificado_documento', 'id_solicitud_decision', 'id_usuario_responsable', 'tipo_decision', 'estado_anterior', 'estado_nuevo', 'fecha_decision', 'observacion', 'datos'])
            ->map(function (object $event): array {
                $data = (array) $event;
                $data['datos'] = array_intersect_key(json_decode($event->datos, true, 512, JSON_THROW_ON_ERROR),
                    array_flip(['hash_pdf', 'contexto']));
                $data['fecha_decision'] = Carbon::parse($event->fecha_decision)->toIso8601String();

                return $data;
            })->groupBy('id_certificado_documento');
    }

    /** @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function pending(User $actor, array $filters): array
    {
        if (! Schema::hasColumn('certificado.certificado_documento', 'estado_flujo')) {
            return app(CertificateDocumentWorkflow::class)->pending($actor, $filters);
        }
        abort_unless(Gate::forUser($actor)->allows('viewAny', CertificateDocument::class) && Gate::forUser($actor)->allows('viewAny', Certificate::class), 403, 'No autorizado.');
        $this->assertSchema();
        $query = $this->documents($actor)->where('d.estado', 'Vigente')->whereIn('d.estado_flujo', [self::PENDING]);
        if (isset($filters['estado_flujo'])) {
            $query->where('d.estado_flujo', $filters['estado_flujo']);
        }
        if (isset($filters['id_actividad'])) {
            $query->where('a.id_actividad', $filters['id_actividad']);
        }
        $page = $query->orderBy('d.id_certificado_documento')->paginate($filters['per_page'] ?? 20, ['*'], 'page', $filters['page'] ?? 1);
        $ids = $page->getCollection()->pluck('id_certificado_documento')->map(fn (mixed $id): int => (int) $id)->all();
        $signers = $this->signerRows($ids);
        $events = $this->eventRows($ids);
        $page->setCollection($page->getCollection()->map(function (object $document) use ($actor, $signers, $events): array {
            abort_unless(Gate::forUser($actor)->allows('view', (new CertificateDocument)->newFromBuilder((array) $document)), 403, 'No autorizado.');
            $id = (int) $document->id_certificado_documento;

            return $this->present($document, $actor, $signers->get($id, collect()), $events->get($id, collect())->all());
        }));

        return $page->toArray();
    }
}
