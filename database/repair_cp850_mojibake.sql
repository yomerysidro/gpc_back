-- psql -X -f /path/to/repair_cp850_mojibake.sql -v mode=preview
-- Review both private preview CSVs and copy only approved entries (with headers)
-- to gpc-utf8-repair-approved.csv and gpc-utf8-repair-checks-approved.csv.
-- After authorization, run with -v mode=apply -v expected_database=NAME.
-- To undo, run with -v mode=revert -v expected_database=NAME using the same directory.
-- Keep both backup CSVs securely. Apply and revert lock tables with repaired CHECKs.
-- Use a separate private working directory for each batch. Never commit the CSVs.
\set ON_ERROR_STOP on
\if :{?mode}
\else
    \set mode preview
\endif
SELECT :'mode' = 'preview' AS preview, :'mode' = 'apply' AS apply,
       :'mode' IN ('preview', 'apply', 'revert') AS valid_mode \gset
\if :valid_mode
\else
    DO $$ BEGIN RAISE EXCEPTION 'Expected mode: preview, apply or revert'; END $$;
\endif

\if :preview
\else
    \if :{?expected_database}
        SELECT :'expected_database' = current_database() AS confirmed_database \gset
    \else
        \set confirmed_database false
    \endif
    \if :confirmed_database
    \else
        DO $$ BEGIN RAISE EXCEPTION 'Specify the connected database with -v expected_database=NAME'; END $$;
    \endif
\endif

BEGIN;
SET LOCAL lock_timeout = '5s';
SET LOCAL statement_timeout = '30s';
SET LOCAL search_path = pg_catalog, pg_temp;

DO $$ BEGIN
    IF current_setting('server_encoding') <> 'UTF8' OR current_setting('client_encoding') <> 'UTF8' THEN
        RAISE EXCEPTION 'Server and client encoding must both be UTF8';
    END IF;
END $$;

CREATE TEMP TABLE utf8_targets (
    table_name regclass,
    key_name name,
    column_name name,
    PRIMARY KEY (table_name, column_name)
);
INSERT INTO utf8_targets VALUES
    ('acceso.modulo', 'id_modulo', 'nombre'),
    ('acceso.permiso', 'id_permiso', 'nombre'),
    ('certificado.actividad_sesion', 'id_actividad_sesion', 'nombre'),
    ('certificado.reconocimiento', 'id_reconocimiento', 'motivo'),
    ('certificado.solicitud_actividad', 'id_solicitud_actividad', 'nombre'),
    ('certificado.solicitud_actividad', 'id_solicitud_actividad', 'estado'),
    ('certificado.solicitud_decision', 'id_solicitud_decision', 'estado_anterior'),
    ('certificado.solicitud_decision', 'id_solicitud_decision', 'estado_nuevo'),
    ('certificado.tipo_actividad', 'id_tipo_actividad', 'nombre'),
    ('certificado.tipo_certificado', 'id_tipo_certificado', 'nombre_tipo'),
    ('gestion.area', 'id_area', 'nombre'),
    ('gestion.institucion', 'id_institucion', 'pais'),
    ('gestion.persona', 'id_persona', 'apellido_paterno'),
    ('gestion.persona', 'id_persona', 'nombres'),
    ('gestion.persona_ponente', 'id_persona_ponente', 'especialidad'),
    ('gestion.persona_trabajador', 'id_persona_trabajador', 'cargo'),
    ('gestion.tipo_documento', 'id_tipo_documento', 'nombre');

-- UTF-8 representations of the CP850 characters for bytes 0x80 to 0xFF.
CREATE FUNCTION pg_temp.undo_cp850(input text) RETURNS text
LANGUAGE plpgsql IMMUTABLE STRICT AS $$
DECLARE
    alphabet constant text := convert_from(decode(
        'c387c3bcc3a9c3a2c3a4c3a0c3a5c3a7c3aac3abc3a8c3afc3aec3acc384c385c389c3a6c386c3b4c3b6c3b2c3bbc3b9c3bfc396c39cc3b8c2a3c398c397c692'
        'c3a1c3adc3b3c3bac3b1c391c2aac2bac2bfc2aec2acc2bdc2bcc2a1c2abc2bbe29691e29692e29693e29482e294a4c381c382c380c2a9e295a3e29591e29597e2959dc2a2c2a5'
        'e29490e29494e294b4e294ace2949ce29480e294bcc3a3c383e2959ae29594e295a9e295a6e295a0e29590e295acc2a4c3b0c390c38ac38bc388c4b1c38dc38ec38fe29498e2948ce29688e29684c2a6c38c'
        'e29680c393c39fc394c392c3b5c395c2b5c3bec39ec39ac39bc399c3bdc39dc2afc2b4c2adc2b1e28097c2bec2b6c2a7c3b7c2b8c2b0c2a8c2b7c2b9c2b3c2b2e296a0c2a0',
        'hex'), 'UTF8');
    octets text;
