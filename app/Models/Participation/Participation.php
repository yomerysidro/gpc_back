<?php

namespace App\Models\Participation;

use App\Models\Activity\Activity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Participation extends Model
{
    protected $table = 'certificado.participacion';

    protected $primaryKey = 'id_participacion';

    public $timestamps = false;

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
}
