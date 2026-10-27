<?php

namespace App\Models\ActivitySession;

use App\Models\Activity\Activity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivitySession extends Model
{
    protected $table = 'certificado.actividad_sesion';

    protected $primaryKey = 'id_actividad_sesion';

    public $timestamps = false;

    protected $fillable = [
        'id_actividad',
        'nombre',
        'fecha',
        'hora_inicio',
        'hora_fin',
        'lugar',
        'estado',
    ];

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class, 'id_actividad', 'id_actividad');
    }
}
