<?php

namespace App\Models\Recognition;

use App\Models\Certificate\Certificate;
use App\Models\Person_Worker\Person_Worker;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Recognition extends Model
{
    protected $table = 'certificado.reconocimiento';

    protected $primaryKey = 'id_reconocimiento';

    public $timestamps = false;

    protected $fillable = [
        0 => 'id_persona_trabajador',
        1 => 'titulo',
        2 => 'descripcion',
        3 => 'motivo',
        4 => 'fecha_reconocimiento',
        5 => 'estado',
    ];

    protected $hidden = [
    ];

    protected $casts = [
        'id_persona_trabajador' => 'integer',
        'fecha_reconocimiento' => 'date:Y-m-d',
    ];

    public function certificados(): HasMany
    {
        return $this->hasMany(Certificate::class, 'id_reconocimiento', 'id_reconocimiento');
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Person_Worker::class, 'id_persona_trabajador', 'id_persona_trabajador');
    }
}
