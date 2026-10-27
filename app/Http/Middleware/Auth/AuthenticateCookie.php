<?php

namespace App\Http\Middleware\Auth;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentica la request con el JWT de la cookie access_token.
 */
class AuthenticateCookie
{
    public function handle(Request $request, Closure $next): Response
    {
        Auth::shouldUse('jwt');
        $user = Auth::guard('jwt')->user();
        if (! $user) {
            return $this->unauthenticated();
        }

        $request->setUserResolver(fn () => $user);

        return $next($request);
    }

    private function unauthenticated(): Response
    {
        if (! request()->is('api/auth/*', 'api/usuarios', 'api/usuarios/*', 'api/roles', 'api/roles/*', 'api/modulos', 'api/modulos/*', 'api/permisos/*')) {
            return response()->json(['success' => false, 'message' => 'No autenticado.', 'data' => []], 401);
        }

        return response()->json(['message' => 'No autenticado'], 401);
    }
}
