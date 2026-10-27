<?php

namespace App\Models\Practicing_Individual;

use Illuminate\Database\Eloquent\Model;

class Practicing_Individual extends Model
{
    protected $table = 'gestion.persona_practicante';

    protected $primaryKey = 'id_persona_practicante';

    public $timestamps = false;

    protected $fillable = [
        'id_persona',
        'codigo',
        'estado',
    ];
}
