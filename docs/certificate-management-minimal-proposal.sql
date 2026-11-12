-- PROPUESTA NUEVA PARA REVISION. NO AUTORIZA EJECUCION.
-- No reemplaza ni reactiva la propuesta histórica rechazada.
-- Todo el SQL está dentro de un comentario: ejecutar este archivo no cambia nada.
-- No es una migración. No se ha validado ejecutándolo en PostgreSQL.
-- Detalle: certificate-management-minimal-design.md
/*
-- PRECONDICIONES A: base exclusiva para ensayo y autorización para despliegue.
-- Si aparecen duplicados, detenerse; no borrarlos ni inferir sesiones.
SELECT id_participacion, id_tipo_certificado, count(*)
FROM certificado.certificado WHERE id_participacion IS NOT NULL
GROUP BY id_participacion, id_tipo_certificado HAVING count(*) > 1;
SELECT id_practica, id_tipo_certificado, count(*)
FROM certificado.certificado WHERE id_practica IS NOT NULL
GROUP BY id_practica, id_tipo_certificado HAVING count(*) > 1;
SELECT id_reconocimiento, id_tipo_certificado, count(*)
FROM certificado.certificado WHERE id_reconocimiento IS NOT NULL
GROUP BY id_reconocimiento, id_tipo_certificado HAVING count(*) > 1;

-- A: PREPARAR / REMITIR / REVISAR. CERO TABLAS NUEVAS.
BEGIN;
SET LOCAL lock_timeout = '5s';
SET LOCAL statement_timeout = '60s';

ALTER TABLE certificado.certificado
  ADD COLUMN id_actividad_sesion BIGINT NULL,
  ALTER COLUMN fecha_emision DROP NOT NULL,
  ADD CONSTRAINT certificado_preparacion_fecha_check
    CHECK (fecha_emision IS NOT NULL OR estado = 'Preparado'),
  ADD CONSTRAINT certificado_sesion_origen_check CHECK (
    id_actividad_sesion IS NULL OR
    (id_participacion IS NOT NULL AND id_practica IS NULL AND id_reconocimiento IS NULL)
  ),
  ADD CONSTRAINT certificado_asistencia_sesion_fk
    FOREIGN KEY (id_participacion, id_actividad_sesion)
    REFERENCES certificado.asistencia (id_participacion, id_actividad_sesion)
    ON UPDATE RESTRICT ON DELETE RESTRICT;

-- Conservar el CHECK de exactamente un origen raíz existente.
-- FK a asistencia demuestra pareja persistente, no que su actividad sea coherente:
-- el servicio valida/bloquea participación, sesión y asistencia antes de escribir.
CREATE UNIQUE INDEX certificado_participacion_sesion_tipo_unique
  ON certificado.certificado (id_participacion, id_actividad_sesion, id_tipo_certificado)
  WHERE id_actividad_sesion IS NOT NULL;
CREATE UNIQUE INDEX certificado_participacion_global_tipo_unique
  ON certificado.certificado (id_participacion, id_tipo_certificado)
  WHERE id_participacion IS NOT NULL AND id_actividad_sesion IS NULL;
CREATE UNIQUE INDEX certificado_practica_tipo_unique
  ON certificado.certificado (id_practica, id_tipo_certificado)
  WHERE id_practica IS NOT NULL;
CREATE UNIQUE INDEX certificado_reconocimiento_tipo_unique
  ON certificado.certificado (id_reconocimiento, id_tipo_certificado)
  WHERE id_reconocimiento IS NOT NULL;

ALTER TABLE certificado.certificado_documento
  ADD COLUMN mensaje_aprobado TEXT NULL,
  ADD COLUMN estado_flujo VARCHAR(24) NULL,
  ADD COLUMN modalidad_firma VARCHAR(12) NULL,
  ADD CONSTRAINT documento_estado_flujo_check CHECK (
    estado_flujo IS NULL OR estado_flujo IN
      ('Preparado','PendienteFirmas','Parcial','Firmado','Emitido','Entregado','Observado')
  ),
  ADD CONSTRAINT documento_modalidad_firma_check CHECK (
    modalidad_firma IS NULL OR modalidad_firma IN ('Paralela','Secuencial')
  ),
  ADD CONSTRAINT documento_preparacion_contenido_check CHECK (
    estado_flujo IS NULL OR
    (mensaje_aprobado IS NOT NULL AND length(btrim(mensaje_aprobado)) > 0
      AND modalidad_firma IS NOT NULL)
  );
-- No modificar certificado_documento_estado_check: Vigente/Anulado.
-- No clasificar documentos históricos ni inferir su aprobación.
CREATE UNIQUE INDEX documento_tramite_activo_certificado_unique
  ON certificado.certificado_documento (id_certificado)
  WHERE estado = 'Vigente' AND estado_flujo IS NOT NULL;
CREATE INDEX documento_bandeja_firmas_index
  ON certificado.certificado_documento (estado_flujo, fecha_generacion, id_certificado_documento)
  WHERE estado = 'Vigente' AND estado_flujo IN ('PendienteFirmas','Parcial');

ALTER TABLE certificado.certificado_firma
  ALTER COLUMN fecha_firma DROP NOT NULL,
  ADD COLUMN estado_firma VARCHAR(16) NULL,
  ADD COLUMN id_persona BIGINT NULL REFERENCES gestion.persona (id_persona)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  ADD COLUMN nombre_aprobado TEXT NULL,
  ADD COLUMN cargo_aprobado TEXT NULL,
  ADD CONSTRAINT certificado_firma_estado_check CHECK (
    estado_firma IS NULL OR estado_firma IN ('Pendiente','Verificada')
  ),
  ADD CONSTRAINT certificado_firma_snapshot_check CHECK (
    estado_firma IS NULL OR
    (id_persona IS NOT NULL AND nombre_aprobado IS NOT NULL
      AND length(btrim(nombre_aprobado)) > 0
      AND cargo_aprobado IS NOT NULL AND length(btrim(cargo_aprobado)) > 0)
  ),
  ADD CONSTRAINT certificado_firma_fecha_estado_check CHECK (
    estado_firma IS NULL OR
    (estado_firma = 'Pendiente' AND fecha_firma IS NULL) OR
    (estado_firma = 'Verificada' AND fecha_firma IS NOT NULL)
  );
CREATE UNIQUE INDEX certificado_firma_documento_persona_unique
  ON certificado.certificado_firma (id_certificado_documento, id_persona)
  WHERE id_persona IS NOT NULL;
-- Conservar UNIQUE(documento,firma) y CHECK orden_firma > 0 existentes.
-- No agregar UNIQUE de orden: mismo orden puede definir una etapa compartida.
-- Validar persona = firma -> trabajador -> persona en el servicio transaccional.
-- No marcar filas existentes como Verificada sin validar el PDF criptográficamente.

ALTER TABLE certificado.solicitud_decision
  ALTER COLUMN id_solicitud_actividad DROP NOT NULL,
  ADD COLUMN id_certificado_documento BIGINT NULL
    REFERENCES certificado.certificado_documento (id_certificado_documento)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  ADD COLUMN clave_operacion UUID NULL,
  ADD COLUMN datos JSONB NOT NULL DEFAULT '{}'::jsonb,
  ADD CONSTRAINT decision_destino_exclusivo_check CHECK (
    (id_solicitud_actividad IS NOT NULL) <> (id_certificado_documento IS NOT NULL)
  ),
  ADD CONSTRAINT decision_documento_operacion_check CHECK (
    id_certificado_documento IS NULL OR clave_operacion IS NOT NULL
  ),
  ADD CONSTRAINT decision_datos_objeto_check CHECK (jsonb_typeof(datos) = 'object');

-- Mantener intactos los valores anteriores para solicitudes de actividad.
-- La condición documental abre únicamente el nuevo dominio con CHECK propios.
ALTER TABLE certificado.solicitud_decision
  DROP CONSTRAINT solicitud_decision_tipo_check,
  DROP CONSTRAINT solicitud_decision_estado_anterior_check,
  DROP CONSTRAINT solicitud_decision_estado_nuevo_check,
  ADD CONSTRAINT solicitud_decision_tipo_check CHECK (
    id_certificado_documento IS NOT NULL OR tipo_decision IN
      ('REVISION_CONFORME','OBSERVAR','DERIVAR_GERENCIA','APROBAR','RECHAZAR')
  ),
  ADD CONSTRAINT solicitud_decision_estado_anterior_check CHECK (
    id_certificado_documento IS NOT NULL OR estado_anterior IN
      ('Borrador','Enviada','En revisión','Observada','Pendiente de aprobación','Aprobada','Rechazada')
  ),
  ADD CONSTRAINT solicitud_decision_estado_nuevo_check CHECK (
    id_certificado_documento IS NOT NULL OR estado_nuevo IN
      ('Borrador','Enviada','En revisión','Observada','Pendiente de aprobación','Aprobada','Rechazada')
  ),
  ADD CONSTRAINT decision_documento_tipo_check CHECK (
    id_certificado_documento IS NULL OR tipo_decision IN
      ('PREPARAR','REMITIR','REVISION_CONFORME','OBSERVAR','ANULAR',
       'FIRMA_SOLICITADA','FIRMA_APLICADA','FIRMA_FALLIDA','EMITIR','ENTREGAR')
  ),
  ADD CONSTRAINT decision_documento_anterior_check CHECK (
    id_certificado_documento IS NULL OR estado_anterior IN
      ('SinRegistro','Preparado','PendienteFirmas','Parcial','Firmado','Emitido','Entregado','Observado')
  ),
  ADD CONSTRAINT decision_documento_nuevo_check CHECK (
    id_certificado_documento IS NULL OR estado_nuevo IN
      ('Preparado','PendienteFirmas','Parcial','Firmado','Emitido','Entregado','Observado','Anulado')
  );
CREATE UNIQUE INDEX decision_documento_operacion_unique
  ON certificado.solicitud_decision (id_certificado_documento, clave_operacion)
  WHERE id_certificado_documento IS NOT NULL;
CREATE UNIQUE INDEX decision_documento_remision_unique
  ON certificado.solicitud_decision (id_certificado_documento)
  WHERE id_certificado_documento IS NOT NULL AND tipo_decision = 'REMITIR';
CREATE INDEX decision_documento_historial_index
  ON certificado.solicitud_decision (id_certificado_documento, fecha_decision, id_solicitud_decision)
  WHERE id_certificado_documento IS NOT NULL;

-- Actor y fecha ya existen: id_usuario_responsable y fecha_decision TIMESTAMPTZ.
-- PREPARAR congela manifiesto y hashes en datos; REMITIR registra actor/fecha reales.
-- Nuevas inserciones y transiciones exigen cambios de código, no solo este DDL.
-- Consultas antiguas deben conservar filtro por solicitud y excluir eventos documentales.
COMMIT;

-- B: SOLO DESPUES DE AUTORIZAR Y PROBAR UN PROVEEDOR CRIPTOGRAFICO.
-- No es requisito para remitir/revisar en A. No instala ninguna dependencia.
BEGIN;
SET LOCAL lock_timeout = '5s';
SET LOCAL statement_timeout = '60s';
ALTER TABLE certificado.certificado_documento
  ADD COLUMN ruta_archivo_preparado VARCHAR(255) NULL,
  ADD COLUMN hash_preparado VARCHAR(64) NULL,
  ADD COLUMN operacion_firma UUID NULL,
  ADD COLUMN hash_entrada_firma VARCHAR(64) NULL,
  ADD CONSTRAINT documento_original_firma_check CHECK (
    (ruta_archivo_preparado IS NULL AND hash_preparado IS NULL) OR
    (ruta_archivo_preparado IS NOT NULL AND hash_preparado IS NOT NULL
      AND hash_preparado ~ '^[a-f0-9]{64}$')
  ),
  ADD CONSTRAINT documento_reserva_firma_check CHECK (
    (operacion_firma IS NULL AND hash_entrada_firma IS NULL) OR
    (operacion_firma IS NOT NULL AND hash_entrada_firma IS NOT NULL
      AND hash_entrada_firma ~ '^[a-f0-9]{64}$'
      AND ruta_archivo_preparado IS NOT NULL)
  );
CREATE UNIQUE INDEX documento_operacion_firma_unique
  ON certificado.certificado_documento (operacion_firma)
  WHERE operacion_firma IS NOT NULL;
-- Antes de la primera firma, servicio conserva ruta/hash actuales como preparado.
-- Una reserva es una operación en curso/incierta, no una firma aplicada.
-- Eventos SOLICITADA/APLICADA/FALLIDA tienen claves de evento distintas;
-- datos.operacion_proveedor identifica el mismo UUID de reserva entre eventos.
-- No actualizar el evento SOLICITADA: resultados se agregan como nuevos eventos.
-- La recuperación busca la reserva persistida y concilia con el proveedor por UUID.
-- Firma verificada y actualización ruta/hash/estado/evento se confirman juntas.
COMMIT;
*/
