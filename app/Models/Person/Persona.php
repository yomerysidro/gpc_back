<?php

namespace App\Models\Person;

use App\Models\PersonDocument\PersonaDocumento;
use App\Models\PersonSpeaker\PersonaPonente;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Persona extends Model
{
    protected $table = 'gestion.persona';

    protected $primaryKey = 'id_persona';

    public $timestamps = false;

    protected $fillable = [
        'nombres',
        'apellido_paterno',
        'apellido_materno',
        'telefono',
        'direccion',
        'correo',
        'fecha_registro',
    ];

    protected $casts = [
        'id_persona' => 'integer',
        'nombres' => 'string',
        'apellido_paterno' => 'string',
        'apellido_materno' => 'string',
        'telefono' => 'string',
        'direccion' => 'string',
        'correo' => 'string',
        'fecha_registro' => 'date:Y-m-d',
    ];

    public function personasPonentes(): HasMany
    {
        return $this->hasMany(PersonaPonente::class, 'id_persona', 'id_persona');
    }

    public function personasDocumentos(): HasMany
    {
        return $this->hasMany(PersonaDocumento::class, 'id_persona', 'id_persona');
    }
}