BEGIN
    IF input !~ '[\u2500-\u259f]' OR strpos(input, chr(65533)) > 0 THEN
        RETURN NULL;
    END IF;
    SELECT CASE WHEN min(octet) >= 0 THEN string_agg(lpad(to_hex(octet), 2, '0'), '' ORDER BY position) END
    INTO octets
    FROM (
        SELECT CASE WHEN ascii(letter) < 128 THEN ascii(letter)
                    WHEN strpos(alphabet, letter) > 0 THEN 127 + strpos(alphabet, letter)
                    ELSE -1 END AS octet, position
        FROM regexp_split_to_table(input, '') WITH ORDINALITY AS letters(letter, position)
    ) decoded;
    RETURN convert_from(decode(octets, 'hex'), 'UTF8');
EXCEPTION
    WHEN character_not_in_repertoire OR untranslatable_character THEN RETURN NULL;
END $$;

CREATE TEMP TABLE utf8_rows (
    table_name regclass,
    column_name name,
    row_id bigint,
    before_text text NOT NULL,
    after_text text NOT NULL,
    PRIMARY KEY (table_name, column_name, row_id),
    CHECK (convert_to(before_text, 'UTF8') <> convert_to(after_text, 'UTF8')
        AND convert_to(after_text, 'UTF8') IS NOT DISTINCT FROM convert_to(pg_temp.undo_cp850(before_text), 'UTF8'))
);

CREATE TEMP TABLE utf8_check_targets (table_name regclass, check_name name, PRIMARY KEY (table_name, check_name));
INSERT INTO utf8_check_targets VALUES
    ('certificado.solicitud_actividad', 'solicitud_actividad_estado_check'),
    ('certificado.solicitud_decision', 'solicitud_decision_estado_anterior_check'),
    ('certificado.solicitud_decision', 'solicitud_decision_estado_nuevo_check');

CREATE TEMP TABLE utf8_checks (
    table_name regclass,
    check_name name,
    before_definition text NOT NULL,
    after_definition text NOT NULL,
    PRIMARY KEY (table_name, check_name),
    FOREIGN KEY (table_name, check_name) REFERENCES utf8_check_targets,
    CHECK (before_definition LIKE 'CHECK (%'
        AND before_definition NOT LIKE '% NOT VALID'
        AND convert_to(after_definition, 'UTF8') IS NOT DISTINCT FROM convert_to(pg_temp.undo_cp850(before_definition), 'UTF8'))
);

\if :preview
    DO $$
    DECLARE target record;
    BEGIN
        FOR target IN SELECT * FROM pg_temp.utf8_targets LOOP
            EXECUTE format(
                'INSERT INTO pg_temp.utf8_rows
                 SELECT $1, $2, %1$I, %2$I, candidate.repaired FROM %3$s
                 CROSS JOIN LATERAL (SELECT pg_temp.undo_cp850(%2$I) AS repaired) AS candidate
                 WHERE candidate.repaired IS NOT NULL',
                target.key_name, target.column_name, target.table_name)
            USING target.table_name, target.column_name;
        END LOOP;
    END $$;
    INSERT INTO utf8_checks
    SELECT t.table_name, t.check_name, v.definition, v.repaired
    FROM utf8_check_targets t
    JOIN pg_constraint c ON c.conrelid = t.table_name AND c.conname = t.check_name
    CROSS JOIN LATERAL (
        SELECT pg_get_constraintdef(c.oid) AS definition, pg_temp.undo_cp850(pg_get_constraintdef(c.oid)) AS repaired
    ) v
    WHERE c.contype = 'c' AND c.convalidated AND v.repaired IS NOT NULL;
    SELECT table_name, column_name, count(*) AS proposed_cells FROM utf8_rows GROUP BY 1, 2 ORDER BY 1, 2;
    SELECT count(*) AS proposed_cells, count(DISTINCT (table_name, row_id)) AS proposed_rows FROM utf8_rows;
    SELECT table_name, check_name FROM utf8_checks ORDER BY 1, 2;
    \copy (SELECT * FROM pg_temp.utf8_rows ORDER BY table_name, column_name, row_id) TO 'gpc-utf8-repair-preview.csv' WITH (FORMAT csv, HEADER true)
    \copy (SELECT * FROM pg_temp.utf8_checks ORDER BY table_name, check_name) TO 'gpc-utf8-repair-checks-preview.csv' WITH (FORMAT csv, HEADER true)
    ROLLBACK;
