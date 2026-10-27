<?php

namespace App\Models\CertificateDocument;

use App\Models\Certificate\Certificate;
use App\Models\CertificateInquiry\CertificateInquiry;
use App\Models\CertificateSignature\CertificateSignature;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CertificateDocument extends Model
{
    protected $table = 'certificado_documento';

    protected $primaryKey = 'id_certificado_documento';

    public $timestamps = false;

    protected $fillable = [
        0 => 'id_certificado',
        1 => 'codigo_verificacion',
        2 => 'ruta_archivo',
        3 => 'motivo_reemision',
        4 => 'estado',
        5 => 'fecha_generacion',
        6 => 'hash_certificado',
    ];

    protected $hidden = [
        0 => 'ruta_archivo',
    ];

    protected $casts = [
        'id_certificado' => 'integer',
        'fecha_generacion' => 'date:Y-m-d',
    ];

    public function certificado(): BelongsTo
    {
        return $this->belongsTo(Certificate::class, 'id_certificado', 'id_certificado');
    }

    public function firmas(): HasMany
    {
        return $this->hasMany(CertificateSignature::class, 'id_certificado_documento', 'id_certificado_documento');
    }

    public function consultas(): HasMany
    {
        return $this->hasMany(CertificateInquiry::class, 'id_certificado_documento', 'id_certificado_documento');
    }
}
