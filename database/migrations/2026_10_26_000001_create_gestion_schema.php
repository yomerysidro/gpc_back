<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS gestion');

        Schema::create('gestion.area', function (Blueprint $table) {
            $table->id('id_area');
            $table->string('nombre', 100);
        });

        Schema::create('gestion.institucion', function (Blueprint $table) {
            $table->id('id_institucion');
            $table->string('nombre_institucion', 150);
            $table->string('pais', 50)->nullable();
            $table->string('tipo', 50);
        });

        Schema::create('gestion.tipo_documento', function (Blueprint $table) {
            $table->id('id_tipo_documento');
            $table->string('nombre', 150);
            $table->boolean('estado')->default(true);
        });

        Schema::create('gestion.persona', function (Blueprint $table) {
            $table->id('id_persona');
            $table->string('nombres', 150);
            $table->string('apellido_paterno', 150);
            $table->string('apellido_materno', 150)->nullable();
            $table->string('telefono', 20)->nullable();
            $table->string('direccion', 200)->nullable();
            $table->string('correo', 255)->nullable();
            $table->date('fecha_registro');
        });

        Schema::create('gestion.persona_documento', function (Blueprint $table) {
            $table->id('id_persona_documento');
            $table->foreignId('id_persona')->constrained('gestion.persona', 'id_persona');
            $table->foreignId('id_tipo_documento')->constrained('gestion.tipo_documento', 'id_tipo_documento');
            $table->string('numero_documento', 100);
            $table->string('estado', 100);
        });

        Schema::create('gestion.persona_trabajador', function (Blueprint $table) {
            $table->id('id_persona_trabajador');
            $table->foreignId('id_persona')->constrained('gestion.persona', 'id_persona');
            $table->foreignId('id_area')->constrained('gestion.area', 'id_area');
            $table->string('cargo', 150);
            $table->date('fecha_ingreso');
            $table->date('fecha_fin')->nullable();
            $table->boolean('estado');
        });

        Schema::create('gestion.persona_practicante', function (Blueprint $table) {
            $table->id('id_persona_practicante');
            $table->foreignId('id_persona')->constrained('gestion.persona', 'id_persona');
            $table->string('codigo', 50);
            $table->string('estado', 30);
        });

        Schema::create('gestion.persona_ponente', function (Blueprint $table) {
            $table->id('id_persona_ponente');
            $table->foreignId('id_persona')->constrained('gestion.persona', 'id_persona');
            $table->foreignId('id_institucion')->nullable()->constrained('gestion.institucion', 'id_institucion');
            $table->string('cargo', 150)->nullable();
            $table->string('especialidad', 100)->nullable();
        });

        Schema::create('gestion.practica', function (Blueprint $table) {
            $table->id('id_practica');
            $table->foreignId('id_persona_practicante')->constrained('gestion.persona_practicante', 'id_persona_practicante');
            $table->foreignId('id_institucion')->constrained('gestion.institucion', 'id_institucion');
            $table->foreignId('id_area')->constrained('gestion.area', 'id_area');
            $table->string('carrera', 55);
            $table->date('fecha_inicio');
            $table->date('fecha_fin');
            $table->string('estado', 100);
        });

        Schema::create('gestion.practica_supervisor', function (Blueprint $table) {
            $table->id('id_practica_supervisor');
            $table->foreignId('id_practica')->constrained('gestion.practica', 'id_practica');
            $table->foreignId('id_persona_trabajador')->constrained('gestion.persona_trabajador', 'id_persona_trabajador');
            $table->string('tipo_supervision', 150);
            $table->date('fecha_inicio');
            $table->date('fecha_fin')->nullable();
            $table->string('estado', 100);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP SCHEMA IF EXISTS gestion CASCADE');
    }
};
