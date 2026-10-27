<?php

namespace App\Models\Activity;

use Illuminate\Database\Eloquent\Model;

class Activity extends Model
{
    protected $table = 'certificado.actividad';

    protected $primaryKey = 'id_actividad';

    public $timestamps = false;

    protected $fillable = [
        'id_area',
        'id_tipo_actividad',
        'nombre',
        'descripcion',
        'fecha_inicio',
        'fecha_fin',
        'sede',
        'estado',
    ];
}
