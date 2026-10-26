<?php

namespace App\Http\Middleware\Auth;

use App\Models\User\User;
use App\Services\Auth\JwtService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Autentica la request con el JWT de la cookie access_token.
 */
class AuthenticateCookie
{
    public function __construct(private JwtService $jwt)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->cookie(JwtService::COOKIE);

        if (! is_string($token) || $token === '') {
            return $this->unauthenticated();
        }

        try {
            $payload = $this->jwt->decode($token);
        } catch (Throwable) {
            return $this->unauthenticated();
        }

        $user = User::with(['person', 'roles'])->find($payload->sub ?? null);

        if (! $user || ! $user->estado) {
            return $this->unauthenticated();
        }

        $request->setUserResolver(fn () => $user);

        return $next($request);
    }

    private function unauthenticated(): Response
    {
        return response()->json(['message' => 'No autenticado'], 401);
    }
}
