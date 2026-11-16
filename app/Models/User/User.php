<?php

namespace App\Models\User;

use App\Models\Module\Module;
use App\Models\Person\Persona;
use App\Models\Role\Role;
use App\Services\RequestReadMemo;
use Illuminate\Auth\Authenticatable;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Query\Builder;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

class User extends Model implements AuthenticatableContract, CanResetPasswordContract
{
    use Authenticatable, CanResetPassword, Notifiable;

    protected $table = 'acceso.usuario';

    protected $primaryKey = 'id_usuario';

    public $timestamps = false;

    protected $fillable = [
        'id_persona',
        'correo',
        'password_hash',
        'estado',
    ];

    protected $hidden = [
        'password_hash',
    ];

    protected $casts = [
        'estado' => 'boolean',
        'intentos_fallidos' => 'integer',
        'bloqueado_hasta' => 'datetime',
        'ultimo_acceso' => 'datetime',
    ];

    public function person(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'id_persona', 'id_persona');
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'acceso.usuario_rol', 'id_usuario', 'id_rol')->select('rol.*');
    }

    /**
     * Nombre del rol del usuario (el sistema asigna uno por usuario).
     */
    public function roleName(): ?string
    {
        return $this->roles->first()?->nombre;
    }

    /**
     * Módulos activos asignados a los roles activos del usuario, ordenados por id.
     */
    public function modulesForMenu(): Collection
    {
        if ($this->isSystemAdministrator()) {
            return Module::where('estado', true)->orderBy('id_modulo')->get(['id_modulo', 'nombre', 'url', 'imagen']);
        }

        return Module::query()
            ->where('estado', true)
            ->whereHas('roles', fn ($query) => $query
                ->where('acceso.rol.estado', true)
                ->whereHas('users', fn ($query) => $query->where('acceso.usuario.id_usuario', $this->id_usuario)))
            ->orderBy('id_modulo')
            ->get(['id_modulo', 'nombre', 'url', 'imagen']);
    }

    /**
     * Claves de los permisos activos que los roles activos del usuario tienen
     * sobre módulos activos y todavía asignados (rol_modulo), ordenadas por id.
     */
    public function permissionKeys(): array
    {
        if (! $this->estado) {
            return [];
        }

        return RequestReadMemo::remember($this->authorizationMemoKey('permissions'),
            fn (): array => $this->resolvePermissionKeys(), $this->getConnectionName());
    }

    /** @return list<string> */
    private function resolvePermissionKeys(): array
    {
        if (! $this->estado) {
            return [];
        }
        $assigned = DB::table('acceso.rol_modulo_permiso as rmp')
            ->join('acceso.rol_modulo as rm', function ($join) {
                $join->on('rm.id_rol', '=', 'rmp.id_rol')
                    ->on('rm.id_modulo', '=', 'rmp.id_modulo');
            })
            ->join('acceso.rol as r', 'r.id_rol', '=', 'rmp.id_rol')
            ->join('acceso.modulo as m', 'm.id_modulo', '=', 'rmp.id_modulo')
            ->join('acceso.permiso as p', function ($join) {
                $join->on('p.id_permiso', '=', 'rmp.id_permiso')
                    ->on('p.id_modulo', '=', 'rmp.id_modulo');
            })
            ->join('acceso.usuario_rol as ur', 'ur.id_rol', '=', 'rmp.id_rol')
            ->where('ur.id_usuario', $this->id_usuario)
            ->where('r.estado', true)
            ->where('m.estado', true)
            ->where('p.estado', true)
            ->groupBy('p.id_permiso', 'p.clave')
            ->select('p.id_permiso', 'p.clave')->selectRaw("'assigned' as source");
        $administrative = DB::table('acceso.permiso as p')->join('acceso.modulo as m', 'm.id_modulo', '=', 'p.id_modulo')
            ->where('p.estado', true)->where('m.estado', true)
            ->whereExists($this->administratorQuery()->selectRaw('1'))
            ->whereNotIn('p.clave', array_diff(config('module_permissions.formal_permissions', []),
                config('module_permissions.administrator_formal_permissions', [])))
            ->select('p.id_permiso', 'p.clave')->selectRaw("'administrative' as source");
        $administrator = $this->administratorQuery()->selectRaw("0 as id_permiso, '' as clave, 'administrator' as source");
        $rows = $assigned->unionAll($administrative)->unionAll($administrator)->get();
        RequestReadMemo::remember($this->authorizationMemoKey('administrator'),
            fn (): bool => $this->exists && $rows->contains('source', 'administrator'), $this->getConnectionName());
        $assigned = $rows->where('source', 'assigned')->sortBy('id_permiso')->pluck('clave')->all();
        if (! $this->exists || ! $rows->contains('source', 'administrator')) {
            return $assigned;
        }
        $administrative = $rows->where('source', 'administrative')->sortBy('id_permiso')->pluck('clave')->all();

        $administrativeCrud = [];
        foreach (config('module_permissions.models', []) as $model => $module) {
            if (! is_string($module) || $module === '') {
                continue;
            }
            foreach (['ver', 'crear', 'editar', 'eliminar'] as $action) {
                $administrativeCrud[] = $module.'.'.config('module_permissions.action_aliases.'.$model.'.'.$action, $action);
            }
        }

        return array_values(array_unique([...$administrative, ...$administrativeCrud, ...$assigned]));
    }

    public function isSystemAdministrator(): bool
    {
        if (! $this->exists || ! $this->estado) {
            return false;
        }

        return RequestReadMemo::remember($this->authorizationMemoKey('administrator'),
            fn (): bool => $this->administratorQuery()->exists(), $this->getConnectionName());
    }

    private function authorizationMemoKey(string $operation): string
    {
        return self::class.'.'.$operation.'.'.hash('sha256', serialize([
            $this->getAuthIdentifier(), $this->exists, $this->estado, config('module_permissions'),
        ]));
    }

    private function administratorQuery(): Builder
    {
        return DB::table('acceso.usuario as u')->join('acceso.usuario_rol as ur', 'ur.id_usuario', '=', 'u.id_usuario')
            ->join('acceso.rol as r', 'r.id_rol', '=', 'ur.id_rol')
            ->where('u.id_usuario', $this->getAuthIdentifier())->where('u.estado', true)->where('r.estado', true)
            ->whereIn('r.id_rol', config('module_permissions.administrator_role_ids', []))
            ->whereIn('r.nombre', config('module_permissions.administrator_role_names', []))
            ->whereRaw($this->exists ? '1 = 1' : '1 = 0');
    }

    public function hasInstitutionalRequestAuthority(): bool
    {
        return config('module_permissions.administrator_request_institutional_scope', false)
            && $this->isSystemAdministrator();
    }

    public function canRepresentAllRequestAreas(): bool
    {
        return config('module_permissions.administrator_request_represent_all_areas', false)
            && $this->isSystemAdministrator()
            && in_array('solicitudes.crear', $this->permissionKeys(), true);
    }

    public function getAuthPasswordName()
    {
        return 'password_hash';
    }

    public function getAuthPassword()
    {
        return $this->password_hash;
    }

    public function getEmailForPasswordReset()
    {
        return $this->correo;
    }

    public function routeNotificationForMail($notification = null): string
    {
        return $this->correo;
    }
}
