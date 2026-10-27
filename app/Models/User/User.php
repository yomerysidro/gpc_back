<?php

namespace App\Models\User;

use App\Models\Module\Module;
use App\Models\Person\Persona;
use App\Models\Role\Role;
use Illuminate\Auth\Authenticatable;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
        return DB::table('acceso.rol_modulo_permiso as rmp')
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
            ->orderBy('p.id_permiso')
            ->pluck('p.clave')
            ->all();
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
