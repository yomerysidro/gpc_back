<?php

namespace App\Models\Area;

use App\Models\Activity\Activity;
use App\Models\Person_Worker\Person_Worker;
use App\Models\Practice\Practice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class, 'id_area', 'id_area');
    }

    public function workers(): HasMany
    {
        return $this->hasMany(Person_Worker::class, 'id_area', 'id_area');
    }

    public function practices(): HasMany
    {
        return $this->hasMany(Practice::class, 'id_area', 'id_area');
    }
}
