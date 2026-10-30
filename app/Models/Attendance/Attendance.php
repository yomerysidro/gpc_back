<?php

namespace App\Models\Attendance;

use App\Models\ActivitySession\ActivitySession;
use App\Models\Participation\Participation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function participation(): BelongsTo
    {
        return $this->belongsTo(Participation::class, 'id_participacion', 'id_participacion');
    }

    public function activitySession(): BelongsTo
    {
        return $this->belongsTo(ActivitySession::class, 'id_actividad_sesion', 'id_actividad_sesion');
    }
}
