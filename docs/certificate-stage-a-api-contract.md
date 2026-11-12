# Contrato vigente de Etapa A con certificados_morales

La inspección de PostgreSQL 15.19 confirmó id_actividad_sesion BIGINT NULL, la FK compuesta validada hacia asistencia, el índice único parcial de participación/sesión/tipo y fecha_emision nullable. Los cambios estructurales fueron aplicados externamente. Esta verificación y las pruebas aisladas de Laravel se realizaron sin crear certificados institucionales en la base principal.

| Entidad | Estados reales |
| --- | --- |
| certificado.certificado | PREPARADO, PENDIENTE_FIRMA, FIRMADO, EMITIDO, ANULADO |
| certificado.certificado_documento | Vigente, Anulado |

PostgreSQL exige una fecha para EMITIDO. Laravel guarda fecha_emision=null y notificado=false en la preparación y conserva ambos al remitir. La Etapa A implementa las transiciones a PREPARADO y PENDIENTE_FIRMA. Firmar, emitir y entregar siguen deshabilitados. El modelo Certificate convierte estado a string.

Las operaciones privadas requieren cookie JWT access_token, permisos efectivos de emision y los permisos de contexto correspondientes. También verifican el ámbito institucional. Respuestas JSON con success, message y data. El código HTTP expresa el resultado. Las respuestas privadas usan Cache-Control private, no-store.

## Selección y revisión

GET /api/activity-session/{id}/beneficiarios obtiene candidatos con sus capacidades y motivos de bloqueo. La elegibilidad requiere sesión Finalizada, participación Activo y asistencia PRESENTE en esa sesión, solicitud aprobada y tipo activo configurado en certification.preparation.session_type_ids. Los IDs se obtienen del catálogo y de los candidatos reales.

POST /api/activity-session/{id}/preparar-certificados acepta el siguiente cuerpo para crear la revisión confirmable.

```json
{
  "accion": "revisar_documento",
  "id_participacion": 2,
  "id_tipo_certificado": 1,
  "mensaje": "Mensaje personalizado aprobado"
}
```

Los IDs del cuerpo son ilustrativos y deben corresponder a los registros seleccionados. La modalidad_firma es opcional en el esquema actual. El mensaje exige texto plano, hasta 1200 caracteres, y conserva espacios y saltos de línea. Se rechazan campos adicionales.

Respuesta 200 con data.accion=revisar_documento, token_revision, expires_at, mensaje, hash_pdf, firmantes, asignacion_firmantes_pendiente, persistido=false, emitida=false y vista_previa_pdf. Esta contiene contenido_base64, tipo_contenido=application/pdf, nombre_archivo y persistido=false. Mostrar ese PDF A4 horizontal y conservar el token para su confirmación. El token queda vinculado al usuario, sesión, contexto, mensaje, plantilla y SHA-256 del archivo durante 30 minutos.

La acción anterior accion=vista_previa, formato=pdf sigue disponible y devuelve un PDF temporal con vista_previa_pdf.hash_pdf. Para guardar debe obtenerse y revisarse el PDF de revisar_documento. Las banderas puede_revisar_documento_para_firma, motivos_bloqueo_preparacion_persistente y siguiente_accion_preparacion indican la disponibilidad de ese paso. Una vista previa temporal queda fuera de la bandeja.

## Confirmar preparación

Mismo POST y sesión, con el token de revisar_documento.

```json
{
  "accion": "confirmar_preparacion",
  "token_revision": "<token_revision recibido de revisar_documento>",
  "confirmar": true
}
```

Respuesta 201 al crear, 200 al repetir la confirmación. data incluye id_certificado, id_certificado_documento, codigo_verificacion, hash_pdf, estado_flujo=PREPARADO, estado_certificado=PREPARADO, estado_documento=Vigente, fecha_emision=null, id_sesion, sesion, id_tipo_certificado, tipo_certificado, beneficiario, actividad, creado, mensaje_aprobado, mensaje_persistido_en_pdf=true y siguiente_paso=revisar-envio. Los reintentos conservan los IDs del expediente.

El esquema actual conserva el mensaje aprobado dentro de los bytes del PDF. El detalle posterior devuelve mensaje_aprobado=null y mensaje_estructurado_disponible=false porque falta una columna estructurada de mensaje. El servidor verifica el manifiesto y copia el archivo confirmado sin regenerarlo. El hash persistido es SHA-256 de esos mismos bytes.

## Revisar y enviar a Gerencia

GET /api/certificados-documentos/detail/{id}?flujo=firmas devuelve data.facultades.remitir. Habilitar el botón cuando sea true.

POST /api/certificados-documentos/{id}/revisar-envio lleva cuerpo vacío. Devuelve 200 con data.token_revision, expires_at, documento y vista_previa_pdf del archivo almacenado. Mostrar ese PDF antes de la confirmación final. Este token de envío es distinto del token de preparación.

POST /api/certificados-documentos/{id}/enviar-gerencia acepta lo siguiente.

```json
{
  "token_revision": "<token_revision recibido de revisar-envio>",
  "confirmar": true
}
```

