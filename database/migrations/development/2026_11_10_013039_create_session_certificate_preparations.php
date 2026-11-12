<?php

use App\Services\CertificationReadiness;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->assertAuthorized();
        DB::unprepared(<<<'SQL'
            ALTER TABLE certificado.participacion ADD CONSTRAINT participacion_preparacion_contexto_unique
              UNIQUE (id_participacion, id_actividad, id_persona);
            ALTER TABLE certificado.actividad_sesion ADD CONSTRAINT sesion_preparacion_contexto_unique
              UNIQUE (id_actividad_sesion, id_actividad);
            ALTER TABLE certificado.certificado ADD CONSTRAINT certificado_preparacion_contexto_unique
              UNIQUE (id_certificado, id_participacion, id_persona, id_tipo_certificado);
            CREATE TABLE certificado.certificacion_sesion_preparacion (
              id_preparacion BIGSERIAL PRIMARY KEY,
              id_actividad_sesion BIGINT NOT NULL,
              id_actividad BIGINT NOT NULL,
              id_participacion BIGINT NOT NULL,
              id_persona BIGINT NOT NULL,
              id_tipo_certificado BIGINT NOT NULL REFERENCES certificado.tipo_certificado(id_tipo_certificado) ON DELETE RESTRICT,
              id_usuario_preparacion BIGINT NOT NULL REFERENCES acceso.usuario(id_usuario) ON DELETE RESTRICT,
              fecha_preparacion TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
              referencia_politica VARCHAR(255) NOT NULL,
              modo VARCHAR(20) NOT NULL CHECK (modo = 'desarrollo'),
              estado VARCHAR(20) NOT NULL,
              id_certificado BIGINT NULL UNIQUE,
              CONSTRAINT preparacion_sesion_identidad_unique UNIQUE (id_actividad_sesion, id_participacion, id_tipo_certificado),
              CONSTRAINT preparacion_sesion_contexto_fk FOREIGN KEY (id_actividad_sesion, id_actividad)
                REFERENCES certificado.actividad_sesion(id_actividad_sesion, id_actividad) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT preparacion_participacion_contexto_fk FOREIGN KEY (id_participacion, id_actividad, id_persona)
                REFERENCES certificado.participacion(id_participacion, id_actividad, id_persona) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT preparacion_certificado_contexto_fk FOREIGN KEY (id_certificado, id_participacion, id_persona, id_tipo_certificado)
                REFERENCES certificado.certificado(id_certificado, id_participacion, id_persona, id_tipo_certificado) ON DELETE RESTRICT ON UPDATE RESTRICT,
              CONSTRAINT preparacion_estado_check CHECK (
                (estado = 'Preparada' AND id_certificado IS NULL)
                OR (estado = 'Vinculada' AND id_certificado IS NOT NULL)
              )
            );
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->assertAuthorized();
        if (DB::table('certificado.certificacion_sesion_preparacion')->exists()) {
            throw new RuntimeException('No se puede retirar el esquema mientras existan preparaciones persistidas.');
        }
        DB::unprepared(<<<'SQL'
            DROP TABLE certificado.certificacion_sesion_preparacion;
            ALTER TABLE certificado.certificado DROP CONSTRAINT certificado_preparacion_contexto_unique;
            ALTER TABLE certificado.actividad_sesion DROP CONSTRAINT sesion_preparacion_contexto_unique;
            ALTER TABLE certificado.participacion DROP CONSTRAINT participacion_preparacion_contexto_unique;
            SQL);
    }

    private function assertAuthorized(): void
    {
        if (config('certification.development_schema_authorized') !== true) {
            throw new RuntimeException('El cambio de esquema requiere autorización expresa antes de ejecutarse.');
        }
        app(CertificationReadiness::class)->assertDevelopmentClosureEnvironment();
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Esta propuesta debe validarse en PostgreSQL.');
        }
    }
};
