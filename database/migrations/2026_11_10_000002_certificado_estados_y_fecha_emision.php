<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Estados de certificado.estado: PREPARADO → PENDIENTE_FIRMA → FIRMADO → EMITIDO,
     * y ANULADO desde cualquiera de ellos.
     */
    private const ESTADOS = [
        'PREPARADO',
        'PENDIENTE_FIRMA',
        'FIRMADO',
        'EMITIDO',
        'ANULADO',
    ];

    /**
     * Único estado que exige fecha_emision.
     */
    private const ESTADO_CON_FECHA = 'EMITIDO';

    /**
     * Run the migrations.
     *
     * fecha_emision pasa a ser obligatoria solo en EMITIDO, y el índice único
     * por participación y sesión incluye el tipo de certificado.
     */
    public function up(): void
    {
        // No se convierten ni se borran datos: si alguna fila no cumple, se detiene.
        $invalid = DB::select(
            'SELECT id_certificado, estado, fecha_emision FROM certificado.certificado
             WHERE estado NOT IN ('.self::list(self::ESTADOS).')
                OR (estado = ? AND fecha_emision IS NULL)
             ORDER BY id_certificado',
            [self::ESTADO_CON_FECHA]
        );

        if ($invalid) {
            throw new RuntimeException(
                'No se puede migrar: hay certificados con un estado no permitido o EMITIDO sin fecha_emision. '
                .'Corríjalos antes de volver a ejecutar la migración: '.json_encode($invalid, JSON_UNESCAPED_UNICODE)
            );
        }

        DB::statement('ALTER TABLE certificado.certificado ALTER COLUMN fecha_emision DROP NOT NULL');

        DB::statement('ALTER TABLE certificado.certificado ADD CONSTRAINT chk_certificado_estado
            CHECK (estado IN ('.self::list(self::ESTADOS).'))');

        DB::statement("ALTER TABLE certificado.certificado ADD CONSTRAINT chk_certificado_fecha_emision_por_estado
            CHECK (estado <> '".self::ESTADO_CON_FECHA."' OR fecha_emision IS NOT NULL)");

        // Un certificado por participación, sesión y tipo. También cubre las búsquedas de fk_certificado_asistencia_sesion.
        DB::statement('DROP INDEX certificado.uq_certificado_participacion_sesion');
        DB::statement('CREATE UNIQUE INDEX uq_certificado_participacion_sesion_tipo
            ON certificado.certificado (id_participacion, id_actividad_sesion, id_tipo_certificado)
            WHERE id_actividad_sesion IS NOT NULL');
    }

    /**
     * Reverse the migrations.
     *
     * Se comprueba todo antes de cambiar nada. Nunca se inventan fechas.
     */
    public function down(): void
    {
        $withoutDate = DB::select('SELECT id_certificado, estado FROM certificado.certificado WHERE fecha_emision IS NULL ORDER BY id_certificado');

        if ($withoutDate) {
            throw new RuntimeException(
                'No se puede revertir: fecha_emision volvería a ser NOT NULL y hay certificados sin fecha. '
                .'Asígneles una fecha real o elimínelos antes de revertir: '.json_encode($withoutDate, JSON_UNESCAPED_UNICODE)
            );
        }

        // El índice anterior no distingue el tipo: dos tipos para la misma participación y sesión lo violarían.
        $duplicates = DB::select(
            'SELECT id_participacion, id_actividad_sesion, count(*) AS certificados FROM certificado.certificado
             WHERE id_actividad_sesion IS NOT NULL
             GROUP BY id_participacion, id_actividad_sesion HAVING count(*) > 1'
        );

        if ($duplicates) {
            throw new RuntimeException(
                'No se puede revertir: uq_certificado_participacion_sesion no admite más de un certificado por participación y sesión: '
                .json_encode($duplicates, JSON_UNESCAPED_UNICODE)
            );
        }

        DB::statement('CREATE UNIQUE INDEX uq_certificado_participacion_sesion
            ON certificado.certificado (id_participacion, id_actividad_sesion)
            WHERE id_actividad_sesion IS NOT NULL');
        DB::statement('DROP INDEX IF EXISTS certificado.uq_certificado_participacion_sesion_tipo');

        DB::statement('ALTER TABLE certificado.certificado DROP CONSTRAINT IF EXISTS chk_certificado_fecha_emision_por_estado');
        DB::statement('ALTER TABLE certificado.certificado DROP CONSTRAINT IF EXISTS chk_certificado_estado');

        DB::statement('ALTER TABLE certificado.certificado ALTER COLUMN fecha_emision SET NOT NULL');
    }

    /**
     * "'a', 'b', 'c'" para un IN (...). Los valores son constantes de esta clase.
     */
    private static function list(array $values): string
    {
        return implode(', ', array_map(fn (string $value) => "'$value'", $values));
    }
};
