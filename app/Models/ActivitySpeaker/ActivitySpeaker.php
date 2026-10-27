<?php

namespace App\Models\ActivitySpeaker;

use App\Models\Activity\Activity;
use App\Models\PersonSpeaker\PersonaPonente;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivitySpeaker extends Model
{
    protected $table = 'certificado.actividad_ponente';

    protected $primaryKey = 'id_actividad_ponente';

    public $timestamps = false;

    protected $casts = [
        'id_actividad' => 'integer',
        'id_persona_ponente' => 'integer',
    ];

    protected $fillable = [
        'id_actividad',
        'id_persona_ponente',
    ];

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class, 'id_actividad', 'id_actividad');
    }

    public function personaPonente(): BelongsTo
    {
        return $this->belongsTo(PersonaPonente::class, 'id_persona_ponente', 'id_persona_ponente');
    }
}
