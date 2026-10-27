<?php

namespace App\Models\Person;

use App\Models\Certificate\Certificate;
use App\Models\Participation\Participation;
use App\Models\Person_Worker\Person_Worker;
use App\Models\PersonDocument\PersonaDocumento;
use App\Models\PersonSpeaker\PersonaPonente;
use App\Models\Practicing_Individual\Practicing_Individual;
use Illuminate\Database\Eloquent\Casts\Attribute;
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

    public function workers(): HasMany
    {
        return $this->hasMany(Person_Worker::class, 'id_persona', 'id_persona');
    }

    public function practitioners(): HasMany
    {
        return $this->hasMany(Practicing_Individual::class, 'id_persona', 'id_persona');
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class, 'id_persona', 'id_persona');
    }

    public function participations(): HasMany
    {
        return $this->hasMany(Participation::class, 'id_persona', 'id_persona');
    }

    protected function fullName(): Attribute
    {
        return Attribute::get(fn () => trim(implode(' ', array_filter([
            $this->nombres, $this->apellido_paterno, $this->apellido_materno,
        ]))));
    }
}
