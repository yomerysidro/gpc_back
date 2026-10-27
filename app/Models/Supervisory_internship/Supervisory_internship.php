<?php

namespace App\Models\Supervisory_internship;

use App\Models\Person_Worker\Person_Worker;
use App\Models\Practice\Practice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function practice(): BelongsTo
    {
        return $this->belongsTo(Practice::class, 'id_practica', 'id_practica');
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Person_Worker::class, 'id_persona_trabajador', 'id_persona_trabajador');
    }
}
