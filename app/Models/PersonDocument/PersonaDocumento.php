<?php

namespace App\Models\PersonDocument;

use App\Models\DocumentType\TipoDocumento;
use App\Models\Person\Persona;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonaDocumento extends Model
{
    protected $table = 'gestion.persona_documento';

    protected $primaryKey = 'id_persona_documento';

    public $timestamps = false;

    protected $fillable = [
        'id_persona',
        'id_tipo_documento',
        'numero_documento',
        'estado',
    ];

    protected $casts = [
        'id_persona_documento' => 'integer',
        'id_persona' => 'integer',
        'id_tipo_documento' => 'integer',
        'numero_documento' => 'string',
        'estado' => 'string',
    ];

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'id_persona', 'id_persona');
    }

    public function tipoDocumento(): BelongsTo
    {
        return $this->belongsTo(TipoDocumento::class, 'id_tipo_documento', 'id_tipo_documento');
    }
}
