-- ============================================================
-- Verificación de la migración 2026_11_11_000001_certificado_firma_asignacion_sin_pfx
-- ============================================================
-- Correr en Neon DESPUÉS de aplicar la migración.
-- Todas las consultas son de solo lectura: no modifican esquema ni datos.
-- Al inicio de cada bloque se indica el resultado esperado.

BEGIN READ ONLY;


-- 1. Columnas nuevas o modificadas
--    Esperado: 4 filas, todas con is_nullable = 'YES':
--      certificado_documento.modalidad_firma        character varying(20)
--      certificado_firma.fecha_firma                timestamp without time zone
--      certificado_firma.id_firma                   bigint
--      certificado_firma.id_persona_trabajador      bigint
SELECT table_name, column_name, data_type, character_maximum_length, is_nullable
FROM information_schema.columns
WHERE table_schema = 'certificado'
  AND (table_name, column_name) IN (
        ('certificado_firma', 'id_persona_trabajador'),
        ('certificado_firma', 'id_firma'),
        ('certificado_firma', 'fecha_firma'),
        ('certificado_documento', 'modalidad_firma'))
ORDER BY table_name, column_name;


-- 2. FK y CHECK nuevos
--    Esperado: 4 filas, todas con convalidated = true:
--      certificado_firma_trabajador_fk (f):
--        FOREIGN KEY (id_persona_trabajador) REFERENCES gestion.persona_trabajador(id_persona_trabajador) ON DELETE RESTRICT
--      chk_certificado_firma_fecha_requiere_firma (c):
--        CHECK (((fecha_firma IS NULL) OR (id_firma IS NOT NULL)))
--      chk_certificado_firma_firmante_presente (c):
--        CHECK (((id_firma IS NOT NULL) OR (id_persona_trabajador IS NOT NULL)))
--      chk_documento_modalidad_firma (c):
--        CHECK (((modalidad_firma)::text = ANY ((ARRAY['PARALELA'::character varying, 'SECUENCIAL'::character varying])::text[])))
SELECT conrelid::regclass AS tabla, conname, contype, convalidated, pg_get_constraintdef(oid) AS definicion
FROM pg_constraint
WHERE conrelid IN ('certificado.certificado_firma'::regclass, 'certificado.certificado_documento'::regclass)
  AND conname IN ('certificado_firma_trabajador_fk',
                  'chk_certificado_firma_firmante_presente',
                  'chk_certificado_firma_fecha_requiere_firma',
                  'chk_documento_modalidad_firma')
ORDER BY conname;


-- 3. Los 2 índices nuevos
--    Esperado: 2 filas:
--      idx_certificado_firma_trabajador:
--        CREATE INDEX idx_certificado_firma_trabajador ON certificado.certificado_firma
--        USING btree (id_persona_trabajador)
--      uq_certificado_firma_trabajador:
--        CREATE UNIQUE INDEX uq_certificado_firma_trabajador ON certificado.certificado_firma
--        USING btree (id_certificado_documento, id_persona_trabajador) WHERE (id_persona_trabajador IS NOT NULL)
SELECT indexname, indexdef
FROM pg_indexes
WHERE schemaname = 'certificado'
  AND indexname IN ('uq_certificado_firma_trabajador', 'idx_certificado_firma_trabajador')
ORDER BY indexname;


-- 4. FK sin validar en ambas tablas
--    Esperado: certificado_documento fk_total = 1, certificado_firma fk_total = 3,
--    y fk_sin_validar = 0 en las dos.
SELECT conrelid::regclass AS tabla,
       count(*) AS fk_total,
       count(*) FILTER (WHERE NOT convalidated) AS fk_sin_validar
FROM pg_constraint
WHERE conrelid IN ('certificado.certificado_firma'::regclass, 'certificado.certificado_documento'::regclass)
  AND contype = 'f'
GROUP BY conrelid
ORDER BY tabla;


-- 5. Conteo de filas
--    Esperado: los mismos totales que antes de migrar (la migración no agrega
--    ni borra filas). Justo después de migrar, pendientes = 0, con_trabajador = 0
--    y con_modalidad = 0.
SELECT 'certificado_firma' AS tabla,
       count(*) AS total,
       count(*) FILTER (WHERE fecha_firma IS NULL) AS pendientes,
       count(*) FILTER (WHERE id_persona_trabajador IS NOT NULL) AS con_trabajador
FROM certificado.certificado_firma;

SELECT 'certificado_documento' AS tabla,
       count(*) AS total,
       count(*) FILTER (WHERE modalidad_firma IS NOT NULL) AS con_modalidad
FROM certificado.certificado_documento;


ROLLBACK;
