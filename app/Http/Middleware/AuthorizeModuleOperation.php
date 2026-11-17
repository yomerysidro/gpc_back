<?php

namespace App\Http\Middleware;

use App\Http\Data\Activity\ActivityData;
use App\Http\Data\ActivityRequests\ActivityRequestData;
use App\Models\Activity\Activity;
use App\Models\ActivitySession\ActivitySession;
use App\Models\ActivitySpeaker\ActivitySpeaker;
use App\Models\Area\Area;
use App\Models\Attendance\Attendance;
use App\Models\Participation\Participation;
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
        $record = null;
        if ($modelClass === Activity::class && $ability === 'create') {
            return $this->error(ActivityData::DIRECT_CREATION_DISABLED, 403);
        }
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
                    default => 'Registro no encontrado.',
                };

                return $this->error($message, 404);
            }
            if (Gate::denies($ability, $record)) {
                return $this->error('No autorizado.', 403);
            }
        }

        if (in_array($modelClass, [Activity::class, ActivitySession::class, Participation::class, ActivitySpeaker::class, Attendance::class], true)) {
            try {
                $user = $request->user();
                $areas = null;
                if (! $user->isSystemAdministrator()) {
                    $result = ActivityRequestData::authorizedAreas((int) $user->id_persona);
                    if (! $result['success']) {
                        return $this->error($result['message'], $result['status']);
                    }
                    $areas = $result['data']->pluck('id_area')->map(fn (mixed $id): int => (int) $id)->all();
                    if ($record !== null) {
                        $currentArea = match (true) {
                            $record instanceof Activity => $record->id_area,
                            $record instanceof Attendance => $record->participation?->activity?->id_area,
                            default => $record->activity?->id_area,
                        };
                        if (! in_array((int) $currentArea, $areas, true)) {
                            return $this->error('Actividad fuera del ámbito de áreas autorizado.', 403);
                        }
                    }
                    $selectedArea = $request->input('id_area');
                    if ($modelClass === Activity::class && filter_var($selectedArea, FILTER_VALIDATE_INT) !== false
                        && ! Area::query()->whereKey($selectedArea)->exists()) {
                        $selectedArea = null;
                    }
                    if ($modelClass !== Activity::class && $request->filled('id_actividad') && filter_var($request->input('id_actividad'), FILTER_VALIDATE_INT) !== false) {
                        $selectedArea = Activity::query()->whereKey($request->input('id_actividad'))->value('id_area');
                    }
                    if ($selectedArea !== null && filter_var($selectedArea, FILTER_VALIDATE_INT) !== false
                        && ! in_array((int) $selectedArea, $areas, true)) {
                        return $this->error('Actividad fuera del ámbito de áreas autorizado.', 403);
                    }
                    if ($modelClass === Attendance::class) {
                        foreach (['ID_PARTICIPACION' => Participation::class, 'ID_ACTIVIDAD_SESION' => ActivitySession::class] as $field => $parentClass) {
                            if ($request->filled($field) && filter_var($request->input($field), FILTER_VALIDATE_INT) !== false) {
                                $parent = $parentClass::query()->find($request->input($field));
                                if ($parent && ! in_array((int) $parent->activity?->id_area, $areas, true)) {
                                    return $this->error('Actividad fuera del ámbito de áreas autorizado.', 403);
                                }
                            }
                        }
                    }
                    if ($areas === [] && $ability === 'create') {
                        return $this->error('No tiene un área vigente autorizada para registrar actividades.', 403);
                    }
                }
                $request->attributes->set('activity_module_areas', $areas);
            } catch (QueryException) {
                return $this->error('No se pudo completar la operación.', 500);
            }
        }

        return $next($request);
    }
}
