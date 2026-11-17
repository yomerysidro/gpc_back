<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Depende de los esquemas gestion y acceso, por eso corre después de ellos.
     */
    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS certificado');

        // Catálogos

        Schema::create('certificado.tipo_certificado', function (Blueprint $table) {
            $table->id('id_tipo_certificado');
            $table->string('nombre_tipo', 64);
            $table->boolean('estado')->default(true);
        });

        Schema::create('certificado.tipo_actividad', function (Blueprint $table) {
            $table->id('id_tipo_actividad');
            $table->string('nombre', 100);
            $table->string('descripcion', 255)->nullable();
            $table->boolean('estado')->default(true);
        });

        Schema::create('certificado.tipo_participacion', function (Blueprint $table) {
            $table->id('id_tipo_participacion');
            $table->string('nombre', 50);
            $table->boolean('estado')->default(true);
        });

        // Actividades

        Schema::create('certificado.actividad', function (Blueprint $table) {
            $table->id('id_actividad');
            $table->foreignId('id_area')->constrained('gestion.area', 'id_area');
            $table->foreignId('id_tipo_actividad')->constrained('certificado.tipo_actividad', 'id_tipo_actividad');
            $table->string('nombre', 100);
            $table->string('descripcion', 255)->nullable();
            $table->date('fecha_inicio');
            $table->date('fecha_fin');
            $table->string('sede', 100)->nullable();
            $table->string('estado', 20)->default('Activa');
        });

        DB::statement('ALTER TABLE certificado.actividad ADD CONSTRAINT actividad_fechas_check CHECK (fecha_fin >= fecha_inicio)');
        DB::statement("ALTER TABLE certificado.actividad ADD CONSTRAINT actividad_estado_check CHECK (estado IN ('Activa', 'Cerrada'))");

        Schema::create('certificado.actividad_sesion', function (Blueprint $table) {
            $table->id('id_actividad_sesion');
            $table->foreignId('id_actividad')->constrained('certificado.actividad', 'id_actividad')->cascadeOnDelete();
            $table->string('nombre', 150);
            $table->date('fecha');
            $table->time('hora_inicio');
            $table->time('hora_fin');
            $table->string('lugar', 200)->nullable();
            $table->string('estado', 30);
        });

        Schema::create('certificado.actividad_ponente', function (Blueprint $table) {
            $table->id('id_actividad_ponente');
            $table->foreignId('id_actividad')->constrained('certificado.actividad', 'id_actividad')->cascadeOnDelete();
            $table->foreignId('id_persona_ponente')->constrained('gestion.persona_ponente', 'id_persona_ponente');
            $table->unique(['id_actividad', 'id_persona_ponente'], 'actividad_ponente_id_actividad_id_persona_ponente_unique');
        });

        Schema::create('certificado.participacion', function (Blueprint $table) {
            $table->id('id_participacion');
            $table->foreignId('id_persona')->constrained('gestion.persona', 'id_persona');
            $table->foreignId('id_actividad')->constrained('certificado.actividad', 'id_actividad')->cascadeOnDelete();
            $table->foreignId('id_tipo_participacion')->constrained('certificado.tipo_participacion', 'id_tipo_participacion');
            $table->date('fecha_inscripcion');
            $table->string('estado', 30);
            $table->unique(['id_persona', 'id_actividad']);
        });

        // Los UNIQUE compuestos llevan nombre explícito porque el generado pasa de 63 caracteres.

        // La fecha de la asistencia es la de actividad_sesion.
        Schema::create('certificado.asistencia', function (Blueprint $table) {
            $table->id('id_asistencia');
            $table->foreignId('id_participacion')->constrained('certificado.participacion', 'id_participacion')->cascadeOnDelete();
            $table->foreignId('id_actividad_sesion')->constrained('certificado.actividad_sesion', 'id_actividad_sesion')->cascadeOnDelete();
            $table->time('hora_entrada')->nullable();
            $table->time('hora_salida')->nullable();
            $table->string('estado', 30);
            $table->unique(['id_participacion', 'id_actividad_sesion'], 'asistencia_id_participacion_id_actividad_sesion_unique');
        });

        // Reconocimientos

        Schema::create('certificado.reconocimiento', function (Blueprint $table) {
            $table->id('id_reconocimiento');
            $table->foreignId('id_persona_trabajador')->constrained('gestion.persona_trabajador', 'id_persona_trabajador');
            $table->string('titulo', 150);
            $table->string('descripcion', 500)->nullable();
            $table->string('motivo', 500)->nullable();
            $table->date('fecha_reconocimiento');
            $table->string('estado', 30);
        });

        // Firma y certificados

        Schema::create('certificado.firma', function (Blueprint $table) {
            $table->id('id_firma');
            $table->foreignId('id_persona_trabajador')->unique()->constrained('gestion.persona_trabajador', 'id_persona_trabajador');
            $table->string('ruta_archivo_pfx', 255);
            $table->string('passphrase_cifrada', 255);
            $table->date('fecha_firma')->nullable();
        });

        Schema::create('certificado.actividad_firma', function (Blueprint $table) {
            $table->id('id_actividad_firma');
            $table->foreignId('id_actividad')->constrained('certificado.actividad', 'id_actividad')->cascadeOnDelete();
            $table->foreignId('id_firma')->constrained('certificado.firma', 'id_firma')->cascadeOnDelete();
            $table->integer('orden_firma')->default(1);
            $table->unique(['id_actividad', 'id_firma']);
        });

        DB::statement('ALTER TABLE certificado.actividad_firma ADD CONSTRAINT actividad_firma_orden_check CHECK (orden_firma > 0)');

        Schema::create('certificado.certificado', function (Blueprint $table) {
            $table->id('id_certificado');
            $table->foreignId('id_persona')->constrained('gestion.persona', 'id_persona');
            $table->foreignId('id_tipo_certificado')->constrained('certificado.tipo_certificado', 'id_tipo_certificado');
            $table->foreignId('id_reconocimiento')->nullable()->constrained('certificado.reconocimiento', 'id_reconocimiento');
            $table->foreignId('id_participacion')->nullable()->constrained('certificado.participacion', 'id_participacion');
            $table->foreignId('id_practica')->nullable()->constrained('gestion.practica', 'id_practica');
            $table->date('fecha_emision');
            $table->string('estado', 30);
            $table->boolean('notificado')->default(false);
            $table->timestamp('fecha_notificacion')->nullable();
        });

        // Un certificado nace de exactamente un origen: reconocimiento, participación o práctica.
        DB::statement('ALTER TABLE certificado.certificado ADD CONSTRAINT certificado_origen_check CHECK (
            (CASE WHEN id_reconocimiento IS NOT NULL THEN 1 ELSE 0 END)
            + (CASE WHEN id_participacion IS NOT NULL THEN 1 ELSE 0 END)
            + (CASE WHEN id_practica IS NOT NULL THEN 1 ELSE 0 END) = 1
        )');

        Schema::create('certificado.certificado_documento', function (Blueprint $table) {
            $table->id('id_certificado_documento');
            $table->foreignId('id_certificado')->constrained('certificado.certificado', 'id_certificado')->cascadeOnDelete();
            $table->string('codigo_verificacion', 30)->unique();
            $table->string('hash_certificado', 64)->unique();
            $table->string('ruta_archivo', 255);
            $table->string('motivo_reemision', 255)->nullable();
            $table->timestamp('fecha_generacion');
            $table->string('estado', 20)->default('Vigente');
        });

        DB::statement("ALTER TABLE certificado.certificado_documento ADD CONSTRAINT certificado_documento_estado_check CHECK (estado IN ('Vigente', 'Anulado'))");

        Schema::create('certificado.certificado_firma', function (Blueprint $table) {
            $table->id('id_certificado_firma');
            $table->foreignId('id_certificado_documento')->constrained('certificado.certificado_documento', 'id_certificado_documento')->cascadeOnDelete();
            $table->foreignId('id_firma')->constrained('certificado.firma', 'id_firma');
            $table->integer('orden_firma');
            $table->timestamp('fecha_firma');
            $table->unique(['id_certificado_documento', 'id_firma'], 'certificado_firma_id_certificado_documento_id_firma_unique');
        });

        DB::statement('ALTER TABLE certificado.certificado_firma ADD CONSTRAINT certificado_firma_orden_check CHECK (orden_firma > 0)');

        // id_certificado_documento es nullable: una consulta sin resultado no encuentra documento.
        Schema::create('certificado.consulta_certificado', function (Blueprint $table) {
            $table->id('id_consulta_certificado');
            $table->foreignId('id_certificado_documento')->nullable();
            $table->string('dni_consulta', 8)->nullable();
            $table->string('codigo_consulta', 50)->nullable();
            $table->timestamp('fecha_consulta')->useCurrent();
            $table->string('resultado', 50);

            // Nombre explícito: el generado por Laravel pasa de 63 caracteres y Postgres lo trunca.
            $table->foreign('id_certificado_documento', 'consulta_certificado_id_certificado_documento_foreign')
                ->references('id_certificado_documento')->on('certificado.certificado_documento');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP SCHEMA IF EXISTS certificado CASCADE');
    }
};
