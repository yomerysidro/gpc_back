<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Data\Auth\AuthData;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User\User;
use App\Services\Auth\JwtService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

/**
 * Autenticación con JWT en cookie httpOnly.
 *
 * Las respuestas no usan ApiResponse: el frontend Angular consume este
 * contrato exacto ({"user": {...}} / {"message": "..."}).
 */
class AuthController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    private const LOCK_MINUTES = 30;

    public function __construct(private JwtService $jwt) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $data = $request->validated();

        $response = AuthData::getUserByEmail($data['email']);

        if (! $response['success']) {
            return $this->serverError($response);
        }

        $user = $response['data'];

        if (! $user) {
            return response()->json(['message' => 'Credenciales inválidas'], 401);
        }

        if (! $user->estado) {
            return response()->json(['message' => 'Usuario desactivado'], 401);
        }

        if ($user->bloqueado_hasta?->isFuture()) {
            return response()->json([
                'message' => 'Cuenta bloqueada',
                'bloqueadoHasta' => $user->bloqueado_hasta->toIso8601String(),
            ], 423);
        }

        // Si el bloqueo ya venció, el conteo de intentos empieza de cero.
        if ($user->bloqueado_hasta) {
            $response = AuthData::resetAttempts($user->id_usuario);

            if (! $response['success']) {
                return $this->serverError($response);
            }
        }

        if (! Hash::check($data['password'], $user->password_hash)) {
            $response = AuthData::registerFailedAttempt($user->id_usuario);

            if (! $response['success']) {
                return $this->serverError($response);
            }

            if ($response['data']['attempts'] >= self::MAX_ATTEMPTS) {
                $lockedUntil = now()->addMinutes(self::LOCK_MINUTES);

                $response = AuthData::lockAccount($user->id_usuario, $lockedUntil);

                if (! $response['success']) {
                    return $this->serverError($response);
                }

                return response()->json([
                    'message' => 'Cuenta bloqueada por intentos fallidos',
                    'bloqueadoHasta' => $lockedUntil->toIso8601String(),
                ], 423);
            }

            return response()->json(['message' => 'Credenciales inválidas'], 401);
        }

        $response = AuthData::resetAttempts($user->id_usuario);

        if (! $response['success']) {
            return $this->serverError($response);
        }

        $response = AuthData::updateLastAccess($user->id_usuario);

        if (! $response['success']) {
            return $this->serverError($response);
        }

        return response()
            ->json(['user' => $this->formatUser($user)])
            ->withCookie($this->jwt->makeCookie($this->jwt->createToken($user)));
    }

    /**
     * Además del usuario devuelve el menú (módulos) y las claves de permiso
     * de sus roles activos; el login no los incluye.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'user' => $this->formatUser($user),
            'modulos' => $user->modulesForMenu()->map(fn ($module) => [
                'id' => $module->id_modulo,
                'nombre' => $module->nombre,
                'url' => $module->url,
                'imagen' => $module->imagen,
            ]),
            'permisos' => $user->permissionKeys(),
        ]);
    }

    public function logout(): JsonResponse
    {
        return response()
            ->json(['message' => 'Sesión cerrada'])
            ->withCookie($this->jwt->expireCookie());
    }

    /**
     * Responde siempre 200 para no revelar qué correos están registrados.
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        Password::sendResetLink(['correo' => $request->validated('email')]);

        return response()->json([
            'message' => 'Si el correo está registrado, se envió un enlace para restablecer la contraseña',
        ]);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $data = $request->validated();
        $response = null;

        $status = Password::reset(
            [
                'correo' => $data['email'],
                'token' => $data['token'],
                'password' => $data['password'],
                'password_confirmation' => $request->input('password_confirmation'),
            ],
            function (User $user, string $password) use (&$response) {
                $response = AuthData::updatePassword($user->id_usuario, $password);
            }
        );

        if ($response && ! $response['success']) {
            return $this->serverError($response);
        }

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json(['message' => 'Token inválido o expirado'], 400);
        }

        return response()->json(['message' => 'Contraseña restablecida']);
    }

    /**
     * Las claves del JSON (nombre, rol) son parte del contrato con el frontend.
     */
    private function formatUser(User $user): array
    {
        return [
            'id' => $user->id_usuario,
            'nombre' => $user->person->full_name,
            'email' => $user->correo,
            'rol' => $user->roleName(),
        ];
    }

    private function serverError(array $response): JsonResponse
    {
        return response()->json(['message' => $response['message']], 500);
    }
}
