<?php

namespace App\Models\Institution;

use App\Models\PersonSpeaker\PersonaPonente;
use App\Models\Practice\Practice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Institution extends Model
{
    protected $table = 'gestion.institucion';

    protected $primaryKey = 'id_institucion';

    public $timestamps = false;

    protected $fillable = [
        'nombre_institucion',
        'pais',
        'tipo',
    ];

    public function practices(): HasMany
    {
        return $this->hasMany(Practice::class, 'id_institucion', 'id_institucion');
    }

    public function speakers(): HasMany
    {
        return $this->hasMany(PersonaPonente::class, 'id_institucion', 'id_institucion');
    }
}
