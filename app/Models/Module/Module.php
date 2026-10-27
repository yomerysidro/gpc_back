<?php

namespace App\Models\Module;

use App\Models\Permission\Permission;
use App\Models\Role\Role;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Module extends Model
{
    protected $table = 'acceso.modulo';
    protected $primaryKey = 'id_modulo';

    public $timestamps = false;

    protected $fillable = [
        'nombre',
        'url',
        'imagen',
        'estado',
    ];

    protected $casts = [
        'estado' => 'boolean',
    ];

    /**
     * Prefijo de las claves de sus permisos: la url sin la "/" inicial y con
     * las "/" internas como "-" (/reportes/anuales → reportes-anuales).
     */
    public function slug(): string
    {
        return str_replace('/', '-', ltrim($this->url, '/'));
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'acceso.rol_modulo', 'id_modulo', 'id_rol');
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(Permission::class, 'id_modulo');
    }
}
