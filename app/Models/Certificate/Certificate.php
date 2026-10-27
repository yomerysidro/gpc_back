<?php

namespace App\Models\Certificate;

use App\Models\CertificateDocument\CertificateDocument;
use App\Models\CertificateType\CertificateType;
use App\Models\Participation\Participation;
use App\Models\Person\Persona;
use App\Models\Practice\Practice;
use App\Models\Recognition\Recognition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Certificate extends Model
{
    protected $table = 'certificado.certificado';

    protected $primaryKey = 'id_certificado';

    public $timestamps = false;

    protected $fillable = [
        0 => 'id_persona',
        1 => 'id_tipo_certificado',
        2 => 'id_reconocimiento',
        3 => 'id_participacion',
        4 => 'id_practica',
        5 => 'fecha_emision',
        6 => 'estado',
        7 => 'notificado',
        8 => 'fecha_notificacion',
    ];

    protected $hidden = [
    ];

    protected $casts = [
        'id_persona' => 'integer',
        'id_tipo_certificado' => 'integer',
        'id_reconocimiento' => 'integer',
        'id_participacion' => 'integer',
        'id_practica' => 'integer',
        'fecha_emision' => 'date:Y-m-d',
        'estado' => 'boolean',
        'notificado' => 'boolean',
        'fecha_notificacion' => 'datetime:Y-m-d H:i:s',
    ];

    public function tipoCertificado(): BelongsTo
    {
        return $this->belongsTo(CertificateType::class, 'id_tipo_certificado', 'id_tipo_certificado');
    }

    public function reconocimiento(): BelongsTo
    {
        return $this->belongsTo(Recognition::class, 'id_reconocimiento', 'id_reconocimiento');
    }

    public function documentos(): HasMany
    {
        return $this->hasMany(CertificateDocument::class, 'id_certificado', 'id_certificado');
    }

    public function participation(): BelongsTo
    {
        return $this->belongsTo(Participation::class, 'id_participacion', 'id_participacion');
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'id_persona', 'id_persona');
    }

    public function practice(): BelongsTo
    {
        return $this->belongsTo(Practice::class, 'id_practica', 'id_practica');
    }
}
