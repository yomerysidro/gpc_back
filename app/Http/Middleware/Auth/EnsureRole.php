<?php

namespace App\Http\Middleware\Auth;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Uso: ->middleware(['auth.cookie', 'role:Administrador,Digitador']).
 * Debe ir después de auth.cookie, que es quien carga el usuario.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        $administratorAccess = in_array('Administrador', $roles, true) && $user->isSystemAdministrator();
        $ordinaryRoles = array_diff($roles, ['Administrador', 'Administrador de Sistemas']);
        if (! $administratorAccess && $user->roles->where('estado', true)->pluck('nombre')->intersect($ordinaryRoles)->isEmpty()) {
            return response()->json(['message' => 'No tiene permisos para acceder a este recurso'], 403);
        }

        return $next($request);
    }
}
