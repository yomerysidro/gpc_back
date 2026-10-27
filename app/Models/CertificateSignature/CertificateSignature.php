<?php

namespace App\Models\CertificateSignature;

use App\Models\CertificateDocument\CertificateDocument;
use App\Models\Signature\Signature;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CertificateSignature extends Model
{
    protected $table = 'certificado.certificado_firma';

    protected $primaryKey = 'id_certificado_firma';

    public $timestamps = false;

    protected $fillable = [
        0 => 'id_certificado_documento',
        1 => 'id_firma',
        2 => 'fecha_firma',
        3 => 'orden_firma',
    ];

    protected $hidden = [
    ];

    protected $casts = [
        'id_certificado_documento' => 'integer',
        'id_firma' => 'integer',
        'fecha_firma' => 'date:Y-m-d',
        'orden_firma' => 'integer',
    ];

    public function documento(): BelongsTo
    {
        return $this->belongsTo(CertificateDocument::class, 'id_certificado_documento', 'id_certificado_documento');
    }

    public function firma(): BelongsTo
    {
        return $this->belongsTo(Signature::class, 'id_firma', 'id_firma');
    }
}