Respuesta 200 con data.enviado=true, reintento, estado_flujo=PENDIENTE_FIRMA y documento. documento.estado_certificado=PENDIENTE_FIRMA, estado_documento=Vigente y fecha_emision=null. El archivo, su hash y sus IDs permanecen iguales. Un reintento con el mismo token vigente devuelve reintento=true. documento.facultades.remitir pasa a false. Se permite cero firmantes con asignacion_firmantes_pendiente=true.

La remisión conserva el estado en certificado.certificado. historial_en_bd=false y fecha_envio_en_bd=null expresan la capacidad del esquema actual. Se escribe un log de auditoría al primer envío y auditoria_log informa el resultado. La tabla solicitud_decision conserva las decisiones de solicitudes de actividad. No se insertan firmas durante la preparación ni la remisión.

## Bandeja, detalle, descarga y QR

| Método | Ruta | Contrato |
| --- | --- | --- |
| GET | /api/certificados-documentos/list?bandeja=por_firmar | data es un paginador con data, total, current_page y per_page |
| GET | /api/certificados-documentos/detail/{id}?flujo=firmas | data contiene el documento real y sus facultades |
| GET | /api/certificados-documentos/{id}/archivo?flujo=firmas | descarga application/pdf del archivo privado con integridad comprobada |
| GET | /api/certificados-documentos/{id}/historial | disponible=false cuando falta una asociación documental persistente de eventos |
| GET | /api/certificados/verificar/{codigo} | estado real y emision_oficial_verificada=false y firma_verificada=false en Etapa A |

La bandeja acepta page, per_page de 1 a 100, id_actividad y estado_flujo=PENDIENTE_FIRMA. El detalle incluye id_certificado_documento, id_certificado, id_sesion, sesion, fecha_sesion, actividad, beneficiario, tipo_certificado, estado_certificado, estado_documento, hash_pdf, url_documento, firmantes, asignacion_firmantes_pendiente, firmas y facultades. Firmar, emitir y entregar devuelven false.

El QR de PREPARADO, PENDIENTE_FIRMA o FIRMADO informa el estado administrativo, mantiene puede_ver_documento=false y deniega la descarga pública. La descarga privada verifica JWT, permisos, ámbito, vigencia documental y SHA-256. La verificación de integridad del archivo mantiene firma_verificada=false hasta disponer de un motor criptográfico real.

| HTTP | Motivo |
| --- | --- |
| 401 | JWT ausente, inválido o caducado |
| 403 | Permiso insuficiente, ámbito rechazado o revisión de otro usuario |
| 404 | Recurso inexistente o fuera del ámbito de consulta |
| 409 | Contexto cambiado, sesión no elegible, token caducado, duplicado, PDF alterado, estado incompatible o esquema incompleto |
| 422 | Payload inválido, campos extra, mensaje inválido o confirmación incorrecta |
| 500 | Fallo interno de consulta, escritura o almacenamiento |

Los reintentos del cliente deben ser limitados. Un 401 devuelve JSON y requiere recuperar la autenticación. Un 409 exige consultar el recurso o solicitar otra revisión según el mensaje.

## Historial de solicitudes de actividad

GET /api/activity-requests/decisions/history conserva la autenticación y los ámbitos existentes. Acepta tipo_decision, estado_nuevo, id_area, id_solicitud_actividad, id_usuario_responsable, fecha_desde, fecha_hasta, page y per_page. Devuelve un paginador en data. Este historial corresponde a solicitudes de actividad.

## Antecedentes conservados

Los apartados siguientes registran verificaciones y contratos de versiones anteriores. Para integrar Angular se utiliza el contrato vigente anterior.

# Etapa A implementada: certificados preparados y remisión

## Decisión vigente: ejecución directa y respaldo eliminado

El desarrollador autorizó trabajar directamente en certificados_morales y pidió
eliminar el respaldo generado. Se eliminó exclusivamente el directorio privado
`storage/app/private/backups/certificados_morales-20261110-230718-ee5ebae7`.
Los enlaces y hashes de respaldo de los apartados históricos ya no representan
un archivo disponible. No se restauró ninguna base ni se instaló software.

Se preparó la migración focalizada
`database/migrations/2026_11_10_232757_enable_session_certificate_stage_a.php`.
Reproduce el SQL mínimo de una sola tabla, con timeout de bloqueo 5 segundos y
sentencia 60 segundos. Laravel/PostgreSQL la ejecuta dentro de una transacción.
El rollback se niega a eliminar la columna si existe alguna sesión registrada
o fecha de emisión NULL; nunca descarta esos datos para forzar una reversión.

El preflight directo confirmó: entorno local, PostgreSQL 15.19,
base certificados_morales, cero certificados/documentos, sin duplicados ni
inconsistencias de persona, claves validadas e índices válidos. Se guardaron
conteos y huellas de datos de los tres esquemas en el preflight existente.

**Impedimento actual: permisos de PostgreSQL.** Laravel usa equipo_dev. El dueño de
certificado.certificado es certificados_morales_owner. equipo_dev no es dueño,
no pertenece a ese rol y no tiene CREATE en el esquema. No se intentó el ALTER
con una cuenta que carece de facultad. No se cambiaron permisos o propietarios.
No se aplicó la migración. La comprobación `--pretend` mostró únicamente su SQL,
sin ejecutar DDL ni otras migraciones.

