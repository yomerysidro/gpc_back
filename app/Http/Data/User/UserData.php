<?php

namespace App\Http\Data\User;

use App\Models\Role\Role;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Throwable;

/**
 * Si el usuario no existe se devuelve success true y data null (el controller
 * responde 404). Las reglas de negocio incumplidas devuelven success false
 * con 'code' (409/422) para que el controller no las confunda con un 500.
 */
class UserData
{
    private const PER_PAGE = 10;

    /**
     * Listar usuarios paginados.
     *
     * $filters: search (nombre completo, DNI o correo), idRol, estado y page.
     */
    public static function getUsers(array $filters): array
    {
        try {
            $query = self::baseQuery();

            if (($filters['search'] ?? '') !== '') {
                $search = '%'.addcslashes($filters['search'], '%_\\').'%';

                $query->where(function ($query) use ($search) {
                    $query->whereRaw(
                        "concat_ws(' ', p.nombres, p.apellido_paterno, p.apellido_materno) ilike ?",
                        [$search]
                    )
                        ->orWhere('acceso.usuario.correo', 'ilike', $search)
                        ->orWhereExists(fn ($query) => self::dniQuery($query)
                            ->where('pd.numero_documento', 'ilike', $search));
                });
            }

            if (isset($filters['idRol'])) {
                $query->whereHas('roles', fn ($query) => $query->where('acceso.rol.id_rol', $filters['idRol']));
            }

            if (isset($filters['estado'])) {
                $query->where('acceso.usuario.estado', $filters['estado']);
            }

            $page = $filters['page'] ?? 1;
            $total = (clone $query)->count();

            $users = $query->orderBy('acceso.usuario.id_usuario')
                ->forPage($page, self::PER_PAGE)
                ->get()
                ->map(fn (User $user) => self::format($user));

            return [
                'success' => true,
                'message' => 'Usuarios obtenidos correctamente.',
                'data' => [
                    'users' => $users,
                    'meta' => [
                        'total' => $total,
                        'page' => $page,
                        'perPage' => self::PER_PAGE,
                    ],
                ],
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
    public static function getUserById(int $idUser): array
    {
        try {
            $user = self::find($idUser);

            return [
                'success' => true,
                'message' => $user ? 'Usuario obtenido correctamente.' : 'Usuario no encontrado.',
                'data' => $user ? self::format($user) : null,
            ];

        } catch (Throwable $e) {
            return [
                'success' => false,
                'message' => 'Error al obtener el usuario.',
            ];
        }
    }

    /**
     * Crear la cuenta de acceso de una persona, identificada por su DNI.
     *
     * Si la persona no existe se crea (persona + persona_documento) y entonces
     * nombres y apellidoPaterno son obligatorios. Si existe no se modifican sus
     * datos: pertenecen a gestion. La contraseña es aleatoria y nadie la conoce;
     * el usuario define la suya con el enlace que envía el controller.
     */
    public static function createUser(array $data): array
    {
        DB::beginTransaction();

        try {
            $personId = DB::table('gestion.persona_documento as pd')
                ->join('gestion.tipo_documento as td', 'td.id_tipo_documento', '=', 'pd.id_tipo_documento')
                ->where('td.nombre', 'DNI')
                ->where('pd.numero_documento', $data['dni'])
                ->value('pd.id_persona');

            if ($personId && User::where('id_persona', $personId)->exists()) {
                DB::rollBack();

                return [
                    'success' => false,
                    'code' => 409,
                    'message' => 'Esta persona ya tiene una cuenta de acceso',
                ];
            }

            if (! $personId) {
                $errors = [];

                foreach (['nombres' => 'nombres', 'apellidoPaterno' => 'apellido paterno'] as $field => $label) {
                    if (empty($data[$field])) {
                        $errors[$field] = ["El campo $label es obligatorio cuando la persona no está registrada."];
                    }
                }

                if ($errors) {
                    DB::rollBack();

                    return [
                        'success' => false,
                        'code' => 422,
                        'message' => 'La persona no está registrada: faltan sus datos.',
                        'data' => $errors,
                    ];
                }

                $personId = DB::table('gestion.persona')->insertGetId([
                    'nombres' => $data['nombres'],
                    'apellido_paterno' => $data['apellidoPaterno'],
                    'apellido_materno' => $data['apellidoMaterno'] ?? null,
                    'fecha_registro' => now()->toDateString(),
                ], 'id_persona');

                DB::table('gestion.persona_documento')->insert([
                    'id_persona' => $personId,
                    'id_tipo_documento' => DB::table('gestion.tipo_documento')->where('nombre', 'DNI')->value('id_tipo_documento'),
                    'numero_documento' => $data['dni'],
                    'estado' => 'Activo',
                ]);
            }

            $user = User::create([
                'id_persona' => $personId,
                'correo' => $data['correo'],
                'password_hash' => Hash::make(Str::random(40)),
                'estado' => true,
            ]);

            $user->roles()->sync([$data['idRol']]);

            DB::commit();

            return [
                'success' => true,
                'message' => 'Usuario registrado correctamente.',
                'data' => self::format(self::find($user->id_usuario)),
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
     * Actualizar el correo y, cuando se envían, el rol y la contraseña.
     *
     * No se permite si el cambio de rol deja al sistema sin ningún
     * Administrador activo.
     */
    public static function updateUser(int $idUser, array $data): array
    {
        DB::beginTransaction();

        try {
            $user = User::with('roles')->lockForUpdate()->find($idUser);

            if (! $user) {
                DB::commit();

                return [
                    'success' => true,
                    'message' => 'Usuario no encontrado.',
                    'data' => null,
                ];
            }

            $newRole = isset($data['idRol']) ? Role::find($data['idRol']) : null;

            if ($newRole && ! $newRole->isAdministrator() && self::isLastActiveAdmin($user)) {
                DB::rollBack();

                return [
                    'success' => false,
                    'code' => 409,
                    'message' => 'No se puede quitar el rol al último Administrador activo.',
                ];
            }

            $emailChanged = $user->correo !== $data['correo'];
            $password = $data['password'] ?? null;

            if ($emailChanged || $password !== null) {
                Password::deleteToken($user);
            }

            $user->correo = $data['correo'];

            if ($password !== null) {
                $user->password_hash = Hash::make($password);
                $user->intentos_fallidos = 0;
                $user->bloqueado_hasta = null;
            }

            $user->save();

            if ($emailChanged) {
                Password::deleteToken($user);
            }

            if ($newRole) {
                $user->roles()->sync([$newRole->id_rol]);
            }

            DB::commit();

            return [
                'success' => true,
                'message' => 'Usuario actualizado correctamente.',
                'data' => self::format(self::find($idUser)),
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
     * Activar o desactivar el usuario.
     * No se puede desactivar al último Administrador activo.
     */
    public static function updateUserStatus(int $idUser, bool $status): array
    {
        DB::beginTransaction();

        try {
            $user = User::with('roles')->lockForUpdate()->find($idUser);

            if (! $user) {
                DB::commit();

                return [
                    'success' => true,
                    'message' => 'Usuario no encontrado.',
                    'data' => null,
                ];
            }

            if (! $status && self::isLastActiveAdmin($user)) {
                DB::rollBack();

                return [
                    'success' => false,
                    'code' => 409,
                    'message' => 'No se puede desactivar al último Administrador activo.',
                ];
            }

            $user->update(['estado' => $status]);

            DB::commit();

            return [
                'success' => true,
                'message' => $status ? 'Usuario activado correctamente.' : 'Usuario desactivado correctamente.',
                'data' => self::format(self::find($idUser)),
            ];

        } catch (Throwable $e) {
            DB::rollBack();

            return [
                'success' => false,
                'message' => 'Error al actualizar el estado del usuario.',
            ];
        }
    }

    /**
     * Reiniciar los intentos fallidos y quitar el bloqueo.
     */
    public static function unlockUser(int $idUser): array
    {
        DB::beginTransaction();

        try {
            $updated = DB::table('acceso.usuario')
                ->where('id_usuario', $idUser)
                ->update([
                    'intentos_fallidos' => 0,
                    'bloqueado_hasta' => null,
                ]);

            DB::commit();

            return [
                'success' => true,
                'message' => $updated ? 'Usuario desbloqueado correctamente.' : 'Usuario no encontrado.',
                'data' => $updated ? self::format(self::find($idUser)) : null,
            ];

        } catch (Throwable $e) {
            DB::rollBack();

            return [
                'success' => false,
                'message' => 'Error al desbloquear el usuario.',
            ];
        }
    }

    /**
     * true si el usuario es Administrador activo y no hay otro.
     */
    private static function isLastActiveAdmin(User $user): bool
    {
        if (! $user->estado || ! $user->roles->contains(fn (Role $role) => $role->isAdministrator())) {
            return false;
        }

        return ! User::where('id_usuario', '!=', $user->id_usuario)
            ->where('estado', true)
            ->whereHas('roles', fn ($query) => $query->where('acceso.rol.nombre', Role::ADMINISTRATOR))
            ->exists();
    }

    /**
     * Usuario con su persona, roles y DNI (columna dni).
     */
    private static function baseQuery(): Builder
    {
        return User::query()
            ->join('gestion.persona as p', 'p.id_persona', '=', 'acceso.usuario.id_persona')
            ->select('usuario.*')
            ->selectSub(fn ($query) => self::dniQuery($query)->select('pd.numero_documento')->limit(1), 'dni')
            ->with(['person', 'roles']);
    }

    /**
     * Documentos DNI de la persona del usuario (correlacionada con "p").
     */
    private static function dniQuery($query)
    {
        return $query->from('gestion.persona_documento as pd')
            ->join('gestion.tipo_documento as td', 'td.id_tipo_documento', '=', 'pd.id_tipo_documento')
            ->where('td.nombre', 'DNI')
            ->whereColumn('pd.id_persona', 'p.id_persona');
    }

    private static function find(int $idUser): ?User
    {
        return self::baseQuery()->where('acceso.usuario.id_usuario', $idUser)->first();
    }

    private static function format(User $user): array
    {
        $role = $user->roles->first();
        $lockedUntil = $user->bloqueado_hasta;
        $isLocked = (bool) $lockedUntil?->isFuture();

        return [
            'id' => $user->id_usuario,
            'nombre' => $user->person->full_name,
            'dni' => $user->dni,
            'correo' => $user->correo,
            'rol' => $role ? ['id' => $role->id_rol, 'nombre' => $role->nombre] : null,
            'estado' => $user->estado,
            'bloqueado' => $isLocked,
            'intentosFallidos' => $lockedUntil && ! $isLocked ? 0 : (int) $user->intentos_fallidos,
            'limiteIntentos' => config('auth.login_max_attempts'),
            'ultimoAcceso' => $user->ultimo_acceso?->toIso8601String(),
        ];
    }
}
