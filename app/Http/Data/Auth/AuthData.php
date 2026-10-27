<?php

namespace App\Http\Data\Auth;

use App\Models\User\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Throwable;

class AuthData
{
    /**
     * Obtener un usuario por correo, con su persona y roles.
     *
     * Si no existe devuelve success true y data null: el controller necesita
     * distinguir "no existe" (401) de un error de base de datos (500).
     */
    public static function getUserByEmail(string $email): array
    {
        try {
            $user = User::with(['person', 'roles'])
                ->where('correo', $email)
                ->first();

            return [
                'success' => true,
                'message' => $user ? 'Usuario obtenido correctamente.' : 'Usuario no encontrado.',
                'data' => $user,
            ];

        } catch (Throwable $e) {
            return [
                'success' => false,
                'message' => 'Error al obtener el usuario.',
            ];
        }
    }

    /**
     * Registrar un intento fallido de login.
     * Devuelve el total de intentos acumulados.
     */
    public static function registerFailedAttempt(int $idUser): array
    {
        DB::beginTransaction();

        try {
            /*
             * Se bloquea la fila para que dos logins simultáneos
             * no pierdan un incremento.
             */
            $attempts = DB::table('acceso.usuario')
                ->where('id_usuario', $idUser)
                ->lockForUpdate()
                ->value('intentos_fallidos') + 1;

            DB::table('acceso.usuario')
                ->where('id_usuario', $idUser)
                ->update(['intentos_fallidos' => $attempts]);

            DB::commit();

            return [
                'success' => true,
                'message' => 'Intento fallido registrado correctamente.',
                'data' => ['attempts' => $attempts],
            ];

        } catch (Throwable $e) {
            DB::rollBack();

            return [
                'success' => false,
                'message' => 'Error al registrar el intento fallido.',
            ];
        }
    }

    /**
     * Bloquear la cuenta hasta la fecha indicada.
     */
    public static function lockAccount(int $idUser, \DateTimeInterface $lockedUntil): array
    {
        DB::beginTransaction();

        try {
            DB::table('acceso.usuario')
                ->where('id_usuario', $idUser)
                ->update(['bloqueado_hasta' => $lockedUntil]);

            DB::commit();

            return [
                'success' => true,
                'message' => 'Cuenta bloqueada correctamente.',
                'data' => null,
            ];

        } catch (Throwable $e) {
            DB::rollBack();

            return [
                'success' => false,
                'message' => 'Error al bloquear la cuenta.',
            ];
        }
    }

    /**
     * Reiniciar los intentos fallidos y quitar el bloqueo.
     */
    public static function resetAttempts(int $idUser): array
    {
        DB::beginTransaction();

        try {
            DB::table('acceso.usuario')
                ->where('id_usuario', $idUser)
                ->update([
                    'intentos_fallidos' => 0,
                    'bloqueado_hasta' => null,
                ]);

            DB::commit();

            return [
                'success' => true,
                'message' => 'Intentos reiniciados correctamente.',
                'data' => null,
            ];

        } catch (Throwable $e) {
            DB::rollBack();

            return [
                'success' => false,
                'message' => 'Error al reiniciar los intentos.',
            ];
        }
    }

    /**
     * Registrar la fecha y hora del último acceso.
     */
    public static function updateLastAccess(int $idUser): array
    {
        DB::beginTransaction();

        try {
            DB::table('acceso.usuario')
                ->where('id_usuario', $idUser)
                ->update(['ultimo_acceso' => now()]);

            DB::commit();

            return [
                'success' => true,
                'message' => 'Último acceso actualizado correctamente.',
                'data' => null,
            ];

        } catch (Throwable $e) {
            DB::rollBack();

            return [
                'success' => false,
                'message' => 'Error al actualizar el último acceso.',
            ];
        }
    }

    /**
     * Guardar una nueva contraseña y quitar el bloqueo.
     */
    public static function updatePassword(int $idUser, string $password): array
    {
        DB::beginTransaction();

        try {
            DB::table('acceso.usuario')
                ->where('id_usuario', $idUser)
                ->update([
                    'password_hash' => Hash::make($password),
                    'intentos_fallidos' => 0,
                    'bloqueado_hasta' => null,
                ]);

            DB::commit();

            return [
                'success' => true,
                'message' => 'Contraseña actualizada correctamente.',
                'data' => null,
            ];

        } catch (Throwable $e) {
            DB::rollBack();

            return [
                'success' => false,
                'message' => 'Error al actualizar la contraseña.',
            ];
        }
    }
}
