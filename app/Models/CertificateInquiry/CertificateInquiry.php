<?php

namespace App\Models\CertificateInquiry;

use App\Models\CertificateDocument\CertificateDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CertificateInquiry extends Model
{
    protected $table = 'certificado.consulta_certificado';

    protected $primaryKey = 'id_consulta_certificado';

    public $timestamps = false;

    protected $fillable = [
        0 => 'id_certificado_documento',
        1 => 'dni_consulta',
        2 => 'codigo_consulta',
        3 => 'fecha_consulta',
        4 => 'resultado',
    ];

    protected $hidden = [
    ];

    protected $casts = [
        'id_certificado_documento' => 'integer',
        'fecha_consulta' => 'datetime:Y-m-d H:i:s',
    ];

    public function documento(): BelongsTo
    {
        return $this->belongsTo(CertificateDocument::class, 'id_certificado_documento', 'id_certificado_documento');
    }
}
