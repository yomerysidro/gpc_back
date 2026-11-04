<?php

namespace App\Models;

use App\Models\User\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityRequestDecision extends Model
{
    protected $table = 'certificado.solicitud_decision';

    protected $primaryKey = 'id_solicitud_decision';

    public $timestamps = false;

    protected $fillable = [
        'id_solicitud_actividad',
        'id_usuario_responsable',
        'tipo_decision',
        'estado_anterior',
        'estado_nuevo',
        'observacion',
        'fecha_decision',
    ];

    protected $casts = [
        'id_solicitud_actividad' => 'integer',
        'id_usuario_responsable' => 'integer',
        'fecha_decision' => 'immutable_datetime',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(ActivityRequest::class, 'id_solicitud_actividad', 'id_solicitud_actividad');
    }

    public function responsibleUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_usuario_responsable', 'id_usuario');
    }
}
