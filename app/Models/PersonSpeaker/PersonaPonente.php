<?php

namespace App\Models\PersonSpeaker;

use App\Models\ActivitySpeaker\ActivitySpeaker;
use App\Models\Institution\Institution;
use App\Models\Person\Persona;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PersonaPonente extends Model
{
    protected $table = 'gestion.persona_ponente';

    protected $primaryKey = 'id_persona_ponente';

    public $timestamps = false;

    protected $fillable = [
        'id_persona',
        'id_institucion',
        'cargo',
        'especialidad',
    ];

    protected $casts = [
        'id_persona_ponente' => 'integer',
        'id_persona' => 'integer',
        'id_institucion' => 'integer',
        'cargo' => 'string',
        'especialidad' => 'string',
    ];

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'id_persona', 'id_persona');
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class, 'id_institucion', 'id_institucion');
    }

    public function activitySpeakers(): HasMany
    {
        return $this->hasMany(ActivitySpeaker::class, 'id_persona_ponente', 'id_persona_ponente');
    }
}