Con la conexión autorizada del propietario configurada localmente, el comando
exacto para aplicar solo esta migración es:

```powershell
php artisan migrate --database=pgsql --path=database/migrations/2026_11_10_232757_enable_session_certificate_stage_a.php --no-interaction
```

No ejecutar `migrate` sin `--path`. No introducir contraseñas en documentación o
mensajes. Después se comprobarán columnas, FK, CHECK, índice y huellas de datos.
El usuario hará la primera confirmación/remisión manual desde Angular; las
pruebas automáticas nunca crean certificados en la base principal.

El resto de este documento conserva el historial de decisiones y verificaciones.

## Alcance vigente: únicamente código y pruebas aisladas

Por indicación posterior no se crearán bases, no se instalará software y no se
aplicará DDL. El servidor remoto fue consultado en modo READ ONLY: equipo_dev
no dispone de CREATEDB ni de superusuario; solo existen postgres y
certificados_morales. No se intentó restaurar en ellas. La consulta no modificó
datos. El envío de documentos ya registrados usa los contratos existentes.
El registro de certificados nuevos por sesión sigue requiriendo la identidad y
nullable descritas en el SQL mínimo; el código no inventa relaciones o fechas.

La comprobación de bandeja, detalle y recuperación de PDF con las tablas actuales
incluye ahora cero firmantes, además de uno a cuatro. CertificateExistingSchemaTest:
17 pruebas, 231 aserciones. Junto con las 38 pruebas y 512 aserciones de
CertificateManagementStageATest: **55 pruebas aprobadas, 743 aserciones**.
Todas usan SQLite en memoria y almacenamiento simulado. El estado pendiente con
lista vacía muestra asignación pendiente y nunca permite firmar, emitir o entregar.

## Actualización: remisión administrativa sin firmantes configurados

Por indicación expresa, la preparación y las rutas existentes de remisión admiten
cero responsables configurados. No se insertan firmas ficticias. El detalle y la
bandeja devuelven `firmantes: []`, `asignacion_firmantes_pendiente: true`, cero firmas
registradas y una advertencia administrativa. `facultades.firmar`, `emitir` y
`entregar` permanecen en false. Cero firmantes no equivale a firmas completas.
Las asignaciones existentes inválidas o duplicadas siguen produciendo errores.
Si cambia la configuración de firmantes entre revisión y confirmación, se requiere
revisar nuevamente el documento. La asignación posterior no agrega nombres al PDF
ya aprobado: deberá revisarse una versión correcta antes de incorporar firmas reales.

Configuración local explícita: `CERTIFICATION_PREPARATION_SESSION_TYPE_IDS=1`,
reutilizando el ID ya configurado en `CERTIFICATION_DEVELOPMENT_SESSION_TYPE_IDS=1`.
No se dedujo compatibilidad del nombre del catálogo ni se creó un tipo nuevo.
Esta configuración de desarrollo no constituye aprobación de una política municipal.

Se encontraron los clientes oficiales 17.0 en
`%APPDATA%/DBeaverData/drivers/clients/postgresql/win/17`.
Se generó un respaldo completo mediante pg_dump custom, sin excluir esquemas ni
datos. Ubicación privada relativa al proyecto:
`storage/app/private/backups/certificados_morales-20261110-230718-ee5ebae7/certificados_morales.dump`.
Tamaño: 172336 bytes. SHA-256:
`e762200cc4de9942a54f6001aeb23b74f03378f2e60f6b9002701d55891d9457`.
pg_dump terminó con código 0; pg_restore validó el índice y decodificó todos los
bloques. **No se ha probado una restauración en otro servidor**. No hay servidor
PostgreSQL nativo instalado en las ubicaciones inspeccionadas. Docker queda excluido.
Por la condición de respaldo recuperable verificado, **no se aplicó el DDL**.
El informe privado `readiness.json` está en el mismo directorio del respaldo.

La lectura de la base confirmó cero registros en firma, actividad_firma y
certificado_firma; tampoco hay titulares activos del permiso `firmas.firmar`.
No se designaron autoridades basándose únicamente en sus cargos de trabajador.
La falta de responsables ya no impide preparar/remitir administrativamente cuando
esté disponible el esquema mínimo. Sigue pendiente probar la restauración nativa
y aplicar únicamente el SQL autorizado de certificado (sesión, unicidad y fecha
nullable). No se puede declarar activo el envío por sesión en certificados_morales
antes de completar esa condición.

Verificación posterior al ajuste: CertificateManagementStageATest, 38 pruebas y
512 aserciones; CertificateExistingSchemaTest, 16 pruebas y 194 aserciones.
Total: 54 pruebas aprobadas, 706 aserciones. Se ejecutaron secuencialmente con
SQLite en memoria y almacenamiento simulado; no escribieron en la base principal.
El PHP CLI tiene SQLite desactivado en su ini, por lo que se habilitaron las DLL
ya instaladas únicamente para cada proceso de prueba:
`php -d extension=pdo_sqlite -d extension=sqlite3 vendor/phpunit/phpunit/phpunit tests/Feature/CertificateManagementStageATest.php`
y el mismo comando con `tests/Feature/CertificateExistingSchemaTest.php`.
Pint, comprobación de sintaxis y `git diff --check` sin errores.

