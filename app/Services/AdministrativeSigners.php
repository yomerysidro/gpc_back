<?php

namespace App\Services;

use App\Http\Data\ActivityRequests\ActivityRequestData;
use App\Models\Certificate\Certificate;
use App\Models\CertificateDocument\CertificateDocument;
use App\Models\User\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

class AdministrativeSigners
{
    public function authorize(User $actor, bool $configure = false): void
    {
        abort_unless($actor->estado && Gate::forUser($actor)->allows('viewAny', Certificate::class)
            && Gate::forUser($actor)->allows('viewAny', CertificateDocument::class), 403, 'No autorizado para consultar funcionarios.');
        if ($configure) {
            abort_unless(in_array('ponentes-firmantes.editar', $actor->permissionKeys(), true),
                403, 'Se requiere permiso para configurar funcionarios firmantes.');
        }
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
    public function workers(?array $areaIds, ?int $activityId = null, bool $authorizedOnly = true): array
    {
        $authorization = $this->authorizationQuery();

        return DB::table('gestion.persona_trabajador as t')
            ->join('gestion.persona as p', 'p.id_persona', '=', 't.id_persona')
            ->leftJoin('gestion.area as area', 'area.id_area', '=', 't.id_area')
            ->leftJoin('certificado.firma as f', 'f.id_persona_trabajador', '=', 't.id_persona_trabajador')
            ->whereRaw("TRIM(COALESCE(p.nombres, '')) <> ''")
            ->whereRaw("TRIM(COALESCE(p.apellido_paterno, '')) <> ''")
            ->whereRaw("TRIM(COALESCE(t.cargo, '')) <> ''")
            ->where('t.estado', true)->whereDate('t.fecha_ingreso', '<=', today())
            ->where(fn (Builder $query) => $query->whereNull('t.fecha_fin')->orWhereDate('t.fecha_fin', '>=', today()))
            ->when($areaIds !== null, function (Builder $query) use ($areaIds, $activityId): void {
                $query->where(function (Builder $scope) use ($areaIds, $activityId): void {
                    $scope->whereIn('t.id_area', $areaIds);
                    if ($activityId !== null) {
                        $scope->orWhereExists(fn (Builder $activity) => $activity->selectRaw('1')->from('certificado.actividad_firma as af')
                            ->whereColumn('af.id_firma', 'f.id_firma')->where('af.id_actividad', $activityId));
                    }
                });
            })
            ->select('f.id_firma', 't.id_persona_trabajador', 't.id_persona', 't.id_area', 't.cargo',
                'p.nombres', 'p.apellido_paterno', 'p.apellido_materno', 'area.nombre as area')
            ->selectRaw("CASE WHEN f.id_firma IS NOT NULL AND TRIM(COALESCE(f.ruta_archivo_pfx, '')) <> '' AND TRIM(COALESCE(f.passphrase_cifrada, '')) <> '' THEN 1 ELSE 0 END AS credencial_registrada")
            ->selectRaw('CASE WHEN EXISTS ('.$authorization->toSql().') THEN 1 ELSE 0 END AS habilitado', $authorization->getBindings())
            ->orderBy('t.id_persona')->orderBy('t.id_persona_trabajador')->get()
            ->filter(fn (object $row): bool => ! $authorizedOnly || (bool) $row->habilitado)
            ->map(fn (object $row): array => [
                'id_persona_trabajador' => (int) $row->id_persona_trabajador, 'id_persona' => (int) $row->id_persona,
                'id_area' => $row->id_area === null ? null : (int) $row->id_area,
                'nombre' => trim(implode(' ', array_filter([$row->nombres, $row->apellido_paterno, $row->apellido_materno]))),
                'cargo' => $row->cargo, 'area' => $row->area, 'disponible_asignacion' => true, 'vigente' => true, 'habilitado' => (bool) $row->habilitado,
                'origen_habilitacion' => 'asignacion_documental',
                'id_firma' => $row->id_firma === null ? null : (int) $row->id_firma,
                'credencial_registrada' => (bool) $row->credencial_registrada,
                'credencial_verificada' => false, 'credencial_valida' => null,
            ])->values()->all();
    }

    private function authorizationQuery(): Builder
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

    /** @return array<string, mixed> */
    public function enable(int $workerId, User $actor): array
    {
        $this->authorize($actor, true);
        $worker = collect($this->workers($this->areas($actor), authorizedOnly: false))->firstWhere('id_persona_trabajador', $workerId);
        abort_unless($worker, 404, 'Trabajador vigente con identidad y cargo no encontrado en su ámbito.');

        return ['completada' => false, 'persistido' => false, 'funcionario' => $worker,
            'codigo_bloqueo' => 'HABILITACION_INDEPENDIENTE_NO_DISPONIBLE',
            'motivo' => 'No existe un catálogo independiente. Envíe id_certificado_documento, orden_firma y modalidad para guardar una habilitación documental real en certificado_firma. No se modifican trabajadores ni credenciales.'];
    }
}
