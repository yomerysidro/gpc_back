<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Data\User\UserData;
use App\Http\Requests\User\UserIndexRequest;
use App\Http\Requests\User\UserStatusRequest;
use App\Http\Requests\User\UserStoreRequest;
use App\Http\Requests\User\UserUpdateRequest;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Cuentas de acceso (acceso.usuario). Los datos de la persona pertenecen a gestion.
 */
class UserController extends Controller
{
    use ApiResponse;

    /**
     * List users (paginated, with filters).
     */
    public function list(UserIndexRequest $request): JsonResponse
    {
        $response = UserData::getUsers($request->filters());

        if (! $response['success']) {
            return $this->error($response['message'], 500);
        }

        return response()->json([
            'success' => true,
            'message' => $response['message'],
            'data' => $response['data']['users'],
            'meta' => $response['data']['meta'],
        ]);
    }

    /**
     * User detail.
     */
    public function detail(int $id): JsonResponse
    {
        return $this->respond(UserData::getUserById($id));
    }

    /**
     * Create user and send the link to set the password.
     *
     * If the email fails the user is kept: the admin can resend it
     * with restablecer-password.
     */
    public function store(UserStoreRequest $request): JsonResponse
    {
        $response = UserData::createUser($request->validated());

        if ($response['success']) {
            try {
                Password::sendResetLink(['correo' => $response['data']['correo']]);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $this->respond($response, 201);
    }

    /**
     * Update email and role.
     */
    public function update(UserUpdateRequest $request, int $id): JsonResponse
    {
        return $this->respond(UserData::updateUser($id, $request->validated()));
    }

    /**
     * Activate or deactivate user. Nobody can deactivate themselves.
     */
    public function updateStatus(UserStatusRequest $request, int $id): JsonResponse
    {
        $status = $request->boolean('estado');

        if (! $status && $request->user()->id_usuario === $id) {
            return $this->error('No puede desactivar su propia cuenta.', 409);
        }

        return $this->respond(UserData::updateUserStatus($id, $status));
    }

    /**
     * Reset failed attempts and remove the lock.
     */
    public function unlock(int $id): JsonResponse
    {
        return $this->respond(UserData::unlockUser($id));
    }

    /**
     * Send the password reset link to the user's email.
     */
    public function resetPassword(int $id): JsonResponse
    {
        $response = UserData::getUserById($id);

        if (! $response['success'] || $response['data'] === null) {
            return $this->respond($response);
        }

        $status = Password::sendResetLink(['correo' => $response['data']['correo']]);

        if ($status === Password::RESET_THROTTLED) {
            return $this->error('Ya se envió un enlace hace poco. Intente nuevamente en un minuto.', 429);
        }

        if ($status !== Password::RESET_LINK_SENT) {
            return $this->error('No se pudo enviar el enlace para restablecer la contraseña.', 500);
        }

        return $this->ok(null, 'Se envió el enlace para restablecer la contraseña a '.$response['data']['correo'].'.');
    }

    /**
     * data null means the user does not exist; a failure without
     * 'code' is a database error. 422 keeps Laravel's validation format.
     */
    private function respond(array $response, int $code = 200): JsonResponse
    {
        if (! $response['success']) {
            if (($response['code'] ?? null) === 422) {
                throw ValidationException::withMessages($response['data']);
            }

            return $this->error($response['message'], $response['code'] ?? 500);
        }

        if ($response['data'] === null) {
            return $this->error($response['message'], 404);
        }

        return $this->ok($response['data'], $response['message'], $code);
    }
}
