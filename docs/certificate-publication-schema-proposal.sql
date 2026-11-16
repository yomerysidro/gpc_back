-- PROPUESTA DESCARTADA DEL ALCANCE ACTUAL. NO EJECUTAR.
-- Se conserva como antecedente; el alcance vigente usa configuracion Laravel.
-- Inventario de certificados_morales: no existe persistencia de publicacion.
-- Una fila por ciclo de publicacion; las retiradas se conservan como historial.
-- No inserta autorizaciones ni modifica certificados, documentos, PDFs o hashes.
-- Aplicar mediante una migracion Laravel solamente despues de aprobar la propuesta.

BEGIN;
SET LOCAL lock_timeout = '3s';

CREATE TABLE certificado.certificado_publicacion (
    id_certificado_publicacion BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    id_certificado_documento BIGINT NOT NULL
        REFERENCES certificado.certificado_documento (id_certificado_documento)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    hash_pdf VARCHAR(64) NOT NULL CHECK (hash_pdf ~ '^[a-f0-9]{64}$'),
    revision_pdf VARCHAR(64) NULL CHECK (revision_pdf IS NULL OR revision_pdf ~ '^[a-f0-9]{64}$'),
    tipo_autorizacion VARCHAR(16) NOT NULL
        CHECK (tipo_autorizacion IN ('INSTITUCIONAL', 'DEMOSTRACION')),
    referencia_autorizacion TEXT NOT NULL
        CHECK (length(btrim(referencia_autorizacion)) BETWEEN 1 AND 1000),
    estado VARCHAR(10) NOT NULL CHECK (estado IN ('PUBLICADO', 'RETIRADO')),
    fecha_publicacion TIMESTAMP WITH TIME ZONE NOT NULL,
    id_usuario_publicacion BIGINT NOT NULL
        REFERENCES acceso.usuario (id_usuario) ON UPDATE RESTRICT ON DELETE RESTRICT,
    fecha_retiro TIMESTAMP WITH TIME ZONE NULL,
    id_usuario_retiro BIGINT NULL
        REFERENCES acceso.usuario (id_usuario) ON UPDATE RESTRICT ON DELETE RESTRICT,
    motivo_retiro TEXT NULL,
    CONSTRAINT chk_publicacion_retiro_coherente CHECK (
        (estado = 'PUBLICADO' AND fecha_retiro IS NULL
            AND id_usuario_retiro IS NULL AND motivo_retiro IS NULL)
        OR
        (estado = 'RETIRADO' AND fecha_retiro IS NOT NULL
            AND id_usuario_retiro IS NOT NULL AND motivo_retiro IS NOT NULL
            AND length(btrim(motivo_retiro)) BETWEEN 1 AND 1000
            AND fecha_retiro >= fecha_publicacion)
    )
);

CREATE UNIQUE INDEX uq_certificado_publicacion_activa
    ON certificado.certificado_publicacion (id_certificado_documento)
    WHERE estado = 'PUBLICADO';

CREATE INDEX idx_certificado_publicacion_historial
    ON certificado.certificado_publicacion (id_certificado_documento, id_certificado_publicacion DESC);

COMMIT;

-- Permisos: proponer una clave emision.publicar en el catalogo existente de
-- acceso.permiso, sin concederla automaticamente. Su asignacion institucional a
-- RR. HH. se hara mediante acceso.rol_modulo_permiso, reutilizando el sistema.
-- No se incluyen INSERTs ni IDs fijos: la asignacion requiere aprobacion expresa.
