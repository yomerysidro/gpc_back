-- Propuesta mínima para el responsable de PostgreSQL. NO ejecutada por Laravel.
-- Reutiliza certificado_documento, acceso.usuario y gestion.area; no crea tablas.
-- Los datos existentes permanecen nulos, sin inventar remisiones históricas.
BEGIN;
ALTER TABLE certificado.certificado_documento ADD COLUMN gestion_entrega jsonb NULL;
ALTER TABLE certificado.certificado_documento
    ADD CONSTRAINT documento_gestion_entrega_check CHECK (
        gestion_entrega IS NULL OR (
            jsonb_typeof(gestion_entrega) = 'object'
            AND gestion_entrega ?& ARRAY['id_certificado_documento','id_certificado','id_persona','id_actividad','id_sesion',
                'id_usuario_remitente','id_area_destino','fecha_envio','revision_pdf',
                'hash_pdf','ruta_archivo','estado_administrativo','estado_recepcion',
                'estado_entrega','firmantes','historial']
            AND (gestion_entrega->>'id_certificado_documento')::bigint = id_certificado_documento
            AND (gestion_entrega->>'id_certificado')::bigint = id_certificado
            AND gestion_entrega->>'estado_administrativo' IN
                ('REMITIDO_RRHH','RECIBIDO_RRHH','DISPONIBLE_ENTREGA','ENTREGADO')
            AND gestion_entrega->>'revision_pdf' ~ '^[a-f0-9]{64}$'
            AND gestion_entrega->>'hash_pdf' ~ '^[a-f0-9]{64}$'
            AND jsonb_typeof(gestion_entrega->'historial') = 'array'
        )
    );
CREATE INDEX documento_bandeja_rrhh_idx ON certificado.certificado_documento
    ((gestion_entrega->>'id_area_destino'), id_certificado_documento)
    WHERE gestion_entrega IS NOT NULL;
COMMIT;
