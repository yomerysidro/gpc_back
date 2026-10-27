<?php

namespace App\Models\DocumentType;

use App\Models\PersonDocument\PersonaDocumento;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TipoDocumento extends Model
{
    protected $table = 'gestion.tipo_documento';

    protected $primaryKey = 'id_tipo_documento';

    public $timestamps = false;

    protected $fillable = [
        'nombre',
        'estado',
    ];

    protected $casts = [
        'id_tipo_documento' => 'integer',
        'nombre' => 'string',
        'estado' => 'boolean',
    ];

    public function personasDocumentos(): HasMany
    {
        return $this->hasMany(PersonaDocumento::class, 'id_tipo_documento', 'id_tipo_documento');
    }
}
