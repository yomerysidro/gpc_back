<?php

namespace App\Models\Role;

use App\Models\Module\Module;
use App\Models\Permission\Permission;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
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

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'acceso.usuario_rol', 'id_rol', 'id_usuario');
    }

    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class, 'acceso.rol_modulo', 'id_rol', 'id_modulo');
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'acceso.rol_modulo_permiso', 'id_rol', 'id_permiso')
            ->withPivot('id_modulo');
    }
}
