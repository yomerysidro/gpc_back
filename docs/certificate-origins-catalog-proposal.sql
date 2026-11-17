-- OPERACIONES DE DATOS OPCIONALES, NO EJECUTADAS, NO SON MIGRACIONES.
-- Ambas estan comentadas. Elegir el bloque expresamente autorizado.
-- No actualizar ni reactivar registros existentes; no asignar IDs manualmente.

-- A: catalogo DOCUMENTAL. En la base revisada ya existen ambos valores.
/*
BEGIN;
SET LOCAL lock_timeout = '5s';
LOCK TABLE certificado.tipo_certificado IN SHARE ROW EXCLUSIVE MODE;
INSERT INTO certificado.tipo_certificado (nombre_tipo, estado)
SELECT candidato.nombre, TRUE
FROM (VALUES ('Reconocimiento'), ('Prácticas')) AS candidato(nombre)
WHERE NOT EXISTS (
    SELECT 1 FROM certificado.tipo_certificado AS existente
    WHERE lower(btrim(existente.nombre_tipo)) = lower(candidato.nombre)
);
SELECT id_tipo_certificado, nombre_tipo, estado FROM certificado.tipo_certificado
WHERE lower(btrim(nombre_tipo)) IN (lower('Reconocimiento'), lower('Prácticas'));
COMMIT;
*/

-- B: catalogo DE ACTIVIDADES, solo si se aprueban estas clasificaciones.
-- Una etiqueta no representa ni acredita un periodo real de practicas.
-- No crear solicitudes, actividades o sesiones para sustituir gestion.practica.
/*
BEGIN;
SET LOCAL lock_timeout = '5s';
LOCK TABLE certificado.tipo_actividad IN SHARE ROW EXCLUSIVE MODE;
INSERT INTO certificado.tipo_actividad (nombre, descripcion, estado)
SELECT candidato.nombre, NULL, TRUE
FROM (VALUES ('Reconocimiento'), ('Prácticas')) AS candidato(nombre)
WHERE NOT EXISTS (
    SELECT 1 FROM certificado.tipo_actividad AS existente
    WHERE lower(btrim(existente.nombre)) = lower(candidato.nombre)
);
SELECT id_tipo_actividad, nombre, descripcion, estado FROM certificado.tipo_actividad
WHERE lower(btrim(nombre)) IN (lower('Reconocimiento'), lower('Prácticas'));
COMMIT;
*/
