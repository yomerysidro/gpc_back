<?php

namespace App\Models;

use App\Models\Activity\Activity;
use App\Models\ActivityType\ActivityType;
use App\Models\Area\Area;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ActivityRequest extends Model
{
    protected $table = 'certificado.solicitud_actividad';

    protected $primaryKey = 'id_solicitud_actividad';

    public $timestamps = false;

    protected $fillable = [
        'id_area',
        'id_tipo_actividad',
        'nombre',
        'descripcion',
        'justificacion',
        'fecha_inicio_propuesta',
        'fecha_fin_propuesta',
        'sede_propuesta',
    ];

    protected $casts = [
        'id_area' => 'integer',
        'id_usuario_solicitante' => 'integer',
        'id_tipo_actividad' => 'integer',
        'id_actividad' => 'integer',
        'fecha_inicio_propuesta' => 'date:Y-m-d',
        'fecha_fin_propuesta' => 'date:Y-m-d',
        'fecha_solicitud' => 'immutable_datetime',
    ];

    public function decisions(): HasMany
    {
        return $this->hasMany(ActivityRequestDecision::class, 'id_solicitud_actividad', 'id_solicitud_actividad');
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class, 'id_actividad', 'id_actividad');
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class, 'id_area', 'id_area');
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_usuario_solicitante', 'id_usuario');
    }

    public function activityType(): BelongsTo
    {
        return $this->belongsTo(ActivityType::class, 'id_tipo_actividad', 'id_tipo_actividad');
    }
}
