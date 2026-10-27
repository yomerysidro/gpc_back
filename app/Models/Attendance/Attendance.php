<?php

namespace App\Models\Attendance;

use Illuminate\Database\Eloquent\Model;

class Attendance extends Model
{
    protected $table = 'certificado.asistencia';

    protected $primaryKey = 'id_asistencia';

    public $timestamps = false;

    protected $fillable = [
        'id_participacion',
        'id_actividad_sesion',
        'hora_entrada',
        'hora_salida',
        'estado',
    ];

    protected $casts = [
        'id_asistencia' => 'integer',
        'id_participacion' => 'integer',
        'id_actividad_sesion' => 'integer',
    ];
}
