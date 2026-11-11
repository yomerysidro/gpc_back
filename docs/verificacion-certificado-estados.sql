-- ============================================================
-- Verificación de la migración 2026_11_10_000002_certificado_estados_y_fecha_emision
-- ============================================================
-- Correr en Neon DESPUÉS de aplicar la migración.
-- Todas las consultas son de solo lectura: no modifican esquema ni datos.
-- Al final de cada bloque se indica el resultado esperado.

BEGIN READ ONLY;


-- 1. fecha_emision admite NULL
--    Esperado: 1 fila, is_nullable = 'YES', data_type = 'date'.
SELECT column_name, data_type, is_nullable
FROM information_schema.columns
WHERE table_schema = 'certificado'
  AND table_name = 'certificado'
  AND column_name = 'fecha_emision';


-- 2. Los dos CHECK nuevos
--    Esperado: 2 filas, convalidated = true.
--    chk_certificado_estado:
--      CHECK (((estado)::text = ANY ((ARRAY['PREPARADO'::character varying, 'PENDIENTE_FIRMA'::character varying,
--        'FIRMADO'::character varying, 'EMITIDO'::character varying, 'ANULADO'::character varying])::text[])))
--    chk_certificado_fecha_emision_por_estado:
--      CHECK ((((estado)::text <> 'EMITIDO'::text) OR (fecha_emision IS NOT NULL)))
SELECT conname, convalidated, pg_get_constraintdef(oid) AS definicion
FROM pg_constraint
WHERE conrelid = 'certificado.certificado'::regclass
  AND conname IN ('chk_certificado_estado', 'chk_certificado_fecha_emision_por_estado')
ORDER BY conname;


-- 3. Índice único nuevo: 3 columnas y su WHERE
--    Esperado: 1 fila con
--      CREATE UNIQUE INDEX uq_certificado_participacion_sesion_tipo ON certificado.certificado
--      USING btree (id_participacion, id_actividad_sesion, id_tipo_certificado)
--      WHERE (id_actividad_sesion IS NOT NULL)
SELECT indexname, indexdef
FROM pg_indexes
WHERE schemaname = 'certificado'
  AND tablename = 'certificado'
  AND indexname = 'uq_certificado_participacion_sesion_tipo';

-- 3b. El índice anterior ya no existe
--     Esperado: 0 filas.
SELECT indexname, indexdef
FROM pg_indexes
WHERE schemaname = 'certificado'
  AND indexname = 'uq_certificado_participacion_sesion';

-- 3c. Todos los índices de la tabla, como referencia
--     Esperado: certificado_pkey y uq_certificado_participacion_sesion_tipo.
SELECT indexname, indexdef
FROM pg_indexes
WHERE schemaname = 'certificado'
  AND tablename = 'certificado'
ORDER BY indexname;


-- 4. Todas las FK de certificado.certificado, validadas
--    Esperado: 6 filas, todas con convalidated = true:
--      certificado_certificado_id_participacion_foreign
--      certificado_certificado_id_persona_foreign
--      certificado_certificado_id_practica_foreign
--      certificado_certificado_id_reconocimiento_foreign
--      certificado_certificado_id_tipo_certificado_foreign
--      fk_certificado_asistencia_sesion (compuesta, ON UPDATE/DELETE RESTRICT)
SELECT conname, convalidated, pg_get_constraintdef(oid) AS definicion
FROM pg_constraint
WHERE conrelid = 'certificado.certificado'::regclass
  AND contype = 'f'
ORDER BY conname;

-- 4b. Resumen: debe dar fk_total = 6 y fk_sin_validar = 0.
SELECT count(*) AS fk_total,
       count(*) FILTER (WHERE NOT convalidated) AS fk_sin_validar
FROM pg_constraint
WHERE conrelid = 'certificado.certificado'::regclass
  AND contype = 'f';


-- 5. Certificados por estado
--    Esperado: solo PREPARADO, PENDIENTE_FIRMA, FIRMADO, EMITIDO o ANULADO,
--    y sin_fecha = 0 en EMITIDO (los CHECK lo garantizan).
SELECT estado,
       count(*) AS total,
       count(*) FILTER (WHERE fecha_emision IS NULL) AS sin_fecha
FROM certificado.certificado
GROUP BY estado
ORDER BY estado;

-- 5b. Total general
SELECT count(*) AS total_certificados,
       count(*) FILTER (WHERE fecha_emision IS NULL) AS total_sin_fecha
FROM certificado.certificado;


ROLLBACK;
