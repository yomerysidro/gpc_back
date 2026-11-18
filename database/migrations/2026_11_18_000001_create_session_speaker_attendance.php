<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificado.asistencia_ponente_sesion', function (Blueprint $table): void {
            $table->foreignId('id_actividad_sesion')->constrained('certificado.actividad_sesion', 'id_actividad_sesion');
            $table->foreignId('id_persona')->constrained('gestion.persona', 'id_persona');
            $table->enum('estado', ['PRESENTE', 'AUSENTE']);
            $table->foreignId('id_usuario_actualizacion')->constrained('acceso.usuario', 'id_usuario');
            $table->timestampTz('actualizado_en');
            $table->primary(['id_actividad_sesion', 'id_persona']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificado.asistencia_ponente_sesion');
    }
};
