-- AUTORIZADO expresamente el 2026-11-15. Todavía NO aplicado.
-- Intento revertido: SQLSTATE 42501, equipo_dev no es propietario de las tablas.
-- Requiere conexión propietaria; respaldo privado preparado y restauración temporal verificada.
-- Estructura real consultada con information_schema, pg_constraint y pg_indexes.
-- No modifica registros existentes; los vínculos históricos conservan sesión NULL.
-- La transacción revierte íntegramente si alguna restricción no puede instalarse.
BEGIN;
SET LOCAL lock_timeout = '5s';
SET LOCAL statement_timeout = '60s';

-- Inspección real 2026-11-15: 1 vínculo, 2 reconocimientos, 7 certificados.
-- No ejecutar sin autorización expresa. No usa CASCADE ni modifica beneficiarios.
LOCK TABLE certificado.actividad_ponente, certificado.actividad_sesion,
    certificado.reconocimiento, certificado.certificado IN SHARE ROW EXCLUSIVE MODE;
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'certificado' AND table_name = 'actividad_ponente'
            AND column_name = 'id_actividad' AND data_type = 'bigint' AND is_nullable = 'NO') THEN
        RAISE EXCEPTION 'La actividad padre debe seguir siendo obligatoria';
    END IF;
    IF EXISTS (SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'certificado' AND
            ((table_name = 'actividad_ponente' AND column_name = 'id_actividad_sesion')
            OR (table_name = 'reconocimiento' AND column_name IN ('id_actividad_ponente', 'id_tipo_certificado')))) THEN
        RAISE EXCEPTION 'Esquema ya ampliado o aplicación parcial: revisar antes de continuar';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint
        WHERE conrelid = 'certificado.actividad_ponente'::regclass
            AND conname = 'actividad_ponente_id_actividad_id_persona_ponente_unique'
            AND contype = 'u'
            AND pg_get_constraintdef(oid) = 'UNIQUE (id_actividad, id_persona_ponente)') THEN
        RAISE EXCEPTION 'La unicidad histórica cambió: no se puede reemplazar automáticamente';
    END IF;
    PERFORM set_config('morales.ponentes_historicos',
        (SELECT COALESCE(jsonb_agg(to_jsonb(ap) ORDER BY id_actividad_ponente), '[]'::jsonb)::text
            FROM certificado.actividad_ponente ap), true);
    PERFORM set_config('morales.reconocimientos_historicos',
        (SELECT COALESCE(jsonb_agg(to_jsonb(r) ORDER BY id_reconocimiento), '[]'::jsonb)::text
            FROM certificado.reconocimiento r), true);
    PERFORM set_config('morales.certificados_historicos',
        (SELECT COALESCE(jsonb_agg(to_jsonb(c) ORDER BY id_certificado), '[]'::jsonb)::text
            FROM certificado.certificado c), true);
END $$;

ALTER TABLE certificado.actividad_ponente ADD COLUMN id_actividad_sesion bigint NULL;
ALTER TABLE certificado.actividad_sesion
    ADD CONSTRAINT uq_sesion_actividad UNIQUE (id_actividad_sesion, id_actividad);
ALTER TABLE certificado.actividad_ponente
    ADD CONSTRAINT fk_ponente_sesion_actividad FOREIGN KEY (id_actividad_sesion, id_actividad)
    REFERENCES certificado.actividad_sesion (id_actividad_sesion, id_actividad) ON DELETE RESTRICT ON UPDATE RESTRICT;

-- Se sustituye la unicidad global por dos ámbitos, conservando los históricos.
ALTER TABLE certificado.actividad_ponente
    DROP CONSTRAINT actividad_ponente_id_actividad_id_persona_ponente_unique RESTRICT;
CREATE UNIQUE INDEX uq_ponente_actividad_historico
    ON certificado.actividad_ponente (id_actividad, id_persona_ponente)
    WHERE id_actividad_sesion IS NULL;
CREATE UNIQUE INDEX uq_ponente_sesion
    ON certificado.actividad_ponente (id_actividad_sesion, id_persona_ponente)
    WHERE id_actividad_sesion IS NOT NULL;

