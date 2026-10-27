<?php

namespace App\Models\ActivitySession;

use App\Models\Activity\Activity;
use App\Models\Attendance\Attendance;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ActivitySession extends Model
{
    protected $table = 'certificado.actividad_sesion';

    protected $primaryKey = 'id_actividad_sesion';

    public $timestamps = false;

    protected $casts = [
        'id_actividad' => 'integer',
        'fecha' => 'date:Y-m-d',
    ];

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

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class, 'id_actividad_sesion', 'id_actividad_sesion');
    }
}
