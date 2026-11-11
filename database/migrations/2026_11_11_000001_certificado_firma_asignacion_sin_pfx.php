<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Modalidades de firma de un documento. NULL = aún no definida.
     */
    private const MODALIDADES = ['PARALELA', 'SECUENCIAL'];

    /**
     * Run the migrations.
     *
     * Permite asignar un firmante (trabajador) a un documento antes de que tenga
     * credencial PFX: id_firma y fecha_firma quedan NULL hasta que firme.
     * fecha_firma NULL = pendiente. Cada paso comprueba si ya existe.
     */
    public function up(): void
    {
        // No se convierten ni se borran datos: si alguna fila no cumple, se detiene.
        $this->assertUpIsSafe();

        if (! $this->columnExists('certificado_firma', 'id_persona_trabajador')) {
            DB::statement('ALTER TABLE certificado.certificado_firma ADD COLUMN id_persona_trabajador BIGINT NULL');
        }

        if (! $this->isNullable('certificado_firma', 'id_firma')) {
            DB::statement('ALTER TABLE certificado.certificado_firma ALTER COLUMN id_firma DROP NOT NULL');
        }

        if (! $this->isNullable('certificado_firma', 'fecha_firma')) {
            DB::statement('ALTER TABLE certificado.certificado_firma ALTER COLUMN fecha_firma DROP NOT NULL');
        }

        if (! $this->constraintExists('certificado_firma', 'certificado_firma_trabajador_fk')) {
            DB::statement('ALTER TABLE certificado.certificado_firma ADD CONSTRAINT certificado_firma_trabajador_fk
                FOREIGN KEY (id_persona_trabajador)
                REFERENCES gestion.persona_trabajador (id_persona_trabajador)
                ON DELETE RESTRICT');
        }

        // Toda asignación identifica al firmante: por su credencial o por el trabajador.
        if (! $this->constraintExists('certificado_firma', 'chk_certificado_firma_firmante_presente')) {
            DB::statement('ALTER TABLE certificado.certificado_firma ADD CONSTRAINT chk_certificado_firma_firmante_presente
                CHECK (id_firma IS NOT NULL OR id_persona_trabajador IS NOT NULL)');
        }

        // Solo hay fecha de firma si se firmó con una credencial.
        if (! $this->constraintExists('certificado_firma', 'chk_certificado_firma_fecha_requiere_firma')) {
            DB::statement('ALTER TABLE certificado.certificado_firma ADD CONSTRAINT chk_certificado_firma_fecha_requiere_firma
                CHECK (fecha_firma IS NULL OR id_firma IS NOT NULL)');
        }

        if (! $this->columnExists('certificado_documento', 'modalidad_firma')) {
            DB::statement('ALTER TABLE certificado.certificado_documento ADD COLUMN modalidad_firma VARCHAR(20) NULL');
        }

        if (! $this->constraintExists('certificado_documento', 'chk_documento_modalidad_firma')) {
            DB::statement('ALTER TABLE certificado.certificado_documento ADD CONSTRAINT chk_documento_modalidad_firma
                CHECK (modalidad_firma IN ('.self::list(self::MODALIDADES).'))');
        }

        // Un trabajador no se asigna dos veces al mismo documento.
        if (! $this->indexExists('uq_certificado_firma_trabajador')) {
            DB::statement('CREATE UNIQUE INDEX uq_certificado_firma_trabajador
                ON certificado.certificado_firma (id_certificado_documento, id_persona_trabajador)
                WHERE id_persona_trabajador IS NOT NULL');
        }

        // Cubre la FK: el índice único empieza por id_certificado_documento.
        if (! $this->indexExists('idx_certificado_firma_trabajador')) {
            DB::statement('CREATE INDEX idx_certificado_firma_trabajador
                ON certificado.certificado_firma (id_persona_trabajador)');
        }
    }

    /**
     * Reverse the migrations.
     *
     * Se comprueba todo antes de cambiar nada. Nunca se inventan valores ni se
     * pierde información: si la hay, se detiene.
     */
    public function down(): void
    {
        $problems = [];

        $pending = DB::select('SELECT id_certificado_firma, id_certificado_documento, id_firma, fecha_firma
            FROM certificado.certificado_firma
            WHERE id_firma IS NULL OR fecha_firma IS NULL
            ORDER BY id_certificado_firma');

        if ($pending) {
            $problems['id_firma o fecha_firma NULL (volverían a ser NOT NULL)'] = $pending;
        }

        // Si el trabajador asignado no es el dueño de la firma, se perdería ese dato.
        if ($this->columnExists('certificado_firma', 'id_persona_trabajador')) {
            $mismatch = DB::select('SELECT cf.id_certificado_firma, cf.id_persona_trabajador, f.id_persona_trabajador AS trabajador_de_la_firma
                FROM certificado.certificado_firma cf
                JOIN certificado.firma f ON f.id_firma = cf.id_firma
                WHERE cf.id_persona_trabajador IS NOT NULL
                  AND cf.id_persona_trabajador <> f.id_persona_trabajador
                ORDER BY cf.id_certificado_firma');

            if ($mismatch) {
                $problems['id_persona_trabajador distinto del trabajador de la firma (se perdería)'] = $mismatch;
            }
        }

        if ($this->columnExists('certificado_documento', 'modalidad_firma')) {
            $withMode = DB::select('SELECT id_certificado_documento, modalidad_firma
                FROM certificado.certificado_documento
                WHERE modalidad_firma IS NOT NULL
                ORDER BY id_certificado_documento');

            if ($withMode) {
                $problems['documentos con modalidad_firma (se perdería)'] = $withMode;
            }
        }

        if ($problems) {
            throw new RuntimeException(
                'No se puede revertir sin perder o inventar datos. Corríjalos antes de revertir: '
                .json_encode($problems, JSON_UNESCAPED_UNICODE)
            );
        }

        DB::statement('DROP INDEX IF EXISTS certificado.idx_certificado_firma_trabajador');
        DB::statement('DROP INDEX IF EXISTS certificado.uq_certificado_firma_trabajador');

        DB::statement('ALTER TABLE certificado.certificado_documento DROP CONSTRAINT IF EXISTS chk_documento_modalidad_firma');
        DB::statement('ALTER TABLE certificado.certificado_firma DROP CONSTRAINT IF EXISTS chk_certificado_firma_fecha_requiere_firma');
        DB::statement('ALTER TABLE certificado.certificado_firma DROP CONSTRAINT IF EXISTS chk_certificado_firma_firmante_presente');
        DB::statement('ALTER TABLE certificado.certificado_firma DROP CONSTRAINT IF EXISTS certificado_firma_trabajador_fk');

        DB::statement('ALTER TABLE certificado.certificado_documento DROP COLUMN IF EXISTS modalidad_firma');
        DB::statement('ALTER TABLE certificado.certificado_firma DROP COLUMN IF EXISTS id_persona_trabajador');

        DB::statement('ALTER TABLE certificado.certificado_firma ALTER COLUMN fecha_firma SET NOT NULL');
        DB::statement('ALTER TABLE certificado.certificado_firma ALTER COLUMN id_firma SET NOT NULL');
    }

    /**
     * Lanza una excepción, con el detalle, si alguna fila actual violaría lo que
     * up() va a crear. Solo consulta las columnas que ya existen.
     */
    private function assertUpIsSafe(): void
    {
        $problems = [];
        $hasWorker = $this->columnExists('certificado_firma', 'id_persona_trabajador');

        $withoutSigner = DB::select('SELECT id_certificado_firma, id_certificado_documento
            FROM certificado.certificado_firma
            WHERE id_firma IS NULL'.($hasWorker ? ' AND id_persona_trabajador IS NULL' : '').'
            ORDER BY id_certificado_firma');

        if ($withoutSigner) {
            $problems['chk_certificado_firma_firmante_presente'] = $withoutSigner;
        }

        $dateWithoutSignature = DB::select('SELECT id_certificado_firma, fecha_firma
            FROM certificado.certificado_firma
            WHERE fecha_firma IS NOT NULL AND id_firma IS NULL
            ORDER BY id_certificado_firma');

        if ($dateWithoutSignature) {
            $problems['chk_certificado_firma_fecha_requiere_firma'] = $dateWithoutSignature;
        }

        if ($hasWorker) {
            $unknownWorker = DB::select('SELECT cf.id_certificado_firma, cf.id_persona_trabajador
                FROM certificado.certificado_firma cf
                LEFT JOIN gestion.persona_trabajador pt ON pt.id_persona_trabajador = cf.id_persona_trabajador
                WHERE cf.id_persona_trabajador IS NOT NULL AND pt.id_persona_trabajador IS NULL
                ORDER BY cf.id_certificado_firma');

            if ($unknownWorker) {
                $problems['certificado_firma_trabajador_fk'] = $unknownWorker;
            }

            $duplicates = DB::select('SELECT id_certificado_documento, id_persona_trabajador, count(*) AS filas
                FROM certificado.certificado_firma
                WHERE id_persona_trabajador IS NOT NULL
                GROUP BY id_certificado_documento, id_persona_trabajador
                HAVING count(*) > 1');

            if ($duplicates) {
                $problems['uq_certificado_firma_trabajador'] = $duplicates;
            }
        }

        if ($this->columnExists('certificado_documento', 'modalidad_firma')) {
            $invalidMode = DB::select('SELECT id_certificado_documento, modalidad_firma
                FROM certificado.certificado_documento
                WHERE modalidad_firma NOT IN ('.self::list(self::MODALIDADES).')
                ORDER BY id_certificado_documento');

            if ($invalidMode) {
                $problems['chk_documento_modalidad_firma'] = $invalidMode;
            }
        }

        if ($problems) {
            throw new RuntimeException(
                'No se puede migrar: hay filas que violarían las restricciones nuevas. '
                .'Corríjalas antes de volver a ejecutar la migración: '.json_encode($problems, JSON_UNESCAPED_UNICODE)
            );
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        return (bool) DB::select(
            "SELECT 1 FROM information_schema.columns
             WHERE table_schema = 'certificado' AND table_name = ? AND column_name = ?",
            [$table, $column]
        );
    }

    private function isNullable(string $table, string $column): bool
    {
        return (bool) DB::select(
            "SELECT 1 FROM information_schema.columns
             WHERE table_schema = 'certificado' AND table_name = ? AND column_name = ? AND is_nullable = 'YES'",
            [$table, $column]
        );
    }

    private function constraintExists(string $table, string $name): bool
    {
        return (bool) DB::select(
            'SELECT 1 FROM pg_constraint WHERE conrelid = ?::regclass AND conname = ?',
            ['certificado.'.$table, $name]
        );
    }

    private function indexExists(string $name): bool
    {
        return (bool) DB::select(
            "SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE n.nspname = 'certificado' AND c.relkind = 'i' AND c.relname = ?",
            [$name]
        );
    }

    /**
     * "'a', 'b'" para un IN (...). Los valores son constantes de esta clase.
     */
    private static function list(array $values): string
    {
        return implode(', ', array_map(fn (string $value) => "'$value'", $values));
    }
};
