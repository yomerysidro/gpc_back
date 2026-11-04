<?php

namespace App\Http\Middleware\ActivityRequests;

use App\Models\User\User;
use App\Services\ActivityRequests\DevelopmentDatabase;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class DevelopmentAccess
{
    public function __construct(private DevelopmentDatabase $database) {}

    public function handle(Request $request, Closure $next, string $operation = 'environment'): Response
    {
        if ($operation === 'environment') {
            if (! in_array($request->server('REMOTE_ADDR'), ['127.0.0.1', '::1'], true)
                || ! in_array($request->getHost(), ['localhost', '127.0.0.1', '::1', '[::1]'], true)) {
                return $this->failure('Acceso permitido únicamente desde localhost.', 403);
            }
            try {
                $this->database->assertConnected();
            } catch (Throwable) {
                return $this->failure('Integración de desarrollo no disponible.', 503);
            }

            return $next($request);
        }

        $user = $request->user();
        if (! $user instanceof User || ! $user->exists || ! $user->estado) {
            return $this->failure('No autenticado.', 401);
        }
        $areas = DB::table('gestion.persona_trabajador')->where('id_persona', $user->id_persona)
            ->where('estado', true)->whereDate('fecha_ingreso', '<=', today())
            ->where(fn ($query) => $query->whereNull('fecha_fin')->orWhereDate('fecha_fin', '>=', today()))
            ->distinct()->pluck('id_area');
        if ($areas->count() !== 1) {
            return $this->failure('Se requiere un ámbito de área vigente y único.', 403);
        }
        $areaId = (int) $areas->first();
        $record = null;
        if ($request->route('requestId') !== null) {
            $record = DB::table('certificado.solicitud_actividad')->where('id_solicitud_actividad', $request->route('requestId'))->first();
            if (! $record) {
                return $this->failure('Solicitud no encontrada.', 404);
            }
            if ((int) $record->id_area !== $areaId) {
                return $this->failure('Solicitud fuera del ámbito de área autorizado.', 403);
            }
        }
        $permission = match ($operation) {
            'store' => 'crear', 'update' => 'editar', 'submit' => 'enviar',
            'mine', 'show', 'history' => 'propias',
            'review', 'derive' => 'revisar', 'approve', 'reject' => 'decidir',
            'observe' => $record?->estado === 'Pendiente de aprobación' ? 'decidir' : 'revisar',
            'decisionHistory' => 'historial',
            'index', 'reviewDetail' => 'consultar',
            default => null,
        };
        if ($permission === null || ! in_array('solicitudes.'.$permission, $user->permissionKeys(), true)) {
            return $this->failure('No tiene permisos para esta operación.', 403);
        }
        if (in_array($operation, ['show', 'history', 'update', 'submit'], true)
            && (int) $record->id_usuario_solicitante !== (int) $user->getAuthIdentifier()) {
            return $this->failure('Solicitud no encontrada.', 404);
        }
        if (in_array($operation, ['approve', 'reject'], true)
            && (int) $record->id_usuario_solicitante === (int) $user->getAuthIdentifier()) {
            return $this->failure('El solicitante no puede decidir su propia solicitud.', 403);
        }
        if ($request->has('id_area')) {
            $requestedArea = filter_var($request->input('id_area'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($requestedArea === false) {
                return response()->json(['success' => false, 'message' => 'Datos de validación incorrectos.', 'data' => ['id_area' => ['El área debe ser un entero positivo.']]], 422);
            }
            if ($requestedArea !== $areaId) {
                return $this->failure('Área no autorizada.', 403);
            }
        }
        if (in_array($operation, ['index', 'mine', 'decisionHistory'], true)) {
            $request->query->set('id_area', $areaId);
        }

        return $next($request);
    }

    private function failure(string $message, int $status): Response
    {
        return response()->json(['success' => false, 'message' => $message, 'data' => null], $status);
    }
}