ALTER TABLE certificado.reconocimiento
    ADD COLUMN id_actividad_ponente bigint NULL,
    ADD COLUMN id_tipo_certificado bigint NULL,
    ADD CONSTRAINT fk_reconocimiento_ponente FOREIGN KEY (id_actividad_ponente)
        REFERENCES certificado.actividad_ponente (id_actividad_ponente) ON DELETE RESTRICT ON UPDATE RESTRICT,
    ADD CONSTRAINT fk_reconocimiento_tipo FOREIGN KEY (id_tipo_certificado)
        REFERENCES certificado.tipo_certificado (id_tipo_certificado) ON DELETE RESTRICT ON UPDATE RESTRICT,
    ADD CONSTRAINT ck_reconocimiento_ponente_tipo CHECK
        ((id_actividad_ponente IS NULL) = (id_tipo_certificado IS NULL)),
    ADD CONSTRAINT uq_reconocimiento_ponente_tipo UNIQUE (id_actividad_ponente, id_tipo_certificado);

-- Protege identidad y sesión del reconocimiento nuevo sin tocar históricos.
CREATE FUNCTION certificado.proteger_ponente_sesion() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE vinculo certificado.actividad_ponente; persona bigint;
BEGIN
    IF TG_TABLE_NAME = 'actividad_ponente' THEN
        IF TG_OP = 'UPDATE' AND ROW(NEW.id_actividad, NEW.id_actividad_sesion, NEW.id_persona_ponente)
            IS DISTINCT FROM ROW(OLD.id_actividad, OLD.id_actividad_sesion, OLD.id_persona_ponente)
            AND EXISTS (SELECT 1 FROM certificado.reconocimiento WHERE id_actividad_ponente = OLD.id_actividad_ponente) THEN
            RAISE EXCEPTION 'El vínculo sustenta un reconocimiento' USING ERRCODE = '23503';
        END IF;
        IF NEW.id_actividad_sesion IS NOT NULL THEN
            PERFORM 1 FROM certificado.actividad_sesion WHERE id_actividad_sesion = NEW.id_actividad_sesion FOR UPDATE;
            SELECT id_persona INTO STRICT persona FROM gestion.persona_ponente WHERE id_persona_ponente = NEW.id_persona_ponente;
            IF EXISTS (SELECT 1 FROM certificado.actividad_ponente ap JOIN gestion.persona_ponente pp USING (id_persona_ponente)
                WHERE ap.id_actividad_sesion = NEW.id_actividad_sesion AND pp.id_persona = persona
                    AND ap.id_actividad_ponente <> NEW.id_actividad_ponente) THEN
                RAISE EXCEPTION 'Persona ya vinculada a la sesión' USING ERRCODE = '23505';
            END IF;
        END IF;
        RETURN NEW;
    END IF;
    IF TG_OP = 'UPDATE' AND OLD.id_actividad_ponente IS DISTINCT FROM NEW.id_actividad_ponente THEN
        RAISE EXCEPTION 'No se convierten ni reasignan reconocimientos' USING ERRCODE = '23514';
    END IF;
    IF NEW.id_actividad_ponente IS NULL THEN RETURN NEW; END IF;
    SELECT * INTO STRICT vinculo FROM certificado.actividad_ponente
        WHERE id_actividad_ponente = NEW.id_actividad_ponente FOR UPDATE;
    SELECT id_persona INTO STRICT persona FROM gestion.persona_ponente
        WHERE id_persona_ponente = vinculo.id_persona_ponente;
    IF vinculo.id_actividad_sesion IS NULL OR NOT EXISTS
        (SELECT 1 FROM gestion.persona_trabajador WHERE id_persona_trabajador = NEW.id_persona_trabajador AND id_persona = persona) THEN
        RAISE EXCEPTION 'Reconocimiento sin identidad de ponente de sesión' USING ERRCODE = '23514';
    END IF;
    IF TG_OP = 'UPDATE' AND ROW(NEW.id_actividad_ponente, NEW.id_tipo_certificado, NEW.id_persona_trabajador)
        IS DISTINCT FROM ROW(OLD.id_actividad_ponente, OLD.id_tipo_certificado, OLD.id_persona_trabajador) THEN
        RAISE EXCEPTION 'La identidad del reconocimiento no se reasigna' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER proteger_ponente_sesion BEFORE INSERT OR UPDATE ON certificado.actividad_ponente
    FOR EACH ROW EXECUTE FUNCTION certificado.proteger_ponente_sesion();
