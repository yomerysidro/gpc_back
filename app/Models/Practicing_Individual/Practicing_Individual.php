<?php

namespace App\Models\Practicing_Individual;

use App\Models\Person\Persona;
use App\Models\Practice\Practice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Practicing_Individual extends Model
{
    protected $table = 'gestion.persona_practicante';

    protected $primaryKey = 'id_persona_practicante';

    public $timestamps = false;

    protected $fillable = [
        'id_persona',
        'codigo',
        'estado',
    ];

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'id_persona', 'id_persona');
    }

    public function practices(): HasMany
    {
        return $this->hasMany(Practice::class, 'id_persona_practicante', 'id_persona_practicante');
    }
}