\else
    \if :apply
        \copy pg_temp.utf8_rows FROM 'gpc-utf8-repair-approved.csv' WITH (FORMAT csv, HEADER true)
        \copy pg_temp.utf8_checks FROM 'gpc-utf8-repair-checks-approved.csv' WITH (FORMAT csv, HEADER true)
        \copy (SELECT * FROM pg_temp.utf8_rows ORDER BY table_name, column_name, row_id) TO 'gpc-utf8-repair-backup.csv' WITH (FORMAT csv, HEADER true)
        \copy (SELECT * FROM pg_temp.utf8_checks ORDER BY table_name, check_name) TO 'gpc-utf8-repair-checks-backup.csv' WITH (FORMAT csv, HEADER true)
    \else
        \copy pg_temp.utf8_rows FROM 'gpc-utf8-repair-backup.csv' WITH (FORMAT csv, HEADER true)
        \copy pg_temp.utf8_checks FROM 'gpc-utf8-repair-checks-backup.csv' WITH (FORMAT csv, HEADER true)
    \endif
    SELECT set_config('gpc.utf8_repair_mode', :'mode', true);
    DO $$
    DECLARE
        entry record;
        expected text;
        replacement text;
        stored text;
        reverting boolean := current_setting('gpc.utf8_repair_mode') = 'revert';
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM pg_temp.utf8_rows) AND NOT EXISTS (SELECT 1 FROM pg_temp.utf8_checks) THEN
            RAISE EXCEPTION 'Both selected CSVs are empty';
        END IF;
        FOR entry IN SELECT * FROM pg_temp.utf8_checks ORDER BY table_name, check_name LOOP
            EXECUTE format('LOCK TABLE %s IN ACCESS EXCLUSIVE MODE', entry.table_name);
            SELECT pg_get_constraintdef(oid) INTO stored FROM pg_constraint
            WHERE conrelid = entry.table_name AND conname = entry.check_name AND contype = 'c' AND convalidated;
            expected := CASE WHEN reverting THEN entry.after_definition ELSE entry.before_definition END;
            replacement := CASE WHEN reverting THEN entry.before_definition ELSE entry.after_definition END;
            IF convert_to(stored, 'UTF8') IS DISTINCT FROM convert_to(expected, 'UTF8') THEN
                RAISE EXCEPTION 'CHECK changed: %.%. Transaction canceled.', entry.table_name, entry.check_name;
            END IF;
            EXECUTE format('ALTER TABLE %s DROP CONSTRAINT %I', entry.table_name, entry.check_name);
            -- PostgreSQL applies NOT VALID CHECKs to new writes immediately.
            -- Recreate after updating rows so checks on sibling columns cannot reject transitional rows.
        END LOOP;
        FOR entry IN
            SELECT r.*, t.key_name FROM pg_temp.utf8_rows r
            LEFT JOIN pg_temp.utf8_targets t USING (table_name, column_name)
            ORDER BY r.table_name, r.row_id, r.column_name
        LOOP
            IF entry.key_name IS NULL THEN
                RAISE EXCEPTION 'Column outside scope: %.%', entry.table_name, entry.column_name;
            END IF;
            expected := CASE WHEN reverting THEN entry.after_text ELSE entry.before_text END;
            replacement := CASE WHEN reverting THEN entry.before_text ELSE entry.after_text END;
            EXECUTE format(
                'UPDATE %1$s SET %2$I = $1 WHERE %3$I = $2
                 AND convert_to(%2$I, ''UTF8'') = convert_to($3, ''UTF8'') RETURNING %2$I',
                entry.table_name, entry.column_name, entry.key_name)
            INTO stored USING replacement, entry.row_id, expected;
            IF convert_to(stored, 'UTF8') IS DISTINCT FROM convert_to(replacement, 'UTF8') THEN
                RAISE EXCEPTION 'Value mismatch for %.% id=%. Transaction canceled.',
                    entry.table_name, entry.column_name, entry.row_id;
            END IF;
        END LOOP;
        FOR entry IN SELECT * FROM pg_temp.utf8_checks ORDER BY table_name, check_name LOOP
            replacement := CASE WHEN reverting THEN entry.before_definition ELSE entry.after_definition END;
            EXECUTE format('ALTER TABLE %s ADD CONSTRAINT %I %s', entry.table_name, entry.check_name, replacement);
            SELECT pg_get_constraintdef(oid) INTO stored FROM pg_constraint
            WHERE conrelid = entry.table_name AND conname = entry.check_name AND contype = 'c' AND convalidated;
            IF convert_to(stored, 'UTF8') IS DISTINCT FROM convert_to(replacement, 'UTF8') THEN
                RAISE EXCEPTION 'CHECK verification failed: %.%. Transaction canceled.', entry.table_name, entry.check_name;
            END IF;
        END LOOP;
    END $$;
    SELECT table_name, column_name, count(*) AS verified_cells FROM utf8_rows GROUP BY 1, 2 ORDER BY 1, 2;
    SELECT table_name, check_name AS verified_check FROM utf8_checks ORDER BY 1, 2;
    COMMIT;
\endif
