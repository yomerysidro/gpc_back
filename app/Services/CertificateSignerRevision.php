<?php

namespace App\Services;

use App\Models\User\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class CertificateSignerRevision
{
    public function __construct(private CertificateSigningStageB $signing, private CertificateDocumentWorkflow $workflow, private CertificateDocumentGenerator $generator, private CertificateDocumentVersions $versions) {}

    /** @param list<array<string, mixed>> $signers */
    public static function planHash(array $signers, ?string $mode): string
    {
        return hash('sha256', json_encode(['modalidad' => $mode, 'firmantes' => array_map(fn (array $signer): array => [
            'trabajador' => (int) $signer['id_persona_trabajador'], 'nombre' => $signer['nombre'],
            'cargo' => $signer['cargo'], 'orden' => (int) $signer['orden_firma'],
        ], $signers)], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  list<array{id_persona_trabajador: int, orden_firma: int}>|null  $selection
     * @return array<string, mixed>
     */
    public function review(int $id, User $actor, ?string $message = null, ?array $selection = null, ?string $mode = null): array
    {
        $document = $this->signing->pendingForUpdate($id, $actor);
        abort_if(CertificateHumanResources::delivery($document) !== null, 409, 'El documento ya fue remitido a RR. HH.; se conserva la revisión enviada.');
        abort_unless(($document->id_sesion ?? null) !== null && $document->fecha_sesion !== null,
            409, 'La revisión con responsables requiere un certificado con contexto real de sesión.');
        $progress = $this->signing->progress($id, $actor);
        $signers = $selection === null ? $progress['firmantes_asignados'] : $this->resolveSelection($id, $actor, $selection, $mode);
        $mode = $selection === null ? $document->modalidad_firma : $mode;
        abort_unless(count($signers) >= 1 && count($signers) <= 8 && $mode !== null,
            422, 'Se requiere un plan de entre uno y ocho responsables para revisar el PDF.');
        $this->assertEligible($id, $actor, $signers);
        $token = bin2hex(random_bytes(32));
        $path = 'certificados/revisiones-firmantes/'.hash('sha256', $token);
        $approved = $this->versions->approvedContent($document);
        abort_unless($message === null || $message === $approved['message'], 422, 'El texto aprobado no puede cambiar al incorporar responsables.');
        $code = $document->codigo_verificacion;
        $pdf = $this->generator->reviewPdf((object) $approved['context'], $approved['message'], $code, $signers);
        $manifest = ['documento' => $id, 'certificado' => (int) $document->id_certificado,
            'actor' => (int) $actor->getAuthIdentifier(), 'expires' => now()->addMinutes(30)->timestamp,
            'anterior' => $document->hash_certificado, 'plan' => self::planHash($signers, $mode),
            'contexto' => $this->contextHash($document), 'code' => $code, 'hash' => hash('sha256', $pdf),
            'plantilla' => $this->templateHash(), 'message' => $approved['message'], 'approved_context' => $approved['context'],
            'original_hash' => $document->hash_certificado_original ?? $document->hash_certificado,
            'original_path' => $document->ruta_archivo_original ?? $document->ruta_archivo,
            'previous_revision' => $document->revision_pdf ?? null];
        $created = [];
        try {
            $this->write($path.'.pdf', $pdf);
            $created[] = $path.'.pdf';
            $this->write($path.'.manifest', Crypt::encryptString(json_encode($manifest, JSON_THROW_ON_ERROR)));
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($created);
            throw $exception;
        }

        return ['token_revision' => $token, 'hash_pdf' => $manifest['hash'], 'expires_at' => date(DATE_ATOM, $manifest['expires']),
            'id_certificado_documento' => $id, 'id_certificado' => (int) $document->id_certificado, 'codigo_verificacion' => $code,
            'mensaje' => $approved['message'], 'requiere_confirmacion' => true, 'firmantes' => $signers, 'modalidad' => $mode, 'persistido' => false,
            'vista_previa_pdf' => ['contenido_base64' => base64_encode($pdf), 'tipo_contenido' => 'application/pdf']];
    }

    /**
     * @param  list<array{id_persona_trabajador: int, orden_firma: int}>  $selection
     * @return list<array<string, mixed>>
     */
    private function resolveSelection(int $id, User $actor, array $selection, ?string $mode): array
    {
        abort_unless(in_array($mode, ['PARALELA', 'SECUENCIAL'], true) && count($selection) >= 1 && count($selection) <= 8,
            422, 'Seleccione modalidad y entre uno y ocho responsables.');
        usort($selection, fn (array $left, array $right): int => $left['orden_firma'] <=> $right['orden_firma']);
        abort_unless(array_column($selection, 'orden_firma') === range(1, count($selection)), 422, 'Los órdenes deben ser consecutivos desde uno.');
        $available = collect($this->signing->availableSigners($id, $actor));
        $people = [];
        $resolved = [];
        foreach ($selection as $entry) {
            $worker = $available->firstWhere('id_persona_trabajador', $entry['id_persona_trabajador']);
            abort_unless($worker && trim($worker['nombre']) !== '' && trim((string) $worker['cargo']) !== '',
                422, 'El responsable no tiene identidad, cargo o ámbito vigente.');
            abort_if(in_array($worker['id_persona'], $people, true), 422, 'No se permite repetir a la misma persona.');
            $people[] = $worker['id_persona'];
            $resolved[] = array_replace($worker, ['orden_firma' => $entry['orden_firma'], 'fecha_firma' => null]);
        }

        return $resolved;
    }

    /** @return array{descartado: bool, persistido: bool, id_certificado_documento: int} */
    public function cancel(int $id, User $actor, string $token): array
    {
        $this->signing->pendingForUpdate($id, $actor);
        $path = 'certificados/revisiones-firmantes/'.hash('sha256', $token);
        if (Storage::disk('local')->exists($path.'.manifest')) {
            $manifest = json_decode(Crypt::decryptString(Storage::disk('local')->get($path.'.manifest')), true, 512, JSON_THROW_ON_ERROR);
            abort_unless($manifest['documento'] === $id && $manifest['actor'] === (int) $actor->getAuthIdentifier(), 403);
            abort_unless(Storage::disk('local')->delete([$path.'.manifest', $path.'.pdf']), 500, 'No se pudo descartar el borrador.');
        }

        return ['descartado' => true, 'persistido' => false, 'id_certificado_documento' => $id];
    }

    /** @return array<string, mixed> */
    public function confirm(int $id, User $actor, string $token, ?string $expectedHash = null): array
    {
        $this->signing->pendingForUpdate($id, $actor);
        $path = 'certificados/revisiones-firmantes/'.hash('sha256', $token);
        abort_unless(Storage::disk('local')->exists($path.'.manifest'), 409, 'La revisión no existe.');
        try {
            $manifest = json_decode(Crypt::decryptString(Storage::disk('local')->get($path.'.manifest')), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            abort(409, 'La revisión no es válida.');
        }
        abort_unless($manifest['documento'] === $id && $manifest['actor'] === (int) $actor->getAuthIdentifier(), 403, 'La revisión pertenece a otro documento o usuario.');
        abort_unless($expectedHash === null || hash_equals($manifest['hash'], $expectedHash), 409, 'El hash no corresponde al PDF revisado.');
        $created = null;
        $approved = false;
        try {
            return DB::transaction(function () use ($id, $actor, $path, $manifest, $token, &$created, &$approved): array {
                $certificate = DB::table('certificado.certificado')->where('id_certificado', $manifest['certificado'])->lockForUpdate()->first();
                abort_unless($certificate, 409, 'El certificado de la revisión no existe.');
                DB::table('gestion.persona')->where('id_persona', $certificate->id_persona)->lockForUpdate()->first();
                DB::table('certificado.tipo_certificado')->where('id_tipo_certificado', $certificate->id_tipo_certificado)->lockForUpdate()->first();
                if (($certificate->id_actividad_sesion ?? null) !== null) {
                    $session = DB::table('certificado.actividad_sesion')->where('id_actividad_sesion', $certificate->id_actividad_sesion)->lockForUpdate()->first();
                    abort_unless($session, 409, 'La sesión de la revisión no existe.');
                    DB::table('certificado.actividad')->where('id_actividad', $session->id_actividad)->lockForUpdate()->first();
                }
                DB::table('certificado.certificado_documento')->where('id_certificado_documento', $id)->lockForUpdate()->first();
                DB::table('certificado.certificado_firma')->where('id_certificado_documento', $id)->lockForUpdate()->get();
                $workerIds = DB::table('certificado.certificado_firma')->where('id_certificado_documento', $id)->pluck('id_persona_trabajador');
                $workers = DB::table('gestion.persona_trabajador')->whereIn('id_persona_trabajador', $workerIds)->lockForUpdate()->get();
                DB::table('gestion.persona')->whereIn('id_persona', $workers->pluck('id_persona'))->lockForUpdate()->get();
                $document = $this->signing->pendingForUpdate($id, $actor);
                $progress = $this->signing->progress($id, $actor);
                if (($document->revision_pdf ?? null) === hash('sha256', $token)) {
                    abort_unless(hash_equals($manifest['hash'], $document->hash_certificado)
                        && $progress['pdf_revisado_disponible'], 409, 'La revisión confirmada cambió.');

                    return ['completada' => true, 'persistido' => true, 'reintento' => true,
                        'persistencia' => 'archivo_privado_versionado', 'codigo_verificacion' => $document->codigo_verificacion,
                        'estado_administrativo' => $progress['estado_administrativo'], 'hash_pdf' => $document->hash_certificado,
                        'revision_pdf' => $document->revision_pdf, 'id_certificado_documento' => $id,
                        'id_certificado' => (int) $document->id_certificado] + $progress;
                }
                abort_unless($manifest['expires'] > now()->timestamp, 409, 'La revisión caducó.');
                abort_if(CertificateHumanResources::delivery($document) !== null, 409, 'El documento ya fue remitido a RR. HH.');
                $signers = $progress['firmantes_asignados'];
                $this->assertEligible($id, $actor, $signers);
                abort_unless((int) $document->id_certificado === $manifest['certificado']
                    && hash_equals($manifest['anterior'], $document->hash_certificado)
                    && hash_equals($manifest['plan'], self::planHash($signers, $document->modalidad_firma))
                    && hash_equals($manifest['contexto'], $this->contextHash($document))
                    && hash_equals($manifest['plantilla'], $this->templateHash()), 409, 'El contexto o los responsables cambiaron; revise un nuevo PDF.');
                abort_unless($document->codigo_verificacion === $manifest['code'], 409, 'Cambió el código de verificación.');
                abort_unless(DB::table('certificado.certificado_documento')->where('id_certificado', $document->id_certificado)
                    ->where('estado', 'Vigente')->count() === 1, 409, 'Existe más de una versión vigente.');
                $pdf = Storage::disk('local')->get($path.'.pdf');
                abort_unless(is_string($pdf) && str_starts_with($pdf, '%PDF-') && hash_equals($manifest['hash'], hash('sha256', $pdf)), 409, 'El PDF revisado cambió.');
                $revisionId = hash('sha256', $token);
                $candidate = 'certificados/versiones/'.$id.'/'.$revisionId.'.pdf';
                $this->write($candidate, $pdf);
                $created = $candidate;
                $record = ['document' => $id, 'certificate' => (int) $document->id_certificado,
                    'code' => $document->codigo_verificacion, 'revision' => $revisionId,
                    'previous_hash' => $manifest['anterior'], 'original_hash' => $manifest['original_hash'],
                    'previous_revision' => $manifest['previous_revision'] ?? null,
                    'original_path' => $manifest['original_path'], 'path' => $candidate, 'hash' => $manifest['hash'],
                    'plan' => $manifest['plan'], 'message' => $manifest['message'], 'context' => $manifest['approved_context'],
                    'actor' => (int) $actor->getAuthIdentifier(), 'approved_at' => now()->toIso8601String()];
                $this->versions->approve($document, $record, function () use ($document, $candidate, $manifest): void {
                    $this->generator->assertArtifact($document);
                    $this->generator->assertArtifact((object) ['ruta_archivo' => $candidate, 'hash_certificado' => $manifest['hash']]);
                });
                $approved = true;

                return ['completada' => true, 'persistido' => true, 'reintento' => false,
                    'estado_administrativo' => 'PREPARADO', 'hash_pdf' => $manifest['hash'],
                    'persistencia' => 'archivo_privado_versionado',
                    'id_certificado_documento' => $id, 'id_certificado' => (int) $document->id_certificado,
                    'codigo_verificacion' => $document->codigo_verificacion, 'revision_pdf' => $revisionId]
                    + $this->signing->progress($id, $actor);
            });
        } catch (Throwable $exception) {
            $this->generator->discardFailedArtifact($created, $exception, $approved);
            throw $exception;
        }
    }

    /** @param list<array<string, mixed>> $signers */
    private function assertEligible(int $id, User $actor, array $signers): void
    {
        $available = collect($this->signing->availableSigners($id, $actor));
        foreach ($signers as $signer) {
            abort_unless($signer['fecha_firma'] === null && $available->contains('id_persona_trabajador', $signer['id_persona_trabajador']),
                409, 'Los responsables deben estar vigentes, pendientes y dentro del ámbito.');
        }
    }

    private function contextHash(object $document): string
    {
        return hash('sha256', json_encode(array_intersect_key((array) $document, array_flip([
            'id_persona', 'nombres', 'apellido_paterno', 'apellido_materno', 'id_sesion', 'sesion', 'fecha_sesion',
            'id_actividad', 'actividad', 'id_area', 'id_tipo_certificado', 'tipo_certificado',
        ])), JSON_THROW_ON_ERROR));
    }

    private function templateHash(): string
    {
        return hash('sha256', hash_file('sha256', resource_path('views/certificates/demonstration.blade.php'))
            .hash_file('sha256', resource_path('images/escudo-morales.png')));
    }

    private function write(string $path, string $bytes): void
    {
        $this->versions->writeExclusive($path, $bytes);
    }
}
