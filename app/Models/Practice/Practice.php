<?php

namespace App\Models\Practice;

use App\Models\Area\Area;
use App\Models\Certificate\Certificate;
use App\Models\Institution\Institution;
use App\Models\Practicing_Individual\Practicing_Individual;
use App\Models\Supervisory_internship\Supervisory_internship;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Practice extends Model
{
    protected $table = 'gestion.practica';

    protected $primaryKey = 'id_practica';

    public $timestamps = false;

    protected $fillable = [
        'id_persona_practicante',
        'id_institucion',
        'id_area',
        'carrera',
        'fecha_inicio',
        'fecha_fin',
        'estado',
    ];

    public function practitioner(): BelongsTo
    {
        return $this->belongsTo(Practicing_Individual::class, 'id_persona_practicante', 'id_persona_practicante');
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class, 'id_institucion', 'id_institucion');
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class, 'id_area', 'id_area');
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class, 'id_practica', 'id_practica');
    }

    public function supervisors(): HasMany
    {
        return $this->hasMany(Supervisory_internship::class, 'id_practica', 'id_practica');
    }
}