Para obtener un servidor nativo, la página oficial de PostgreSQL para Windows
ofrece instalador y binarios ZIP: https://www.postgresql.org/download/windows/ .
La instalación queda pendiente de autorización; no se instalaron dependencias.

El apartado siguiente conserva el resultado del preflight anterior a este respaldo.

## Resultado de validación antes de aplicar el cambio

Se recibió autorización condicionada para el bloque mínimo de una tabla y se
inspeccionó PostgreSQL 15.19 mediante una transacción REPEATABLE READ, READ ONLY,
cerrada con rollback. [Evidencia de preflight](certificate-stage-a-preflight.json).
No se aplicó DDL ni se ejecutaron migraciones.

- Certificados, documentos, firmas aplicadas y firmas configuradas: cero registros.
  No se encontraron duplicados por origen/tipo ni inconsistencias de persona o
  actividad en las comprobaciones efectuadas. El digest de certificados corresponde
  a un conjunto vacío; no es un respaldo de la base completa.
- La FK/UNIQUE participación-sesión de asistencia está validada; los índices
  inspeccionados son válidos y listos. No hay colisiones con los nombres propuestos.
  Sigue ausente certificado.id_actividad_sesion y fecha_emision continúa NOT NULL.
- No se encontraron pg_dump/pg_restore en PATH, ubicaciones habituales de Windows,
  instalaciones registradas ni Ubuntu WSL. Docker no tiene motor activo. No se
  generó un backup ni se pudo verificar una restauración en PostgreSQL aislado.
  Por la condición expresa del usuario, se detuvo la operación antes de escribir.
- Adicionalmente, la configuración de tipos compatibles por sesión está vacía
  y no hay filas en firma/actividad_firma. No se inventaron autoridades ni se
  modificó la configuración para simular disponibilidad.

Contratos y recuperación del mismo PDF comprobados con las pruebas aisladas:
CertificateExistingSchemaTest, 16 pruebas/194 aserciones, y nueve pruebas del esquema
mínimo de CertificateManagementStageATest, 9 pruebas/173 aserciones. Total:
**25 aprobadas, 367 aserciones, cero fallos**. Se comprobaron las rutas registradas.
No se crearon certificados en certificados_morales ni se ejecutó el SQL propuesto
en PostgreSQL de pruebas. La verificación SQLite no sustituye una prueba de restauración.

Para continuar se necesita un respaldo que pueda restaurarse y verificarse en un
PostgreSQL aislado compatible. La autorización no se amplió a otras tablas,
migraciones pendientes, datos históricos, Angular, emisión, firma o notificación.

---

## Confirmación por sesión con el esquema mínimo, pendiente de autorización

El código de confirmación por sesión ya puede usar únicamente la referencia de
sesión y `fecha_emision` nullable en certificado, conservando las otras tablas.
**En certificados_morales continúa bloqueado:** el cambio estructural no se ha
aplicado. El [impacto y SQL exacto](certificate-management-minimal-design.md)
están documentados para autorización; no se creó ni ejecutó una migración.

Secuencia implementada cuando el esquema lo admita:

1. La vista previa actual `accion=vista_previa, formato=pdf` no cambia de contrato.
   Esa vista es efímera y no tiene token de confirmación administrativa. No enviar
   su hash al backend como sustituto de una revisión autenticada.
2. Sobre el mismo POST `/api/activity-session/{id}/preparar-certificados`, pedir:
   `{"accion":"revisar_documento","id_participacion":123,"id_tipo_certificado":1,"mensaje":"Mensaje exacto aprobado"}`.
   El servidor reutiliza el generador existente para entregar el candidato con
   código/QR y un `token_revision` opaco de 64 caracteres. Mostrar y revisar este
   PDF: es el que se conservará. Modalidad no se exige ni se inventa en el esquema mínimo.
3. Mismo POST: `{"accion":"confirmar_preparacion","token_revision":"token recibido","confirmar":true}`.
   La confirmación valida el manifiesto cifrado, actor, sesión, elegibilidad,
   mensaje/composición, responsables, caducidad, bytes y hash. No renderiza otro PDF.
   201 al crear, 200 al repetir la confirmación válida. Devuelve
   `id_certificado_documento`, `id_certificado`, `id_sesion`, `hash_pdf`, código,
   `fecha_emision=null`, `estado_flujo=Preparado`, `enviado=false` al crear y
   `mensaje_persistido_en_pdf=true`. El mensaje de confirmación se devuelve desde
   el manifiesto autorizado; no se afirma que exista una nueva columna de mensaje.
4. Con el ID real: POST `/api/certificados-documentos/{id}/revisar-envio`, cuerpo `{}`.
   Verifica el archivo persistido y devuelve los mismos bytes y un nuevo token
   cifrado de remisión, diferente del token opaco de preparación. No intercambiarlos.
5. POST `/api/certificados-documentos/{id}/enviar-gerencia` con
   `{"token_revision":"token de revisar-envio","confirmar":true}`. Conserva el
   PDF y establece `certificado.estado=PendienteFirmas`, sin emisión/notificación.
