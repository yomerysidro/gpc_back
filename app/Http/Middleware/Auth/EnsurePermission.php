<?php

namespace App\Http\Middleware\Auth;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Uso: ->middleware(['auth.cookie', 'permission:actividades.crear']).
 * Con varias claves basta con tener una. Debe ir después de auth.cookie.
 */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        if (! array_intersect($permissions, $user->permissionKeys())) {
            return response()->json(['message' => 'No tiene permisos para acceder a este recurso'], 403);
        }

        return $next($request);
    }
}
