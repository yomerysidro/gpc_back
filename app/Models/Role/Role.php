<?php

namespace App\Models\Role;

use App\Models\Module\Module;
use App\Models\Permission\Permission;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    /**
     * El esquema no tiene una marca para el rol de sistema: se identifica por
     * su nombre, que es único y no se puede cambiar (RoleData::updateRole).
     * role:Administrador y las protecciones de acceso dependen de él.
     */
    public const ADMINISTRATOR = 'Administrador';

    protected $table = 'acceso.rol';

    protected $primaryKey = 'id_rol';

    public $timestamps = false;

    protected $fillable = [
        'nombre',
        'estado',
    ];

    protected $casts = [
        'estado' => 'boolean',
    ];

    /**
     * Compara el nombre guardado, no el que se esté asignando en memoria.
     */
    public function isAdministrator(): bool
    {
        return $this->getOriginal('nombre') === self::ADMINISTRATOR;
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'acceso.usuario_rol', 'id_rol', 'id_usuario')->select('usuario.*');
    }

    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class, 'acceso.rol_modulo', 'id_rol', 'id_modulo')->select('modulo.*');
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'acceso.rol_modulo_permiso', 'id_rol', 'id_permiso')->select('permiso.*')
            ->withPivot('id_modulo');
    }
}
