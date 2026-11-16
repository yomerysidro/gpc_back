-- ============================================================
-- Verificación de la migración 2026_11_15_000001_ponente_por_sesion_y_reconocimiento_ponente
-- ============================================================
-- Correr en Neon DESPUÉS de aplicar la migración.
-- Todas las consultas son de solo lectura: no modifican esquema ni datos.
-- Al inicio de cada bloque se indica el resultado esperado.

BEGIN READ ONLY;


-- 1. Columnas nuevas o modificadas
--    Esperado: 3 filas, bigint, todas con is_nullable = 'YES':
--      actividad_ponente.id_actividad_sesion
--      reconocimiento.id_actividad_ponente
--      reconocimiento.id_persona_trabajador
SELECT table_name, column_name, data_type, is_nullable
FROM information_schema.columns
WHERE table_schema = 'certificado'
  AND (table_name, column_name) IN (
        ('actividad_ponente', 'id_actividad_sesion'),
        ('reconocimiento', 'id_actividad_ponente'),
        ('reconocimiento', 'id_persona_trabajador'))
ORDER BY table_name, column_name;


-- 2. FK, CHECK y UNIQUE nuevos
--    Esperado: 5 filas:
--      actividad_ponente  chk_actividad_ponente_requiere_sesion  c  convalidated = FALSE (NOT VALID a propósito)
--        CHECK ((id_actividad_sesion IS NOT NULL)) NOT VALID
--      actividad_ponente  fk_actividad_ponente_sesion_actividad  f  true
--        FOREIGN KEY (id_actividad_sesion, id_actividad) REFERENCES certificado.actividad_sesion(id_actividad_sesion, id_actividad)
--        ON UPDATE RESTRICT ON DELETE RESTRICT
--      reconocimiento     chk_reconocimiento_un_destinatario     c  true
--        CHECK ((num_nonnulls(id_persona_trabajador, id_actividad_ponente) = 1))
--      reconocimiento     fk_reconocimiento_actividad_ponente    f  true
--        FOREIGN KEY (id_actividad_ponente) REFERENCES certificado.actividad_ponente(id_actividad_ponente) ON DELETE RESTRICT
--      actividad_sesion   uq_actividad_sesion_id_actividad       u  true
--        UNIQUE (id_actividad_sesion, id_actividad)
SELECT conrelid::regclass AS tabla, conname, contype, convalidated, pg_get_constraintdef(oid) AS definicion
FROM pg_constraint
WHERE conname IN ('uq_actividad_sesion_id_actividad',
                  'fk_actividad_ponente_sesion_actividad',
                  'chk_actividad_ponente_requiere_sesion',
                  'fk_reconocimiento_actividad_ponente',
                  'chk_reconocimiento_un_destinatario')
ORDER BY conname;


-- 3. Índices nuevos
--    Esperado: 4 filas:
--      idx_actividad_ponente_sesion         (id_actividad_sesion)
--      uq_actividad_ponente_historico       UNIQUE (id_actividad, id_persona_ponente) WHERE (id_actividad_sesion IS NULL)
--      uq_actividad_ponente_sesion          UNIQUE (id_actividad_sesion, id_persona_ponente) WHERE (id_actividad_sesion IS NOT NULL)
--      uq_reconocimiento_actividad_ponente  UNIQUE (id_actividad_ponente) WHERE (id_actividad_ponente IS NOT NULL)
--    (uq_actividad_sesion_id_actividad también tiene índice, pero es una restricción: ver bloque 2.)
SELECT tablename, indexname, indexdef
FROM pg_indexes
WHERE schemaname = 'certificado'
  AND indexname IN ('idx_actividad_ponente_sesion',
                    'uq_actividad_ponente_historico',
                    'uq_actividad_ponente_sesion',
                    'uq_reconocimiento_actividad_ponente')
ORDER BY indexname;


-- 4. El UNIQUE viejo (id_actividad, id_persona_ponente) ya no existe
--    Esperado: 0 filas en las dos consultas.
SELECT conname, pg_get_constraintdef(oid) AS definicion
FROM pg_constraint
WHERE conname = 'actividad_ponente_id_actividad_id_persona_ponente_unique';

--    Por definición, sin importar el nombre: ningún UNIQUE sin WHERE sobre esas dos columnas.
SELECT indexname, indexdef
FROM pg_indexes
WHERE schemaname = 'certificado'
  AND tablename = 'actividad_ponente'
  AND indexdef ILIKE 'CREATE UNIQUE INDEX%(id_actividad, id_persona_ponente)'
  AND indexdef NOT ILIKE '%WHERE%';


-- 5. Restricciones sin validar en las 3 tablas
--    Esperado: 1 sola fila, chk_actividad_ponente_requiere_sesion (NOT VALID a propósito).
--    Cualquier otra fila es un problema.
SELECT conrelid::regclass AS tabla, conname, contype
FROM pg_constraint
WHERE conrelid IN ('certificado.actividad_ponente'::regclass,
                   'certificado.actividad_sesion'::regclass,
                   'certificado.reconocimiento'::regclass)
  AND NOT convalidated
ORDER BY tabla, conname;

-- 5b. FK por tabla
--     Esperado: actividad_ponente fk_total = 3, actividad_sesion fk_total = 1,
--     reconocimiento fk_total = 2, y fk_sin_validar = 0 en las tres.
SELECT conrelid::regclass AS tabla,
       count(*) AS fk_total,
       count(*) FILTER (WHERE NOT convalidated) AS fk_sin_validar
FROM pg_constraint
WHERE conrelid IN ('certificado.actividad_ponente'::regclass,
                   'certificado.actividad_sesion'::regclass,
                   'certificado.reconocimiento'::regclass)
  AND contype = 'f'
GROUP BY conrelid
ORDER BY tabla;


-- 6. Conteo de filas
--    Esperado: los mismos totales que antes de migrar (la migración no agrega ni borra
--    filas). Justo después de migrar: actividad_ponente.con_sesion = 0 (todas son
--    históricas) y reconocimiento.de_ponente = 0.
SELECT 'actividad_ponente' AS tabla,
       count(*) AS total,
       count(*) FILTER (WHERE id_actividad_sesion IS NOT NULL) AS con_sesion,
       count(*) FILTER (WHERE id_actividad_sesion IS NULL) AS historicas_sin_sesion
FROM certificado.actividad_ponente;

SELECT 'actividad_sesion' AS tabla, count(*) AS total
FROM certificado.actividad_sesion;

SELECT 'reconocimiento' AS tabla,
       count(*) AS total,
       count(*) FILTER (WHERE id_persona_trabajador IS NOT NULL) AS de_trabajador,
       count(*) FILTER (WHERE id_actividad_ponente IS NOT NULL) AS de_ponente
FROM certificado.reconocimiento;


ROLLBACK;
