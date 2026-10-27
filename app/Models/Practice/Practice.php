<?php

namespace App\Models\Practice;

use Illuminate\Database\Eloquent\Model;

class Practice extends Model
{
    protected $table = 'practica';

    protected $primaryKey = 'id_practica';

    public $timestamps = false;

    protected $fillable = [
        'id_persona_practicante',
        'id_institucion',
        'id_area',
        'carrera',
        'fecha_inicio',
        'fecha_fin',
        'estado',
    ];
}