6. Los GET existentes de bandeja, detalle y archivo resuelven la sesión mediante
   la nueva FK cuando está disponible. Los firmantes pendientes se obtienen por
   actividad_firma y ausencia de aplicación en certificado_firma, sin fechas falsas.

El atajo anterior `accion=enviar_gerencia` con token de preparación no se utiliza
en el esquema mínimo: devuelve 422 y pide completar la secuencia anterior.
Así la remisión siempre usa las rutas de Etapa A y un documento persistente.

La vista previa agrega `puede_revisar_documento_para_firma`,
`motivos_bloqueo_preparacion_persistente` y
`siguiente_accion_preparacion=revisar_documento`. Las capacidades reflejan el esquema
y permisos reales; antes de persistir no se afirma que el documento pueda enviarse.
Si falta identidad de sesión o la fecha de emisión sigue siendo obligatoria, la
revisión/confirmación devuelve 409 y no crea certificado, documento o archivo.
JWT/ámbito/titularidad se conservan; una selección/campo inválido devuelve 422.

El PDF de vista previa ya no muestra la leyenda «Vista previa · Sin emisión ni firma
digital institucional», por solicitud del usuario. Esto no modifica las banderas
de estado de la API ni acredita emisión o firma.

Verificación de esta revisión: **481 pruebas aprobadas, 19 opcionales omitidas,
5914 aserciones y cero fallos**. Ejecución de toda la suite en procesos separados
para los tres archivos con renderizado PDF: 403 casos generales, 26 de generación,
37 de preparación y 34 de demostración/vista previa. Las nueve pruebas añadidas
del esquema mínimo cubren confirmación con 1/2/3/4 firmantes, mismo PDF en Gerencia,
fecha NULL, reintentos, duplicidad, sesiones distintas, otro actor, alteración del
archivo, rollback e impedimentos de esquema. La regresión de la leyenda también
se ejecutó por separado. Pint y whitespace aprobados. No se ejecutaron escrituras,
ALTER TABLE o migraciones sobre certificados_morales; SQLite no acredita la
concurrencia ni la ejecución del SQL propuesto en PostgreSQL.

---

## Contrato vigente con las tablas actuales, sin cambios de esquema

Se implementó `CertificateDocumentWorkflow`, conectado al módulo documental
existente. Las consultas y la remisión descritas en esta sección no dependen de
las columnas propuestas anteriormente. No se crearon ni ejecutaron migraciones.
Las secciones inferiores conservan antecedentes del diseño ampliado y no deben
interpretarse como requisitos de esta consulta/remisión de registros existentes.

El alcance operativo es **documentos ya registrados** de certificados por actividad
con estado `Preparado`, fecha real previamente registrada, tipo activo, PDF
`Vigente`, responsables configurados y ninguna firma aplicada registrada.
No genera otro certificado ni inventa fechas. No convierte certificados `Vigente`
o demostraciones en pendientes, ni notifica al beneficiario.

1. POST `/api/certificados-documentos/{id}/revisar-envio`, cuerpo `{}`. Devuelve
   `data.token_revision` cifrado y autenticado, vinculado a usuario/documento y
   válido por 30 minutos; `data.documento` y `data.vista_previa_pdf` con los bytes
   ya almacenados en base64. No modifica el archivo ni la base.
2. POST `/api/certificados-documentos/{id}/enviar-gerencia`, cuerpo
   `{"token_revision":"valor devuelto al revisar","confirmar":true}`. Valida
   permisos, contexto, configuración de responsables, hash y versión vigente única;
   actualiza únicamente `certificado.estado` a `PendienteFirmas`. Retorna 200 con
   `enviado`, `reintento`, `estado_flujo`, `documento`, `historial_en_bd=false` y
   `fecha_envio_en_bd=null`. No acepta un hash o archivo aportado por el cliente.
3. GET `/api/certificados-documentos/list?bandeja=por_firmar` consulta certificados
   en `PendienteFirmas`/`Parcial` con PDF vigente y ámbito autorizado. Usa paginación
   existente y filtros `id_actividad`/`estado_flujo`.
4. GET `/api/certificados-documentos/detail/{id}` obtiene el contexto y los
   responsables de certificados pendientes. Para revisar otros documentos ya
   registrados mediante el mismo contrato: agregar `?flujo=firmas`.
5. GET `/api/certificados-documentos/{id}/archivo` descarga los mismos bytes,
   comprobando autorización e integridad. `url_documento` incluye `?flujo=firmas`
   para que también pueda consultarse un preparado sin depender del modo de desarrollo.
6. GET `/api/certificados-documentos/{id}/historial` en la estructura actual devuelve
   `disponible=false`, no inventa eventos ni reutiliza decisiones de actividades.

Los datos se resuelven por JOIN entre los tres esquemas funcionales. Beneficiario,
tipo, actividad y cargo no se duplican en columnas nuevas. Los responsables se
consultan desde `actividad_firma → firma → persona_trabajador → persona`; no se
crean filas pendientes en `certificado_firma`, cuya fecha es obligatoria.
La ausencia de aplicación registrada se presenta como `Pendiente`; una fila
existente se muestra `Registrada`, nunca como verificada criptográficamente.
`firmas.verificadas=null` indica que el motor no ha verificado ese resultado.
No se aplica orden de firma ni se deduce una modalidad municipal.

