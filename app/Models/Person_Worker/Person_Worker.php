<?php

namespace App\Models\Person_Worker;

use App\Models\Area\Area;
use App\Models\Person\Persona;
use App\Models\Recognition\Recognition;
use App\Models\Signature\Signature;
use App\Models\Supervisory_internship\Supervisory_internship;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Person_Worker extends Model
{
    protected $table = 'gestion.persona_trabajador';

    protected $primaryKey = 'id_persona_trabajador';

    public $timestamps = false;

    protected $fillable = [
        'id_persona',
        'id_area',
        'cargo',
        'fecha_ingreso',
        'fecha_fin',
        'estado',
    ];

    protected $casts = [
        'estado' => 'boolean',
    ];

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'id_persona', 'id_persona');
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class, 'id_area', 'id_area');
    }

    public function recognitions(): HasMany
    {
        return $this->hasMany(Recognition::class, 'id_persona_trabajador', 'id_persona_trabajador');
    }

    public function signatures(): HasMany
    {
        return $this->hasMany(Signature::class, 'id_persona_trabajador', 'id_persona_trabajador');
    }

    public function supervisions(): HasMany
    {
        return $this->hasMany(Supervisory_internship::class, 'id_persona_trabajador', 'id_persona_trabajador');
    }
}
