<?php

namespace App\Models\ParticipationType;

use App\Models\Participation\Participation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ParticipationType extends Model
{
    protected $table = 'certificado.tipo_participacion';

    protected $primaryKey = 'id_tipo_participacion';

    public $timestamps = false;

    protected $fillable = [
        'nombre',
        'estado',
    ];

    protected $casts = [
        'estado' => 'boolean',
    ];

    public function participations(): HasMany
    {
        return $this->hasMany(Participation::class, 'id_tipo_participacion', 'id_tipo_participacion');
    }
}
