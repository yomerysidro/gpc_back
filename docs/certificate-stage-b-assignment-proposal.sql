-- PROPUESTA: no ejecutar sin autorización explícita.
-- Solo habilita asignaciones pendientes; no habilita firma ni emisión.
-- Revisar duplicados de orden antes de aplicar. Cualquier conflicto aborta todo.
-- Requiere respaldo restaurable y autorización del propietario. No es idempotente.
BEGIN;
SET LOCAL lock_timeout = '5s';
ALTER TABLE certificado.certificado_firma
    ALTER COLUMN fecha_firma DROP NOT NULL,
    ALTER COLUMN id_firma DROP NOT NULL,
    ADD COLUMN id_persona_trabajador bigint
        REFERENCES gestion.persona_trabajador(id_persona_trabajador),
    ADD CONSTRAINT certificado_firma_responsable_check
        CHECK ((id_firma IS NOT NULL AND id_persona_trabajador IS NULL)
            OR (id_firma IS NULL AND id_persona_trabajador IS NOT NULL AND fecha_firma IS NULL)),
    ADD CONSTRAINT certificado_firma_documento_trabajador_unique
        UNIQUE (id_certificado_documento, id_persona_trabajador);
-- Las filas anteriores mantienen su FK a firma. Las asignaciones nuevas por trabajador
-- no crean filas en firma ni alteran las restricciones de PFX de esa tabla.
-- El CHECK impide convertir una asignación administrativa en firma fechada.
ALTER TABLE certificado.certificado_documento
    ADD COLUMN modalidad_firma varchar(10),
    ADD CONSTRAINT certificado_documento_modalidad_firma_check
        CHECK (modalidad_firma IN ('PARALELA', 'SECUENCIAL'));
ALTER TABLE certificado.certificado_firma
    ADD CONSTRAINT certificado_firma_documento_orden_unique
        UNIQUE (id_certificado_documento, orden_firma);
COMMIT;
