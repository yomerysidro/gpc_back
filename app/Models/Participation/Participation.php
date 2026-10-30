<?php

namespace App\Models\Participation;

use App\Models\Activity\Activity;
use App\Models\Attendance\Attendance;
use App\Models\ParticipationType\ParticipationType;
use App\Models\Person\Persona;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Participation extends Model
{
    protected $table = 'certificado.participacion';

    protected $primaryKey = 'id_participacion';

    public $timestamps = false;

    protected $casts = [
        'id_persona' => 'integer',
        'id_actividad' => 'integer',
        'id_tipo_participacion' => 'integer',
    ];

    protected $fillable = [
        'id_persona',
        'id_actividad',
        'id_tipo_participacion',
        'fecha_inscripcion',
        'estado',
    ];

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class, 'id_actividad', 'id_actividad');
    }

    public function participationType(): BelongsTo
    {
        return $this->belongsTo(ParticipationType::class, 'id_tipo_participacion', 'id_tipo_participacion');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class, 'id_participacion', 'id_participacion');
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'id_persona', 'id_persona');
    }
}
