<?php

namespace App\Models\CertificateType;

use App\Models\Certificate\Certificate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CertificateType extends Model
{
    protected $table = 'tipo_certificado';

    protected $primaryKey = 'id_tipo_certificado';

    public $timestamps = false;

    protected $fillable = [
        0 => 'nombre_tipo',
        1 => 'estado',
    ];

    protected $hidden = [
    ];

    protected $casts = [
    ];

    public function certificados(): HasMany
    {
        return $this->hasMany(Certificate::class, 'id_tipo_certificado', 'id_tipo_certificado');
    }
}
