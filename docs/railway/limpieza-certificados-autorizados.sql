\set ON_ERROR_STOP on
\if :{?aprobados}
\else
  \set aprobados ''
\endif
\if :{?aplicar}
\else
  \set aplicar false
\endif

BEGIN;
SET LOCAL search_path = pg_catalog, pg_temp;
SET LOCAL TIME ZONE 'Etc/UTC';
SET LOCAL lock_timeout = '5s';
SET LOCAL statement_timeout = '60s';
SET LOCAL row_security = off;

\if :aplicar
LOCK TABLE certificado.certificado, certificado.certificado_documento,
    certificado.certificado_firma, certificado.consulta_certificado
    IN SHARE ROW EXCLUSIVE MODE;
\endif

CREATE TEMP TABLE inventario (
    certificado_id bigint PRIMARY KEY,
    documento_id bigint UNIQUE NOT NULL,
    huella text NOT NULL
) ON COMMIT DROP;

INSERT INTO inventario VALUES
    (2, 2, '894fc22e39239cdce5a0136db94c0d06'),
    (3, 4, 'db1b39564d49a42da11177d63b0f7c5e'),
    (4, 5, '19358e9677994c58d1bb6c92fcdac23d'),
    (5, 6, 'e6a42c77306aade088b28c475c5fdaa2'),
    (6, 7, '90a3e7ff4ea74f172b0febedede80966'),
    (8, 9, 'acdc12fdf2ea9375b1445cb09a5f51a5'),
    (9, 10, '03d32154e2c275b44f45c7cf25cbffa7'),
    (23, 22, '3927e91b280823e0cf4ac6e4ff7f980e'),
    (26, 25, '1793994a8882b44d7bb696ada337d514'),
    (27, 26, '0a83935872f1dbe1d52827a9fca15ee6'),
    (32, 31, '8f031220a5da4ba522fcbf6c49d44a60'),
    (33, 32, '2d57fe63d010a9ca2c576c65c3ba63e7'),
    (41, 40, '3162384861dd48b27f8e6962104baacb');

CREATE TEMP TABLE autorizados (certificado_id bigint PRIMARY KEY) ON COMMIT DROP;
INSERT INTO autorizados
SELECT id::bigint FROM unnest(string_to_array(:'aprobados', ',')) AS ids(id);

CREATE TEMP VIEW alcance AS
SELECT i.certificado_id, i.documento_id, a.certificado_id IS NOT NULL AS autorizado,
       c.estado AS estado_certificado, d.estado AS estado_documento,
       d.codigo_verificacion, d.ruta_archivo, d.hash_certificado,
       f.ids AS firmas, q.ids AS consultas,
       (c.id_certificado IS NOT NULL AND d.id_certificado_documento IS NOT NULL
        AND md5(to_jsonb(c)::text || to_jsonb(d)::text || f.filas) = i.huella
        AND (SELECT count(*) FROM certificado.certificado_documento AS otro
             WHERE otro.id_certificado = i.certificado_id) = 1
        AND cardinality(q.ids) = 0) AS coincide
FROM inventario i
LEFT JOIN autorizados a USING (certificado_id)
LEFT JOIN certificado.certificado c ON c.id_certificado = i.certificado_id
LEFT JOIN certificado.certificado_documento d ON d.id_certificado_documento = i.documento_id
    AND d.id_certificado = i.certificado_id
CROSS JOIN LATERAL (
    SELECT coalesce(array_agg(cf.id_certificado_firma ORDER BY cf.id_certificado_firma), '{}') AS ids,
           coalesce(jsonb_agg(to_jsonb(cf) ORDER BY cf.id_certificado_firma)::text, '[]') AS filas
    FROM certificado.certificado_firma cf WHERE cf.id_certificado_documento = i.documento_id
) f
CROSS JOIN LATERAL (
    SELECT coalesce(array_agg(qc.id_consulta_certificado ORDER BY qc.id_consulta_certificado), '{}') AS ids
    FROM certificado.consulta_certificado qc WHERE qc.id_certificado_documento = i.documento_id
) q;

