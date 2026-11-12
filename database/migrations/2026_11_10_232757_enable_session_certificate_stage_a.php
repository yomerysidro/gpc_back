<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '5s'");
        DB::statement("SET LOCAL statement_timeout = '60s'");
        DB::statement(<<<'SQL'
            ALTER TABLE certificado.certificado
                ADD COLUMN id_actividad_sesion BIGINT NULL,
                ALTER COLUMN fecha_emision DROP NOT NULL,
                ADD CONSTRAINT certificado_sesion_origen_check CHECK (
                    id_actividad_sesion IS NULL OR (
                        id_participacion IS NOT NULL
                        AND id_practica IS NULL
                        AND id_reconocimiento IS NULL
                    )
                ),
                ADD CONSTRAINT certificado_asistencia_sesion_fk
                    FOREIGN KEY (id_participacion, id_actividad_sesion)
                    REFERENCES certificado.asistencia (id_participacion, id_actividad_sesion)
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                ADD CONSTRAINT certificado_sesion_sin_emision_check CHECK (
                    fecha_emision IS NOT NULL OR (
                        id_actividad_sesion IS NOT NULL
                        AND estado IN ('Preparado', 'PendienteFirmas', 'Parcial', 'Firmado')
                    )
                )
            SQL);
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX certificado_participacion_sesion_tipo_unique
                ON certificado.certificado
                    (id_participacion, id_actividad_sesion, id_tipo_certificado)
                WHERE id_actividad_sesion IS NOT NULL
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("SET LOCAL lock_timeout = '5s'");
        DB::statement("SET LOCAL statement_timeout = '60s'");
        DB::statement('LOCK TABLE certificado.certificado IN ACCESS EXCLUSIVE MODE');
        if (DB::table('certificado.certificado')->whereNotNull('id_actividad_sesion')->orWhereNull('fecha_emision')->exists()) {
            throw new RuntimeException('No se puede revertir sin perder identidad de sesión o fechas pendientes; se requiere una corrección hacia delante.');
        }
        DB::statement(<<<'SQL'
            ALTER TABLE certificado.certificado
                DROP CONSTRAINT certificado_sesion_sin_emision_check,
                DROP CONSTRAINT certificado_asistencia_sesion_fk,
                DROP CONSTRAINT certificado_sesion_origen_check,
                ALTER COLUMN fecha_emision SET NOT NULL,
                DROP COLUMN id_actividad_sesion RESTRICT
            SQL);
    }
};
