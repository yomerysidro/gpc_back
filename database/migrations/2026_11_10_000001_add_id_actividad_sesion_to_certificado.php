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
     * Vincula cada certificado a la sesión a la que asistió el participante:
     * un certificado por participación y sesión. Las reemisiones no crean otro
     * certificado, van en certificado_documento.
     */
    public function up(): void
    {
        Schema::table('certificado.certificado', function (Blueprint $table) {
            $table->bigInteger('id_actividad_sesion')->nullable();
        });

        // La sesión debe ser una en la que esa participación tiene asistencia
        // (asistencia tiene UNIQUE (id_participacion, id_actividad_sesion)).
        DB::statement('ALTER TABLE certificado.certificado ADD CONSTRAINT fk_certificado_asistencia_sesion
            FOREIGN KEY (id_participacion, id_actividad_sesion)
            REFERENCES certificado.asistencia (id_participacion, id_actividad_sesion)
            ON UPDATE RESTRICT ON DELETE RESTRICT');

        // Con id_participacion NULL la FK compuesta (MATCH SIMPLE) no se valida.
        DB::statement('ALTER TABLE certificado.certificado ADD CONSTRAINT chk_certificado_sesion_requiere_participacion
            CHECK (id_actividad_sesion IS NULL OR id_participacion IS NOT NULL)');

        // Un solo certificado por participación y sesión. También cubre las búsquedas de la FK.
        DB::statement('CREATE UNIQUE INDEX uq_certificado_participacion_sesion
            ON certificado.certificado (id_participacion, id_actividad_sesion)
            WHERE id_actividad_sesion IS NOT NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS certificado.uq_certificado_participacion_sesion');
        DB::statement('ALTER TABLE certificado.certificado DROP CONSTRAINT IF EXISTS chk_certificado_sesion_requiere_participacion');
        DB::statement('ALTER TABLE certificado.certificado DROP CONSTRAINT IF EXISTS fk_certificado_asistencia_sesion');

        Schema::table('certificado.certificado', function (Blueprint $table) {
            $table->dropColumn('id_actividad_sesion');
        });
    }
};
