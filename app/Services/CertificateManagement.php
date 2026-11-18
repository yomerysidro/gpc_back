<?php

namespace App\Services;

use App\Http\Data\ActivityRequests\ActivityRequestData;
use App\Models\ActivitySession\ActivitySession;
use App\Models\Certificate\Certificate;
use App\Models\CertificateDocument\CertificateDocument;
use App\Models\Participation\Participation;
use App\Models\Recognition\Recognition;
use App\Models\User\User;
use App\Services\RequestSchema as Schema;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
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

    private ?bool $minimalSchema = null;

    public function __construct(private CertificateDocumentGenerator $generator) {}

    private function authorizeOrigin(string $origin, int $id, User $actor): object
    {
        $this->authorizeOriginAccess($origin, $id, $actor);

        return app(CertificateOrigins::class)->context($origin, $id);
    }

    private function authorizeOriginAccess(string $origin, int $id, User $actor): void
    {
        foreach ([Certificate::class, CertificateDocument::class] as $model) {
            abort_unless(Gate::forUser($actor)->allows('viewAny', $model)
                && Gate::forUser($actor)->allows('create', $model), 403, 'No autorizado para preparar certificados.');
        }
        $origins = app(CertificateOrigins::class);
        $origins->authorize($origin, $id, $actor);
        if (in_array($origin, ['ponente_actividad', 'ponente_sesion'], true)) {
            abort_unless(Gate::forUser($actor)->allows('create', Recognition::class),
                403, 'No autorizado para registrar el reconocimiento municipal.');
        }
    }

    /** @return array<string, mixed> */
    private function activityOriginIdentity(object $context, ?int $recognitionId = null): array
    {
        return ! in_array($context->origen, ['ponente_actividad', 'ponente_sesion'], true) ? ($context->origen === 'reconocimiento' ? [
            'origen' => 'reconocimiento', 'id_reconocimiento' => $context->id_origen,
            'modalidad_reconocimiento' => 'individual', 'modalidad_preparacion' => $context->modalidad_preparacion ?? 'institucional',
        ] : []) : [
            'origen' => $context->origen, 'modalidad_reconocimiento' => $context->modalidad_reconocimiento,
            'id_actividad_ponente' => $context->id_actividad_ponente, 'id_actividad' => $context->id_actividad,
            'actividad' => $context->actividad, 'id_sesion' => $context->id_sesion, 'id_reconocimiento' => $recognitionId,
        ];
    }

    /** @param array{mensaje: string, motivo?: string}|null $text
     * @return array<string, mixed>
     */
    public function reviewOrigin(string $origin, int $id, User $actor, ?array $text = null): array
    {
        return in_array($origin, ['ponente_sesion', 'practica', 'reconocimiento'], true)
            ? $this->withSessionReviewLock($id, $actor, fn (): array => $this->reviewOriginUnlocked($origin, $id, $actor, $text), $origin)
            : $this->reviewOriginUnlocked($origin, $id, $actor, $text);
    }

    /** @param array{mensaje: string, motivo?: string}|null $text
     * @return array<string, mixed>
     */
    private function reviewOriginUnlocked(string $origin, int $id, User $actor, ?array $text): array
    {
        $started = microtime(true);
        if ($origin === 'ponente_sesion') {
            $existing = $this->consultSessionRecognition($id, $actor, $text, true);
            if ($existing['recuperado']) {
                return $existing;
            }
        }
        $context = $this->authorizeOrigin($origin, $id, $actor);
        $contextFinished = microtime(true);
        $origins = app(CertificateOrigins::class);
        $existing = in_array($origin, ['ponente_actividad', 'ponente_sesion'], true) ? $origins->activityCertificates($context) : collect();
        if ($origin === 'ponente_sesion' && $existing->isNotEmpty()) {
            return $this->recoverSessionRecognition($existing, $actor, $text);
        }
        abort_if(in_array($origin, ['ponente_actividad', 'ponente_sesion'], true) ? $existing->isNotEmpty()
            : DB::table('certificado.certificado')->where('id_'.$origin, $id)
                ->where('id_tipo_certificado', $context->id_tipo_certificado)->exists(),
            409, 'Ya existe un certificado de este origen y tipo; consulte el catálogo existente.');
        if (in_array($origin, ['ponente_sesion', 'reconocimiento'], true) && $text === null) {
            $text = ['mensaje' => $origins->message($context), 'motivo' => $origins->recognitionMotive($context)];
        }
        if ($origin === 'practica' && $text === null) {
            $text = ['mensaje' => $origins->message($context)];
        }
        $context = $origins->withDocumentText($context, $text);
        $message = $origins->message($context);
        if (in_array($origin, ['ponente_sesion', 'practica', 'reconocimiento'], true)) {
            $previous = $this->reusableSessionReview($id, $actor, $context);
            if ($previous !== null) {
                return $previous;
            }
        }
        $eligibilityFinished = microtime(true);
        $token = bin2hex(random_bytes(32));
        $code = bin2hex(random_bytes(15));
        $tokenFinished = microtime(true);
        $pdf = $this->generator->reviewPdf($context, $message, $code, []);
        $pdfFinished = microtime(true);
        $manifest = ['actor' => (int) $actor->getAuthIdentifier(), 'context' => (array) $context,
            'message' => $message, 'code' => $code, 'hash' => hash('sha256', $pdf),
            'template_hash' => hash_file('sha256', resource_path('views/certificates/origins.blade.php')),
            'expires' => now()->addMinutes(30)->timestamp];
        if ($origin === 'ponente_sesion') {
            $manifest['revision_ponente_sesion'] = 1;
        }
        if ($origin === 'practica') {
            $manifest['revision_practica'] = 1;
        }
        if ($origin === 'reconocimiento') {
            $manifest['revision_reconocimiento'] = 1;
        }
        $path = 'certificados/revisiones/'.hash('sha256', $token);
        $created = [];
        $reservationStarted = microtime(true);
        try {
            $this->writeExclusive($path.'.pdf', $pdf, function () use (&$created, $path): void {
                $created[] = $path.'.pdf';
            });
            $this->writeExclusive($path.'.manifest', Crypt::encryptString(json_encode($manifest, JSON_THROW_ON_ERROR)), function () use (&$created, $path): void {
                $created[] = $path.'.manifest';
            });
            if (in_array($origin, ['ponente_sesion', 'practica', 'reconocimiento'], true)) {
                abort_unless(Storage::disk('local')->put($this->sessionRecognitionReviewPointer($id, $actor, $origin),
                    Crypt::encryptString(json_encode(['token' => $token], JSON_THROW_ON_ERROR))), 503, 'No se pudo conservar la revisión vigente.');
            }
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($created);
            throw $exception;
        }

        if (app()->environment('local', 'testing')) {
            Log::debug('certificado.revision.tiempos', ['contexto_ms' => ($contextFinished - $started) * 1000,
                'elegibilidad_ms' => ($eligibilityFinished - $contextFinished) * 1000, 'pdf_ms' => ($pdfFinished - $tokenFinished) * 1000,
                'token_ms' => ($tokenFinished - $eligibilityFinished) * 1000, 'reserva_ms' => (microtime(true) - $reservationStarted) * 1000]);
        }

        return $this->originReviewResult($context, $token, $manifest, $pdf);
    }

    /** @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    private function originReviewResult(object $context, string $token, array $manifest, string $pdf): array
    {
        $origin = $context->origen;
        $origins = app(CertificateOrigins::class);

        return ['modo' => 'administrativo', 'persistido' => false, 'token_revision' => $token,
            'origen' => $origin, 'id_origen' => $context->id_origen, 'mensaje' => $manifest['message'], 'hash_pdf' => $manifest['hash'],
            'expires_at' => date(DATE_ATOM, $manifest['expires']),
            'vista_previa_pdf' => ['contenido_base64' => base64_encode($pdf), 'tipo_contenido' => 'application/pdf'],
            ...$this->activityOriginIdentity($context),
            ...($origin === 'practica' ? ['texto_editable' => true, 'mensaje_estructurado_disponible' => true,
                'requiere_confirmacion' => true, 'recuperado' => false] : []),
            ...(in_array($origin, ['ponente_sesion', 'reconocimiento'], true) ? ['motivo' => $origins->recognitionMotive($context), 'texto_editable' => true,
                'mensaje_estructurado_disponible' => true,
                'requiere_confirmacion' => true, 'recuperado' => false] : [])];
    }

    private function sessionRecognitionReviewPointer(int $id, User $actor, string $origin = 'ponente_sesion'): string
    {
        return 'certificados/revisiones/'.($origin === 'practica' ? 'practica-' : ($origin === 'reconocimiento' ? 'individual-' : 'ponente-sesion-'))
            .hash('sha256', $actor->getAuthIdentifier().'|'.$id).'.current';
    }

    /** @return array<string, mixed>|null */
    private function reusableSessionReview(int $id, User $actor, object $context): ?array
    {
        $disk = Storage::disk('local');
        $pointer = $this->sessionRecognitionReviewPointer($id, $actor, $context->origen);
        if (! $disk->exists($pointer)) {
            return null;
        }
        try {
            $record = json_decode(Crypt::decryptString($disk->get($pointer)), true, 512, JSON_THROW_ON_ERROR);
            $token = $record['token'] ?? null;
            if (! is_string($token) || preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
                return null;
            }
            $path = 'certificados/revisiones/'.hash('sha256', $token);
            if (! $disk->exists($path.'.manifest') || ! $disk->exists($path.'.pdf')) {
                return null;
            }
            $manifest = json_decode(Crypt::decryptString($disk->get($path.'.manifest')), true, 512, JSON_THROW_ON_ERROR);
            if (($manifest['actor'] ?? null) !== (int) $actor->getAuthIdentifier() || ($manifest['context'] ?? null) !== (array) $context
                || ($manifest['expires'] ?? 0) <= now()->timestamp
                || ($manifest['template_hash'] ?? null) !== hash_file('sha256', resource_path('views/certificates/origins.blade.php'))) {
                return null;
            }
            $pdf = $disk->get($path.'.pdf');
            if (! str_starts_with($pdf, '%PDF-') || ! hash_equals($manifest['hash'], hash('sha256', $pdf))) {
                return null;
            }
        } catch (Throwable) {
            return null;
        }

        return [...$this->originReviewResult($context, $token, $manifest, $pdf), 'revision_reutilizada' => true];
    }

    /** @return array<string, mixed> */
    private function withSessionReviewLock(int $id, User $actor, callable $operation, string $origin = 'ponente_sesion'): array
    {
        $disk = Storage::disk('local');
        $path = $this->sessionRecognitionReviewPointer($id, $actor, $origin).'.lock';
        abort_unless($disk->makeDirectory(dirname($path)), 503, 'No se pudo preparar el almacenamiento privado.');
        $stream = @fopen($disk->path($path), 'c+b');
        abort_unless(is_resource($stream), 503, 'No se pudo abrir la reserva de revisión.');
        try {
            abort_unless(flock($stream, LOCK_EX | LOCK_NB), 409, $origin === 'practica'
                ? 'Ya hay una revisión de esta práctica en curso.' : 'Ya hay una revisión de este reconocimiento en curso.');

            return $operation();
        } finally {
            flock($stream, LOCK_UN);
            fclose($stream);
        }
    }

    /** @param Collection<int, object> $certificates
     * @param  array{mensaje: string, motivo: string}|null  $text
     * @return array<string, mixed>
     */
    private function recoverSessionRecognition(Collection $certificates, User $actor, ?array $text, bool $includePdf = true): array
    {
        abort_unless($certificates->count() === 1 && $certificates->first()->estado !== 'ANULADO', 409,
            'No existe una preparación vigente e inequívoca recuperable.');
        $documents = DB::table('certificado.certificado_documento')->where('id_certificado', $certificates->first()->id_certificado)
            ->where('estado', 'Vigente')->get();
        abort_unless($documents->count() === 1, 409, 'El certificado no tiene un documento vigente inequívoco.');
        $workflow = app(CertificateDocumentWorkflow::class);
        $document = $this->generator->authorizedArtifact($workflow->document((int) $documents->first()->id_certificado_documento, $actor), readOnly: true);
        $saved = app(CertificateOrigins::class)->savedSessionRecognitionText(app(CertificateDocumentVersions::class)->approvedContent($document));
        abort_unless($saved !== [] && ($text === null || ($text['mensaje'] === $saved['mensaje'] && $text['motivo'] === $saved['motivo'])),
            409, 'El reconocimiento ya está preparado; su texto es inmutable. Consulte el documento guardado.');

        $detail = $workflow->detail((int) $document->id_certificado_documento, $actor);

        $state = $detail['estado_certificado'];
        $prepared = in_array($state, [self::PREPARED, self::PENDING], true)
            ? $this->preparationResult((int) $document->id_certificado_documento, $actor, false, knownDetail: $detail)
            : [...$detail, 'estado_flujo' => $state, 'puede_enviar_gerencia' => false,
                'enviado' => false, 'siguiente_paso' => 'consultar_documento'];

        return [...$prepared, ...$saved,
            'puede_preparar' => false, 'puede_generar_pdf' => false, 'puede_emitir' => false,
            'puede_consultar_certificado_existente' => true, 'estado_elegibilidad' => 'Documento existente',
            'remision' => $state === self::PENDING ? ['estado_persistido' => self::PENDING,
                'historial_en_bd' => false, 'fecha_envio_en_bd' => null] : ($detail['remision'] ?? null),
            'creado' => false, 'persistido' => true, 'recuperado' => true, 'requiere_confirmacion' => false, 'token_revision' => null,
            'hash_pdf' => $document->hash_certificado,
            ...($includePdf ? ['vista_previa_pdf' => ['contenido_base64' => base64_encode(Storage::disk('local')->get($document->ruta_archivo)),
                'tipo_contenido' => 'application/pdf', 'persistido' => true]] : [])];
    }

    /** @param array{mensaje: string, motivo: string}|null $text
     * @return array<string, mixed>
     */
    public function consultSessionRecognition(int $id, User $actor, ?array $text = null, bool $includePdf = false): array
    {
        app(SessionSpeakers::class)->assertSchema();
        $origins = app(CertificateOrigins::class);
        $origins->authorize('ponente_sesion', $id, $actor);
        $assignment = $origins->assignment($id);
        $session = RequestReadMemo::remember(ActivitySession::class.'.'.$assignment->id_actividad_sesion,
            fn (): ?ActivitySession => ActivitySession::find($assignment->id_actividad_sesion));
        abort_unless($session && (int) $session->id_actividad === (int) $assignment->id_actividad,
            409, 'La sesión no pertenece a la actividad.');
        $certificates = DB::table('certificado.reconocimiento as r')
            ->leftJoin('certificado.certificado as c', 'c.id_reconocimiento', '=', 'r.id_reconocimiento')
            ->where('r.id_actividad_ponente', $id)->get(['c.*']);
        if ($certificates->isEmpty()) {
            if ($includePdf) {
                return ['recuperado' => false];
            }

            $evaluation = $origins->activityRecognition($id, true);
            $allowed = Gate::forUser($actor)->allows('create', Certificate::class)
                && Gate::forUser($actor)->allows('create', CertificateDocument::class)
                && Gate::forUser($actor)->allows('create', Recognition::class);

            return [...$evaluation, 'puede_preparar' => $allowed && $evaluation['puede_preparar'],
                'puede_generar_pdf' => $allowed && $evaluation['puede_generar_pdf'], 'recuperado' => false, 'persistido' => false];
        }

        $recovered = $this->recoverSessionRecognition($certificates, $actor, $text, $includePdf);
        abort_unless((int) ($recovered['id_actividad_ponente'] ?? 0) === $id
            && (int) ($recovered['id_sesion'] ?? 0) === (int) $session->id_actividad_sesion
            && (int) ($recovered['id_actividad'] ?? 0) === (int) $assignment->id_actividad,
            409, 'El documento no conserva la asignación y sesión solicitadas.');

        return $recovered;
    }

    /** @return array<string, mixed> */
    public function consultPractice(int $id, User $actor): array
    {
        return $this->consultStoredOrigin('practica', $id, $actor);
    }

    /** @return array<string, mixed> */
    public function consultRecognition(int $id, User $actor): array
    {
        return $this->consultStoredOrigin('reconocimiento', $id, $actor);
    }

    /** @return array<string, mixed> */
    private function consultStoredOrigin(string $origin, int $id, User $actor): array
    {
        abort_unless(Gate::forUser($actor)->allows('viewAny', Certificate::class)
            && Gate::forUser($actor)->allows('viewAny', CertificateDocument::class), 403, 'No autorizado para consultar certificados.');
        $origins = app(CertificateOrigins::class);
        $origins->authorize($origin, $id, $actor);
        $evaluation = $origin === 'practica' ? $origins->practice($id) : $origins->recognition($id);
        if ($origin === 'reconocimiento') {
            $evaluation = [...$evaluation, ...app(CertificateDocumentWorkflow::class)->recognitionSummary($id, $actor, $evaluation['contexto_origen_real'])];
            if ($evaluation['modalidad_reconocimiento'] !== 'individual') {
                return [...$evaluation, 'recuperado' => $evaluation['puede_consultar_documento_original'],
                    'persistido' => $evaluation['puede_consultar_documento_original'], 'creado' => false,
                    'texto_editable' => false, 'puede_preparar' => false, 'puede_generar_pdf' => false];
            }
        }
        $certificates = $evaluation['certificados_existentes'];
        if ($certificates->isEmpty()) {
            $allowed = Gate::forUser($actor)->allows('create', Certificate::class)
                && Gate::forUser($actor)->allows('create', CertificateDocument::class);

            return [...$evaluation, 'puede_preparar' => $allowed && $evaluation['puede_preparar'],
                'puede_generar_pdf' => $allowed && $evaluation['puede_generar_pdf'], 'recuperado' => false, 'persistido' => false,
                'texto_editable' => $allowed && $evaluation['puede_preparar']];
        }
        abort_unless($certificates->count() === 1 && $certificates->first()->estado !== 'ANULADO'
            && (int) $certificates->first()->id_persona === $evaluation['id_persona']
            && $certificates->first()->id_certificado_documento !== null, 409,
            $origin === 'practica' ? 'La práctica no tiene una constancia vigente e inequívoca recuperable.'
                : 'El reconocimiento no tiene un documento vigente e inequívoco del beneficiario recuperable.');
        $idDocument = (int) $certificates->first()->id_certificado_documento;
        $workflow = app(CertificateDocumentWorkflow::class);
        $detail = $workflow->detail($idDocument, $actor);
        try {
            $document = $this->generator->authorizedArtifact($workflow->document($idDocument, $actor, resolveVersion: false), readOnly: true);
            $approved = app(CertificateDocumentVersions::class)->approvedContent($document);
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() !== 409) {
                throw $exception;
            }

            return [...$evaluation, ...$detail, 'origen' => $origin, 'id_origen' => $id,
                'estado_flujo' => $detail['estado_certificado'], 'creado' => false, 'persistido' => true, 'recuperado' => true,
                'texto_editable' => false, 'puede_preparar' => false, 'puede_generar_pdf' => false, 'puede_emitir' => false,
                'puede_enviar_gerencia' => false, 'token_revision' => null,
                'motivos_bloqueo' => array_values(array_unique([...$evaluation['motivos_bloqueo'], $exception->getMessage()]))];
        }
        $saved = $origin === 'practica' ? $origins->savedPracticeText($approved) : $origins->savedSessionRecognitionText($approved);
        $state = $detail['estado_certificado'];
        $prepared = in_array($state, [self::PREPARED, self::PENDING], true)
            ? $this->preparationResult($idDocument, $actor, false, knownDetail: $detail)
            : [...$detail, 'estado_flujo' => $state, 'puede_enviar_gerencia' => false];

        return [...$evaluation, ...$prepared, ...$saved, 'origen' => $origin, 'id_origen' => $id,
            'creado' => false, 'persistido' => true, 'recuperado' => true, 'texto_editable' => false,
            'puede_preparar' => false, 'puede_generar_pdf' => false, 'puede_emitir' => false,
            'hash_pdf' => $document->hash_certificado, 'token_revision' => null];
    }

    /** @param array{mensaje: string, motivo?: string}|null $text
     * @return array<string, mixed>
     */
    public function confirmOrigin(string $origin, int $id, string $token, User $actor, ?array $text = null): array
    {
        return in_array($origin, ['ponente_sesion', 'practica', 'reconocimiento'], true)
            ? $this->withSessionReviewLock($id, $actor, fn (): array => $this->confirmOriginUnlocked($origin, $id, $token, $actor, $text), $origin)
            : $this->confirmOriginUnlocked($origin, $id, $token, $actor, $text);
    }

    /** @param array{mensaje: string, motivo?: string}|null $text
     * @return array<string, mixed>
     */
    private function confirmOriginUnlocked(string $origin, int $id, string $token, User $actor, ?array $text): array
    {
        $this->authorizeOriginAccess($origin, $id, $actor);
        $path = 'certificados/revisiones/'.hash('sha256', $token);
        abort_unless(Storage::disk('local')->exists($path.'.manifest'), 409, 'La revisión no existe.');
        try {
            $manifest = json_decode(Crypt::decryptString(Storage::disk('local')->get($path.'.manifest')), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            abort(409, 'La revisión no es válida.');
        }
        abort_unless($manifest['actor'] === (int) $actor->getAuthIdentifier(), 403, 'La revisión pertenece a otro usuario.');
        abort_unless(($manifest['context']['origen'] ?? null) === $origin && ($manifest['context']['id_origen'] ?? null) === $id,
            409, 'La revisión pertenece a otro origen.');
        $approvedText = $manifest['context']['texto_reconocimiento'] ?? null;
        if ($origin === 'practica' && isset($manifest['context']['texto_practica'])) {
            $approvedPracticeText = $manifest['context']['texto_practica'];
            $defaultContext = (object) $manifest['context'];
            unset($defaultContext->texto_practica);
            if ($text === null && $approvedPracticeText['mensaje'] === app(CertificateOrigins::class)->message($defaultContext)) {
                $text = ['mensaje' => $approvedPracticeText['mensaje']];
            }
            abort_unless($text !== null, 422, 'Confirme el mensaje de la vista previa.');
            abort_unless(($approvedPracticeText['v'] ?? null) === 1 && $text['mensaje'] === ($approvedPracticeText['mensaje'] ?? null)
                && $manifest['message'] === $text['mensaje'], 409, 'El texto cambió; genere y revise nuevamente el PDF.');
        }
        if ($approvedText !== null) {
            if ($origin === 'reconocimiento' && $text !== null && ! array_key_exists('motivo', $text)) {
                $text['motivo'] = $approvedText['motivo'];
            }
            if ($text === null && in_array($origin, ['ponente_sesion', 'reconocimiento'], true)) {
                $defaultContext = (object) $manifest['context'];
                unset($defaultContext->texto_reconocimiento);
                $origins = app(CertificateOrigins::class);
                if ($approvedText['mensaje'] === $origins->message($defaultContext) && $approvedText['motivo'] === $origins->recognitionMotive($defaultContext)) {
                    $text = ['mensaje' => $approvedText['mensaje'], 'motivo' => $approvedText['motivo']];
                }
            }
            abort_unless($text !== null, 422, 'Confirme el mensaje y motivo de la vista previa.');
            abort_unless(in_array($origin, ['ponente_sesion', 'reconocimiento'], true) && ($approvedText['v'] ?? null) === 1
                && $text['mensaje'] === ($approvedText['mensaje'] ?? null) && $text['motivo'] === ($approvedText['motivo'] ?? null)
                && $manifest['message'] === $text['mensaje'], 409, 'El texto cambió; genere y revise nuevamente el PDF.');
        } elseif ($origin !== 'practica' || ! isset($manifest['context']['texto_practica'])) {
            abort_if($text !== null, 409, 'El texto no corresponde a la revisión; revise nuevamente el PDF.');
        }
        $created = null;
        $createdApproval = null;
        $committed = false;
        try {
            return DB::transaction(function () use ($origin, $id, $actor, $manifest, $path, $text, $token, &$created, &$createdApproval, &$committed): array {
                if ($origin === 'ponente_sesion' && DB::connection()->getDriverName() === 'pgsql') {
                    DB::statement("SET LOCAL lock_timeout = '3s'");
                }
                $origins = app(CertificateOrigins::class);
                if ($origin === 'ponente_sesion') {
                    DB::table('certificado.actividad')->where('id_actividad', $manifest['context']['id_actividad'])->lockForUpdate()->first();
                } else {
                    $origins->lock($origin, $id);
                }
                DB::table('certificado.tipo_certificado')->where('id_tipo_certificado', $manifest['context']['id_tipo_certificado'])->lockForUpdate()->first();
                $context = $origins->withDocumentText($origin === 'ponente_sesion' ? $origins->context($origin, $id)
                    : $this->authorizeOrigin($origin, $id, $actor), $text);
                abort_unless((array) $context === $manifest['context'], 409, 'El origen o su acreditación cambiaron; revise nuevamente.');
                $existing = in_array($origin, ['ponente_actividad', 'ponente_sesion'], true) ? $origins->activityCertificates($context)
                    : DB::table('certificado.certificado')->where('id_'.$origin, $id)
                        ->where('id_tipo_certificado', $context->id_tipo_certificado)->get();
                if ($existing->isNotEmpty()) {
                    abort_unless($existing->count() === 1 && in_array($existing->first()->estado, [self::PREPARED, self::PENDING], true)
                        && $existing->first()->fecha_emision === null,
                        409, 'El certificado ya existe y no es una preparación recuperable.');
                    $document = DB::table('certificado.certificado_documento')->where('id_certificado', $existing->first()->id_certificado)->get();
                    abort_unless($document->count() === 1 && $document->first()->codigo_verificacion === $manifest['code']
                        && hash_equals($document->first()->hash_certificado, $manifest['hash']) && $document->first()->estado === 'Vigente',
                        409, 'Ya existe un documento distinto; no se duplicará ni sobrescribirá.');
                    $this->generator->assertArtifact($document->first());

                    $detail = app(CertificateDocumentWorkflow::class)->detail((int) $document->first()->id_certificado_documento, $actor);

                    return [...$this->preparationResult((int) $document->first()->id_certificado_documento, $actor, false, knownDetail: $detail),
                        ...$this->activityOriginIdentity($context, (int) $existing->first()->id_reconocimiento)];
                }
                if (in_array($origin, ['ponente_sesion', 'practica', 'reconocimiento'], true)) {
                    $pointer = $this->sessionRecognitionReviewPointer($id, $actor, $origin);
                    if (Storage::disk('local')->exists($pointer)) {
                        try {
                            $currentTokenHash = Crypt::decryptString(Storage::disk('local')->get($pointer));
                            if (str_starts_with($currentTokenHash, '{')) {
                                $currentTokenHash = hash('sha256', json_decode($currentTokenHash, true, 512, JSON_THROW_ON_ERROR)['token']);
                            }
                        } catch (Throwable) {
                            abort(409, 'La revisión vigente no es válida; revise nuevamente el PDF.');
                        }
                        abort_unless(hash_equals($currentTokenHash, hash('sha256', $token)), 409, 'Existe una revisión más reciente; utilice su token.');
                    } else {
                        abort_if(isset($manifest['revision_ponente_sesion']) || isset($manifest['revision_practica']) || isset($manifest['revision_reconocimiento']), 409, 'Falta la revisión vigente; revise nuevamente el PDF.');
                    }
                }
                abort_unless($manifest['expires'] > now()->timestamp
                    && hash_equals($manifest['template_hash'], hash_file('sha256', resource_path('views/certificates/origins.blade.php'))),
                    409, 'La revisión o la plantilla caducó; revise nuevamente.');
                $pdf = Storage::disk('local')->get($path.'.pdf');
                abort_unless(is_string($pdf) && str_starts_with($pdf, '%PDF-') && hash_equals($manifest['hash'], hash('sha256', $pdf)),
                    409, 'El PDF revisado no conserva su integridad.');
                $recognitionId = $origin === 'reconocimiento' ? $id : null;
                if (in_array($origin, ['ponente_actividad', 'ponente_sesion'], true)) {
                    $recognitionId = DB::table('certificado.reconocimiento')->insertGetId([
                        ...($origin === 'ponente_sesion' ? ['id_actividad_ponente' => $id,
                            ...(Schema::hasColumn('certificado.reconocimiento', 'id_tipo_certificado') ? ['id_tipo_certificado' => $context->id_tipo_certificado] : [])] : []),
                        'id_persona_trabajador' => $context->datos_origen['id_persona_trabajador'],
                        'titulo' => $context->datos_origen['titulo'], 'motivo' => $origins->recognitionMotive($context),
                        'descripcion' => null, 'fecha_reconocimiento' => today()->toDateString(), 'estado' => self::PREPARED,
                    ], 'id_reconocimiento');
                }
                $certificateId = DB::table('certificado.certificado')->insertGetId([
                    'id_persona' => $context->id_persona, 'id_tipo_certificado' => $context->id_tipo_certificado,
                    'id_reconocimiento' => $recognitionId,
                    'id_practica' => $origin === 'practica' ? $id : null, 'id_participacion' => null, 'id_actividad_sesion' => null,
                    'fecha_emision' => null, 'estado' => self::PREPARED, 'notificado' => false, 'fecha_notificacion' => null,
                ], 'id_certificado');
                $candidate = 'certificados/preparados/'.$manifest['code'].'.pdf';
                $createdApproval = app(CertificateDocumentVersions::class)->retainApproval($manifest);
                $this->writeReviewedPdf($candidate, $pdf, function () use (&$created, $candidate): void {
                    $created = $candidate;
                });
                $documentData = [
                    'id_certificado' => $certificateId, 'codigo_verificacion' => $manifest['code'],
                    'hash_certificado' => $manifest['hash'], 'ruta_archivo' => $candidate, 'estado' => 'Vigente', 'fecha_generacion' => now(),
                ];
                $this->generator->assertArtifact((object) $documentData);
                $documentId = DB::table('certificado.certificado_documento')->insertGetId($documentData, 'id_certificado_documento');
                $detail = app(CertificateDocumentWorkflow::class)->detail($documentId, $actor);
                $committed = true;

                return [...$this->preparationResult($documentId, $actor, true, knownDetail: $detail),
                    ...$this->activityOriginIdentity($context, $recognitionId)];
            });
        } catch (Throwable $exception) {
            $this->generator->discardFailedArtifact($created, $exception, $committed);
            $this->generator->discardFailedArtifact($createdApproval, $exception, $committed);
            throw $exception;
        }
    }

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
        return $this->minimalSchema ??= ! Schema::hasColumn('certificado.certificado_documento', 'estado_flujo');
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
        if ($signers !== []) {
            app(AdministrativeSigners::class)->assertAuthorizationAvailable();
            $authorized = DB::table('gestion.persona_trabajador')
                ->whereIn('id_persona_trabajador', array_column($signers, 'id_persona_trabajador'))
                ->where('habilitado_firma_certificados', true)->where('estado', true)
                ->whereDate('fecha_ingreso', '<=', today())
                ->where(fn (Builder $query) => $query->whereNull('fecha_fin')->orWhereDate('fecha_fin', '>=', today()))
                ->when(DB::transactionLevel() > 0, fn (Builder $query) => $query->lockForUpdate())
                ->get(['id_persona_trabajador'])->pluck('id_persona_trabajador')->map(fn (mixed $id): int => (int) $id)->all();
            foreach ($signers as $signer) {
                abort_unless(in_array($signer['id_persona_trabajador'], $authorized, true), 422,
                    'El responsable configurado no está administrativamente habilitado.');
            }
        }
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
        $existing = $this->preparedForContext($context, $actor);
        if ($existing !== null) {
            $approvedMessage = $existing->mensaje_aprobado ?? null;
            if ($approvedMessage === null) {
                try {
                    $approvedMessage = app(CertificateDocumentVersions::class)->approvedContent($existing)['message'];
                } catch (HttpException $exception) {
                    if ($exception->getStatusCode() !== 409) {
                        throw $exception;
                    }
                }
            }
            $token = bin2hex(random_bytes(32));
            $expires = now()->addMinutes(30);
            $this->writeExclusive('certificados/revisiones/'.hash('sha256', $token).'.manifest', Crypt::encryptString(json_encode([
                'actor' => (int) $actor->getAuthIdentifier(), 'context' => (array) $context,
                'code' => $existing->codigo_verificacion, 'hash' => $existing->hash_certificado,
                'message' => $approvedMessage, 'expires' => $expires->timestamp, 'recuperacion' => true,
            ], JSON_THROW_ON_ERROR)));

            return [...$this->preparationResult((int) $existing->id_certificado_documento, $actor, false),
                'token_revision' => $token, 'expires_at' => $expires->toIso8601String(),
                'mensaje_aprobado' => $approvedMessage, 'mensaje_persistido_en_pdf' => $approvedMessage !== null,
                'hash_pdf' => $existing->hash_certificado,
                'vista_previa_pdf' => ['contenido_base64' => base64_encode(Storage::disk('local')->get($existing->ruta_archivo)),
                    'tipo_contenido' => 'application/pdf', 'persistido' => true]];
        }
        $signers = $workers === null ? ($this->usesMinimalSchema() ? [] : $this->signers((int) $context->id_actividad))
            : $this->selectedSigners($context, $workers);
        abort_if($workers !== null && $mode === null, 422, 'Seleccione una modalidad para los funcionarios.');
        $token = bin2hex(random_bytes(32));
        $key = hash('sha256', $token);
        $code = bin2hex(random_bytes(15));
        $reusable = null;
        foreach (Storage::disk('local')->files('certificados/revisiones') as $candidate) {
            if (! str_ends_with($candidate, '.manifest')) {
                continue;
            }
            try {
                $previous = json_decode(Crypt::decryptString(Storage::disk('local')->get($candidate)), true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                continue;
            }
            if (($previous['actor'] ?? null) !== (int) $actor->getAuthIdentifier()
                || ($previous['context'] ?? null) !== (array) $context || ($previous['message'] ?? null) !== $message
                || ($previous['mode'] ?? null) !== $mode || ($previous['signers'] ?? null) !== $signers
                || ($previous['workers'] ?? null) !== $workers || ($previous['template_hash'] ?? null) !== $this->templateHash()) {
                continue;
            }
            $previousPdf = substr($candidate, 0, -strlen('.manifest')).'.pdf';
            if (Storage::disk('local')->exists($previousPdf)) {
                $bytes = Storage::disk('local')->get($previousPdf);
                if (str_starts_with($bytes, '%PDF-') && hash_equals($previous['hash'], hash('sha256', $bytes))) {
                    $reusable = ['code' => $previous['code'], 'pdf' => $bytes];
                    break;
                }
            }
        }
        $code = $reusable['code'] ?? $code;
        $pdf = $reusable['pdf'] ?? $this->generator->reviewPdf($context, $message, $code, $signers);
        $manifest = [
            'actor' => (int) $actor->getAuthIdentifier(), 'expires' => now()->addMinutes(30)->timestamp,
            'context' => (array) $context, 'message' => $message, 'mode' => $mode, 'signers' => $signers,
            'workers' => $workers,
            'recuperacion' => $reusable !== null,
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
            'revision_reutilizada' => $reusable !== null,
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

    private function preparedForContext(object $context, User $actor): ?object
    {
        $certificates = DB::table('certificado.certificado')->where('id_participacion', $context->id_participacion)
            ->where('id_actividad_sesion', $context->id_sesion)->where('id_tipo_certificado', $context->id_tipo_certificado)->get();
        if ($certificates->isEmpty()) {
            return null;
        }
        abort_unless($certificates->count() === 1, 409, 'Existe más de un certificado para esta participación, sesión y tipo.');
        $certificate = $certificates->first();
        abort_unless((int) $certificate->id_persona === (int) $context->id_persona
            && in_array($certificate->estado, [self::PREPARED, self::PENDING], true)
            && $certificate->fecha_emision === null, 409, 'El certificado existente no es una preparación recuperable.');
        $documents = DB::table('certificado.certificado_documento')->where('id_certificado', $certificate->id_certificado)
            ->where('estado', 'Vigente')->get(['id_certificado_documento']);
        abort_unless($documents->count() === 1, 409, 'El certificado existente requiere un único documento vigente; no se creará otro.');
        $document = $this->document((int) $documents->first()->id_certificado_documento, $actor);
        $this->generator->assertArtifact($document);

        return $document;
    }

    /** @return array<string, mixed>|null */
    public function recoverPreparation(int $sessionId, int $participationId, int $typeId, User $actor): ?array
    {
        $this->authorizePreparation($actor);
        if (! app(SessionCertificatePreparation::class)->identityAvailable()) {
            return null;
        }
        if (! DB::table('certificado.certificado')->where('id_participacion', $participationId)
            ->where('id_actividad_sesion', $sessionId)->where('id_tipo_certificado', $typeId)->exists()) {
            return null;
        }
        $document = $this->preparedForContext($this->context($sessionId, $participationId, $typeId, $actor), $actor);
        abort_unless($document, 409, 'La preparación cambió durante la consulta; vuelva a consultar su estado.');

        return $this->preparationResult((int) $document->id_certificado_documento, $actor, false);
    }

    /** @param array<string, mixed>|null $knownDetail
     * @return array<string, mixed>
     */
    private function preparationResult(int $id, User $actor, bool $created, bool $send = false, ?int $sessionId = null, ?array $knownDetail = null): array
    {
        $detail = $send ? $this->remit($id, $actor, $sessionId) : ($knownDetail ?? $this->detail($id, $actor));
        $state = $detail['estado_flujo'] ?? $detail['estado_certificado'] ?? null;
        abort_unless(in_array($state, [self::PREPARED, self::PENDING], true)
            && ($detail['estado_documento'] ?? $detail['estado'] ?? null) === 'Vigente',
            409, 'El documento no está en una etapa de preparación recuperable.');
        $sent = $state === self::PENDING;

        return [...$detail, 'modo' => 'administrativo', 'creado' => $created,
            'reintento' => $send ? (bool) ($detail['reintento'] ?? false) : ! $created,
            'preparacion_persistida' => true, 'enviado' => $sent,
            'estado_flujo' => $state,
            'puede_enviar_gerencia' => ! $sent && ($detail['facultades']['remitir'] ?? false),
            'siguiente_paso' => $sent ? 'consultar_gerencia' : 'revisar-envio',
            'siguiente_accion_preparacion' => $sent ? 'consultar_gerencia' : 'enviar_gerencia'];
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
        app(AdministrativeSigners::class)->assertAuthorizationAvailable();
        $available = collect(app(AdministrativeSigners::class)->authorizedWorkers());
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
        $createdApproval = null;
        $commitMayHaveSucceeded = false;
        try {
            return DB::transaction(function () use ($sessionId, $actor, $send, $minimal, $manifest, $path, $key, &$createdPath, &$createdApproval, &$commitMayHaveSucceeded): array {
                $participationId = (int) $manifest['context']['id_participacion'];
                DB::table('certificado.participacion')->where('id_participacion', $participationId)->lockForUpdate()->first();
                DB::table('certificado.actividad_sesion')->where('id_actividad_sesion', $sessionId)->lockForUpdate()->first();
                DB::table('certificado.actividad')->where('id_actividad', $manifest['context']['id_actividad'])->lockForUpdate()->first();
                DB::table('certificado.asistencia')->where('id_participacion', $participationId)->where('id_actividad_sesion', $sessionId)->lockForUpdate()->get();
                $existing = DB::table('certificado.certificado_documento')->where('codigo_verificacion', $manifest['code'])->first();
                if ($existing) {
                    $registered = $this->document((int) $existing->id_certificado_documento, $actor);
                    abort_unless($registered->estado === 'Vigente'
                        && in_array($registered->estado_flujo ?? $registered->estado_certificado, [self::PREPARED, self::PENDING], true)
                        && $registered->fecha_emision === null, 409, 'El documento existente no es una preparación recuperable.');
                    abort_unless((int) ($registered->id_sesion ?? $registered->id_actividad_sesion ?? 0) === $sessionId
                        && (int) $registered->id_participacion === $participationId
                        && (int) $registered->id_tipo_certificado === (int) $manifest['context']['id_tipo_certificado']
                        && (int) $registered->id_persona === (int) $manifest['context']['id_persona']
                        && hash_equals($registered->hash_certificado, $manifest['hash']), 409, 'El documento existente no corresponde a esta revisión.');
                    if ($minimal) {
                        abort_unless((int) $registered->id_sesion === $sessionId
                            && (int) $registered->id_participacion === $participationId
                            && (int) $registered->id_tipo_certificado === (int) $manifest['context']['id_tipo_certificado']
                            && (int) $registered->id_persona === (int) $manifest['context']['id_persona']
                            && hash_equals($registered->hash_certificado, $manifest['hash']), 409, 'El documento existente no corresponde a esta revisión.');
                        $this->generator->assertArtifact($registered);

                        return [...$this->preparationResult((int) $existing->id_certificado_documento, $actor, false, $send, $sessionId),
                            'creado' => false, 'mensaje_aprobado' => $manifest['message'], 'mensaje_persistido_en_pdf' => $manifest['message'] !== null,
                        ];
                    }
                    $event = DB::table('certificado.solicitud_decision')->where('id_certificado_documento', $existing->id_certificado_documento)->where('tipo_decision', 'PREPARAR')->first();
                    $data = $event ? json_decode($event->datos, true, 512, JSON_THROW_ON_ERROR) : [];
                    abort_unless((($data['revision'] ?? null) === $key || ($manifest['recuperacion'] ?? false))
                        && hash_equals($existing->hash_certificado, $manifest['hash']), 409, 'El código corresponde a otra revisión.');
                    $this->generator->assertArtifact($existing);

                    return $this->preparationResult((int) $existing->id_certificado_documento, $actor, false, $send, $sessionId);
                }
                $context = $this->context($sessionId, $participationId, (int) $manifest['context']['id_tipo_certificado'], $actor);
                $prepared = $this->preparedForContext($context, $actor);
                if ($prepared !== null) {
                    return [...$this->preparationResult((int) $prepared->id_certificado_documento, $actor, false),
                        'revision_reutilizada' => false, 'requiere_revision_envio' => true];
                }
                abort_unless($manifest['expires'] > now()->timestamp, 409, 'La revisión caducó; revise de nuevo el documento.');
                DB::table('certificado.tipo_certificado')->where('id_tipo_certificado', $manifest['context']['id_tipo_certificado'])->lockForUpdate()->first();
                DB::table('certificado.actividad_firma')->where('id_actividad', $manifest['context']['id_actividad'])->lockForUpdate()->get();
                DB::table('certificado.firma')->whereIn('id_firma', array_column($manifest['signers'], 'id_firma'))->lockForUpdate()->get();
                DB::table('gestion.persona_trabajador')->whereIn('id_persona_trabajador', array_column($manifest['signers'], 'id_persona_trabajador'))->lockForUpdate()->get();
                DB::table('gestion.persona')->whereIn('id_persona', [$manifest['context']['id_persona'], ...array_column($manifest['signers'], 'id_persona')])->lockForUpdate()->get();
                $context = $this->context($sessionId, $participationId, (int) $manifest['context']['id_tipo_certificado'], $actor);
                $signers = ($manifest['workers'] ?? null) === null ? ($minimal ? [] : $this->signers((int) $context->id_actividad))
                    : $this->selectedSigners($context, $manifest['workers']);
                abort_unless((array) $context === $manifest['context'] && $signers === $manifest['signers']
                    && hash_equals($manifest['template_hash'], $this->templateHash()), 409, 'El contexto o los firmantes cambiaron; revise un nuevo PDF.');
                abort_if(DB::table('certificado.certificado')->where('id_participacion', $participationId)->where('id_tipo_certificado', $context->id_tipo_certificado)
                    ->where(fn (Builder $query) => $query->whereNull('id_actividad_sesion')->orWhere('id_actividad_sesion', $sessionId))->exists(),
                    409, 'Ya existe un certificado de este tipo para la sesión o un antecedente sin sesión.');
                $pdf = Storage::disk('local')->get($path.'.pdf');
                abort_unless(is_string($pdf) && str_starts_with($pdf, '%PDF-') && hash_equals($manifest['hash'], hash('sha256', $pdf)), 409, 'El archivo revisado fue alterado o no está disponible.');
                $candidatePath = 'certificados/preparados/'.$manifest['code'].'.pdf';
                if (Storage::disk('local')->exists($candidatePath)) {
                    abort_if(DB::table('certificado.certificado_documento')->where('ruta_archivo', $candidatePath)->exists(),
                        409, 'El archivo ya pertenece a otro documento.');
                    $this->generator->assertArtifact((object) ['ruta_archivo' => $candidatePath, 'hash_certificado' => $manifest['hash']]);
                } else {
                    $this->writeExclusive($candidatePath, $pdf, function () use (&$createdPath, $candidatePath): void {
                        $createdPath = $candidatePath;
                    });
                }
                $createdApproval = app(CertificateDocumentVersions::class)->retainApproval($manifest);
                $certificateId = DB::table('certificado.certificado')->insertGetId([
                    'id_persona' => $context->id_persona, 'id_participacion' => $participationId, 'id_actividad_sesion' => $sessionId,
                    'id_tipo_certificado' => $context->id_tipo_certificado, 'id_practica' => null, 'id_reconocimiento' => null,
                    'fecha_emision' => null, 'estado' => self::PREPARED, 'notificado' => false, 'fecha_notificacion' => null,
                ], 'id_certificado');
                $documentData = [
                    'id_certificado' => $certificateId, 'codigo_verificacion' => $manifest['code'], 'ruta_archivo' => $candidatePath,
                    'hash_certificado' => $manifest['hash'], 'fecha_generacion' => now(), 'estado' => 'Vigente',
                ];
                $this->generator->assertArtifact((object) $documentData);
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
                    $result = $this->preparationResult($documentId, $actor, true, $send, $sessionId);
                    $commitMayHaveSucceeded = true;

                    return [...$result, 'creado' => true, 'mensaje_aprobado' => $manifest['message'],
                        'mensaje_persistido_en_pdf' => true];
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
            $this->generator->discardFailedArtifact($createdApproval, $exception, $commitMayHaveSucceeded);
            throw $exception;
        }
    }

    private function writeReviewedPdf(string $path, string $bytes, callable $opened): void
    {
        $disk = Storage::disk('local');
        if (! $disk->exists($path)) {
            try {
                $this->writeExclusive($path, $bytes, $opened);

                return;
            } catch (HttpException $exception) {
                if ($exception->getStatusCode() !== 409 || ! $disk->exists($path)) {
                    throw $exception;
                }
            }
        }
        abort_unless(hash_equals(hash('sha256', $bytes), hash('sha256', $disk->get($path))),
            409, 'La ruta reservada contiene otro documento; no se sobrescribirá. Genere una nueva revisión.');
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
        if (! is_resource($stream)) {
            clearstatcache(true, $disk->path($path));
            abort_if(file_exists($disk->path($path)), 409, 'La ruta ya está reservada por un archivo existente; no se sobrescribirá.');
            Log::warning('certificado.archivo.reserva_no_disponible', ['directorio_escribible' => is_writable($directory)]);
            abort(503, 'El almacenamiento privado no permite reservar el archivo. Vuelva a intentar.');
        }
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
        abort_if($sessionId !== null && (int) ($document->id_sesion ?? $document->id_actividad_sesion ?? 0) !== $sessionId,
            422, 'El documento pertenece a otra sesión.');
        if ($this->usesMinimalSchema()) {
            $workflow = app(CertificateDocumentWorkflow::class);
            if ($document->estado_certificado === self::PENDING) {
                $this->generator->assertArtifact($document);

                return [...$workflow->detail($id, $actor), 'enviado' => true, 'reintento' => true, 'estado_flujo' => self::PENDING,
                    'preparacion_persistida' => true, 'puede_enviar_gerencia' => false, 'siguiente_paso' => 'consultar_gerencia'];
            }
            $review = $workflow->review($id, $actor);
            $result = $workflow->remit($id, $review['token_revision'], $actor);

            return [...$result['documento'], ...$result, 'preparacion_persistida' => true,
                'puede_enviar_gerencia' => false, 'siguiente_paso' => 'consultar_gerencia'];
        }

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
