<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Nombre con el que la migración del esquema creó el UNIQUE (id_actividad, id_persona_ponente).
     * Se usa para recrearlo en down() y como respaldo en --pretend; en up() el nombre real
     * se busca por su definición.
     */
    private const OLD_UNIQUE = 'actividad_ponente_id_actividad_id_persona_ponente_unique';

    /**
     * Run the migrations.
     *
     * Actividad → Sesión → Ponentes → Reconocimiento. Cada paso comprueba si ya existe.
     */
    public function up(): void
    {
        // No se convierten ni se borran datos: si alguna fila no cumple, se detiene.
        $this->assertUpIsSafe();

        // ---------------------------------------------------------------- actividad_sesion

        // Destino de la FK compuesta: permite exigir que la sesión sea de esa actividad.
        if (! $this->constraintExists('actividad_sesion', 'uq_actividad_sesion_id_actividad')) {
            DB::statement('ALTER TABLE certificado.actividad_sesion ADD CONSTRAINT uq_actividad_sesion_id_actividad
                UNIQUE (id_actividad_sesion, id_actividad)');
        }

        // ---------------------------------------------------------------- actividad_ponente

        if (! $this->columnExists('actividad_ponente', 'id_actividad_sesion')) {
            DB::statement('ALTER TABLE certificado.actividad_ponente ADD COLUMN id_actividad_sesion BIGINT NULL');
        }

        // La sesión debe pertenecer a la misma actividad de la asignación.
        if (! $this->constraintExists('actividad_ponente', 'fk_actividad_ponente_sesion_actividad')) {
            DB::statement('ALTER TABLE certificado.actividad_ponente ADD CONSTRAINT fk_actividad_ponente_sesion_actividad
                FOREIGN KEY (id_actividad_sesion, id_actividad)
                REFERENCES certificado.actividad_sesion (id_actividad_sesion, id_actividad)
                ON UPDATE RESTRICT ON DELETE RESTRICT');
        }

        // NOT VALID a propósito: obliga a las filas nuevas o editadas y conserva las
        // históricas sin sesión. No se valida después.
        if (! $this->constraintExists('actividad_ponente', 'chk_actividad_ponente_requiere_sesion')) {
            DB::statement('ALTER TABLE certificado.actividad_ponente ADD CONSTRAINT chk_actividad_ponente_requiere_sesion
                CHECK (id_actividad_sesion IS NOT NULL) NOT VALID');
        }

        // Primero los índices nuevos, después se elimina el UNIQUE viejo.
        if (! $this->indexExists('uq_actividad_ponente_sesion')) {
            DB::statement('CREATE UNIQUE INDEX uq_actividad_ponente_sesion
                ON certificado.actividad_ponente (id_actividad_sesion, id_persona_ponente)
                WHERE id_actividad_sesion IS NOT NULL');
        }

        if (! $this->indexExists('uq_actividad_ponente_historico')) {
            DB::statement('CREATE UNIQUE INDEX uq_actividad_ponente_historico
                ON certificado.actividad_ponente (id_actividad, id_persona_ponente)
                WHERE id_actividad_sesion IS NULL');
        }

        $oldUnique = $this->oldUniqueName() ?? (DB::pretending() ? self::OLD_UNIQUE : null);

        if ($oldUnique) {
            DB::statement('ALTER TABLE certificado.actividad_ponente DROP CONSTRAINT "'.$oldUnique.'"');
        }

        // Cubre la FK: uq_actividad_ponente_sesion es parcial.
        if (! $this->indexExists('idx_actividad_ponente_sesion')) {
            DB::statement('CREATE INDEX idx_actividad_ponente_sesion
                ON certificado.actividad_ponente (id_actividad_sesion)');
        }

        // ---------------------------------------------------------------- reconocimiento

        // Un ponente externo no es trabajador.
        if (! $this->isNullable('reconocimiento', 'id_persona_trabajador')) {
            DB::statement('ALTER TABLE certificado.reconocimiento ALTER COLUMN id_persona_trabajador DROP NOT NULL');
        }

        if (! $this->columnExists('reconocimiento', 'id_actividad_ponente')) {
            DB::statement('ALTER TABLE certificado.reconocimiento ADD COLUMN id_actividad_ponente BIGINT NULL');
        }

        if (! $this->constraintExists('reconocimiento', 'fk_reconocimiento_actividad_ponente')) {
            DB::statement('ALTER TABLE certificado.reconocimiento ADD CONSTRAINT fk_reconocimiento_actividad_ponente
                FOREIGN KEY (id_actividad_ponente)
                REFERENCES certificado.actividad_ponente (id_actividad_ponente)
                ON DELETE RESTRICT');
        }

        // REGLA DE NEGOCIO 1 (puede cambiar): un reconocimiento tiene exactamente un
        // destinatario, o un trabajador o la asignación de un ponente; nunca ambos ni ninguno.
        if (! $this->constraintExists('reconocimiento', 'chk_reconocimiento_un_destinatario')) {
            DB::statement('ALTER TABLE certificado.reconocimiento ADD CONSTRAINT chk_reconocimiento_un_destinatario
                CHECK (num_nonnulls(id_persona_trabajador, id_actividad_ponente) = 1)');
        }

        // REGLA DE NEGOCIO 2 (puede cambiar): como máximo un reconocimiento por asignación,
        // es decir, por ponente y sesión. También cubre las búsquedas de la FK.
        if (! $this->indexExists('uq_reconocimiento_actividad_ponente')) {
            DB::statement('CREATE UNIQUE INDEX uq_reconocimiento_actividad_ponente
                ON certificado.reconocimiento (id_actividad_ponente)
                WHERE id_actividad_ponente IS NOT NULL');
        }
    }

    /**
     * Reverse the migrations.
     *
     * Se comprueba todo antes de cambiar nada. Nunca se borran ni se inventan datos.
     */
    public function down(): void
    {
        $this->assertDownIsSafe();

        // ---------------------------------------------------------------- reconocimiento
        DB::statement('DROP INDEX IF EXISTS certificado.uq_reconocimiento_actividad_ponente');
        DB::statement('ALTER TABLE certificado.reconocimiento DROP CONSTRAINT IF EXISTS chk_reconocimiento_un_destinatario');
        DB::statement('ALTER TABLE certificado.reconocimiento DROP CONSTRAINT IF EXISTS fk_reconocimiento_actividad_ponente');
        DB::statement('ALTER TABLE certificado.reconocimiento DROP COLUMN IF EXISTS id_actividad_ponente');
        DB::statement('ALTER TABLE certificado.reconocimiento ALTER COLUMN id_persona_trabajador SET NOT NULL');

        // ---------------------------------------------------------------- actividad_ponente
        DB::statement('DROP INDEX IF EXISTS certificado.idx_actividad_ponente_sesion');

        if (! $this->oldUniqueName()) {
            DB::statement('ALTER TABLE certificado.actividad_ponente ADD CONSTRAINT '.self::OLD_UNIQUE.'
                UNIQUE (id_actividad, id_persona_ponente)');
        }

        DB::statement('DROP INDEX IF EXISTS certificado.uq_actividad_ponente_historico');
        DB::statement('DROP INDEX IF EXISTS certificado.uq_actividad_ponente_sesion');
        DB::statement('ALTER TABLE certificado.actividad_ponente DROP CONSTRAINT IF EXISTS chk_actividad_ponente_requiere_sesion');
        DB::statement('ALTER TABLE certificado.actividad_ponente DROP CONSTRAINT IF EXISTS fk_actividad_ponente_sesion_actividad');
        DB::statement('ALTER TABLE certificado.actividad_ponente DROP COLUMN IF EXISTS id_actividad_sesion');

        // ---------------------------------------------------------------- actividad_sesion
        DB::statement('ALTER TABLE certificado.actividad_sesion DROP CONSTRAINT IF EXISTS uq_actividad_sesion_id_actividad');
    }

    /**
     * Lanza una excepción, con el detalle, si alguna fila actual violaría una regla
     * validada de up(). El CHECK NOT VALID no se comprueba: no revisa filas existentes.
     * Solo consulta columnas que ya existen (para volver a ejecutar tras un fallo parcial).
     */
    private function assertUpIsSafe(): void
    {
        $problems = [];

        if ($this->columnExists('actividad_ponente', 'id_actividad_sesion')) {
            $problems['fk_actividad_ponente_sesion_actividad'] = DB::select('SELECT ap.id_actividad_ponente, ap.id_actividad, ap.id_actividad_sesion
                FROM certificado.actividad_ponente ap
                LEFT JOIN certificado.actividad_sesion s
                       ON s.id_actividad_sesion = ap.id_actividad_sesion AND s.id_actividad = ap.id_actividad
                WHERE ap.id_actividad_sesion IS NOT NULL AND s.id_actividad_sesion IS NULL
                ORDER BY ap.id_actividad_ponente');

            $problems['uq_actividad_ponente_sesion'] = DB::select('SELECT id_actividad_sesion, id_persona_ponente, count(*) AS filas
                FROM certificado.actividad_ponente
                WHERE id_actividad_sesion IS NOT NULL
                GROUP BY id_actividad_sesion, id_persona_ponente HAVING count(*) > 1');

            $problems['uq_actividad_ponente_historico'] = DB::select('SELECT id_actividad, id_persona_ponente, count(*) AS filas
                FROM certificado.actividad_ponente
                WHERE id_actividad_sesion IS NULL
                GROUP BY id_actividad, id_persona_ponente HAVING count(*) > 1');
        }

        if ($this->columnExists('reconocimiento', 'id_actividad_ponente')) {
            $problems['fk_reconocimiento_actividad_ponente'] = DB::select('SELECT r.id_reconocimiento, r.id_actividad_ponente
                FROM certificado.reconocimiento r
                LEFT JOIN certificado.actividad_ponente ap ON ap.id_actividad_ponente = r.id_actividad_ponente
                WHERE r.id_actividad_ponente IS NOT NULL AND ap.id_actividad_ponente IS NULL
                ORDER BY r.id_reconocimiento');

            $problems['chk_reconocimiento_un_destinatario'] = DB::select('SELECT id_reconocimiento, id_persona_trabajador, id_actividad_ponente
                FROM certificado.reconocimiento
                WHERE num_nonnulls(id_persona_trabajador, id_actividad_ponente) <> 1
                ORDER BY id_reconocimiento');

            $problems['uq_reconocimiento_actividad_ponente'] = DB::select('SELECT id_actividad_ponente, count(*) AS filas
                FROM certificado.reconocimiento
                WHERE id_actividad_ponente IS NOT NULL
                GROUP BY id_actividad_ponente HAVING count(*) > 1');
        } else {
            $problems['chk_reconocimiento_un_destinatario'] = DB::select('SELECT id_reconocimiento
                FROM certificado.reconocimiento WHERE id_persona_trabajador IS NULL ORDER BY id_reconocimiento');
        }

        $problems = array_filter($problems);

        if ($problems) {
            throw new RuntimeException(
                'No se puede migrar: hay filas que violarían las reglas nuevas. '
                .'Corríjalas antes de volver a ejecutar la migración: '.json_encode($problems, JSON_UNESCAPED_UNICODE)
            );
        }
    }

    /**
     * Lanza una excepción si las reglas viejas volverían a violarse o si se perdería
     * información al quitar las columnas nuevas.
     */
    private function assertDownIsSafe(): void
    {
        $problems = [];

        // El UNIQUE viejo abarca todas las filas, con o sin sesión.
        $problems['UNIQUE (id_actividad, id_persona_ponente) volvería a violarse'] = DB::select('SELECT id_actividad, id_persona_ponente, count(*) AS filas
            FROM certificado.actividad_ponente
            GROUP BY id_actividad, id_persona_ponente HAVING count(*) > 1');

        $problems['reconocimientos sin id_persona_trabajador (volvería a ser NOT NULL)'] = DB::select('SELECT id_reconocimiento
            FROM certificado.reconocimiento WHERE id_persona_trabajador IS NULL ORDER BY id_reconocimiento');

        if ($this->columnExists('actividad_ponente', 'id_actividad_sesion')) {
            $problems['asignaciones con sesión (se perdería la sesión)'] = DB::select('SELECT id_actividad_ponente, id_actividad, id_actividad_sesion
                FROM certificado.actividad_ponente WHERE id_actividad_sesion IS NOT NULL ORDER BY id_actividad_ponente');
        }

        $problems = array_filter($problems);

        if ($problems) {
            throw new RuntimeException(
                'No se puede revertir sin perder o inventar datos. Corríjalos antes de revertir: '
                .json_encode($problems, JSON_UNESCAPED_UNICODE)
            );
        }
    }

    /**
     * Nombre real del UNIQUE exactamente sobre (id_actividad, id_persona_ponente), o null.
     */
    private function oldUniqueName(): ?string
    {
        $row = DB::selectOne("SELECT c.conname
            FROM pg_constraint c
            WHERE c.conrelid = 'certificado.actividad_ponente'::regclass
              AND c.contype = 'u'
              AND (SELECT array_agg(a.attname::text ORDER BY a.attname)
                   FROM unnest(c.conkey) k JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = k)
                  = ARRAY['id_actividad', 'id_persona_ponente']");

        return $row->conname ?? null;
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
};
