<?php

namespace App\Models\User;

use App\Models\Person\Person;
use App\Models\Role\Role;
use Illuminate\Auth\Authenticatable;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Notifications\Notifiable;

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
        return $this->belongsTo(Person::class, 'id_persona');
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'acceso.usuario_rol', 'id_usuario', 'id_rol');
    }

    /**
     * Nombre del rol del usuario (el sistema asigna uno por usuario).
     */
    public function roleName(): ?string
    {
        return $this->roles->first()?->nombre;
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
