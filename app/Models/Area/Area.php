<?php

namespace App\Models\Area;

use Illuminate\Database\Eloquent\Model;

class Area extends Model
{
    protected $table = 'gestion.area';

    protected $primaryKey = 'id_area';

    public $timestamps = false;

    protected $fillable = [
        'nombre',
    ];

    protected $casts = [
        'id_area' => 'integer',
        'nombre' => 'string',
    ];
}