CREATE TRIGGER proteger_reconocimiento_sesion BEFORE INSERT OR UPDATE ON certificado.reconocimiento
    FOR EACH ROW EXECUTE FUNCTION certificado.proteger_ponente_sesion();

-- El tipo y la persona del certificado deben coincidir con el reconocimiento.
-- La exclusión se limita a los nuevos orígenes vinculados, sin indexar históricos.
CREATE FUNCTION certificado.validar_certificado_ponente() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE reconocimiento certificado.reconocimiento;
BEGIN
    IF TG_OP = 'UPDATE' AND OLD.id_reconocimiento IS DISTINCT FROM NEW.id_reconocimiento
        AND EXISTS (SELECT 1 FROM certificado.reconocimiento
            WHERE id_reconocimiento = OLD.id_reconocimiento AND id_actividad_ponente IS NOT NULL) THEN
        RAISE EXCEPTION 'No se reasigna el origen del certificado de ponente' USING ERRCODE = '23514';
    END IF;
    IF NEW.id_reconocimiento IS NULL THEN RETURN NEW; END IF;
    SELECT * INTO reconocimiento FROM certificado.reconocimiento
        WHERE id_reconocimiento = NEW.id_reconocimiento FOR UPDATE;
    IF reconocimiento.id_actividad_ponente IS NULL THEN RETURN NEW; END IF;
    IF NEW.id_tipo_certificado IS DISTINCT FROM reconocimiento.id_tipo_certificado
        OR NEW.id_participacion IS NOT NULL OR NEW.id_practica IS NOT NULL OR NEW.id_actividad_sesion IS NOT NULL
        OR NOT EXISTS (SELECT 1 FROM gestion.persona_trabajador
            WHERE id_persona_trabajador = reconocimiento.id_persona_trabajador AND id_persona = NEW.id_persona) THEN
        RAISE EXCEPTION 'Identidad o tipo de reconocimiento incoherente' USING ERRCODE = '23514';
    END IF;
    IF EXISTS (SELECT 1 FROM certificado.certificado WHERE id_reconocimiento = NEW.id_reconocimiento
        AND id_certificado <> NEW.id_certificado) THEN
        RAISE EXCEPTION 'Reconocimiento ya certificado' USING ERRCODE = '23505';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER validar_certificado_ponente BEFORE INSERT OR UPDATE ON certificado.certificado
    FOR EACH ROW EXECUTE FUNCTION certificado.validar_certificado_ponente();

-- Verificación posterior dentro de la misma transacción: los registros previos
-- deben conservar íntegramente sus valores y las columnas nuevas seguir NULL.
DO $$
BEGIN
    IF (SELECT COALESCE(jsonb_agg(to_jsonb(ap) - 'id_actividad_sesion' ORDER BY id_actividad_ponente), '[]'::jsonb)
        FROM certificado.actividad_ponente ap) IS DISTINCT FROM current_setting('morales.ponentes_historicos')::jsonb
        OR EXISTS (SELECT 1 FROM certificado.actividad_ponente WHERE id_actividad_sesion IS NOT NULL)
        OR (SELECT COALESCE(jsonb_agg(to_jsonb(r) - 'id_actividad_ponente' - 'id_tipo_certificado' ORDER BY id_reconocimiento), '[]'::jsonb)
            FROM certificado.reconocimiento r) IS DISTINCT FROM current_setting('morales.reconocimientos_historicos')::jsonb
        OR EXISTS (SELECT 1 FROM certificado.reconocimiento WHERE id_actividad_ponente IS NOT NULL OR id_tipo_certificado IS NOT NULL)
        OR (SELECT COALESCE(jsonb_agg(to_jsonb(c) ORDER BY id_certificado), '[]'::jsonb)
            FROM certificado.certificado c) IS DISTINCT FROM current_setting('morales.certificados_historicos')::jsonb THEN
        RAISE EXCEPTION 'La ampliación alteró registros históricos: revertir transacción';
    END IF;
END $$;

-- Ante cualquier error ejecutar ROLLBACK; el cliente debe detenerse al primer
-- error (psql: ON_ERROR_STOP). Antes del COMMIT puede revertirse con ROLLBACK.
-- Después de registrar intervenciones no retirar columnas: perdería su origen.
COMMIT;
