<?php

namespace App\Models\Role;

use Illuminate\Database\Eloquent\Model;

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
}
