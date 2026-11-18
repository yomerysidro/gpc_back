<?php

namespace App\Services;

use App\Models\ActivitySession\ActivitySession;
use App\Models\User\User;
use App\Services\RequestSchema as Schema;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class SessionCertificatePreparation
{
    public const DEMONSTRATION_STATE = 'Demostracion';

    public function identityAvailable(): bool
    {
        $request = request();
        $identityKey = self::class.'.identity.'.DB::connection()->getName();
        if ($request->route() !== null && $request->attributes->has($identityKey)) {
            return $request->attributes->get($identityKey);
        }
        $available = $this->inspectIdentity();
        if ($request->route() !== null) {
            $request->attributes->set($identityKey, $available);
        }

        return $available;
    }

    private function inspectIdentity(): bool
    {
        if (! Schema::hasColumn('certificado.certificado', 'id_actividad_sesion')) {
            return false;
        }
        $indexes = Schema::getIndexes('certificado.certificado');
        $unique = collect($indexes)->contains(fn (array $index): bool => $index['unique']
            && $index['columns'] === ['id_participacion', 'id_actividad_sesion', 'id_tipo_certificado']);
        $foreignKeys = Schema::getForeignKeys('certificado.certificado');
        $attendanceReference = collect($foreignKeys)->contains(fn (array $key): bool => $key['columns'] === ['id_participacion', 'id_actividad_sesion']
            && ($key['foreign_schema'] ?? 'certificado') === 'certificado'
            && $key['foreign_table'] === 'asistencia' && $key['foreign_columns'] === ['id_participacion', 'id_actividad_sesion']
            && in_array(strtolower($key['on_delete']), ['restrict', 'no action'], true)
            && in_array(strtolower($key['on_update']), ['restrict', 'no action'], true));

        return $unique && $attendanceReference
            && Schema::hasIndex('certificado.asistencia', ['id_participacion', 'id_actividad_sesion'], 'unique');
    }

    public function resolveType(?int $typeId): int
    {
        $types = DB::table('certificado.tipo_certificado')->where('estado', true)
            ->whereIn('id_tipo_certificado', config('certification.development_session_type_ids', []))
            ->pluck('id_tipo_certificado')->map(fn (mixed $id): int => (int) $id);
        if ($typeId !== null) {
            abort_unless($types->contains($typeId), 422, 'El tipo seleccionado no está vigente o no es compatible con la demostración por sesión.');

            return $typeId;
        }
        abort_unless($types->count() === 1, 422, 'Seleccione un tipo de certificado compatible del catálogo.');

        return $types->first();
    }

    /**
     * @param  list<int>|null  $areaIds
     * @return array<string, mixed>
     */
    public function preview(int $sessionId, int $participationId, int $typeId, ?array $areaIds, ?string $message = null, bool $pdf = false): array
    {
        $readiness = app(CertificationReadiness::class);
        $readiness->assertDevelopmentClosureEnvironment();
        $context = $this->selectedContext($sessionId, $participationId, $typeId, $areaIds);
        $this->assertEligible($context, $typeId);
        $available = $this->identityAvailable();
        $blockers = $available ? [] : [CertificationReadiness::SESSION_IDENTITY_BLOCKER];
        if ($available && DB::table('certificado.certificado')->where('id_participacion', $participationId)
            ->where('id_tipo_certificado', $typeId)->whereNull('id_actividad_sesion')->exists()) {
            $blockers[] = 'Existe un certificado histórico sin sesión identificable; requiere revisión antes de otra emisión.';
        }

        $generator = app(CertificateDocumentGenerator::class);
        $signers = $generator->signers((int) $context->id_actividad);
        $html = $generator->preview($context, $message, $signers);
        $pdfBytes = $pdf ? $generator->previewPdf($html) : null;
        $preparation = app(CertificateManagement::class)->preparationAvailability();

        return [
            'accion' => 'vista_previa', 'modo' => 'demostracion', 'oficial' => false,
            'emitida' => false, 'creado' => false, 'contexto' => (array) $context,
            'vista_previa_html' => $html,
            'huella_composicion' => hash('sha256', $html),
            'firmantes_configurados' => $signers,
            'puede_enviar_gerencia' => false, 'puede_entregar' => false,
            'motivos_bloqueo_envio' => $this->signatureSubmissionBlockers($preparation['motivos']),
            'mensaje' => $message, 'mensaje_persistido' => false,
            'puede_revisar_documento_para_firma' => $preparation['disponible'],
            'motivos_bloqueo_preparacion_persistente' => $preparation['motivos'],
            'siguiente_accion_preparacion' => $preparation['disponible'] ? 'revisar_documento' : null,
            'formato' => $pdf ? 'pdf' : 'html',
            'vista_previa_pdf' => $pdfBytes !== null ? [
                'contenido_base64' => base64_encode($pdfBytes),
                'hash_pdf' => hash('sha256', $pdfBytes),
                'tipo_contenido' => 'application/pdf', 'nombre_archivo' => 'vista-previa-certificado.pdf',
                'persistido' => false,
            ] : null,
            'identidad_sesion_disponible' => $available,
            'puede_emitir_demostracion' => $blockers === [], 'puede_emitir' => false, 'puede_firmar' => false,
            'motivos_bloqueo_emision' => $blockers,
        ];
    }

    /** @param list<string> $preparationReasons
     * @return list<string>
     */
    private function signatureSubmissionBlockers(array $preparationReasons): array
    {
        return [...$preparationReasons,
            'La vista previa no es un documento registrado; revise y confirme la preparación antes de remitir.',
            'La remisión requiere el ID documental real y el token de revisar-envio.',
        ];
    }

    /**
     * @param  list<int>|null  $areaIds
     * @return array<string, mixed>
     */
    public function sendToManagement(int $sessionId, int $participationId, int $typeId, ?array $areaIds, string $message, string $compositionHash): array
    {
        $preview = $this->preview($sessionId, $participationId, $typeId, $areaIds, $message);
        abort_unless(hash_equals($preview['huella_composicion'], $compositionHash), 409,
            'La composición cambió desde la revisión. Solicite y revise una nueva vista previa.');
        unset($preview['vista_previa_html'], $preview['vista_previa_pdf']);

        return [...$preview, 'accion' => 'enviar_gerencia', 'enviado' => false,
            'estado_flujo' => null, 'codigo_bloqueo' => 'REVISION_DOCUMENTAL_REQUERIDA'];
    }

    /**
     * @param  list<int>|null  $areaIds
     * @return array<string, mixed>
     */
    public function issueDemonstration(int $sessionId, int $participationId, int $typeId, User $actor, ?array $areaIds): array
    {
        app(CertificationReadiness::class)->assertDevelopmentClosureEnvironment();
        abort_unless($this->identityAvailable(), 409,
            'La emisión requiere la relación persistente de sesión y sus restricciones autorizadas; no se ha modificado el esquema.');
        $createdPath = null;
        $callbackCompleted = false;
        try {
            return DB::transaction(function () use ($sessionId, $participationId, $typeId, $actor, $areaIds, &$createdPath, &$callbackCompleted): array {
                $session = DB::table('certificado.actividad_sesion')->where('id_actividad_sesion', $sessionId)->lockForUpdate()->first();
                abort_unless($session, 404, 'Sesión no encontrada.');
                DB::table('certificado.actividad')->where('id_actividad', $session->id_actividad)->lockForUpdate()->first();
                $participation = DB::table('certificado.participacion')->where('id_participacion', $participationId)->lockForUpdate()->first();
                abort_unless($participation && (int) $participation->id_actividad === (int) $session->id_actividad, 422,
                    'La participación no pertenece a la actividad de la sesión.');
                DB::table('certificado.asistencia')->where('id_participacion', $participationId)
                    ->where('id_actividad_sesion', $sessionId)->lockForUpdate()->get();
                $context = $this->selectedContext($sessionId, $participationId, $typeId, $areaIds);
                $existing = DB::table('certificado.certificado')->where('id_participacion', $participationId)
                    ->where('id_actividad_sesion', $sessionId)->where('id_tipo_certificado', $typeId)->lockForUpdate()->first();
                if ($existing) {
                    abort_unless($existing->estado === self::DEMONSTRATION_STATE && (int) $existing->id_persona === (int) $context->id_persona, 409,
                        'El certificado existente no corresponde a una demostración recuperable.');
                    abort_unless(DB::table('certificado.certificado_documento')->where('id_certificado', $existing->id_certificado)->exists(), 409,
                        'El certificado previo carece de documento; requiere revisión, no una emisión duplicada.');
                    $certificateId = (int) $existing->id_certificado;
                } else {
                    abort_if(DB::table('certificado.certificado')->where('id_participacion', $participationId)
                        ->where('id_tipo_certificado', $typeId)->whereNull('id_actividad_sesion')->exists(), 409,
                        'Existe un certificado histórico de participación sin sesión identificable; requiere revisión antes de otra emisión.');
                    $this->assertEligible($context, $typeId);
                    $certificateId = DB::table('certificado.certificado')->insertGetId([
                        'id_persona' => $context->id_persona, 'id_participacion' => $participationId,
                        'id_actividad_sesion' => $sessionId, 'id_tipo_certificado' => $typeId,
                        'id_practica' => null, 'id_reconocimiento' => null,
                        'fecha_emision' => now()->toDateString(), 'estado' => self::DEMONSTRATION_STATE,
                        'notificado' => false, 'fecha_notificacion' => null,
                    ], 'id_certificado');
                }
                $document = app(CertificateDocumentGenerator::class)->generate($certificateId, $actor,
                    function (string $path) use (&$createdPath): void {
                        $createdPath = $path;
                    });
                $callbackCompleted = true;

                return [
                    ...$document, 'accion' => 'emitir_demostracion', 'modo' => 'demostracion',
                    'emitida' => true, 'demostracion' => true, 'oficial' => false,
                    'id_sesion' => $sessionId, 'id_actividad' => (int) $context->id_actividad,
                    'id_participacion' => $participationId, 'id_persona' => (int) $context->id_persona,
                    'id_tipo_certificado' => $typeId, 'estado_certificado' => self::DEMONSTRATION_STATE,
                ];
            });
        } catch (Throwable $exception) {
            app(CertificateDocumentGenerator::class)->discardFailedArtifact($createdPath, $exception, $callbackCompleted);
            throw $exception;
        }
    }

    /** @param list<int>|null $areaIds */
    private function selectedContext(int $sessionId, int $participationId, int $typeId, ?array $areaIds): object
    {
        $context = DB::table('certificado.actividad_sesion as s')
            ->join('certificado.actividad as a', 'a.id_actividad', '=', 's.id_actividad')
            ->join('certificado.participacion as p', 'p.id_actividad', '=', 'a.id_actividad')
            ->join('gestion.persona as persona', 'persona.id_persona', '=', 'p.id_persona')
            ->where('s.id_actividad_sesion', $sessionId)->where('p.id_participacion', $participationId)
            ->select('a.id_actividad', 'a.id_area', 'a.nombre as actividad', 'a.estado as estado_actividad',
                's.id_actividad_sesion as id_sesion', 's.nombre as sesion', 's.estado as estado_sesion', 's.fecha as fecha_sesion', 's.lugar',
                'p.id_participacion', 'p.id_persona', 'persona.nombres', 'persona.apellido_paterno', 'persona.apellido_materno')->first();
        abort_unless($context, 422, 'El beneficiario no corresponde a la sesión seleccionada.');
        abort_unless($areaIds === null || in_array((int) $context->id_area, $areaIds, true), 403, 'Actividad fuera del ámbito autorizado.');
        $type = DB::table('certificado.tipo_certificado')->where('id_tipo_certificado', $typeId)->where('estado', true)->first();
        abort_unless($type && trim($type->nombre_tipo) !== '', 422, 'Tipo de certificado no disponible.');
        $context->id_tipo_certificado = $typeId;
        $context->tipo_certificado = $type->nombre_tipo;

        return $context;
    }

    private function assertEligible(object $context, int $typeId): void
    {
        $evaluation = app(CertificationReadiness::class)->sessionPreparationCandidates($context, $typeId);
        $beneficiary = $evaluation['beneficiaries']->firstWhere('id_participacion', (int) $context->id_participacion);
        abort_unless($evaluation['compatible'] === true && ($beneficiary['estado_elegibilidad'] ?? null) === 'Elegible', 409,
            implode(' ', $beneficiary['motivos'] ?? ['El participante no es elegible para esta sesión.']));
    }

    /**
     * @param  list<int>|null  $areaIds
     * @return array<string, mixed>
     */
    public function prepare(int $sessionId, int $typeId, int $actorId, ?array $areaIds, ?int $expectedActivityId = null): array
    {
        $session = ActivitySession::with('activity')->findOrFail($sessionId);
        abort_if($expectedActivityId !== null && (int) $session->id_actividad !== $expectedActivityId, 409,
            'El contexto de la sesión cambió; vuelva a consultar la actividad.');
        abort_unless($session->activity && ($areaIds === null || in_array((int) $session->activity->id_area, $areaIds, true)), 403,
            'Actividad fuera del ámbito autorizado.');
        $context = (object) [
            'id_actividad' => (int) $session->id_actividad, 'id_sesion' => $sessionId,
            'nombre_sesion' => $session->nombre, 'estado_sesion' => $session->estado,
            'estado_actividad' => $session->activity->estado,
        ];
        $readiness = app(CertificationReadiness::class);
        $data = $readiness->beneficiaries($context, 'sesion', ['id_tipo_certificado' => $typeId]);

        return [
            ...$data, 'modo' => $readiness->usesDevelopmentPolicy() ? 'desarrollo' : 'academico', 'oficial' => false,
            'id_actividad' => (int) $session->id_actividad, 'id_sesion' => $sessionId,
            'id_tipo_certificado' => $typeId,
            'estado_preparacion' => $data['puede_preparar'] ? 'Evaluada sin persistencia' : 'Bloqueada',
            'preparaciones_creadas' => 0, 'preparaciones_existentes' => 0,
            'preparacion_persistida' => false,
            'certificado_sesion' => null,
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    public function forSession(int $sessionId, ?int $typeId = null): Collection
    {
        return collect();
    }
}