Se rechazan responsables duplicados/inactivos y cambios posteriores a la revisión.
Mientras haya certificados remitidos se protegen altas, cambios y borrados de la
configuración de firmas de esa actividad en la API para conservar el conjunto
aprobado sin duplicarlo. El PDF y la identidad remitidos también quedan protegidos
contra cambios por el CRUD. Estas garantías de aplicación no impiden SQL directo.

La primera remisión escribe el actor, fecha real y hash en el log Laravel
`certificado_documento.remitido_gerencia`. Los reintentos no repiten ese evento.
**El log no equivale a un historial transaccional en PostgreSQL**: el esquema actual
no relaciona remisión/documento/actor/fecha. Si falla el log después del commit,
la respuesta conserva el éxito del estado y añade `auditoria_log=false` y una
advertencia; no afirma que haya historial. No se almacenan fechas de envío en
`fecha_emision`, `fecha_generacion` ni campos de otros orígenes.

No se implementa un alta por sesión con asociaciones ambiguas: las consultas
devuelven `id_sesion=null` y no la deducen desde las asistencias. El esquema sigue
sin una identidad persistente de certificado/sesión y exige fecha de emisión.
La vista previa por sesión original se conserva; no se declara habilitada su
creación administrativa sin emisión con el esquema actual.

Pruebas específicas: `CertificateExistingSchemaTest`, sobre SQLite en memoria,
columnas actuales y almacenamiento falso; sin escritura en certificados_morales.

Validación final de esta implementación: **472 pruebas aprobadas, 19 opcionales
omitidas, cero fallos; 5738 aserciones**. La suite se ejecutó en cuatro procesos
para conservar el límite PHP de 128 MB durante los renderizados de Dompdf:
el resto de la suite (403 casos) y, por separado, CertificateDocumentGenerationTest
(26), CertificateManagementStageATest (28, fixtures del esquema ampliado anterior)
y SessionDemonstrationIssuanceTest (34). Las 16 pruebas específicas del esquema
actual también se ejecutaron de forma independiente y aprobaron (194 aserciones).
Estas últimas no crean columnas propuestas ni aplican migraciones: construyen
únicamente fixtures aisladas en memoria. Se revisaron las rutas reales,
`php vendor/bin/pint --dirty --format agent` y `git diff --check`, aprobados.
No se ejecutó una remisión ni una prueba de escritura contra PostgreSQL institucional.

---

## Antecedente: implementación para un esquema ampliado, no aplicada

La implementación está en Laravel. **No está activada la persistencia en
certificados_morales:** faltan columnas indispensables y la instrucción vigente prohíbe preparar o ejecutar migraciones.
No se han ejecutado ALTER, migraciones, escrituras institucionales, cambios de
Angular, instalación de dependencias, commit ni push.

## Código y condiciones de activación

- `app/Services/CertificateManagement.php`: revisión segura, confirmación,
  persistencia, remisión idempotente, consulta por ámbito e historial.
- `CertificateDocumentGenerator::reviewPdf`: reutiliza Dompdf, plantilla A4,
  escudo y generador QR actuales; no existe otro generador.
- `CertificationReadinessController`: amplía las acciones del endpoint actual.
- `CertificateDocumentController`: reutiliza listado, detalle y archivo; agrega
  historial documental. La opción existente Documentos por firmar usa esa API.
- Modelos de certificado, firma y decisión: relación de sesión/documento y casts.
- Guardas Data: los expedientes preparados se conservan y no se alteran mediante
  CRUD. Las listas genéricas de certificados/documentos/firmas excluyen los nuevos
  expedientes para que no eludan el ámbito del circuito documental.
No existe una migración de Etapa A en el repositorio: se retiró el archivo preparado y sus opciones de ejecución por instrucción expresa del usuario. No se ejecutó en ninguna base. El código no modifica automáticamente el esquema ni sustituye campos ausentes con asociaciones ambiguas.

La configuración de tipos compatibles permanece independiente del esquema:

CERTIFICATION_PREPARATION_SESSION_TYPE_IDS=

Configurar IDs reales de tipos compatibles en `CERTIFICATION_PREPARATION_SESSION_TYPE_IDS`
(lista de enteros separados por comas). No se deduce compatibilidad del nombre
del tipo, ni se heredan los IDs o permisos del modo de demostración. Se reutiliza
la URL de verificación configurada en `CERTIFICATION_PUBLIC_VERIFICATION_BASE_URL`
o la base frontend HTTPS existente.

El servicio comprueba la estructura necesaria antes de escribir. Si falta,
devuelve 409 con explicación; no ejecuta DDL desde HTTP. Las pruebas con fixtures
acreditan el flujo de aplicación, no la aplicación de la migración en PostgreSQL.

## Contratos reales para Angular

Todas las operaciones usan cookie JWT `access_token`, permisos efectivos y ámbito.
Se mantiene `success/message/data`, cabeceras privadas sin caché y el endpoint:

`POST /api/activity-session/{id}/preparar-certificados`

