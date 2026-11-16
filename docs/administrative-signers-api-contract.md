# Habilitacion administrativa persistente de firmantes municipales

La autorizacion se guarda exclusivamente en
 gestion.persona_trabajador.habilitado_firma_certificados (BOOLEAN NOT NULL DEFAULT FALSE),
columna existente en PostgreSQL. No se crean tablas, columnas ni migraciones.
El estado laboral y la habilitacion son decisiones diferentes; una firma digital autentica
requiere un procedimiento y credenciales distintos.

## Consulta administrativa

- GET /api/funcionarios-firmantes y /trabajadores: trabajadores del ambito, incluso no vigentes.
- GET /api/funcionarios-firmantes/{id_persona_trabajador}: detalle laboral y habilitacion.
- Filtro opcional id_area: restringe el ambito; nunca lo amplia.

Cada trabajador devuelve id_persona_trabajador, nombre real, cargo, area, id_area,
 estado_laboral, vigente, elegible_laboralmente y habilitado_firma_certificados (booleano).
No se fusionan personas o vinculos con el mismo nombre. La identidad es siempre el ID laboral.
El modelo Person_Worker convierte habilitado_firma_certificados a booleano sin permitir su
asignacion masiva mediante los formularios laborales comunes.

Los campos de compatibilidad habilitado y habilitacion_global reflejan la columna real.
disponible_asignacion exige habilitacion mas elegibilidad laboral. La habilitacion permite
ser responsable de cualquier certificado, sin limitar por el area laboral del firmante. origen_habilitacion identifica la columna de Gestion. No se interpretan
las asignaciones documentales ni las credenciales como autorizacion administrativa.

habilitacion_administrativa.disponible y registro_independiente_disponible son true cuando
el backend reconoce la columna; catalogo_independiente:false indica que no hay otra tabla.
requiere_pfx y requiere_password son false. credencial_verificada:false y
credencial_valida:null no acreditan ninguna firma criptografica.

## Habilitar y deshabilitar

POST /api/funcionarios-firmantes/habilitar recibe {"id_persona_trabajador":2}.
confirmar:true es opcional y se conserva por compatibilidad. POST /api/funcionarios-firmantes
sin id_certificado_documento utiliza la misma operacion administrativa.
DELETE /api/funcionarios-firmantes/{id_persona_trabajador}/habilitacion deshabilita.

Ambas operaciones exigen JWT, usuario activo, ponentes-firmantes.editar y ambito autorizado.
Habilitar exige vinculo vigente, identidad y cargo validos. Se puede deshabilitar un vinculo
inactivo. No se requieren cuenta del trabajador, firmas.firmar, PFX, contrasena ni documento.
No se modifica estado, cargo, area, persona, permisos ni asignaciones documentales.

La transaccion bloquea el vinculo laboral y actualiza solamente habilitado_firma_certificados.
La respuesta 200 incluye completada:true, persistido:true y funcionario actualizado.
reintento:true indica que ya estaba en el estado solicitado: no se repite la escritura.
Otra consulta o una nueva sesion autenticada recupera la columna guardada en la base.
401: sin JWT; 403: sin permiso; 404: inexistente o fuera del ambito; 409: habilitacion de un
vinculo no elegible; 422: datos invalidos, campos personales, rutas, PFX o contrasenas.

## Gerencia y flujo documental

La consulta bajo /api/certificados-documentos/{id}/firma/firmantes-disponibles requiere
habilitado_firma_certificados=TRUE, estado=TRUE, fechas laborales vigentes, identidad/cargo
completos. La habilitacion administrativa tiene alcance institucional: el area laboral del
firmante no restringe los certificados en que puede figurar como responsable. No se requiere
fila en certificado.firma, PFX ni configuracion en actividad_firma. Se mantienen los permisos
y el ambito del usuario que consulta o gestiona el documento. Los permisos de emision y
administracion de firmantes siguen separados.

El endpoint de asignacion valida de nuevo la habilitacion dentro de la transaccion,
bloqueando los vinculos y releyendo su estado para coordinarse con una deshabilitacion.
Una lista visual antigua no permite guardar un responsable ya deshabilitado.
Los planes nuevos con trabajadores no autorizados se rechazan con 422 sin guardar filas.

La asignacion utiliza id_persona_trabajador, orden_firma, id_firma=NULL y fecha_firma=NULL.
Se mantienen modalidad PARALELA/SECUENCIAL, orden consecutivo, vista previa, revision y
confirmacion del PDF, hashes, QR y condiciones de disponibilidad para RR. HH.
Habilitar no firma un PDF, no crea un certificado ni concede permiso de firma criptografica.

Los responsables historicos se leen desde certificado.certificado_firma aunque sean
posteriormente deshabilitados. Repetir exactamente un plan ya guardado sigue siendo
idempotente; otro plan devuelve 409 sin reemplazarlo. La baja no modifica ni elimina PDF,
documentos, certificados o asignaciones existentes. Las revisiones nuevas requieren las
validaciones actuales; no se reemplaza silenciosamente un documento confirmado.

POST /api/funcionarios-firmantes con id_certificado_documento conserva el atajo documental
explicito: requiere modalidad, orden_firma y confirmar:true y devuelve
 tipo_registro:asignacion_documental. No concede habilitacion global. Para varios responsables
se usa /firma/asignar con el plan completo.

## Verificacion

Las pruebas focalizadas cubren persistencia, cast booleano, estados laborales, reconsulta,
alta/baja/rehabilitacion idempotentes, homonimos, permisos/ambitos, exclusion en Gerencia,
rechazo de asignaciones por API, conservacion de historicos y flujo sin PFX. Las fixtures
usan la columna existente en SQLite aislado; no habilitan trabajadores en PostgreSQL.
La comprobacion real autorizada consulta metadatos y el servicio de lectura sin escrituras.
