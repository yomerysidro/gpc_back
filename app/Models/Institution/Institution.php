<?php

namespace App\Models\Institution;

use Illuminate\Database\Eloquent\Model;

class Institution extends Model
{
    protected $table = 'institucion';

    protected $primaryKey = 'id_institucion';

    public $timestamps = false;

    protected $fillable = [
        'nombre_institucion',
        'pais',
        'tipo',
    ];
}
