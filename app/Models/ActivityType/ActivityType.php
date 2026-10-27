<?php

namespace App\Models\ActivityType;

use App\Models\Activity\Activity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ActivityType extends Model
{
    protected $table = 'certificado.tipo_actividad';

    protected $primaryKey = 'id_tipo_actividad';

    public $timestamps = false;

    protected $fillable = [
        'nombre',
        'descripcion',
        'estado',
    ];

    protected $casts = [
        'estado' => 'boolean',
    ];

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class, 'id_tipo_actividad', 'id_tipo_actividad');
    }
}