### Angular con el esquema actual de certificados_morales

- Consultar candidatos con `GET /api/activity-session/{id}/beneficiarios`.
- Previsualizar con `POST /api/activity-session/{id}/preparar-certificados` y los campos
  `accion=vista_previa`, `formato=pdf`, `id_participacion`, `id_tipo_certificado` y `mensaje`.
  Verificar el SHA-256 de los bytes Base64 decodificados contra `data.vista_previa_pdf.hash_pdf`.
  Con `siguiente_accion_preparacion=null`, la revisión queda en pantalla y el botón de envío permanece deshabilitado.
- Para un documento persistido, consultar `GET /api/certificados-documentos/detail/{id}?flujo=firmas`.
  Habilitar el botón si `data.facultades.remitir=true`. Llamar a
  `POST /api/certificados-documentos/{id}/revisar-envio` y mostrar el PDF que devuelve.
  Al confirmar, llamar a `POST /api/certificados-documentos/{id}/enviar-gerencia` con
  `token_revision` y `confirmar=true`. Refrescar el detalle con el estado recibido.
- En Gerencia usar `GET /api/certificados-documentos/list?bandeja=por_firmar` y abrir el PDF con
  `GET /api/certificados-documentos/{id}/archivo?flujo=firmas`. Todas estas rutas usan la cookie JWT y el ámbito del usuario.

### 1. Vista previa existente

`accion=vista_previa, formato=pdf` genera un PDF temporal con su SHA-256.
Cuando el esquema permite registrar la sesión, la siguiente acción devuelve
los bytes que se podrán confirmar, con código y QR reservados.

### 2. Revisar el archivo que será guardado

```json
{
  "accion": "revisar_documento",
  "id_participacion": 123,
  "id_tipo_certificado": 1,
  "mensaje": "  Mensaje aprobado exacto.\nSegunda línea.  ",
  "modalidad_firma": "Paralela"
}
```

Los IDs son ejemplos. Se requiere sesión `Finalizada`, participación `Activo`,
asistencia `PRESENTE` de esa pareja, actividad con solicitud aprobada y tipo activo
configurado. `modalidad_firma` admite `Paralela` o `Secuencial`, sin valor implícito;
se registra la selección de quien prepara, no se impone una norma municipal.
La Etapa A no ejecuta ningún orden de firmas.

200: `data.accion=revisar_documento`, `modo=administrativo`, `token_revision`,
`expires_at`, `mensaje`, `hash_pdf`, `firmantes`, `persistido=false`, `emitida=false`
y `vista_previa_pdf={contenido_base64,tipo_contenido,nombre_archivo,persistido:false}`.
Mostrar y revisar este PDF. Mantener el token en memoria del cliente; no publicarlo
ni incluirlo en URLs/logs. Se conserva en el servidor un manifiesto cifrado y
autenticado, vinculado al usuario y sesión, y el PDF privado. Caduca a los 30 minutos
para crear un expediente. No existe aún un registro administrativo en BD.

El mensaje exige texto plano, hasta 1200 caracteres, conserva espacios y saltos;
el renderizado exige una página. No se recorta contenido. Los responsables proceden
de actividad_firma → firma → trabajador/persona vigentes, con cargos reales.
No se fija cantidad dos: una, dos, tres, cuatro o más según espacio. Asignaciones
inválidas se rechazan, no se omiten. La duplicación de persona devuelve 409.

### 3. Confirmar y enviar en una sola operación

```json
{
  "accion": "enviar_gerencia",
  "token_revision": "token opaco de 64 caracteres hexadecimales devuelto por el servidor",
  "confirmar": true
}
```

201 al crear y remitir; 200 en reintentos. No reenviar mensaje, hash, firmantes,
PDF, ruta, código o actor: se rechazan campos extra con 422. El hash mostrado
en la revisión es informativo y no sustituye el token. El servidor valida el
manifiesto, titular, sesión, caducidad, hash de los bytes y contexto/firmantes
actuales. No vuelve a generar el PDF durante confirmación o envío.

En una transacción se guardan: certificado con sesión concreta y fecha_emision NULL;
documento con mensaje exacto/código/hash/ruta privada; N responsables con
`estado_firma=Pendiente` y `fecha_firma=NULL`; eventos PREPARAR y REMITIR con actor y
fecha reales. El estado final del documento es `PendienteFirmas` (etiqueta UI
«Pendiente de firma»); certificado sigue `Preparado`, no `Vigente`/emitido.

Respuesta `data`: `modo`, `accion`, `creado`, `enviado`, `reintento`,
`id_certificado`, `id_certificado_documento`, `codigo_verificacion`, `hash_pdf`,
`estado`, `estado_flujo`, `estado_certificado`, `fecha_emision=null`, `emitida=false`,
`firma_verificada=false`, `mensaje_aprobado`, `modalidad_firma`, `contexto`,
`firmantes`, `firmas={requeridas,pendientes,verificadas:0}`, `remision`, `historial`,
`url_documento` relativa y `facultades`.

`contexto` conserva identidad de beneficiario, tipo, actividad y sesión; `firmantes`
incluye id_certificado_firma, id_firma, id_persona, nombre_aprobado, cargo_aprobado,
orden_firma, estado_firma y fecha_firma. Cada evento identifica documento, actor,
tipo, transición y fecha ISO 8601. No se revelan token, ruta privada ni credenciales.

