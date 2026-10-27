<?php

namespace App\Http\Data\User;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Throwable;

class UserData
{
    /**
     * Listar todos los usuarios.
     */
    public static function getUsers(): array
    {
        try {
            $users = DB::table('users')
                ->select(
                    'id',
                    'name',
                    'email',
                    'created_at',
                    'updated_at'
                )
                ->orderBy('id', 'desc')
                ->get();

            return [
                'success' => true,
                'message' => 'Usuarios obtenidos correctamente.',
                'data' => $users,
            ];

        } catch (Throwable $e) {
            return [
                'success' => false,
                'message' => 'Error al obtener los usuarios.',
            ];
        }
    }

    /**
     * Obtener un usuario por ID.
     */
    public static function getUserById(int $id): array
    {
        try {
            $user = DB::table('users')
                ->select(
                    'id',
                    'name',
                    'email',
                    'created_at',
                    'updated_at'
                )
                ->where('id', $id)
                ->first();

            if (! $user) {
                return [
                    'success' => false,
                    'message' => 'Usuario no encontrado.',
                ];
            }

            return [
                'success' => true,
                'message' => 'Usuario obtenido correctamente.',
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
     * Crear usuario.
     */
    public static function createUser(array $data): array
    {
        DB::beginTransaction();

        try {
            $idUser = DB::table('users')->insertGetId([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'created_at' => now(),
                'updated_at' => now(),
            ], 'id');

            $user = DB::table('users')
                ->select(
                    'id',
                    'name',
                    'email',
                    'created_at',
                    'updated_at'
                )
                ->where('id', $idUser)
                ->first();

            DB::commit();

            return [
                'success' => true,
                'message' => 'Usuario registrado correctamente.',
                'data' => $user,
            ];

        } catch (Throwable $e) {
            DB::rollBack();

            return [
                'success' => false,
                'message' => 'Error al registrar el usuario.',
            ];
        }
    }

    /**
     * Actualizar usuario.
     */
    public static function updateUser(int $id, array $data): array
    {
        DB::beginTransaction();

        try {
            $user = DB::table('users')
                ->where('id', $id)
                ->first();

            if (! $user) {
                DB::rollBack();

                return [
                    'success' => false,
                    'message' => 'Usuario no encontrado.',
                ];
            }

            $updateData = array_intersect_key($data, array_flip(['name', 'email']));
            $updateData['updated_at'] = now();

            /*
             * La contraseña solamente se modifica
             * si se envía una nueva contraseña.
             */
            if (! empty($data['password'])) {
                $updateData['password'] = Hash::make($data['password']);
            }

            DB::table('users')
                ->where('id', $id)
                ->update($updateData);

            $updatedUser = DB::table('users')
                ->select(
                    'id',
                    'name',
                    'email',
                    'created_at',
                    'updated_at'
                )
                ->where('id', $id)
                ->first();

            DB::commit();

            return [
                'success' => true,
                'message' => 'Usuario actualizado correctamente.',
                'data' => $updatedUser,
            ];

        } catch (Throwable $e) {
            DB::rollBack();

            return [
                'success' => false,
                'message' => 'Error al actualizar el usuario.',
            ];
        }
    }

    /**
     * Eliminar usuario.
     */
    public static function deleteUser(int $id): array
    {
        DB::beginTransaction();

        try {
            $user = DB::table('users')
                ->where('id', $id)
                ->first();

            if (! $user) {
                DB::rollBack();

                return [
                    'success' => false,
                    'message' => 'Usuario no encontrado.',
                ];
            }

            DB::table('users')
                ->where('id', $id)
                ->delete();

            DB::commit();

            return [
                'success' => true,
                'message' => 'Usuario eliminado correctamente.',
                'data' => null,
            ];

        } catch (Throwable $e) {
            DB::rollBack();

            return [
                'success' => false,
                'message' => 'Error al eliminar el usuario.',
            ];
        }
    }
}
