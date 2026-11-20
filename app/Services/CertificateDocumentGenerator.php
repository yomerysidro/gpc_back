<?php

namespace App\Services;

use App\Http\Data\ActivityRequests\ActivityRequestData;
use App\Models\User\User;
use App\Services\RequestSchema as Schema;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Dompdf\Dompdf;
use Dompdf\Image\Cache;
use Dompdf\Options;
use Illuminate\Database\LostConnectionDetector;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class CertificateDocumentGenerator
{
    public const ARTIFACT_MISSING = 1;

    public function privatePdfResponse(object $verifiedDocument, bool $download = false): BinaryFileResponse
    {
        return response()->download(
            Storage::disk('local')->path($verifiedDocument->ruta_archivo),
            'certificado-'.$verifiedDocument->codigo_verificacion.'.pdf',
            [
                'Content-Type' => 'application/pdf',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
                'X-Document-SHA256' => $verifiedDocument->hash_certificado,
            ],
            $download ? 'attachment' : 'inline',
        )->setPrivate();
    }

    public function authorizedArtifact(object $document, bool $readOnly = false): object
    {
        $original = clone $document;
        $original->ruta_archivo = $document->ruta_archivo_original ?? $document->ruta_archivo;
        $original->hash_certificado = $document->hash_certificado_original ?? $document->hash_certificado;
        $this->assertArtifact($original);
        $resolved = app(CertificateDocumentVersions::class)->resolve($original, $readOnly);
        $this->assertArtifact($resolved);

        return $resolved;
    }

    /**
     * Prepare an unsigned development document for an existing activity certificate.
     *
     * @return array<string, mixed>
     */
    public function generate(int $certificateId, User $actor, ?callable $artifactCreated = null): array
    {
        app(CertificationReadiness::class)->assertDevelopmentClosureEnvironment();
        $createdPath = null;
        $callbackCompleted = false;
        try {
            $result = DB::transaction(function () use ($certificateId, $actor, $artifactCreated, &$createdPath, &$callbackCompleted): array {
                $certificate = DB::table('certificado.certificado')->where('id_certificado', $certificateId)->lockForUpdate()->first();
                abort_unless($certificate, 404, 'Certificado administrativo no encontrado.');
                $context = $this->context($certificate, $actor);
                $demonstration = $certificate->estado === SessionCertificatePreparation::DEMONSTRATION_STATE;
                $existing = DB::table('certificado.certificado_documento')->where('id_certificado', $certificateId)
                    ->orderByDesc('id_certificado_documento')->lockForUpdate()->first();
                if ($existing) {
                    abort_unless((str_starts_with($existing->ruta_archivo, 'certificados/desarrollo/') || str_starts_with($existing->ruta_archivo, 'certificados/demostracion/'))
                        && $existing->estado === 'Vigente', 409,
                        'El certificado tiene documentación previa; se requiere un procedimiento de reemisión.');
                    $this->assertArtifact($existing);

                    return $this->response($existing, false, $demonstration);
                }

                abort_unless(class_exists(Dompdf::class) && class_exists(Writer::class), 409,
                    'Faltan las dependencias autorizadas de generación PDF y QR. No se generó ni registró un documento.');

                $code = bin2hex(random_bytes(15));
                $verificationUrl = $this->verificationUrl($code, $demonstration);
                $qr = (new Writer(new ImageRenderer(new RendererStyle(200, 4), new SvgImageBackEnd)))->writeString($verificationUrl);
                $options = new Options;
                $options->set('isRemoteEnabled', false);
                $options->set('isPhpEnabled', false);
                $options->set('isJavascriptEnabled', false);
                $options->set('defaultFont', 'DejaVu Sans');
                $options->set('tempDir', storage_path('framework/cache'));
                $options->set('fontCache', storage_path('framework/cache'));
                $pdf = new Dompdf($options);
                try {
                    $signers = $demonstration ? $this->signers((int) $context->id_actividad) : [];
                    $pdf->loadHtml(view($demonstration ? 'certificates.demonstration' : 'certificates.development', [
                        'context' => $context, 'certificate' => $certificate, 'code' => $code,
                        'qrDataUri' => 'data:image/svg+xml;base64,'.base64_encode($qr),
                        'logoDataUri' => 'data:image/png;base64,'.base64_encode(file_get_contents(resource_path('images/escudo-morales.png'))),
                        'signers' => $signers, 'signatureImages' => $this->signatureImages($signers),
                    ])->render(), 'UTF-8');
                    $pdf->setPaper('A4', 'landscape');
                    $pdf->render();
                    abort_unless($pdf->getCanvas()->get_page_count() === 1, 422,
                        'El contenido excede una página; reduzca el mensaje o revise la distribución de firmantes.');
                    $content = $pdf->output();
                } finally {
                    unset($pdf);
                    gc_collect_cycles();
                }
                abort_unless(str_starts_with($content, '%PDF-'), 500, 'El generador no produjo un PDF válido.');
                $path = 'certificados/'.($demonstration ? 'demostracion' : 'desarrollo').'/'.$code.'.pdf';
                abort_unless(Storage::disk('local')->makeDirectory(dirname($path)), 500,
                    'No se pudo preparar el almacenamiento privado.');
                $stream = @fopen(Storage::disk('local')->path($path), 'x+b');
                abort_unless(is_resource($stream), 409, 'No se pudo reservar un archivo nuevo sin sobrescribir documentos existentes.');
                $createdPath = $path;
                if ($artifactCreated !== null) {
                    $artifactCreated($createdPath);
                }
                try {
                    $written = 0;
                    while ($written < strlen($content)) {
                        $bytes = fwrite($stream, substr($content, $written));
                        abort_unless(is_int($bytes) && $bytes > 0, 500, 'No se pudo guardar el PDF completo.');
                        $written += $bytes;
                    }
                    abort_unless(fflush($stream), 500, 'No se pudo completar la escritura del PDF.');
                } finally {
                    fclose($stream);
                }
                $data = [
                    'id_certificado' => $certificateId, 'codigo_verificacion' => $code,
                    'hash_certificado' => hash('sha256', $content), 'ruta_archivo' => $createdPath,
                    'fecha_generacion' => now(), 'estado' => 'Vigente',
                ];
                $this->assertArtifact((object) $data);
                $id = DB::table('certificado.certificado_documento')->insertGetId($data, 'id_certificado_documento');
                $callbackCompleted = true;

                return $this->response((object) ['id_certificado_documento' => $id, ...$data], true, $demonstration);
            });
        } catch (Throwable $exception) {
            $this->discardFailedArtifact($createdPath, $exception, $callbackCompleted);
            throw $exception;
        }
        if ($result['creado']) {
            Log::info('certificado_documento.desarrollo_generado', [
                'id_usuario' => (int) $actor->getAuthIdentifier(), 'id_certificado' => $certificateId,
                'id_certificado_documento' => $result['id_certificado_documento'], 'oficial' => false,
            ]);
        }

        return $result;
    }

    /**
     * A lost commit response leaves the database outcome unknown.
     * Preserve that private file for reconciliation instead of breaking a possible committed document.
     */
    public function discardFailedArtifact(?string $path, Throwable $exception, bool $commitMayHaveSucceeded = false): void
    {
        if ($path === null) {
            return;
        }
        if ($commitMayHaveSucceeded || (new LostConnectionDetector)->causedByLostConnection($exception)) {
            return;
        }
        Storage::disk('local')->delete($path);
    }

    public function authorizeDocument(object $document, User $actor): void
    {
        $certificate = DB::table('certificado.certificado')->where('id_certificado', $document->id_certificado)->first();
        abort_unless($certificate, 404, 'Certificado administrativo no encontrado.');
        $this->context($certificate, $actor);
    }

    /** @param list<array<string, mixed>>|null $signers */
    public function preview(object $context, ?string $message = null, ?array $signers = null, bool $academicDraft = false): string
    {
        $signers ??= $this->signers((int) $context->id_actividad);

        return view('certificates.demonstration', [
            'context' => $context, 'certificate' => $academicDraft ? (object) ['fecha_emision' => null] : null, 'code' => null, 'qrDataUri' => null,
            'customMessage' => $message,
            'logoDataUri' => 'data:image/png;base64,'.base64_encode(file_get_contents(resource_path('images/escudo-morales.png'))),
            'signers' => $signers, 'signatureImages' => $this->signatureImages($signers),
        ])->render();
    }

    public function previewPdf(string $html): string
    {
        abort_unless(class_exists(Dompdf::class), 409, 'El generador PDF no está disponible.');
        gc_collect_cycles();
        $temporaryDirectory = storage_path('framework/cache/pdf-'.bin2hex(random_bytes(16)));
        abort_unless(File::makeDirectory($temporaryDirectory, 0700), 503, 'No se pudo reservar el directorio temporal del PDF.');
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('isJavascriptEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('tempDir', $temporaryDirectory);
        $options->set('fontCache', storage_path('framework/cache'));
        $pdf = new Dompdf($options);
        try {
            $pdf->loadHtml($html, 'UTF-8');
            $pdf->setPaper('A4', 'landscape');
            $pdf->render();
            abort_unless($pdf->getCanvas()->get_page_count() === 1, 422, 'El contenido excede una página; reduzca el mensaje.');
            $content = $pdf->output();
            abort_unless(str_starts_with($content, '%PDF-'), 500, 'No se pudo generar la vista previa PDF.');

            return $content;
        } finally {
            unset($pdf);
            Cache::clear();
            File::deleteDirectory($temporaryDirectory);
            gc_collect_cycles();
        }
    }

    /**
     * @param  list<array<string, mixed>>  $signers
     * @param  array<int, string>|null  $signatureImages
     */
    public function reviewPdf(object $context, string $message, string $code, array $signers, ?array $signatureImages = null): string
    {
        $qr = (new Writer(new ImageRenderer(new RendererStyle(200, 4), new SvgImageBackEnd)))
            ->writeString($this->verificationUrl($code));

        return $this->previewPdf(view(in_array($context->origen ?? null, ['reconocimiento', 'practica', 'ponente_actividad', 'ponente_sesion'], true) ? 'certificates.origins' : 'certificates.demonstration', [
            'context' => $context, 'certificate' => null, 'customMessage' => $message,
            'code' => $code, 'qrDataUri' => 'data:image/svg+xml;base64,'.base64_encode($qr),
            'logoDataUri' => 'data:image/png;base64,'.base64_encode(file_get_contents(resource_path('images/escudo-morales.png'))),
            'signers' => $signers, 'signatureImages' => $signatureImages ?? $this->signatureImages($signers),
            'administrative' => true,
        ])->render());
    }

    /**
     * @param  list<array<string, mixed>>  $signers
     * @return array<int, string>
     */
    public function signatureImages(array $signers): array
    {
        $workerIds = array_column($signers, 'id_persona_trabajador');
        if ($workerIds === [] || ! Schema::hasColumn('gestion.persona_trabajador', 'imagen_firma_cifrada')) {
            return [];
        }

        return DB::table('gestion.persona_trabajador')->whereIn('id_persona_trabajador', $workerIds)
            ->whereNotNull('imagen_firma_cifrada')->orderBy('id_persona_trabajador')
            ->pluck('imagen_firma_cifrada', 'id_persona_trabajador')
            ->map(fn (string $image): string => Crypt::decryptString($image))->all();
    }

    /** @return list<array<string, mixed>> */
    public function signers(int $activityId): array
    {
        if (! Schema::hasTable('certificado.actividad_firma') || ! Schema::hasTable('certificado.firma')
            || ! Schema::hasTable('gestion.persona_trabajador')) {
            return [];
        }

        $signers = DB::table('certificado.actividad_firma as af')
            ->join('certificado.firma as f', 'f.id_firma', '=', 'af.id_firma')
            ->join('gestion.persona_trabajador as t', 't.id_persona_trabajador', '=', 'f.id_persona_trabajador')
            ->join('gestion.persona as p', 'p.id_persona', '=', 't.id_persona')
            ->where('af.id_actividad', $activityId)->where('t.estado', true)->whereDate('t.fecha_ingreso', '<=', today())
            ->where(function (Builder $query): void {
                $query->whereNull('t.fecha_fin')->orWhereDate('t.fecha_fin', '>=', today());
            })
            ->orderBy('af.orden_firma')->orderBy('af.id_actividad_firma')
            ->get(['p.id_persona', 'f.id_firma', 't.id_persona_trabajador', 'af.orden_firma', 'p.nombres', 'p.apellido_paterno', 'p.apellido_materno', 't.cargo']);
        if ($signers->isEmpty()) {
            return [];
        }
        abort_unless($signers->pluck('id_persona')->unique()->count() === $signers->count(), 409,
            'Una misma persona tiene varias asignaciones de firma; corrija la configuración antes de generar el documento.');
        $roles = collect();
        if (collect(Schema::getSchemas())->contains('name', 'acceso') && Schema::hasTable('acceso.usuario') && Schema::hasTable('acceso.usuario_rol') && Schema::hasTable('acceso.rol')) {
            $roles = DB::table('acceso.usuario as u')->join('acceso.usuario_rol as ur', 'ur.id_usuario', '=', 'u.id_usuario')
                ->join('acceso.rol as r', 'r.id_rol', '=', 'ur.id_rol')
                ->whereIn('u.id_persona', $signers->pluck('id_persona'))->where('u.estado', true)->where('r.estado', true)
                ->distinct()->orderBy('r.nombre')->get(['u.id_persona', 'r.nombre'])->groupBy('id_persona');
        }

        return $signers
            ->map(fn (object $row): array => [
                'id_firma' => (int) $row->id_firma, 'id_persona' => (int) $row->id_persona,
                'id_persona_trabajador' => (int) $row->id_persona_trabajador, 'orden_firma' => (int) $row->orden_firma,
                'nombre' => trim(implode(' ', array_filter([$row->nombres, $row->apellido_paterno, $row->apellido_materno]))),
                'cargo' => $row->cargo, 'roles' => ($roles->get($row->id_persona) ?? collect())->pluck('nombre')->values()->all(),
                'asignado_actividad' => true, 'firma_verificada' => false, 'autorizacion_criptografica_verificada' => false,
                'estado' => 'Configurado',
            ])->all();
    }

    public function assertArtifact(object $document): void
    {
        $path = $document->ruta_archivo;
        abort_unless(is_string($path) && str_starts_with($path, 'certificados/')
            && ! str_contains($path, '..') && ! str_contains($path, '\\')
            && preg_match('~\.pdf$~iD', $path) === 1, 409, 'Ruta documental no compatible.');
        $file = realpath(Storage::disk('local')->path($path));
        if ($file === false) {
            throw new ConflictHttpException('Documento no disponible. El PDF registrado no está en el almacenamiento privado.', code: self::ARTIFACT_MISSING);
        }
        $root = realpath(Storage::disk('local')->path('certificados'));
        $base = realpath(Storage::disk('local')->path(''));
        foreach ([public_path(), storage_path('app/public')] as $publicDirectory) {
            $publicRoot = realpath($publicDirectory);
            abort_if($file && $publicRoot && str_starts_with(strtolower($file), strtolower($publicRoot.DIRECTORY_SEPARATOR)), 409,
                'El documento debe permanecer en almacenamiento privado.');
        }
        abort_unless($file && $root && $base && str_starts_with($root, $base.DIRECTORY_SEPARATOR)
            && str_starts_with($file, $root.DIRECTORY_SEPARATOR)
            && is_file($file) && is_readable($file)
            && preg_match('/^[a-f0-9]{64}$/iD', $document->hash_certificado ?? '') === 1
            && file_get_contents($file, false, null, 0, 5) === '%PDF-'
            && hash_equals(strtolower($document->hash_certificado), hash_file('sha256', $file)), 409,
            'El documento no está disponible o su integridad no coincide con el registro.');
    }

    public function documentByCode(string $code): object
    {
        $document = DB::table('certificado.certificado_documento as d')
            ->join('certificado.certificado as c', 'c.id_certificado', '=', 'd.id_certificado')
            ->where('d.codigo_verificacion', $code)
            ->select('d.*', 'c.estado as estado_certificado', 'c.fecha_emision', 'c.id_tipo_certificado', 'c.id_participacion', 'c.id_persona')->first();
        abort_unless($document && $document->codigo_verificacion === $code, 404, 'Certificado no encontrado.');
        if ($document->estado_certificado === SessionCertificatePreparation::DEMONSTRATION_STATE) {
            $document->demonstration_context = null;
            if (app(SessionCertificatePreparation::class)->identityAvailable()) {
                $document->demonstration_context = DB::table('certificado.certificado as c')
                    ->join('certificado.participacion as p', 'p.id_participacion', '=', 'c.id_participacion')
                    ->join('certificado.actividad_sesion as s', 's.id_actividad_sesion', '=', 'c.id_actividad_sesion')
                    ->join('certificado.actividad as a', 'a.id_actividad', '=', 's.id_actividad')
                    ->join('certificado.asistencia as attendance', function (JoinClause $join): void {
                        $join->on('attendance.id_participacion', '=', 'p.id_participacion')->on('attendance.id_actividad_sesion', '=', 's.id_actividad_sesion');
                    })
                    ->join('gestion.persona as persona', 'persona.id_persona', '=', 'c.id_persona')
                    ->join('certificado.tipo_certificado as tipo', 'tipo.id_tipo_certificado', '=', 'c.id_tipo_certificado')
                    ->where('c.id_certificado', $document->id_certificado)->whereColumn('p.id_persona', 'c.id_persona')
                    ->whereColumn('p.id_actividad', 's.id_actividad')
                    ->first(['a.nombre as actividad', 's.nombre as sesion', 's.fecha as fecha_sesion', 'tipo.nombre_tipo as tipo_certificado',
                        'persona.nombres', 'persona.apellido_paterno', 'persona.apellido_materno']);
            }
        }

        return $document;
    }

    /** @return array<string, mixed> */
    public function verification(object $document, ?object $publicArtifact = null): array
    {
        if (str_starts_with((string) $document->ruta_archivo, 'certificados/preparados/')
            || in_array($document->estado_certificado, [CertificateManagement::PREPARED, CertificateManagement::PENDING,
                CertificateManagement::SIGNED, CertificateManagement::ISSUED], true)) {
            $integrity = true;
            if ($publicArtifact === null) {
                try {
                    $this->assertArtifact($document);
                } catch (HttpException) {
                    $integrity = false;
                }
            }
            $publicUrl = $publicArtifact === null ? null : app(PublicCertificateLookup::class)->pdfUrl($document->codigo_verificacion);

            return [
                'codigo_verificacion' => $document->codigo_verificacion, 'registro_encontrado' => true,
                'estado_certificado' => $document->estado_certificado, 'estado_documento' => $document->estado,
                'estado_flujo' => $document->estado_flujo ?? $document->estado_certificado, 'vigente' => $publicUrl !== null,
                'puede_ver_documento' => $publicUrl !== null, 'pdf_publicado' => $publicUrl !== null,
                'emision_oficial_verificada' => false, 'firma_verificada' => false,
                'hash_sha256' => $publicArtifact?->hash_certificado, 'url_documento' => $publicUrl,
                'url_descarga' => $publicUrl === null ? null : $publicUrl.'&descargar=1', 'beneficiario' => null,
                'integridad_verificada' => $integrity, 'demostracion' => false, 'modo' => 'administrativo',
                'fecha_emision' => $document->fecha_emision, 'fecha_generacion' => $document->fecha_generacion,
                'motivos' => array_values(array_filter([! $integrity ? 'Documento no disponible.' : null,
                    $document->estado_certificado === CertificateManagement::ISSUED
                    ? 'La emisión registrada requiere verificación criptográfica del PDF final antes de habilitar la consulta pública.'
                    : ($publicUrl === null ? 'Documento administrativo en trámite; no emitido ni disponible públicamente.'
                        : 'PDF confirmado para entrega; la firma digital y la emisión oficial siguen pendientes.')])),
            ];
        }
        $integrity = true;
        try {
            $this->assertArtifact($document);
        } catch (HttpException) {
            $integrity = false;
        }
        $demonstration = $document->estado_certificado === SessionCertificatePreparation::DEMONSTRATION_STATE;
        $identity = $document->demonstration_context ?? null;
        $current = $document->estado === 'Vigente' && (
            ($document->estado_certificado === CertificateManagement::ISSUED && $document->fecha_emision !== null)
            || $document->estado_certificado === 'Vigente'
            || ($demonstration && $identity !== null));
        $development = str_starts_with((string) $document->ruta_archivo, 'certificados/desarrollo/');
        $lookup = app(PublicCertificateLookup::class);
        $publicPdf = $integrity && $lookup->isPublishable($document);

        return [
            'codigo_verificacion' => $document->codigo_verificacion,
            'estado_documento' => $document->estado, 'estado_certificado' => $document->estado_certificado,
            'fecha_generacion' => $document->fecha_generacion, 'fecha_emision' => $document->fecha_emision,
            'registro_encontrado' => true, 'integridad_verificada' => $integrity,
            'hash_sha256' => $integrity ? $document->hash_certificado : null,
            'vigente' => $publicPdf && ! $demonstration, 'puede_ver_documento' => $publicPdf,
            'modo' => $demonstration ? 'demostracion' : ($development ? 'desarrollo' : 'registro'),
            'demostracion' => $demonstration, 'identidad_sesion_verificada' => $demonstration && $identity !== null,
            'tipo_certificado' => $identity?->tipo_certificado,
            'actividad' => $identity?->actividad, 'sesion' => $identity?->sesion, 'fecha_sesion' => $identity?->fecha_sesion,
            'beneficiario' => $identity && config('certification.documents.public_beneficiary_name', false)
                ? trim(implode(' ', array_filter([$identity->nombres, $identity->apellido_paterno, $identity->apellido_materno]))) : null,
            'url_documento' => $publicPdf ? $lookup->pdfUrl($document->codigo_verificacion) : null,
            'url_descarga' => $publicPdf ? $lookup->pdfUrl($document->codigo_verificacion).'&descargar=1' : null,
            'pdf_publicado' => $publicPdf,
            'emision_oficial_verificada' => false, 'firma_verificada' => false,
            'motivos' => array_values(array_filter([
                ! $integrity ? 'Documento no disponible.' : null,
                ! $current ? 'El certificado o su documento no están vigentes.' : null,
                $integrity && $current && ! $publicPdf ? 'El PDF no está autorizado para publicación.' : null,
                $development ? 'Documento de desarrollo; no constituye una emisión oficial.' : null,
                $demonstration ? 'Certificado de demostración; sin firma digital institucional.' : null,
                $demonstration && $identity === null ? 'No se pudo verificar la identidad persistente de la sesión.' : null,
                'La integridad del archivo no acredita una firma criptográfica ni una emisión oficial.',
            ])),
        ];
    }

    private function context(object $certificate, User $actor): object
    {
        abort_unless($certificate->id_participacion !== null
            && $certificate->id_practica === null && $certificate->id_reconocimiento === null, 409,
            'Esta generación provisional solo admite certificados administrativos por participación en actividad.');
        abort_unless(in_array((int) $certificate->id_tipo_certificado, config('certification.development_session_type_ids', []), true), 409,
            'El tipo de certificado no está autorizado para esta generación provisional por participación.');
        $context = DB::table('certificado.participacion as p')
            ->join('certificado.actividad as a', 'a.id_actividad', '=', 'p.id_actividad')
            ->join('gestion.persona as persona', 'persona.id_persona', '=', 'p.id_persona')
            ->crossJoin('certificado.tipo_certificado as tipo')
            ->where('tipo.id_tipo_certificado', (int) $certificate->id_tipo_certificado)
            ->where('tipo.estado', true)
            ->whereRaw("TRIM(tipo.nombre_tipo) <> ''")
            ->where('p.id_participacion', $certificate->id_participacion)->where('p.id_persona', $certificate->id_persona)
            ->select('a.nombre as actividad', 'a.id_area', 'tipo.nombre_tipo as tipo_certificado', 'persona.nombres', 'persona.apellido_paterno', 'persona.apellido_materno')->first();
        abort_unless($context, 409, 'El certificado y el beneficiario no tienen un contexto de participación coherente.');
        if ($certificate->estado === SessionCertificatePreparation::DEMONSTRATION_STATE) {
            $sessionId = $certificate->id_actividad_sesion ?? null;
            abort_unless($sessionId !== null, 409, 'La demostración no identifica una sesión persistente.');
            $session = DB::table('certificado.actividad_sesion as s')
                ->join('certificado.asistencia as asistencia', 'asistencia.id_actividad_sesion', '=', 's.id_actividad_sesion')
                ->join('certificado.participacion as participacion', 'participacion.id_participacion', '=', 'asistencia.id_participacion')
                ->where('s.id_actividad_sesion', $sessionId)->where('participacion.id_participacion', $certificate->id_participacion)
                ->whereColumn('s.id_actividad', 'participacion.id_actividad')
                ->first(['s.nombre', 's.fecha', 's.lugar', 's.id_actividad']);
            abort_unless($session, 409, 'La identidad de sesión y participación del certificado es inconsistente.');
            $context->id_actividad = (int) $session->id_actividad;
            $context->id_sesion = (int) $sessionId;
            $context->sesion = $session->nombre;
            $context->fecha_sesion = $session->fecha;
            $context->lugar = $session->lugar;
        }
        if (! $actor->isSystemAdministrator()) {
            $areas = ActivityRequestData::authorizedAreas((int) $actor->id_persona);
            abort_unless($areas['success'], $areas['status'] ?? 500, 'No se pudo comprobar el ámbito autorizado.');
            abort_unless($areas['data']->contains('id_area', $context->id_area), 403, 'Certificado fuera del ámbito autorizado.');
        }

        return $context;
    }

    private function verificationUrl(string $code, bool $demonstration = false): string
    {
        if (app()->isProduction()) {
            return 'https://certificados-morales.aiyond.org/verificar/'.$code;
        }

        $local = app()->environment('local', 'testing');
        $defaultBase = $demonstration && $local
            ? rtrim((string) config('app.url'), '/').dirname(route('certificates.verify', ['code' => $code], false))
            : rtrim((string) config('app.frontend_url'), '/').'/verificar';
        $base = $local ? rtrim((string) (config('certification.documents.verification_base_url') ?: $defaultBase), '/') : $defaultBase;
        $host = strtolower((string) parse_url($base, PHP_URL_HOST));
        $scheme = parse_url($base, PHP_URL_SCHEME);
        $loopback = in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
        $localDevelopment = app()->environment('local', 'testing') && $loopback && in_array($scheme, ['http', 'https'], true);
        $lanDevelopment = app()->environment('local', 'testing') && config('certification.documents.allow_http_lan') === true
            && $scheme === 'http' && filter_var($host, FILTER_VALIDATE_IP) !== false
            && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE) === false;
        $publicHttps = $scheme === 'https' && $host !== '' && ! $loopback
            && ! str_ends_with($host, '.localhost') && filter_var($host, FILTER_VALIDATE_IP) === false
            && (app()->environment('testing') || ! preg_match('/(?:^|\.)(?:test|invalid|example)$/D', $host));
        abort_unless(($localDevelopment || $lanDevelopment || $publicHttps)
            && ($local || parse_url($base, PHP_URL_PATH) === '/verificar')
            && ! parse_url($base, PHP_URL_USER) && ! parse_url($base, PHP_URL_PASS)
            && ! parse_url($base, PHP_URL_QUERY) && ! parse_url($base, PHP_URL_FRAGMENT), 503,
            'Configure una URL pública HTTPS real de verificación; localhost solo se permite en documentos de desarrollo local.');

        return $base.'/'.$code;
    }

    /** @return array<string, mixed> */
    private function response(object $document, bool $created, bool $demonstration = false): array
    {
        return [
            'id_certificado_documento' => (int) $document->id_certificado_documento,
            'id_certificado' => (int) $document->id_certificado,
            'codigo_verificacion' => $document->codigo_verificacion,
            'hash_certificado' => $document->hash_certificado,
            'url_verificacion' => $this->verificationUrl($document->codigo_verificacion, $demonstration),
            'url_descarga' => route('certificates.document.download', ['id' => $document->id_certificado_documento, 'descargar' => 1], false),
            'creado' => $created, 'modo' => $demonstration ? 'demostracion' : 'desarrollo', 'demostracion' => $demonstration, 'oficial' => false,
            'puede_emitir' => false, 'puede_firmar' => false,
        ];
    }
}
