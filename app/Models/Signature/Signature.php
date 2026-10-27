<?php

namespace App\Models\Signature;

use App\Models\ActivitySignature\ActivitySignature;
use App\Models\CertificateSignature\CertificateSignature;
use App\Models\Person_Worker\Person_Worker;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Signature extends Model
{
    protected $table = 'certificado.firma';

    protected $primaryKey = 'id_firma';

    public $timestamps = false;

    protected $fillable = [
        0 => 'id_persona_trabajador',
        1 => 'ruta_archivo_pfx',
        2 => 'fecha_firma',
        3 => 'passphrase_cifrada',
    ];

    protected $hidden = [
        0 => 'passphrase_cifrada',
        1 => 'ruta_archivo_pfx',
    ];

    protected $casts = [
        'id_persona_trabajador' => 'integer',
        'fecha_firma' => 'date:Y-m-d',
    ];

    public function certificadosFirmas(): HasMany
    {
        return $this->hasMany(CertificateSignature::class, 'id_firma', 'id_firma');
    }

    public function actividadesFirmas(): HasMany
    {
        return $this->hasMany(ActivitySignature::class, 'id_firma', 'id_firma');
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Person_Worker::class, 'id_persona_trabajador', 'id_persona_trabajador');
    }
}
