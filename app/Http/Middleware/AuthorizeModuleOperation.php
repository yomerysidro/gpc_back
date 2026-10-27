<?php

namespace App\Http\Middleware;

use App\Traits\ApiResponse;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class AuthorizeModuleOperation
{
    use ApiResponse;

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $modelClass): Response
    {
        $ability = match ($request->route()->getActionMethod()) {
            'list' => 'viewAny',
            'detail' => 'view',
            'store' => 'create',
            'update' => 'update',
            'destroy' => 'delete',
        };
        if (in_array($ability, ['viewAny', 'create'], true)) {
            if (Gate::denies($ability, $modelClass)) {
                return $this->error('No autorizado.', 403);
            }
        } else {
            if (Gate::denies('viewAny', $modelClass)) {
                return $this->error('No autorizado.', 403);
            }
            $id = array_values($request->route()->parameters())[0] ?? null;
            try {
                $record = $modelClass::query()->find($id);
            } catch (QueryException) {
                return $this->error('No se pudo completar la operación.', 500);
            }
            if (! $record) {
                $message = match (class_basename($modelClass)) {
                    'Activity' => 'Actividad no encontrada.',
                    'ActivitySession' => 'Sesión de actividad no encontrada.',
                    'ActivitySpeaker' => 'Ponente de actividad no encontrado.',
                    'ActivityType' => 'Tipo de actividad no encontrado.',
                    'Participation' => 'Participación no encontrada.',
                    'ParticipationType' => 'Tipo de participación no encontrado.',
                    'Attendance' => 'Asistencia no encontrada.',
                    'User' => 'Usuario no encontrado.',
                };

                return $this->error($message, 404);
            }
            if (Gate::denies($ability, $record)) {
                return $this->error('No autorizado.', 403);
            }
        }

        return $next($request);
    }
}
