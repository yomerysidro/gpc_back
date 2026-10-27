<?php

namespace App\Models\Permission;

use App\Models\Module\Module;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Permission extends Model
{
    protected $table = 'acceso.permiso';
    protected $primaryKey = 'id_permiso';

    public $timestamps = false;

    protected $fillable = [
        'id_modulo',
        'nombre',
        'clave',
        'valor',
        'comentario',
        'estado',
    ];

    protected $casts = [
        'estado' => 'boolean',
    ];

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class, 'id_modulo');
    }
}
