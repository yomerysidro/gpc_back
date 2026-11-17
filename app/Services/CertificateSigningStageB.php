<?php

namespace App\Services;

use App\Models\CertificateDocument\CertificateDocument;
use App\Models\User\User;
use App\Services\RequestSchema as Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CertificateSigningStageB
{
    /** @var array<string, mixed>|null */
    private ?array $requirements = null;

    public function __construct(
        private CertificateDocumentWorkflow $workflow,
        private CertificateDocumentGenerator $generator,
    ) {}

    /** @return array<string, mixed> */
    public function requirements(): array
    {
        if ($this->requirements !== null) {
            return $this->requirements;
        }
        $signatureDate = collect(Schema::getColumns('certificado.certificado_firma'))->firstWhere('name', 'fecha_firma');
        $pendingSupported = $signatureDate !== null && $signatureDate['nullable'];
        $modeSupported = Schema::hasColumn('certificado.certificado_documento', 'modalidad_firma');
        $versionsSupported = Schema::hasColumn('certificado.certificado_documento', 'id_documento_anterior');
        $workerSupported = Schema::hasColumn('certificado.certificado_firma', 'id_persona_trabajador');
        $credentialOptional = (bool) (collect(Schema::getColumns('certificado.certificado_firma'))->firstWhere('name', 'id_firma')['nullable'] ?? false);
        $controlledVersions = $modeSupported && $workerSupported && $credentialOptional && $pendingSupported;

        return $this->requirements = [
            'habilitacion_global_administrativa' => app(AdministrativeSigners::class)->authorizationAvailability()['disponible'],
            'firmantes_pendientes_individuales' => $pendingSupported,
            'modalidad_documental' => $modeSupported,
            'vinculo_versiones' => $versionsSupported,
            'versionado_pdf_controlado' => $controlledVersions,
            'persistencia_pdf_versiones' => 'archivo_privado_versionado',
            'identidad_documental_conservada' => true,
            'motor_pdf_disponible' => false,
            'asignacion_sin_credencial' => $workerSupported
                && $credentialOptional,
            'motivos_bloqueo' => array_values(array_filter([
                app(AdministrativeSigners::class)->authorizationAvailability()['motivo'],
                ! $pendingSupported ? 'certificado_firma.fecha_firma es obligatoria; no admite asignaciones individuales pendientes.' : null,
                ! $modeSupported ? 'El documento carece de modalidad_firma persistente.' : null,
                ! $versionsSupported && ! $controlledVersions ? 'Falta soporte persistente para revisar versiones del documento.' : null,
                ! $workerSupported ? 'Falta una referencia directa al trabajador para asignar sin credencial PFX.' : null,
                ! $credentialOptional ? 'certificado_firma.id_firma es obligatoria; no permite asignar sin credencial.' : null,
                'Falta un motor PDF para firma incremental y verificación criptográfica, identidad, confianza y revocación.',
            ])),
        ];
    }

    /** @return array<string, mixed> */
    public function progress(int $id, User $actor): array
    {
        $document = $this->workflow->document($id, $actor);
        $this->generator->assertArtifact($document);
        $detail = $this->workflow->detail($id, $actor);

        $result = $this->present($detail, true);
        $result['firmantes_asignados'] = $this->assignedSigners($id);
        $result['modalidad'] = $document->modalidad_firma ?? null;
        $result['modalidad_persistida'] = $result['modalidad'] !== null;
        $result['asignacion_documental_persistida'] = $result['firmantes_asignados'] !== [];
        $result['origen_firmantes'] = $result['asignacion_documental_persistida'] ? 'documento' : 'actividad';
        $metadata = json_decode($document->motivo_reemision ?? '', true);
        $parent = is_array($metadata) && ($metadata['v'] ?? null) === 1 && is_int($metadata['d'] ?? null)
            && $metadata['d'] !== $id && is_string($metadata['h'] ?? null) && is_string($metadata['p'] ?? null)
            ? DB::table('certificado.certificado_documento')->where('id_certificado_documento', $metadata['d'])
                ->where('id_certificado', $document->id_certificado)->where('hash_certificado', $metadata['h'])->first() : null;
        $initialApproval = is_array($metadata) && ($metadata['v'] ?? null) === 0 && is_string($metadata['p'] ?? null);
        if (isset($document->plan_pdf_aprobado)) {
            $metadata = ['p' => $document->plan_pdf_aprobado];
            $initialApproval = true;
        }
        $result['pdf_revisado_disponible'] = ($parent !== null || $initialApproval)
            && hash_equals($metadata['p'], CertificateSignerRevision::planHash($result['firmantes_asignados'], $result['modalidad']));
        $result['pdf_confirmado'] = $result['pdf_revisado_disponible'];
        $result['requiere_nueva_revision_pdf'] = $result['asignacion_documental_persistida'] && ! $result['pdf_revisado_disponible'];
        $delivery = CertificateHumanResources::delivery($document);
        $result['estado_administrativo'] = $delivery['estado_administrativo']
            ?? ($result['pdf_revisado_disponible'] ? 'PREPARADO' : 'PENDIENTE_PREPARACION');
        $result['puede_enviar_rrhh'] = false;
        $prepared = $result['pdf_revisado_disponible'] && $result['asignacion_documental_persistida']
            && $document->estado === 'Vigente' && $document->fecha_emision === null
            && in_array($document->estado_certificado, [CertificateManagement::PREPARED, CertificateManagement::PENDING], true);
        $result['preparado_para_entrega'] = $prepared;
        $result['disponible_rrhh'] = $prepared;
        $result['estado_preparacion'] = $prepared ? 'PREPARADO_ENTREGA' : null;
        $result['etiqueta_estado'] = $prepared ? 'Listo para entrega' : null;
        $result['documento']['hash_pdf'] = $document->hash_certificado;
        $result['documento']['revision_pdf'] = $document->revision_pdf ?? null;
        $result['documento']['codigo_verificacion'] = $document->codigo_verificacion;
        $result['documento']['hash_pdf_original'] = $document->hash_certificado_original ?? $document->hash_certificado;
        $result['facultades']['asignar'] = $this->requirements()['firmantes_pendientes_individuales']
            && $this->requirements()['habilitacion_global_administrativa']
            && $this->requirements()['modalidad_documental']
            && $this->requirements()['asignacion_sin_credencial']
            && $document->estado_certificado === CertificateManagement::PENDING
            && $document->estado === 'Vigente' && $document->fecha_emision === null
            && ! $document->notificado && $document->fecha_notificacion === null
            && Gate::forUser($actor)->allows('update', (new CertificateDocument)->newFromBuilder((array) $document));

        return $result;
    }

    /** @return list<array<string, mixed>> */
    private function assignedSigners(int $id): array
    {
        $worker = Schema::hasColumn('certificado.certificado_firma', 'id_persona_trabajador')
            ? DB::raw('COALESCE(cf.id_persona_trabajador, f.id_persona_trabajador)') : 'f.id_persona_trabajador';

        return DB::table('certificado.certificado_firma as cf')
            ->leftJoin('certificado.firma as f', 'f.id_firma', '=', 'cf.id_firma')
            ->join('gestion.persona_trabajador as t', 't.id_persona_trabajador', '=', $worker)
            ->join('gestion.persona as p', 'p.id_persona', '=', 't.id_persona')
            ->leftJoin('gestion.area as area', 'area.id_area', '=', 't.id_area')
            ->where('cf.id_certificado_documento', $id)->orderBy('cf.orden_firma')
            ->get(['cf.id_firma', 'cf.orden_firma', 'cf.fecha_firma', 't.id_persona_trabajador', 't.id_persona', 't.cargo',
                'p.nombres', 'p.apellido_paterno', 'p.apellido_materno', 't.id_area', 'area.nombre as area'])
            ->map(fn (object $signer): array => [
                'id_firma' => $signer->id_firma === null ? null : (int) $signer->id_firma,
                'id_persona_trabajador' => (int) $signer->id_persona_trabajador, 'id_persona' => (int) $signer->id_persona,
                'nombre' => trim(implode(' ', array_filter([$signer->nombres, $signer->apellido_paterno, $signer->apellido_materno]))),
                'cargo' => $signer->cargo, 'id_area' => $signer->id_area === null ? null : (int) $signer->id_area,
                'area' => $signer->area, 'orden_firma' => (int) $signer->orden_firma,
                'fecha_firma' => $signer->fecha_firma, 'firma_verificada' => false,
                'estado' => $signer->fecha_firma === null ? 'Pendiente' : 'Registrada sin verificar',
            ])->all();
    }

    /**
     * @param  list<array{id_firma?: int, id_persona_trabajador?: int, orden_firma: int}>  $signers
     * @return array<string, mixed>
     */
    public function assign(int $id, User $actor, string $mode, array $signers): array
    {
        abort_unless(in_array($mode, ['PARALELA', 'SECUENCIAL'], true) && count($signers) >= 1 && count($signers) <= 100,
            422, 'Se requiere una modalidad válida y entre uno y cien responsables.');
        $document = $this->pendingForUpdate($id, $actor);
        if (! $this->requirements()['firmantes_pendientes_individuales'] || ! $this->requirements()['modalidad_documental']) {
            return $this->blockedOperation($id, 'asignar', $actor);
        }
        if (collect($signers)->contains(fn (array $signer): bool => isset($signer['id_persona_trabajador']))
            && ! $this->requirements()['asignacion_sin_credencial']) {
            return $this->blockedOperation($id, 'asignar', $actor);
        }
        usort($signers, fn (array $left, array $right): int => $left['orden_firma'] <=> $right['orden_firma']);
        abort_unless(array_column($signers, 'orden_firma') === range(1, count($signers)), 422, 'Los órdenes deben ser consecutivos desde uno.');

        $result = DB::transaction(function () use ($document, $id, $actor, $mode, $signers): array {
            DB::table('certificado.certificado')->where('id_certificado', $document->id_certificado)->lockForUpdate()->first();
            DB::table('certificado.certificado_documento')->where('id_certificado_documento', $id)->lockForUpdate()->first();
            $current = $this->pendingForUpdate($id, $actor);
            $existing = $this->assignedSigners($id);
            if ($existing !== []) {
                $samePlan = count($existing) === count($signers) && $current->modalidad_firma === $mode;
                foreach ($signers as $index => $signer) {
                    $key = isset($signer['id_persona_trabajador']) ? 'id_persona_trabajador' : 'id_firma';
                    $samePlan = $samePlan && isset($existing[$index][$key])
                        && $existing[$index][$key] === $signer[$key]
                        && $existing[$index]['orden_firma'] === $signer['orden_firma'];
                }
                abort_unless($samePlan, 409, 'El documento ya tiene otro plan de firmas; no se reemplaza.');

                return ['completada' => true, 'persistido' => true, 'reintento' => true] + $this->progress($id, $actor);
            }
            app(AdministrativeSigners::class)->assertAuthorizationAvailable();
            $available = collect($this->availableSigners($id, $actor));
            $plan = [];
            foreach ($signers as $signer) {
                $key = isset($signer['id_persona_trabajador']) ? 'id_persona_trabajador' : 'id_firma';
                $responsible = $available->firstWhere($key, $signer[$key]);
                abort_unless($responsible && trim($responsible['nombre']) !== '' && trim((string) $responsible['cargo']) !== '',
                    422, 'El responsable no tiene identidad, cargo, competencia o autorización vigente.');
                $plan[] = ['id_persona_trabajador' => $responsible['id_persona_trabajador'], 'orden_firma' => $signer['orden_firma']];
            }
            abort_unless(count($plan) === count(array_unique(array_column($plan, 'id_persona_trabajador'))), 422, 'No se permite repetir al trabajador.');
            abort_unless($current->modalidad_firma === null, 409, 'El plan documental está incompleto; requiere revisión.');
            $people = [];
            foreach ($plan as $index => $entry) {
                $responsible = $available->firstWhere('id_persona_trabajador', $entry['id_persona_trabajador']);
                abort_unless($responsible && trim($responsible['nombre']) !== '' && trim((string) $responsible['cargo']) !== '',
                    422, 'El responsable no tiene identidad, cargo o autorización vigente.');
                abort_if(in_array($responsible['id_persona'], $people, true), 422, 'No se permite repetir a la misma persona.');
                $people[] = $responsible['id_persona'];
                $record = ['id_certificado_documento' => $id, 'fecha_firma' => null, 'orden_firma' => $entry['orden_firma']];
                if (isset($signers[$index]['id_persona_trabajador'])) {
                    $record += ['id_persona_trabajador' => $entry['id_persona_trabajador'], 'id_firma' => null];
                } else {
                    $record['id_firma'] = $signers[$index]['id_firma'];
                }
                DB::table('certificado.certificado_firma')->insert($record);
            }
            DB::table('certificado.certificado_documento')->where('id_certificado_documento', $id)->update(['modalidad_firma' => $mode]);

            return ['completada' => true, 'persistido' => true, 'reintento' => false] + $this->progress($id, $actor);
        });

        if (! $result['requiere_nueva_revision_pdf']) {
            return $result;
        }
        try {
            $result['revision_pdf'] = app(CertificateSignerRevision::class)->review($id, $actor);
        } catch (HttpException $exception) {
            $result['revision_pdf'] = ['preparada' => false, 'requiere_confirmacion' => false,
                'status' => $exception->getStatusCode(), 'motivo' => $exception->getMessage()];
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public function verification(int $id, User $actor): array
    {
        $document = $this->workflow->document($id, $actor);
        $integrity = true;
        try {
            $this->generator->assertArtifact($document);
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() !== 409) {
                throw $exception;
            }
            $integrity = false;
        }

        return [
            'id_certificado_documento' => $id, 'id_certificado' => (int) $document->id_certificado,
            'estado_certificado' => $document->estado_certificado,
            'integridad_verificada' => $integrity, 'hash_pdf' => $document->hash_certificado,
            'firma_verificada' => false, 'emision_oficial_verificada' => false,
            'codigo_bloqueo' => $integrity ? 'MOTOR_FIRMA_NO_DISPONIBLE' : 'INTEGRIDAD_PDF_INVALIDA',
            'requisitos' => $this->requirements(),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function availableSigners(int $id, User $actor): array
    {
        $this->pendingForUpdate($id, $actor);

        return app(AdministrativeSigners::class)->authorizedWorkers();
    }

    public function pendingForUpdate(int $id, User $actor): object
    {
        $document = $this->workflow->document($id, $actor);
        abort_unless(Gate::forUser($actor)->allows('update', (new CertificateDocument)->newFromBuilder((array) $document)),
            403, 'No autorizado para gestionar la firma del documento.');
        abort_unless($document->estado_certificado === CertificateManagement::PENDING && $document->estado === 'Vigente'
            && $document->fecha_emision === null && ! $document->notificado && $document->fecha_notificacion === null,
            409, 'Se requiere un documento pendiente de firma y sin emisión.');
        $this->generator->assertArtifact($document);

        return $document;
    }

    /** @return array<string, mixed> */
    public function blockedOperation(int $id, string $operation, User $actor): array
    {
        $document = $this->workflow->document($id, $actor);
        abort_unless(Gate::forUser($actor)->allows('update', (new CertificateDocument)->newFromBuilder((array) $document)),
            403, 'No autorizado para gestionar el documento.');
        if ($operation === 'firmar') {
            abort_unless(in_array('firmas.firmar', $actor->permissionKeys(), true), 403, 'Se requiere el permiso formal firmas.firmar.');
        }
        $this->generator->assertArtifact($document);

        return [
            'operacion' => $operation, 'completada' => false, 'id_certificado_documento' => $id,
            'estado_certificado' => $document->estado_certificado,
            'codigo_bloqueo' => $operation === 'asignar' ? 'ASIGNACION_DOCUMENTAL_NO_DISPONIBLE' : 'MOTOR_FIRMA_NO_DISPONIBLE',
            'requisitos' => $this->requirements(),
        ];
    }

    /**
     * @param  list<int>  $ids
     * @return array<string, mixed>
     */
    public function batchProgress(array $ids, User $actor): array
    {
        $details = $this->workflow->detailsForDocuments($ids, $actor);
        $results = [];
        foreach ($ids as $id) {
            $results[] = isset($details[$id])
                ? ['id_certificado_documento' => $id, 'status' => 200, 'success' => true, 'data' => $this->present($details[$id], null)]
                : ['id_certificado_documento' => $id, 'status' => 404, 'success' => false, 'message' => 'Documento no encontrado en su ámbito.'];
        }

        return ['lote_persistido' => false, 'resultados' => $results];
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    private function present(array $detail, ?bool $integrity): array
    {
        return [
            'documento' => $detail, 'integridad_verificada' => $integrity,
            'modalidad' => null, 'modalidad_persistida' => false,
            'asignacion_documental_persistida' => false, 'origen_firmantes' => 'actividad',
            'firma_verificada' => false, 'emision_habilitada' => false,
            'entrega_habilitada' => false, 'requisitos' => $this->requirements(),
            'facultades' => ['asignar' => false, 'firmar' => false, 'emitir' => false, 'descargar_final' => false],
            'demo_disponible' => app()->environment('local') && config('certification.stage_b.demo_enabled') === true,
        ];
    }
}