### Alternativa: guardar Preparado y remitir después

Con el mismo token: `accion=confirmar_preparacion, token_revision, confirmar=true`.
201/200 con el documento `Preparado` y evento PREPARAR; todavía sin REMITIR.
Después, sobre el mismo endpoint y sesión:

```json
{
  "accion": "enviar_gerencia",
  "id_certificado_documento": 456,
  "confirmar": true
}
```

200: remite el documento ya guardado. Repetir no duplica documento, firma o evento;
devuelve el actor/fecha originales de REMITIR. La referencia por documento y el
token son mutuamente excluyentes. El payload antiguo basado solo en mensaje y
huella no se convierte en una remisión válida.

## Gerencia: mismo documento y misma bandeja

| Operación | Endpoint |
| --- | --- |
| Documentos por firmar | GET `/api/certificados-documentos/list?bandeja=por_firmar` |
| Detalle | GET `/api/certificados-documentos/detail/{id}` |
| Archivo privado | GET `/api/certificados-documentos/{id}/archivo` |
| Historial | GET `/api/certificados-documentos/{id}/historial` |
| Verificación QR | GET `/api/certificados/verificar/{code}` |

Listado paginado: `page`, `per_page` (1–100), opcionales `id_actividad` y
`estado_flujo=PendienteFirmas|Parcial`. Solo incluye remitidos vigentes en el ámbito.
`data` contiene el paginador y sus elementos con los datos de detalle anteriores.
Firmantes e historial se consultan en lote para la página. Los GET no crean eventos.
Historial de A tiene dos eventos por remisión; no se mezclan con solicitudes de
actividad, que conservan sus FK/filtros/estados anteriores.

La descarga lee el archivo registrado y comprueba SHA-256 y almacenamiento privado;
no regenera. El nombre se forma con el código. Requiere `emision.ver` y ámbito.
Preparar exige además `emision.crear`, `actividades.ver`, `participantes.ver`;
remitir exige `emision.editar`. La condición de administrador no concede permisos
de operación ausentes. Los demás usuarios se limitan a sus áreas laborales vigentes.
Un revisor de Gerencia necesita los permisos de consulta y ámbito correspondientes;
no se le otorga acceso por el texto del nombre de su rol.

El QR conserva un código documental único. En A muestra que existe un trámite,
sin exponer beneficiario o PDF al público: `puede_ver_documento=false`,
`firma_verificada=false`, emisión oficial falsa. No habilita descarga con `archivo=1`.

## Integridad, reintentos y errores

La FK compuesta y el índice de participación/sesión/tipo fijan identidad. Locks
de filas y creación exclusiva de archivo evitan sobrescrituras. Un token ya
confirmado recupera el mismo documento aun después de caducar para nuevas altas;
otra revisión para la misma identidad devuelve 409. Se conserva el manifiesto
privado para ese reintento; no se ha instalado una tarea automática de depuración
de temporales en este cambio.

Fallo conocido: rollback de todas las filas y eliminación solo del archivo nuevo
creado por esa operación. Archivo preexistente nunca se borra. Commit incierto:
se conserva el archivo para conciliación, reutilizando el tratamiento ya existente.
No hay transacción distribuida entre disco y PostgreSQL.

401 sin JWT válido; 403 sin permisos o titular de token; 404 documento fuera de
ámbito; 409 falta de esquema, revisión caducada/alterada/cambiada, duplicidad o
transición inválida; 422 campos, tipo, mensaje o espacio PDF inválidos; 503 fallo de
almacenamiento. Infraestructura inesperada devuelve error genérico sin secretos.

## Etapa B

Estados preparados en esquema/código: Preparado → PendienteFirmas → Parcial →
Firmado → Emitido. A solo ejecuta las dos primeras etapas y conserva los datos
necesarios para el proveedor. `firmar/emitir/entregar=false`; no hay endpoint que
simule esos resultados. El CRUD no puede modificar el PDF o emitir estos registros.
La firma manual del módulo existente sigue denegada, incluida la utilización de
PFX ajeno por un administrador. No se instalaron motor, TSA ni firmas gráficas.

B deberá aplicar revisiones incrementales, validar firma nueva y anteriores,
conservar PDF aprobado y revisiones, y actualizar el hash del archivo actual en
una transacción con evidencia. No debe cambiar el código ni regenerar el certificado.
El diseño B anterior sigue siendo propuesta; no se incluyeron sus columnas en A.

## Pruebas

`CertificateManagementStageATest` usa SQLite adjunto en memoria, disco falso,
PDF real y JWT real en un caso. Cubre identidad, bytes/hash/mensaje, cuatro
cantidades de firmantes, estados NULL, duplicados, reintentos, manipulación,
caducidad, cargo/contexto cambiados, ámbito, permisos, fallo de almacenamiento,
rollback después de crear archivo, preservación de archivo preexistente y guardas
de CRUD. PostgreSQL y concurrencia entre conexiones independientes no
se acreditan con SQLite. No se ejecutó SQL de migración en ninguna base.
