<?php

namespace App\Services;

use App\Http\Data\ActivityRequests\ActivityRequestData;
use App\Models\Certificate\Certificate;
use App\Models\CertificateDocument\CertificateDocument;
use App\Models\User\User;
use App\Services\RequestSchema as Schema;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class AdministrativeSigners
{
    /** @return array{disponible: bool, codigo_bloqueo: ?string, motivo: ?string} */
    public function authorizationAvailability(): array
    {
        if (Schema::hasColumn('gestion.persona_trabajador', 'habilitado_firma_certificados')) {
            return ['disponible' => true, 'codigo_bloqueo' => null, 'motivo' => null];
        }

        return ['disponible' => false, 'codigo_bloqueo' => 'PERSISTENCIA_HABILITACION_GLOBAL_NO_DISPONIBLE',
            'motivo' => 'No está disponible gestion.persona_trabajador.habilitado_firma_certificados.'];
    }

    public function assertAuthorizationAvailable(): void
    {
        $availability = $this->authorizationAvailability();
        abort_unless($availability['disponible'], 409, $availability['codigo_bloqueo'].': '.$availability['motivo']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function authorizedWorkers(): array
    {
        $workers = $this->workers(null);
        if (DB::transactionLevel() > 0 && $workers !== []) {
            DB::table('gestion.persona_trabajador')->whereIn('id_persona_trabajador', array_column($workers, 'id_persona_trabajador'))
                ->orderBy('id_persona_trabajador')->lockForUpdate()->get(['id_persona_trabajador']);
            $workers = $this->workers(null);
        }

        return array_values(array_filter($workers,
            fn (array $worker): bool => $worker['habilitado'] && $worker['vigente']));
    }

    /** @return array<string, mixed> */
    public function changeAuthorization(int $workerId, User $actor, bool $enabled = true, ?UploadedFile $image = null): array
    {
        return DB::transaction(function () use ($workerId, $actor, $enabled, $image): array {
            $record = $this->lockEditableWorker($workerId, $actor);
            $this->assertAuthorizationAvailable();
            $worker = $this->detail($workerId, $actor);
            abort_if($enabled && ! $worker['elegible_laboralmente'], 409, 'El trabajador no tiene vínculo laboral vigente, identidad o cargo válido.');
            $changes = [];
            if ((bool) $record->habilitado_firma_certificados !== $enabled) {
                $changes['habilitado_firma_certificados'] = $enabled;
            }
            if ($image !== null) {
                abort_unless(Schema::hasColumn('gestion.persona_trabajador', 'imagen_firma_cifrada'), 409,
                    'El almacenamiento de imágenes de firma todavía no está disponible.');
                $dataUri = 'data:'.$image->getMimeType().';base64,'.base64_encode($image->get());
                if ($record->imagen_firma_cifrada === null || Crypt::decryptString($record->imagen_firma_cifrada) !== $dataUri) {
                    $changes['imagen_firma_cifrada'] = Crypt::encryptString($dataUri);
                }
            }
            if ($changes !== []) {
                $saved = DB::table('gestion.persona_trabajador')->where('id_persona_trabajador', $workerId)
                    ->update($changes);
                abort_unless($saved === 1, 409, 'No se pudo guardar la habilitación.');
            }

            return ['completada' => true, 'persistido' => true, 'reintento' => $changes === [],
                'funcionario' => $this->detail($workerId, $actor)];
        });
    }

    /** @return array<string, mixed> */
    public function removeImage(int $workerId, User $actor): array
    {
        return DB::transaction(function () use ($workerId, $actor): array {
            $record = $this->lockEditableWorker($workerId, $actor);
            abort_unless(Schema::hasColumn('gestion.persona_trabajador', 'imagen_firma_cifrada'), 409,
                'El almacenamiento de imágenes de firma todavía no está disponible.');

            $retry = $record->imagen_firma_cifrada === null;
            if (! $retry) {
                DB::table('gestion.persona_trabajador')->where('id_persona_trabajador', $workerId)
                    ->update(['imagen_firma_cifrada' => null]);
            }

            return ['completada' => true, 'persistido' => true, 'reintento' => $retry,
                'funcionario' => $this->detail($workerId, $actor)];
        });
    }

    private function lockEditableWorker(int $workerId, User $actor): object
    {
        abort_unless($actor->estado && in_array('ponentes-firmantes.editar', $actor->permissionKeys(), true),
            403, 'Se requiere permiso para administrar habilitaciones de firmantes.');

        $areas = $this->areas($actor);
        $record = DB::table('gestion.persona_trabajador')->where('id_persona_trabajador', $workerId)
            ->when($areas !== null, fn (Builder $query) => $query->whereIn('id_area', $areas))->lockForUpdate()->first();
        abort_unless($record, 404, 'Trabajador no encontrado en su ámbito.');

        return $record;
    }

    /** @return array<string, mixed> */
    public function detail(int $workerId, User $actor): array
    {
        $this->authorize($actor);
        $worker = collect($this->workers($this->areas($actor), includeInactive: true))->firstWhere('id_persona_trabajador', $workerId);
        abort_unless($worker, 404, 'Trabajador no encontrado en su ambito.');

        return $worker;
    }

    public function authorize(User $actor): void
    {
        if ($actor->estado && in_array('ponentes-firmantes.editar', $actor->permissionKeys(), true)) {
            return;
        }
        abort_unless($actor->estado && Gate::forUser($actor)->allows('viewAny', Certificate::class)
            && Gate::forUser($actor)->allows('viewAny', CertificateDocument::class), 403, 'No autorizado para consultar funcionarios.');
    }

    /** @return list<int>|null */
    public function areas(User $actor): ?array
    {
        if ($actor->isSystemAdministrator()) {
            return null;
        }
        $result = ActivityRequestData::authorizedAreas((int) $actor->id_persona);
        abort_unless($result['success'], $result['status'] ?? 500, 'No se pudo comprobar el ámbito laboral.');

        return $result['data']->pluck('id_area')->map(fn (mixed $id): int => (int) $id)->all();
    }

    /**
     * @param  list<int>|null  $areaIds
     * @return list<array<string, mixed>>
     */
    public function workers(?array $areaIds, ?int $activityId = null, bool $includeInactive = false): array
    {
        $documentAssignment = $this->documentAssignmentQuery();
        $authorizationAvailable = $this->authorizationAvailability()['disponible'];

        return DB::table('gestion.persona_trabajador as t')
            ->join('gestion.persona as p', 'p.id_persona', '=', 't.id_persona')
            ->leftJoin('gestion.area as area', 'area.id_area', '=', 't.id_area')
            ->leftJoin('certificado.firma as f', 'f.id_persona_trabajador', '=', 't.id_persona_trabajador')
            ->when(! $includeInactive, function (Builder $query): void {
                $query->whereRaw("TRIM(COALESCE(p.nombres, '')) <> ''")
                    ->whereRaw("TRIM(COALESCE(p.apellido_paterno, '')) <> ''")
                    ->whereRaw("TRIM(COALESCE(t.cargo, '')) <> ''")
                    ->where('t.estado', true)->whereDate('t.fecha_ingreso', '<=', today())
                    ->where(fn (Builder $dates) => $dates->whereNull('t.fecha_fin')->orWhereDate('t.fecha_fin', '>=', today()));
            })
            ->when($areaIds !== null, function (Builder $query) use ($areaIds, $activityId): void {
                $query->where(function (Builder $scope) use ($areaIds, $activityId): void {
                    $scope->whereIn('t.id_area', $areaIds);
                    if ($activityId !== null) {
                        $scope->orWhereExists(fn (Builder $activity) => $activity->selectRaw('1')->from('certificado.actividad_firma as af')
                            ->whereColumn('af.id_firma', 'f.id_firma')->where('af.id_actividad', $activityId));
                    }
                });
            })
            ->select('f.id_firma', 't.id_persona_trabajador', 't.id_persona', 't.id_area', 't.cargo', 't.estado', 't.fecha_ingreso', 't.fecha_fin',
                'p.nombres', 'p.apellido_paterno', 'p.apellido_materno', 'area.nombre as area')
            ->selectRaw($authorizationAvailable ? 't.habilitado_firma_certificados' : 'FALSE AS habilitado_firma_certificados')
            ->selectRaw(Schema::hasColumn('gestion.persona_trabajador', 'imagen_firma_cifrada')
                ? 't.imagen_firma_cifrada IS NOT NULL AS tiene_imagen_firma' : 'FALSE AS tiene_imagen_firma')
            ->selectRaw("CASE WHEN f.id_firma IS NOT NULL AND TRIM(COALESCE(f.ruta_archivo_pfx, '')) <> '' AND TRIM(COALESCE(f.passphrase_cifrada, '')) <> '' THEN 1 ELSE 0 END AS credencial_registrada")
            ->selectRaw('CASE WHEN EXISTS ('.$documentAssignment->toSql().') THEN 1 ELSE 0 END AS tiene_asignacion_documental', $documentAssignment->getBindings())
            ->orderBy('t.id_persona')->orderBy('t.id_persona_trabajador')
            ->orderByDesc('credencial_registrada')->orderBy('f.id_firma')->get()
            ->unique('id_persona_trabajador')
            ->map(function (object $row): array {
                $current = (bool) $row->estado && $row->fecha_ingreso !== null && $row->fecha_ingreso <= today()->toDateString()
                    && ($row->fecha_fin === null || $row->fecha_fin >= today()->toDateString());
                $eligible = $current && trim((string) $row->nombres) !== ''
                    && trim((string) $row->apellido_paterno) !== '' && trim((string) $row->cargo) !== '';

                return [
                    'id_persona_trabajador' => (int) $row->id_persona_trabajador, 'id_persona' => (int) $row->id_persona,
                    'id_area' => $row->id_area === null ? null : (int) $row->id_area,
                    'nombre' => trim(implode(' ', array_filter([$row->nombres, $row->apellido_paterno, $row->apellido_materno]))),
                    'cargo' => $row->cargo, 'area' => $row->area, 'elegible_laboralmente' => $eligible,
                    'habilitado_firma_certificados' => (bool) $row->habilitado_firma_certificados,
                    'tiene_imagen_firma' => (bool) $row->tiene_imagen_firma,
                    'disponible_asignacion' => $eligible && (bool) $row->habilitado_firma_certificados,
                    'vigente' => $current, 'habilitado' => (bool) $row->habilitado_firma_certificados,
                    'estado_habilitacion' => $row->habilitado_firma_certificados ? 'Habilitado' : 'No habilitado',
                    'estado_laboral' => $current ? 'Vigente' : 'No vigente', 'habilitacion_global' => (bool) $row->habilitado_firma_certificados,
                    'tiene_asignacion_documental' => (bool) $row->tiene_asignacion_documental,
                    'origen_habilitacion' => 'gestion.persona_trabajador.habilitado_firma_certificados',
                    'id_firma' => $row->id_firma === null ? null : (int) $row->id_firma,
                    'credencial_registrada' => (bool) $row->credencial_registrada,
                    'credencial_verificada' => false, 'credencial_valida' => null,
                ];
            })->values()->all();
    }

    private function documentAssignmentQuery(): Builder
    {
        $query = DB::table('certificado.certificado_firma as habilitacion')->selectRaw('1')
            ->join('certificado.certificado_documento as documento', 'documento.id_certificado_documento', '=', 'habilitacion.id_certificado_documento')
            ->join('certificado.certificado as certificado', 'certificado.id_certificado', '=', 'documento.id_certificado')
            ->where('documento.estado', 'Vigente')->whereIn('certificado.estado', [CertificateManagement::PREPARED, CertificateManagement::PENDING]);
        if (Schema::hasColumn('certificado.certificado_firma', 'id_persona_trabajador')) {
            return $query->whereColumn('habilitacion.id_persona_trabajador', 't.id_persona_trabajador');
        }

        return $query->whereRaw('1 = 0');
    }
}
