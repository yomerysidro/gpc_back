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
     * Depende de gestion.persona (acceso.usuario.id_persona), por eso corre
     * después de la migración del esquema gestion.
     */
    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS acceso');

        Schema::create('acceso.rol', function (Blueprint $table) {
            $table->id('id_rol');
            $table->string('nombre', 50);
            $table->boolean('estado');
        });

        Schema::create('acceso.modulo', function (Blueprint $table) {
            $table->id('id_modulo');
            $table->string('nombre', 255);
            $table->string('url', 255);
            $table->string('imagen', 255)->nullable();
            $table->boolean('estado')->default(true);
        });

        Schema::create('acceso.permiso', function (Blueprint $table) {
            $table->id('id_permiso');
            $table->foreignId('id_modulo')->constrained('acceso.modulo', 'id_modulo');
            $table->string('nombre', 50);
            $table->string('clave', 50);
            $table->string('valor', 50)->nullable();
            $table->string('comentario', 50)->nullable();
            $table->boolean('estado');
        });

        Schema::create('acceso.rol_modulo', function (Blueprint $table) {
            $table->foreignId('id_modulo')->constrained('acceso.modulo', 'id_modulo');
            $table->foreignId('id_rol')->constrained('acceso.rol', 'id_rol');
            $table->primary(['id_modulo', 'id_rol']);
        });

        Schema::create('acceso.rol_modulo_permiso', function (Blueprint $table) {
            $table->foreignId('id_permiso')->constrained('acceso.permiso', 'id_permiso');
            $table->foreignId('id_modulo')->constrained('acceso.modulo', 'id_modulo');
            $table->foreignId('id_rol')->constrained('acceso.rol', 'id_rol');
            $table->primary(['id_permiso', 'id_modulo', 'id_rol']);
        });

        Schema::create('acceso.usuario', function (Blueprint $table) {
            $table->id('id_usuario');
            $table->foreignId('id_persona')->constrained('gestion.persona', 'id_persona');
            $table->string('correo', 150)->unique();
            $table->string('password_hash', 255);
            $table->boolean('estado')->default(true);
            $table->integer('intentos_fallidos')->default(0);
            $table->timestamp('bloqueado_hasta')->nullable();
            $table->timestamp('ultimo_acceso')->nullable();
        });

        Schema::create('acceso.usuario_rol', function (Blueprint $table) {
            $table->id('id_usuario_rol');
            $table->foreignId('id_rol')->constrained('acceso.rol', 'id_rol');
            $table->foreignId('id_usuario')->constrained('acceso.usuario', 'id_usuario');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP SCHEMA IF EXISTS acceso CASCADE');
    }
};
