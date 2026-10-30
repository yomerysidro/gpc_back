<?php

namespace App\Models\Person;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class Person extends Model
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
        'fecha_registro' => 'date',
    ];

    /**
     * "nombres apellido_paterno apellido_materno", sin el materno si es null.
     */
    protected function fullName(): Attribute
    {
        return Attribute::get(fn () => trim(implode(' ', array_filter([
            $this->nombres,
            $this->apellido_paterno,
            $this->apellido_materno,
        ]))));
    }
}
