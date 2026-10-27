<?php

namespace App\Models\ActivitySignature;

use App\Models\Signature\Signature;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivitySignature extends Model
{
    protected $table = 'actividad_firma';

    protected $primaryKey = 'id_actividad_firma';

    public $timestamps = false;

    protected $fillable = [
        0 => 'id_actividad',
        1 => 'id_firma',
        2 => 'orden_firma',
    ];

    protected $hidden = [
    ];

    protected $casts = [
        'id_actividad' => 'integer',
        'id_firma' => 'integer',
        'orden_firma' => 'integer',
    ];

    public function firma(): BelongsTo
    {
        return $this->belongsTo(Signature::class, 'id_firma', 'id_firma');
    }
}
