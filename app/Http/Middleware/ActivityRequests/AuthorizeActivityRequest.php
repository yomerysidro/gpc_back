<?php

namespace App\Http\Middleware\ActivityRequests;

use App\Http\Data\ActivityRequests\ActivityRequestData;
use App\Models\User\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

class AuthorizeActivityRequest
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User || ! $user->estado) {
            return $this->failure('No autenticado.', 401);
        }
        $operation = $request->route()->getActionMethod();
        $keys = $user->permissionKeys();
        if (array_filter($keys, fn (string $key): bool => str_starts_with($key, 'solicitudes.')) === []) {
            return $this->failure('No tiene permisos de Solicitudes.', 403);
        }
        if ($operation === 'authorizedAreas') {
            if (! in_array('solicitudes.crear', $keys, true)) {
                return $this->failure('No tiene permiso para crear solicitudes.', 403);
            }

            return $next($request);
        }
        $administrator = $user->isSystemAdministrator();
        $areaResult = ActivityRequestData::authorizedRequestAreas($user);
        if (! $areaResult['success']) {
            return $this->failure($areaResult['message'], $areaResult['status']);
        }
        $areas = $areaResult['data']->pluck('id_area')->map(fn (mixed $id): int => (int) $id)->all();
        $globalRead = $administrator && in_array($operation, ['index', 'reviewDetail', 'show', 'history', 'decisionHistory', 'review', 'derive', 'observe'], true);
        $institutionalAuthority = $user->hasInstitutionalRequestAuthority();
        $globalDecision = $institutionalAuthority && in_array($operation, ['approve', 'reject', 'observe'], true);
        $scope = $globalRead || $globalDecision ? null : $areas;
        if ($scope === [] || $request->hasHeader('X-Area-Id') || $request->has('id_area')) {
            $selection = $request->header('X-Area-Id', $request->input('id_area'));
            if ($selection !== null) {
                $validator = Validator::make(['id_area' => $selection], ['id_area' => ['required', 'integer', 'min:1']]);
                if ($validator->fails()) {
                    return response()->json(['success' => false, 'message' => 'Datos de validación incorrectos.', 'data' => $validator->errors()], 422);
                }
                if (! $globalRead && ! $globalDecision && ! in_array((int) $selection, $areas, true)) {
                    return $this->areaFailure($areas, (int) $selection);
                }
                if ($request->hasHeader('X-Area-Id')) {
                    $scope = [(int) $selection];
                }
            }
        }
        if ($scope === []) {
            return $this->areaFailure([], null);
        }
        if ($request->has('id_area') && $scope !== null) {
            $validator = Validator::make($request->only('id_area'), ['id_area' => ['required', 'integer', 'min:1']]);
            if ($validator->fails()) {
                return response()->json(['success' => false, 'message' => 'Datos de validación incorrectos.', 'data' => $validator->errors()], 422);
            }
            if (! in_array((int) $request->input('id_area'), $scope, true)) {
                return $this->areaFailure($scope, (int) $request->input('id_area'));
            }
        }
        $record = null;
        if ($request->route('requestId') !== null) {
            $record = DB::table('certificado.solicitud_actividad')->where('id_solicitud_actividad', $request->route('requestId'))->first();
            if (! $record) {
                return $this->failure('Solicitud no encontrada.', 404);
            }
            if ($administrator && ! $institutionalAuthority && $operation === 'observe' && $record->estado === 'Pendiente de aprobación') {
                $scope = array_values(array_intersect($scope ?? $areas, $areas));
            }
            if ($scope !== null && ! in_array((int) $record->id_area, $scope, true)) {
                return $this->areaFailure($scope, (int) $record->id_area);
            }
        }
        $own = $record && (int) $record->id_usuario_solicitante === (int) $user->getAuthIdentifier();
        $permission = match ($operation) {
            'store' => 'crear', 'update' => 'editar', 'submit' => 'enviar', 'mine' => 'propias',
            'show' => $own ? 'propias' : 'consultar', 'history' => $own ? 'propias' : 'historial',
            'index', 'reviewDetail' => 'consultar', 'decisionHistory' => 'historial',
            'review' => 'revisar', 'derive' => 'derivar', 'observe' => 'observar',
            'approve' => 'aprobar', 'reject' => 'rechazar', default => null,
        };
        if ($permission === null || ! in_array('solicitudes.'.$permission, $keys, true)) {
            return $this->failure('No tiene permisos para esta operación.', 403);
        }
        if (in_array($operation, ['update', 'submit'], true) && ! $own) {
            return $this->failure('Solicitud no encontrada.', 404);
        }
        if ($operation === 'observe') {
            $extra = $record->estado === 'Pendiente de aprobación' ? 'aprobar' : 'revisar';
            if (! in_array('solicitudes.'.$extra, $keys, true)) {
                return $this->failure('No tiene atribuciones para observar en esta etapa.', 403);
            }
        }
        if ($operation === 'derive' && ! in_array('solicitudes.revisar', $keys, true)) {
            return $this->failure('No tiene atribuciones de revisión.', 403);
        }
        if (in_array($operation, ['approve', 'reject'], true) && $own && ! $institutionalAuthority) {
            return $this->failure('No puede decidir su propia solicitud.', 403);
        }
        $states = null;
        if (! $administrator && in_array($operation, ['index', 'reviewDetail', 'show', 'history', 'decisionHistory'], true)
            && ! ($own && in_array($operation, ['show', 'history'], true))) {
            $states = ['Enviada', 'En revisión', 'Observada', 'Pendiente de aprobación', 'Aprobada', 'Rechazada'];
            if ($record && $record->estado === 'Borrador') {
                return $this->failure('El borrador solo está disponible para su solicitante o la administración autorizada.', 403);
            }
            if (! in_array('solicitudes.revisar', $keys, true)
                && array_intersect(['solicitudes.aprobar', 'solicitudes.rechazar'], $keys) !== []) {
                $states = ['Pendiente de aprobación', 'Aprobada', 'Rechazada'];
            }
            if ($record && ! in_array($record->estado, $states, true)
                && ! DB::table('certificado.solicitud_decision')->where('id_solicitud_actividad', $record->id_solicitud_actividad)->where('tipo_decision', 'DERIVAR_GERENCIA')->exists()) {
                return $this->failure('Solicitud no derivada a su competencia.', 403);
            }
        }
        $request->attributes->set('activity_request_areas', $scope);
        $request->attributes->set('activity_request_states', $states);
        $request->attributes->set('activity_request_owner_only', $own);

        return $next($request);
    }

    private function failure(string $message, int $status): Response
    {
        return response()->json(['success' => false, 'message' => $message, 'data' => null], $status);
    }

    /** @param list<int> $areas */
    private function areaFailure(array $areas, ?int $requestedArea): Response
    {
        return response()->json([
            'success' => false,
            'message' => $areas === []
                ? 'No tiene un área solicitante con vínculo laboral vigente. Solicite la revisión de su asignación institucional.'
                : 'El área seleccionada no está autorizada para esta sesión. Seleccione una de sus áreas solicitantes habilitadas.',
            'data' => [
                'codigo' => $areas === [] ? 'SOLICITUD_SIN_AREA_VIGENTE' : 'SOLICITUD_AREA_NO_AUTORIZADA',
                'id_area_solicitada' => $requestedArea,
                'id_areas_autorizadas' => array_values($areas),
            ],
        ], 403);
    }
}
