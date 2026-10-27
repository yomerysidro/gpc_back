<?php

namespace App\Models\Person_Worker;

use Illuminate\Database\Eloquent\Model;

class Person_Worker extends Model
{
    protected $table = 'persona_trabajador';

    protected $primaryKey = 'id_persona_trabajador';

    public $timestamps = false;

    protected $fillable = [
        'id_persona',
        'id_area',
        'cargo',
        'fecha_ingreso',
        'fecha_fin',
        'estado',
    ];

    protected $casts = [
        'estado' => 'boolean',
    ];
}