DO $$
BEGIN
    IF current_database() <> 'railway' THEN
        RAISE EXCEPTION 'La base conectada debe llamarse railway';
    END IF;
    IF EXISTS (SELECT certificado_id FROM autorizados EXCEPT SELECT certificado_id FROM inventario) THEN
        RAISE EXCEPTION 'Hay IDs autorizados fuera del inventario revisado';
    END IF;
    IF EXISTS (SELECT FROM alcance WHERE autorizado AND coincide IS DISTINCT FROM true) THEN
        RAISE EXCEPTION 'Cambió el alcance autorizado. Repetir la inspección';
    END IF;
    IF EXISTS (
        WITH esperadas(hija, padre, definicion) AS (
            VALUES
            ('certificado.certificado_documento'::regclass, 'certificado.certificado'::regclass,
             'FOREIGN KEY (id_certificado) REFERENCES certificado.certificado(id_certificado) ON DELETE CASCADE'),
            ('certificado.certificado_firma'::regclass, 'certificado.certificado_documento'::regclass,
             'FOREIGN KEY (id_certificado_documento) REFERENCES certificado.certificado_documento(id_certificado_documento) ON DELETE CASCADE'),
            ('certificado.consulta_certificado'::regclass, 'certificado.certificado_documento'::regclass,
             'FOREIGN KEY (id_certificado_documento) REFERENCES certificado.certificado_documento(id_certificado_documento)')
        ), reales AS (
            SELECT conrelid::regclass, confrelid::regclass, pg_get_constraintdef(oid)
            FROM pg_constraint WHERE contype = 'f'
              AND confrelid IN ('certificado.certificado'::regclass, 'certificado.certificado_documento'::regclass,
                                'certificado.certificado_firma'::regclass)
        )
        (SELECT * FROM reales EXCEPT ALL SELECT * FROM esperadas)
        UNION ALL
        (SELECT * FROM esperadas EXCEPT ALL SELECT * FROM reales)
    ) THEN
        RAISE EXCEPTION 'Cambió el conjunto de claves foráneas entrantes';
    END IF;
    IF EXISTS (
        SELECT FROM pg_class
        WHERE oid IN ('certificado.certificado'::regclass, 'certificado.certificado_documento'::regclass,
                      'certificado.certificado_firma'::regclass)
          AND (relkind <> 'r' OR relhassubclass OR relispartition OR relhasrules OR relrowsecurity)
    ) OR EXISTS (
        SELECT FROM pg_trigger
        WHERE tgrelid IN ('certificado.certificado'::regclass, 'certificado.certificado_documento'::regclass,
                          'certificado.certificado_firma'::regclass)
          AND (NOT tgisinternal OR tgenabled <> 'O')
    ) THEN
        RAISE EXCEPTION 'Se detectaron reglas, políticas, herencia o triggers distintos de los esperados';
    END IF;
END $$;

-- Con la lista vacía muestra los 13 candidatos. Con una lista muestra solo
-- las filas autorizadas y los IDs exactos que quedarían en el alcance.
SELECT * FROM alcance
WHERE autorizado OR NOT EXISTS (SELECT FROM autorizados)
ORDER BY certificado_id;

SELECT count(*) AS certificados, count(*) AS documentos,
       coalesce(sum(cardinality(firmas)), 0) AS asignaciones_firma
FROM alcance WHERE autorizado;

\if :aplicar
CREATE TEMP TABLE objetivo ON COMMIT DROP AS
SELECT certificado_id, documento_id, firmas FROM alcance WHERE autorizado;

DO $$
DECLARE
    esperados bigint;
    eliminados bigint;
BEGIN
    SELECT count(*) INTO esperados FROM objetivo;
    IF esperados = 0 THEN
        RAISE EXCEPTION 'Se requiere una lista explícita de IDs aprobados';
    END IF;

    DELETE FROM certificado.certificado_firma f USING objetivo o
    WHERE f.id_certificado_documento = o.documento_id AND f.id_certificado_firma = ANY(o.firmas);
    GET DIAGNOSTICS eliminados = ROW_COUNT;
    IF eliminados <> (SELECT sum(cardinality(firmas)) FROM objetivo) THEN
        RAISE EXCEPTION 'Número inesperado de asignaciones eliminadas';
    END IF;

    DELETE FROM certificado.certificado_documento d USING objetivo o
    WHERE d.id_certificado_documento = o.documento_id AND d.id_certificado = o.certificado_id;
    GET DIAGNOSTICS eliminados = ROW_COUNT;
    IF eliminados <> esperados THEN
        RAISE EXCEPTION 'Número inesperado de documentos eliminados';
    END IF;

    DELETE FROM certificado.certificado c USING objetivo o
    WHERE c.id_certificado = o.certificado_id;
    GET DIAGNOSTICS eliminados = ROW_COUNT;
    IF eliminados <> esperados THEN
        RAISE EXCEPTION 'Número inesperado de certificados eliminados';
    END IF;
END $$;

SET CONSTRAINTS ALL IMMEDIATE;
COMMIT;
\else
ROLLBACK;
\endif
