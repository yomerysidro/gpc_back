<?php

namespace App\Models\Supervisory_internship;

use Illuminate\Database\Eloquent\Model;

class Supervisory_internship extends Model
{
    protected $table = 'gestion.practica_supervisor';

    protected $primaryKey = 'id_practica_supervisor';

    public $timestamps = false;

    protected $fillable = [
        'id_practica',
        'id_persona_trabajador',
        'tipo_supervision',
        'fecha_inicio',
        'fecha_fin',
        'estado',
    ];
}
