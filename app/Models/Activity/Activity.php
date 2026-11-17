<?php

namespace App\Models\Activity;

use App\Models\ActivityRequest;
use App\Models\ActivitySession\ActivitySession;
use App\Models\ActivitySignature\ActivitySignature;
use App\Models\ActivitySpeaker\ActivitySpeaker;
use App\Models\ActivityType\ActivityType;
use App\Models\Area\Area;
use App\Models\Participation\Participation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Activity extends Model
{
    protected $table = 'certificado.actividad';

    protected $primaryKey = 'id_actividad';

    public $timestamps = false;

    protected $casts = [
        'id_area' => 'integer',
        'id_tipo_actividad' => 'integer',
        'fecha_inicio' => 'date:Y-m-d',
        'fecha_fin' => 'date:Y-m-d',
    ];

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

    public function activityType(): BelongsTo
    {
        return $this->belongsTo(ActivityType::class, 'id_tipo_actividad', 'id_tipo_actividad');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(ActivitySession::class, 'id_actividad', 'id_actividad');
    }

    public function participations(): HasMany
    {
        return $this->hasMany(Participation::class, 'id_actividad', 'id_actividad');
    }

    public function speakers(): HasMany
    {
        return $this->hasMany(ActivitySpeaker::class, 'id_actividad', 'id_actividad');
    }

    public function signatures(): HasMany
    {
        return $this->hasMany(ActivitySignature::class, 'id_actividad', 'id_actividad');
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class, 'id_area', 'id_area');
    }

    public function sourceRequest(): HasOne
    {
        return $this->hasOne(ActivityRequest::class, 'id_actividad', 'id_actividad');
    }
}
