# Vista previa PDF auténtica con mensaje personalizado

> Confirmación por sesión implementada condicionalmente: consultar la secuencia
> `revisar_documento → confirmar_preparacion → revisar-envio → enviar-gerencia`
> en [Etapa A](certificate-stage-a-api-contract.md). La base actual sigue sin sesión
> persistente y exige fecha de emisión; el [SQL mínimo no aplicado](certificate-management-minimal-design.md)
> describe únicamente el cambio indispensable sobre certificado.

> Etapa A implementada en código: [contratos actuales, activación y límites](certificate-stage-a-api-contract.md).
> La persistencia requiere columnas actualmente ausentes; no se preparan ni ejecutan migraciones por instrucción vigente.

> Auditoría de firma y Gerencia del 10 de noviembre de 2026:
> [resultado, contratos y bloqueos reales](certificate-signing-flow.md).
> El envío oficial y la firma criptográfica siguen sin habilitarse; el inventario
> enlazado confirma el esquema real mediante una transacción de solo lectura.

## Contrato para Angular

Se reutiliza `POST /api/activity-session/{id}/preparar-certificados`, protegido por el JWT en cookie HttpOnly, permisos de consulta/preparación y ámbito de la actividad. `{id}` y la participación son identificadores reales seleccionados, nunca constantes del servidor.

```json
{
  "accion": "vista_previa",
  "formato": "pdf",
  "id_participacion": 123,
  "id_tipo_certificado": 1,
  "mensaje": "Por su participación en la actividad y sesión seleccionadas, organizada por la Municipalidad Distrital de Morales."
}
```

Los números del ejemplo se reemplazan con los devueltos por la consulta de beneficiarios. El tipo puede omitirse únicamente si hay un solo tipo activo permitido por el backend. `mensaje` es opcional: su ausencia conserva el texto automático anterior. Si está presente debe tener entre 1 y 1200 caracteres, no ser blanco y ser texto plano, sin etiquetas HTML, scripts ni caracteres de control peligrosos. Se admiten Unicode, tildes, saltos de línea, tabulaciones y espacios. No se recortan los espacios del mensaje. La salida Blade escapa el texto; Dompdf deshabilita PHP, JavaScript y recursos remotos. Un mensaje que provoque más de una página devuelve 422, sin truncarlo. `formato` omitido o `html` conserva el contrato previo de HTML; `pdf` renderiza un PDF A4 horizontal verdadero.

Respuesta JSON existente `success/message/data`, con estos campos adicionales en `data`:

```json
{
  "accion": "vista_previa",
  "emitida": false,
  "creado": false,
  "mensaje": "El texto recibido, sin modificar",
  "mensaje_persistido": false,
  "formato": "pdf",
  "vista_previa_pdf": {
    "contenido_base64": "JVBERi0...",
    "tipo_contenido": "application/pdf",
    "nombre_archivo": "vista-previa-certificado.pdf",
    "persistido": false
  }
}
```

También se conservan `contexto`, `vista_previa_html`, capacidades y motivos de bloqueo. El nombre, tipo, actividad, sesión y fecha proceden de las relaciones del servidor. Con mensaje personalizado se muestra una línea factual con actividad/sesión/fecha, separada del cuerpo libre; el mensaje no cambia identidades, elegibilidad ni estado. La respuesta usa `Cache-Control: private, no-store`. No se crea URL temporal, archivo persistente, registro, fecha de emisión, código ni QR. La indicación inferior identifica el PDF como vista previa sin emisión ni firma. Los responsables se consultan mediante actividad_firma → firma → trabajador vigente → persona; sin responsables configurados quedan líneas vacías, sin imágenes de firmas.

Angular debe solicitar JSON, decodificar el PDF y mostrar un Blob, por ejemplo:

```ts
const bytes = Uint8Array.from(atob(respuesta.data.vista_previa_pdf.contenido_base64), c => c.charCodeAt(0));
const blob = new Blob([bytes], { type: 'application/pdf' });
const url = URL.createObjectURL(blob);
// Usar url en el visor o descarga y llamar URL.revokeObjectURL(url) al cerrar.
```

No se modificó Angular. Su editor debe conservar `mensaje` y reenviarlo al editar; el backend lo devuelve exactamente, pero no guarda borradores entre solicitudes ni recargas. El PDF se obtiene en la misma respuesta: no existe un GET para recuperar una vista previa no almacenada. Puede descargarse desde el Blob con el nombre proporcionado.

## Secuencia real y límites de persistencia

1. Consultar `GET /api/activity-session/{id}/beneficiarios?id_tipo_certificado={tipo}` y seleccionar una participación elegible.
2. Componer el mensaje en el editor y pedir `accion=vista_previa, formato=pdf` con esa selección.
3. Editar y repetir la misma operación con el texto actualizado. No se guarda ni emite nada en esos pasos.
4. La confirmación `accion=emitir_demostracion, confirmar=true` permanece bloqueada con 409 en PostgreSQL actual: falta la relación inequívoca certificado/sesión. Además, enviar `mensaje` o `formato` a esa operación devuelve 422; no se acepta un texto que el emisor no pueda conservar. La operación antigua sin `accion` también los rechaza, evitando descartarlos silenciosamente.
5. Para certificados ya registrados existen `GET /api/certificados/detail/{id}`, `GET /api/certificados-documentos/list?id_certificado={id}` y `GET /api/certificados-documentos/{idDocumento}/archivo`. Ninguna de estas rutas convierte una vista previa en un documento emitido.

Se comprobó de nuevo el esquema real de las nueve tablas solicitadas en una transacción PostgreSQL READ ONLY, cerrada mediante rollback: certificado y certificado_documento no tienen una columna semántica para el cuerpo personalizado. `actividad.descripcion` describe la actividad; `motivo_reemision` explica una reemisión. No se utilizaron para guardar mensajes, tampoco rutas, fechas, campos de firmas u otros orígenes. Certificado tampoco almacena la sesión. Conteos: cero certificados y cero documentos, sin emisiones automáticas ni cambios de estructura/datos.

`POST /api/certificados/create` registra un certificado administrativo con exactamente uno de los orígenes existentes, previa autorización y validación; no genera un PDF ni constituye firma oficial. `POST /api/certificados/{id}/generar-documento` sigue aceptando exclusivamente `{}` y requiere que ese certificado exista. Actualmente su generador solo soporta participación global en modo de desarrollo (y el flujo de demostración de sesión condicionado a una estructura que no existe en la base actual). No crea certificados nuevos, no recibe un mensaje personalizado y no debe usarse como confirmación del PDF previo. Los CRUD de prácticas y reconocimientos conservan sus orígenes; este generador rechaza sus documentos con 409 y no les aplica una plantilla de capacitación. No se afirma que exista aquí una emisión documental institucional de esos orígenes ni una nueva plantilla de reconocimiento terminada.

El generador persistente existente conserva código aleatorio de 120 bits, QR de URL configurable, PDF privado en `storage/app/private/certificados/desarrollo/`, hash SHA-256 del archivo, reutilización del mismo documento ante reintentos y recuperación segura frente a fallos. No genera QR para esta vista previa. La verificación existente `GET /api/certificados/verificar/{code}` consulta documento y certificado, estados y archivo/hash; no considera acreditado un borrador de desarrollo y no equipara integridad a firma criptográfica. `APP_URL`, `FRONTEND_URL` y `CERTIFICATION_PUBLIC_VERIFICATION_BASE_URL` permanecen configurables. Un QR con localhost solo apunta al propio dispositivo: para un teléfono se requiere dirección LAN accesible permitida en desarrollo o un dominio HTTPS público estable en despliegue.

Validación de esta entrega: 10 casos nuevos, archivo afectado 30/30 aprobado; suite completa 442 casos, 423 aprobados, 19 omitidos opcionales existentes, 5201 aserciones, cero fallos. Se comprobaron dos ediciones de un mensaje Unicode dentro de PDFs reales (contenido de los flujos PDF descomprimidos), identidad/tipo/sesión, rechazo de HTML, longitudes, permisos/ámbito, ausencia de persistencia y desbordamiento de página. Se mantuvieron las pruebas existentes de JWT, reintentos, duplicados, múltiples asistencias, PDF almacenado, códigos y verificación. El primer intento completo detectó agotamiento de memoria de 128 MB; se corrigió liberando referencias de prueba y recolectando ciclos antes del renderizado. La ejecución final usó el mismo límite original, sin aumentarlo. Pint y `git diff --check` aprobados. No se modificó Angular, instalaron dependencias, ejecutaron emisiones en PostgreSQL ni hizo commit/push.

---

# Unicidad por origen: diagnóstico del 10 de noviembre de 2026

Se consultaron índices y restricciones de `certificados_morales` en una transacción PostgreSQL `REPEATABLE READ, READ ONLY`, cerrada mediante rollback. Conteos leídos: cero certificados y cero documentos. No se escribió en PostgreSQL ni se ejecutaron migraciones.

| Origen | Identidad validada en Laravel | Garantía UNIQUE actual de PostgreSQL en certificado |
| --- | --- | --- |
| Actividad global | participación + tipo; persona debe corresponder a la participación | Ninguna, salvo la clave primaria |
| Práctica | práctica + tipo; persona debe corresponder al practicante | Ninguna, salvo la clave primaria |
| Reconocimiento | reconocimiento + tipo; persona debe corresponder al trabajador | Ninguna, salvo la clave primaria |
| Sesión | requeriría participación + sesión + tipo | No representable: certificado no almacena la sesión; emisión bloqueada con 409 |

Laravel valida exactamente un origen, comprueba la identidad del beneficiario y rechaza un duplicado con HTTP 409, incluso si cambian fecha o estado. Una anulación no libera la identidad: no se permite evadir la regla creando otro certificado. Distintos orígenes o tipos pueden pertenecer a una misma persona. Los bloqueos de las relaciones existentes se toman dentro de la transacción antes de comprobar e insertar; las operaciones de alta y actualización reintentan hasta tres veces errores de serialización o interbloqueo. La garantía depende de que todas las escrituras utilicen este flujo y del aislamiento PostgreSQL habitual READ COMMITTED; no sustituye una restricción UNIQUE frente a SQL directo u otros escritores.

La comprobación genérica separa certificados globales de los de sesión si alguna vez existiera la relación autorizada. No se habilitó esa estructura: su compatibilidad se prueba únicamente en memoria. Los certificados históricos de participación sin sesión nunca se atribuyen a una sesión por inferencia; el emisor condicional exige revisar esa ambigüedad antes de emitir otro certificado.

PostgreSQL sí garantiza actualmente: participación única por persona y actividad; asistencia única por participación y sesión; exactamente un origen no nulo por certificado; claves foráneas; códigos y hashes únicos por documento. Ninguna de estas restricciones impide por sí sola repetir un certificado del mismo origen y tipo.

El cambio mínimo indispensable para certificados independientes por sesión sería una referencia nullable a la sesión en la tabla existente certificado, una FK compuesta hacia la pareja participación/sesión de asistencia y un índice UNIQUE parcial de participación/sesión/tipo. El SQL exacto, con CHECK de coherencia del origen, permanece íntegramente comentado en [session-certification-schema-proposal.sql](session-certification-schema-proposal.sql). Es una propuesta técnica no autorizada y no aplicada. No requiere tabla de preparaciones ni campos de actividad/asistencia redundantes. Los históricos permanecerían con sesión NULL. Los orígenes global, práctica y reconocimiento seguirían requiriendo sus propios índices parciales si se quisiera imponer también su unicidad frente a cualquier escritor SQL; no se crearon índices.

Continúan operativas para Angular la consulta dinámica paginada de beneficiarios, la vista previa sin persistencia y las consultas y operaciones existentes de los otros orígenes. Se mantienen JWT, permisos, ámbito y filtros de asistencia/estado/tipo. No se crean certificados al registrar asistencia o finalizar sesiones. El flujo de sesión no está disponible para emitir en la base actual, aunque el generador PDF/QR esté implementado.

Se añadieron siete casos ejecutados: unicidad y conflictos de actualización en los tres orígenes; múltiples orígenes para una misma persona; separación global/sesión en una estructura aislada; recuperación ante fallo de serialización en alta y actualización. La inyección controlada de error prueba rollback/reintento, no concurrencia real entre conexiones PostgreSQL. No se ejecutaron escrituras concurrentes sobre la base institucional. Se conservan las pruebas existentes de ausentes, sesiones abiertas, dos beneficiarios, dos sesiones, reintentos, códigos, PDF, JWT, permisos y paginación. Los escenarios de emisión exitosa por sesión usan exclusivamente una estructura alternativa en SQLite en memoria y no describen una capacidad disponible en PostgreSQL actual.

Validación final: conjunto afectado 70/70 aprobado; suite completa 432 casos, 413 aprobados, 19 omitidos opcionales existentes, 5127 aserciones y cero fallos. Comando: `php -d extension=pdo_sqlite vendor/bin/phpunit --no-progress`, con `ACTIVITY_REQUESTS_TEST_EXCLUSIVE=false`. Pint y `git diff --check` aprobados. No se instalaron dependencias, modificó Angular ni hizo commit/push.

---

# Decisión vigente: estructura existente sin cambios

El usuario rechazó añadir identificadores o modificar la estructura. No se autoriza ni se ejecutó ningún ALTER TABLE, tabla, columna, restricción, migración o emisión de certificados durante esta revisión. La propuesta de SQL quedó íntegramente comentada y desactivada en `docs/session-certification-schema-proposal.sql`; no debe aplicarse. Las solicitudes de autorización y propuestas de las secciones históricas siguientes quedan retiradas y no constituyen permiso.

Continúan disponibles la consulta dinámica de beneficiarios y la vista previa formal sin registros. La emisión independiente por sesión permanece bloqueada en la conexión actual: certificado solo identifica la participación y no distingue sus distintas sesiones. No se guardará la sesión en campos de prácticas, reconocimientos, fechas, rutas o JSON. No se atribuirá un certificado de participación a una sesión por coincidencia. Las pruebas aisladas de una estructura alternativa no autorizan ni describen una modificación realizada en PostgreSQL.

La emisión por participación/actividad sería un alcance diferente y no se sustituye silenciosamente por la emisión por sesión solicitada. No se crearán certificados automáticamente ni se cambiarán datos para eludir esta limitación.

---
# Flujo de certificados de demostración por sesión — revisión vigente

Esta sección sustituye las conclusiones anteriores sobre el flujo pendiente. El backend de demostración está implementado y probado con persistencia aislada. **La emisión en certificados_morales sigue bloqueada porque falta autorizar y aplicar la relación estructural mínima. No se ejecutó ALTER TABLE, una migración ni una emisión real durante esta revisión.**

## Resultado de la comprobación real

Se consultó la conexión actual PostgreSQL `certificados_morales` en una transacción `REPEATABLE READ, READ ONLY`, comprobando nombre de base y `transaction_read_only=on`, con rollback final. La consulta dinámica encontró dos beneficiarios PRESENTE y Activo en una sesión Finalizada. El tipo compatible se resolvió desde el catálogo y la política del backend, sin fijar una sesión en el código.

Se generaron dos PDF privados de vista previa con los datos reales, usando la misma plantilla formal del flujo. Ambos ocupan una página, no llevan código ni QR, no tienen fecha de emisión inventada y no crearon registros. La última lectura confirmó **0 certificados y 0 documentos**. Evidencia: `demonstration_readonly_validation` en `docs/certification-schema-audit.json`.

Archivos locales para revisar:

- `storage/app/private/certificados/vistas-previas/vista-previa-1.pdf`
- `storage/app/private/certificados/vistas-previas/vista-previa-2.pdf`

La auditoría de vista previa ejercita servicios y lecturas reales; no se presenta como una llamada HTTP con la sesión de un funcionario. JWT, permisos y ámbito sí se comprueban en las pruebas HTTP aisladas y en el controlador de las operaciones reales.

## Qué quedó implementado y dónde se almacena cada dato

| Dato o comportamiento | Fuente/persistencia |
| --- | --- |
| Persona beneficiaria | `gestion.persona`, identificada por `participacion.id_persona`; nunca se acepta un ID de persona fabricado en la confirmación. |
| Participación y actividad | `certificado.participacion` → `certificado.actividad`. Se comprueba pertenencia de la participación a la actividad de la sesión. |
| Candidatura por sesión | `actividad_sesion` → `asistencia` → `participacion`, sesión Finalizada, participación Activo, asistencia PRESENTE sin duplicados y política/tipo compatibles. |
| Tipo y título | `certificado.tipo_certificado`, vigente y permitido por la configuración backend. Se resuelve automáticamente si solo hay uno; con varios compatibles se exige selección por ID. No se recibe el nombre del título desde Angular. |
| Vista previa | HTML formal, sin escritura de certificados, preparaciones, códigos, QR ni documentos. No crea una fecha de emisión. |
| Certificado de demostración | `certificado.certificado`: persona, participación, tipo, fecha real del servidor al confirmar y `estado=Demostracion`. La propuesta añade únicamente `id_actividad_sesion`. |
| PDF y metadatos | Archivo real privado en `certificados/demostracion/{codigo}.pdf` y fila de `certificado.certificado_documento`: código, SHA-256, ruta, fecha de generación, certificado y estado `Vigente`. Vigente describe la versión documental; no una firma oficial. |
| Identidad de sesión | Columna propuesta `certificado.id_actividad_sesion` y FK compuesta a la asistencia real. No se oculta en nombres de archivo, JSON, fecha, práctica o reconocimiento. |
| Firmantes visuales | `certificado.actividad_firma` → `certificado.firma` → `gestion.persona_trabajador` → `gestion.persona`. Solo trabajadores activos y vigentes para los responsables asignados a esa actividad. No se leen contraseñas/PFX para dibujar líneas. |
| Firma criptográfica | No integrada. No se crean filas de `certificado_firma` al reservar una línea ni se afirma FIRMADO. |

Se conserva el flujo previo de consulta y preparación evaluada cuando no se envía una acción nueva. No se creó una tabla de preparaciones ni una base de datos adicional. No se modificó Angular ni se instalaron dependencias nuevas en esta revisión: se reutilizaron Dompdf 3.1.6 y BaconQrCode 3.1.1.

## Acciones reales del endpoint existente

Se conserva:

`GET /api/activity-session/{id}/beneficiarios?id_tipo_certificado={tipo}`

`POST /api/activity-session/{id}/preparar-certificados`

El POST admite ahora dos acciones explícitas. Los siguientes son objetos TypeScript que Angular puede serializar a JSON con los valores reales de su selección; las variables no son valores literales ni registros de ejemplo.

Vista previa:

```typescript
{
  accion: 'vista_previa',
  id_participacion: beneficiario.id_participacion,
  id_tipo_certificado: tipoSeleccionado.id_tipo_certificado
}
```

`id_tipo_certificado` puede omitirse cuando hay exactamente un tipo vigente compatible configurado. La respuesta es HTTP 200 con `data.vista_previa_html`, contexto real, `emitida=false`, `oficial=false` y `puede_emitir_demostracion`. La vista previa funciona aunque falte el cambio estructural, pero entonces la capacidad de emisión es false y se explica la limitación.

Emisión solo después de la confirmación del usuario:

```typescript
{
  accion: 'emitir_demostracion',
  id_participacion: beneficiario.id_participacion,
  id_tipo_certificado: tipoSeleccionado.id_tipo_certificado,
  confirmar: true
}
```

No enviar `id_persona`, actor, fecha de emisión, código, hash o ruta. El servidor los determina y rechaza esos campos. Se aceptan opcionalmente `id_sesion` e `id_actividad` para contrastar contexto, pero deben coincidir con el recurso solicitado. La ausencia de confirmación o `confirmar=false` devuelve 422 sin escribir. Enviar `confirmar` durante una vista previa también se rechaza.

Con la relación estructural y sus restricciones verificadas, la primera emisión devuelve HTTP 201; un reintento recuperable devuelve HTTP 200, `creado=false`, el mismo certificado, documento, código y PDF. Devuelve `modo=demostracion`, `demostracion=true`, `emitida=true`, `oficial=false`, IDs reales, SHA-256 y URLs. Sin esquema autorizado disponible devuelve 409 sin crear nada. Un certificado histórico de participación sin sesión atribuible exige revisión antes de otra emisión; nunca se le asigna una sesión por coincidencia.

Las capacidades se mantienen separadas: `puede_consultar_beneficiarios`, `puede_preparar` y `puede_emitir_demostracion`. `puede_emitir=false` y `puede_firmar=false` siguen describiendo las operaciones institucionales no integradas. Tener una candidatura elegible no significa que exista un certificado. Cuando existe una asociación persistente inequívoca, la consulta recupera los IDs reales de certificado y documento de esa sesión; los históricos con sesión null no se muestran como emitidos para ella.

Se conservan JWT HttpOnly, permisos efectivos y ámbito. La vista previa requiere los permisos del flujo administrativo; la confirmación exige también crear documentos. La interfaz Angular no fue modificada: debe consumir estas acciones/payloads para mostrar el botón de confirmación; no se afirma que sus componentes ya lo hagan automáticamente.

## PDF formal y verificación auténtica

La nueva plantilla `certificates.demonstration` tiene escudo auténtico, Municipalidad, tipo arriba a la derecha, título central, beneficiario destacado, un único párrafo con actividad/sesión/fecha reales, lugar opcional y líneas inferiores de firma. La fecha de emisión solo aparece después de registrar el certificado. No imprime elegibilidad, asistencia, estados técnicos, datos ausentes ni nombres o firmas inventados. La indicación discreta de demostración permanece en el PDF.

El QR de una demostración apunta por defecto a la ruta pública real de Laravel construida desde `APP_URL` y el nombre de ruta existente. Esto permite verificar sin modificar el componente Angular `/verificar/:codigo`, que actualmente tiene su consulta deshabilitada. `CERTIFICATION_PUBLIC_VERIFICATION_BASE_URL` permite elegir otra base estable cuando exista una integración frontend.

- `GET /api/certificados/verificar/{codigo}` con Accept application/json devuelve metadatos reales.
- La misma URL en el navegador devuelve una página de verificación con enlace/visor del PDF almacenado. No es un endpoint duplicado.
- `GET /api/certificados/verificar/{codigo}?archivo=1` sirve el PDF almacenado cuando registro, identidad de sesión, estados e integridad permiten consultarlo. No lo regenera.
- `GET /api/certificados-documentos/{id}/archivo` conserva la recuperación autenticada y sus controles.

Se consulta documento → certificado y, para demostración, certificado → participación/sesión/asistencia/actividad/persona/tipo. La verificación comprueba el archivo real y su SHA-256. Código inexistente o documento huérfano: 404. Archivo ausente/corrupto, identidad incoherente o documento anulado: no se permite abrir el PDF. La versión JSON conserva el estado real y explica el resultado. Devuelve tipo, actividad, sesión y fecha cuando están verificadas; el nombre público del beneficiario está deshabilitado por defecto y solo se incluye con `CERTIFICATION_PUBLIC_BENEFICIARY_NAME=true`. No expone DNI, ID de persona, rutas físicas, claves o PFX. El PDF contiene el nombre del beneficiario autorizado y se recupera mediante su código no predecible.

Una demostración verificable puede tener `puede_ver_documento=true`, `integridad_verificada=true` y `vigente=false`: se reconoce como demostración, no como acreditación institucional. `demostracion=true`, `estado_certificado=Demostracion`, `firma_verificada=false` y `emision_oficial_verificada=false` son explícitos. La integridad no se equipara a firma criptográfica.

No se permite convertir una demostración en institucional con un PATCH de estado ni crear ese estado reservado mediante el CRUD genérico. Código, archivo, hash y fecha documental de una demostración son inmutables; puede anularse la versión documental conservando su identidad y no reactivarse por el CRUD. La anulación no autoriza un segundo certificado para la misma identidad. No se alteran documentos históricos ni estados de prácticas/reconocimientos.

## Duplicados, archivos y fallos

La emisión bloquea sesión, actividad, participación y asistencia dentro de una transacción y consulta la identidad participación/sesión/tipo antes de crear. La restricción UNIQUE propuesta protege también frente a escrituras concurrentes o que eludan el controlador. El código usa 120 bits aleatorios y el UNIQUE real de `certificado_documento.codigo_verificacion`; el hash también tiene UNIQUE real.

Se reutiliza el generador y el archivo se crea de forma exclusiva (`x+b`), sin sobrescribir documentos. Certificado y documento se confirman conjuntamente; si falla la inserción del documento, se revierte el certificado y se elimina el PDF creado. Si se pierde la respuesta al COMMIT y su resultado es indeterminado, se conserva el archivo privado para conciliación en lugar de borrar un PDF potencialmente confirmado; la operación devuelve error, no una emisión exitosa. Un reintento consulta la identidad persistente. Un archivo huérfano privado no es verificable ni aparece como documento emitido. No se borran archivos previos durante la recuperación.

Se corrigió la liberación de recursos de Dompdf tras cada renderizado: la suite completa agotaba el límite PHP de 128 MB al generar varios PDF consecutivos. La suite ahora pasa con el mismo límite; no se elevó ni se ocultó el fallo.

## Firmantes y configuración de URLs

Para mostrar responsables reales, utilizar los módulos existentes: firma válida vinculada a trabajador real y asignación en actividad_firma con su orden. El nombre proviene de persona y el cargo de persona_trabajador. Si no hay responsables configurados, se mantienen dos líneas vacías. No se insertan firmas aplicadas ni imágenes de firma. En la lectura actual no hay firmas configuradas; no se atribuyeron nombres ficticios.

Se conservan los valores locales solicitados:

```dotenv
APP_URL=http://127.0.0.1:8000
FRONTEND_URL=http://localhost:4200
CERTIFICATION_PUBLIC_VERIFICATION_BASE_URL=
CERTIFICATION_ALLOW_HTTP_LAN_VERIFICATION=false
CERTIFICATION_PUBLIC_BENEFICIARY_NAME=false
```

Para verificar desde un teléfono, configurar la base pública con la IP LAN real del equipo y la ruta `/api/certificados/verificar`, habilitar `CERTIFICATION_ALLOW_HTTP_LAN_VERIFICATION=true` solo en local y exponer Laravel a esa interfaz mediante el comando de arranque correspondiente y las reglas de red del equipo. No se adivinó ni guardó una IP de red ficticia en `.env`. En producción se exige HTTPS y un dominio público; el permiso de HTTP LAN no se aplica allí. La emisión de demostración está restringida al entorno de desarrollo y conexión verificada autorizada.

Los códigos se conservan en PostgreSQL sin incluir el dominio en su identidad. Una URL nueva puede consultar el mismo código y PDF. El QR ya impreso conserva la URL original: para que siga abriendo al cambiar dominio, mantener una ruta/dominio estable o una redirección del origen anterior; no se reescribe el PDF histórico ni su hash.

## Cambio estructural mínimo pendiente de autorización

Es indispensable una relación adicional: `id_participacion` por sí solo no identifica cuál de las varias asistencias/sesiones originó el certificado. No se propone una tabla de preparaciones, id_asistencia adicional, id_actividad redundante ni otra base. SQL exacto propuesto, **no ejecutado**:

```sql-- PROPUESTA PARA REVISIÓN. NO EJECUTADA. Requiere autorización expresa.
-- Una sola columna nueva en una tabla existente; sin tabla de preparaciones.
-- Los certificados históricos conservan NULL: no se infiere su sesión.
BEGIN;
SET LOCAL lock_timeout = '5s';
SET LOCAL statement_timeout = '60s';

ALTER TABLE certificado.certificado
    ADD COLUMN id_actividad_sesion BIGINT NULL;

ALTER TABLE certificado.certificado
    ADD CONSTRAINT certificado_sesion_origen_check CHECK (
        id_actividad_sesion IS NULL OR (
            id_participacion IS NOT NULL
            AND id_practica IS NULL
            AND id_reconocimiento IS NULL
        )
    ),
    ADD CONSTRAINT certificado_asistencia_sesion_fk
        FOREIGN KEY (id_participacion, id_actividad_sesion)
        REFERENCES certificado.asistencia (id_participacion, id_actividad_sesion)
        ON UPDATE RESTRICT ON DELETE RESTRICT;

-- asistencia ya tiene UNIQUE(id_participacion, id_actividad_sesion).
-- Este índice impide también los duplicados de dos solicitudes concurrentes.
CREATE UNIQUE INDEX certificado_participacion_sesion_tipo_unique
    ON certificado.certificado (id_participacion, id_actividad_sesion, id_tipo_certificado)
    WHERE id_actividad_sesion IS NOT NULL;

-- El servicio debe validar y bloquear participación, sesión y asistencia:
-- misma actividad/persona, Finalizada, Activo, PRESENTE, tipo autorizado y ámbito.
-- No modificar filas históricas ni usar fecha/ruta/JSON para atribuir una sesión.
-- No borrar automáticamente un certificado anulado para permitir reemisión:
-- conservar la identidad y registrar versiones documentales con motivo real.

-- Ejecutar esta transacción únicamente después de autorización expresa.
COMMIT;
```

Impacto: añade una sola columna nullable, un CHECK de origen, una FK compuesta y un índice UNIQUE parcial en la tabla existente certificado. Los históricos conservarían null y no se actualizarían. La FK reutiliza el UNIQUE ya existente de asistencia y evita perder esa asistencia mientras un certificado la referencia. El índice impide dos certificados de una participación/sesión/tipo; no se elimina un certificado anulado para eludirlo. No cambia prácticas/reconocimientos porque su sesión permanece null.

La transacción necesita permisos de propietario/ALTER y bloqueos de esquema breves. Los tiempos de espera limitan el bloqueo; un error revierte la transacción. No hay backfill ni ejecución de migraciones pendientes. El archivo de propuesta tiene COMMIT para que sea exactamente la transacción a revisar y autorizar, pero no ha sido cargado ni ejecutado. El backend comprueba columna, FK y UNIQUE antes de habilitar la escritura: añadir solo la columna no basta.

Hasta recibir esa autorización, la conexión actual permite consultar beneficiarios y mostrar vistas previas; la confirmación de emisión devuelve 409. Una autorización del SQL no autoriza al agente a emitir certificados de prueba en la base: la emisión real se iniciará únicamente mediante la acción confirmada de un usuario autorizado.

## Pruebas y límites de la entrega

Se añadieron 20 casos de `SessionDemonstrationIssuanceTest` sin eliminar pruebas anteriores: vista previa sin escrituras con esquema actual; confirmación explícita; fecha de servidor; PDF/QR reales; reintento idéntico; dos beneficiarios; una participación en dos sesiones (incluida sesión con ID 47); unicidad impuesta por la base de pruebas; columna sin restricción que no habilita emisión; JWT/permisos/ámbito; responsables configurados/inactivos; URL LAN explícita; errores de archivo/base; COMMIT indeterminado; protección frente a cambiar una demo a oficial; anulación sin duplicados; históricos sin sesión; y rechazo de la creación manual del estado reservado.

Suite completa configurada: **425 casos; 406 aprobados, 19 omisiones opcionales ya existentes, 5.085 aserciones, 0 fallos y 0 errores**, con el límite PHP original. Las pruebas escriben exclusivamente fixtures efímeras y archivos aislados; no crearon certificados en certificados_morales. Las pruebas PostgreSQL opcionales no se activaron ni se ampliaron omisiones. La auditoría de la base actual fue solo lectura. La exclusión anterior del grupo de pruebas PostgreSQL que ejecuta migraciones se conserva.

Pint y git diff --check aprobados. No se ejecutaron ALTER TABLE, migraciones ni emisiones durante la auditoría. Sin cambios en Angular, sin otra base principal, sin datos ficticios en la aplicación, sin commit y sin push. Antes de uso institucional todavía faltan integración criptográfica verificable, autoridades/política institucionales aplicables y revisión de despliegue/seguridad; los avisos Symfony preexistentes documentados en la revisión anterior no se actualizaron sin autorización.

---
# Auditoría documental real y configuración local vigente

Esta sección sustituye los estados de instalación y verificación de revisiones anteriores. No se presenta como completada la emisión oficial por sesión.

## Direcciones locales y posterior despliegue

Configuración efectiva comprobada en Laravel:

```dotenv
APP_ENV=local
APP_URL=http://127.0.0.1:8000
FRONTEND_URL=http://localhost:4200
CERTIFICATION_PUBLIC_VERIFICATION_BASE_URL=
```

El valor opcional vacío deriva la URL del QR de `FRONTEND_URL` más `/verificar/{codigo}`. En local/testing se permite HTTP exclusivamente para hosts loopback en documentos de desarrollo. En producción se rechazan localhost/loopback y se exige HTTPS para la URL del QR. Las URLs proceden de configuración, no de los datos enviados por el cliente ni del encabezado Host.

Se inspeccionó Angular sin modificarlo: sus entornos ya usan `apiBaseUrl: '/api'` y `proxy.conf.json` dirige `/api` a `http://127.0.0.1:8000`. El navegador puede usar `http://localhost:4200/api/...` mediante ese proxy; no necesita enviar directamente la cookie entre localhost y 127.0.0.1. Laravel conserva CORS con origen `FRONTEND_URL` y credenciales, JWT HttpOnly y SameSite Strict. No se relajó la seguridad de la cookie.

En un despliegue de un solo origen, configurar `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` y `FRONTEND_URL` con el dominio HTTPS real, y mantener el servidor configurado para enviar `/api` a Laravel y las rutas de Angular a su SPA. Después, reconstruir la caché de configuración mediante `php artisan config:cache`; no ejecutar migraciones pendientes como parte de ese cambio. No hace falta editar el código para sustituir las URLs. Cambiar el dominio no autoriza ni completa los bloqueos de emisión y firma descritos abajo. Los PDF ya generados no cambian de contenido ni de QR cuando se cambia la configuración; no se deben emitir documentos oficiales con direcciones locales.

La página pública Angular `/verificar/:codigo` existe, pero su componente actual tiene el botón deshabilitado y muestra integración pendiente. No se modificó. El backend devuelve el contrato real y permite recuperar archivos por código; no se afirma que la interfaz Angular ya lo consuma. Un QR con localhost solo resuelve en el equipo local, no constituye una publicación accesible desde otros dispositivos.

## Evidencia sobre PostgreSQL existente

Se efectuó una auditoría real sobre `certificados_morales` usando `SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY`, comprobación de nombre de base y `transaction_read_only=on`, y rollback final. No se emitió ningún certificado ni se modificaron datos o esquema. La evidencia actual está en `docs/certification-schema-audit.json`.

Se revisaron columnas, claves, restricciones e índices de actividad, sesiones, participación, asistencia, tipos, certificados, documentos, firmas aplicadas, firmas y firmas de actividad; también las columnas de personas, documentos personales, trabajadores y áreas.

Resultados observados: 2 actividades, 2 sesiones, 3 participaciones y 3 asistencias. Hay una sesión Finalizada. Los tres tipos vigentes del catálogo son Capacitación, Reconocimiento y Prácticas. Hay **0 certificados, 0 documentos, 0 firmas y 0 firmas aplicadas**. La consulta de participaciones presentes en varias sesiones no devolvió filas; no se inventaron registros institucionales para forzar esa demostración. El caso de una misma participación presente en dos sesiones sigue cubierto por pruebas aisladas en memoria.

Restricciones reales relevantes:

- `asistencia`: UNIQUE `(id_participacion, id_actividad_sesion)`.
- `certificado`: FK a participación, persona, tipo y los orígenes alternativos; CHECK que exige exactamente un origen. **No contiene relación con sesión o asistencia ni un índice único de emisión por sesión/tipo**.
- `certificado_documento`: FK a certificado, UNIQUE de código y de hash; estado limitado por CHECK a `Vigente` o `Anulado`.
- `certificado_firma`: relaciona documento y firma; una fila administrativa no demuestra una firma criptográfica aplicada al PDF.

## Entrega de los doce puntos solicitados

| Punto | Resultado real |
| --- | --- |
| 1. Tablas reutilizadas | La consulta conserva actividad_sesion → asistencia → participacion. El generador reutiliza certificado, certificado_documento, tipo_certificado, actividad y persona. Se auditaron firma, certificado_firma, actividad_firma y trabajadores sin exponer claves ni PFX. |
| 2. Emisión por sesión sin alterar esquema | **No es inequívoca con el esquema actual. Sigue bloqueada.** No se crearon certificados institucionales para aparentar una solución. |
| 3. Identidad permanente de sesión | Ausente en el esquema actual. Se propone una sola columna nullable `certificado.id_actividad_sesion`, una FK compuesta a la asistencia ya existente y un índice único de participación/sesión/tipo. Los históricos quedarían null sin atribución inferida. |
| 4. Prevención de duplicados | Los documentos tienen códigos aleatorios de 120 bits, UNIQUE real de código/hash y bloqueo de la fila del certificado para serializar reintentos. Se reserva el archivo con creación exclusiva `x+b`, evitando sobrescribir un archivo existente. La unicidad de emisión por sesión requiere el índice de la propuesta; no se afirma que ya esté instalado. |
| 5. PDF y QR reales | Se instalaron, con autorización, Dompdf 3.1.6 y BaconQrCode 3.1.1 con cinco transitivas. No se actualizaron paquetes existentes ni se ejecutaron scripts Composer. Se ejecutaron renderizado PDF y codificación QR reales, con URL local y HTTPS de prueba, almacenamiento, hash y recuperación. Son pruebas locales aisladas; no emisiones institucionales ni una prueba de firma. No se probó escaneo externo de un QR publicado. |
| 6. Almacenamiento | Disco local privado, configurado en `storage/app/private`; los documentos actuales del generador provisional usan `certificados/desarrollo/{codigo}.pdf`. Se comprueban ruta, contención fuera de public, cabecera PDF, lectura y SHA-256. Un fallo de persistencia elimina el archivo creado y revierte el registro documental. No se crea una nueva fecha de emisión en este generador de un certificado existente. |
| 7. Validación QR contra PostgreSQL | El endpoint público busca el código con parámetros enlazados y hace JOIN con certificado. Si no existe el certificado asociado, devuelve 404. Comprueba archivo, hash y estados; un código con forma válida por sí solo no acredita nada. La lectura pública no requiere habilitar el modo provisional de desarrollo. |
| 8. Datos públicos | Código, fechas registradas, estado real del certificado/documento, integridad, disponibilidad del PDF y motivos. No se devuelven DNI, ID de persona, ruta física, contraseñas ni claves. `firma_verificada=false` y `emision_oficial_verificada=false` mientras no haya procedimientos verificables integrados. Los documentos de desarrollo no son publicables. Una anulación/revocación o archivo ausente/corrupto impide servir el PDF y da `vigente=false`; el estado original del registro se conserva en sus campos. |
| 9. Tipo automático | El generador recupera el ID y `nombre_tipo` del certificado por JOIN al catálogo vigente y exige compatibilidad en la configuración backend. El usuario no escribe el título del documento. Se reutilizó el escudo auténtico del frontend en una copia de recurso backend, sin editar Angular. La plantilla profesional actual sigue etiquetada como desarrollo y no inventa sesiones, autoridades, acreditaciones o firmas. No se extendió esa plantilla a prácticas ni reconocimientos. |
| 10. Firmas operativas | El módulo registra credenciales de firma cifradas y valida archivos privados; el registro manual de firmas aplicadas está bloqueado. No hay firma criptográfica PDF oficial integrada, ni firmas reales configuradas en la base auditada. No se afirma FIRMADO por un registro o una imagen. |
| 11. Rutas/payloads Angular | Se describen a continuación usando únicamente las rutas existentes. No se crearon endpoints duplicados ni se habilitó una ruta ficticia de emisión. |
| 12. Bloqueos | Faltan autorización e integración de identidad persistente de sesión, flujo oficial de emisión/estados y firma institucional. Cambiar el dominio no los solventa. La propuesta y las actualizaciones de seguridad tienen autorizaciones pendientes separadas. |

## Rutas existentes y contratos efectivos

Todas las rutas administrativas conservan JWT, permisos y comprobaciones de ámbito aplicables. No enviar al backend un actor, código, hash o ruta para generar un documento.

| Operación | Ruta | Entrada y resultado |
| --- | --- | --- |
| Tipos reales | `GET /api/tipos-certificado/list` | Catálogo real de tipos; listar no habilita un origen incompatible. |
| Beneficiarios por sesión | `GET /api/activity-session/{id}/beneficiarios?id_tipo_certificado={tipo}&page=1&per_page=20` | ID real de sesión y tipo seleccionado del catálogo. Mantiene `data.contexto`, `data.beneficiarios.data`, paginación, elegibilidad y capacidades separadas. |
| Evaluar preparación | `POST /api/activity-session/{id}/preparar-certificados` | JSON `{"id_tipo_certificado": ID_REAL}`. Evalúa candidatos sin persistir preparación ni emitir. La notación ID_REAL se sustituye por el número del catálogo, no es un payload literal. |
| Consultar certificados existentes | `GET /api/certificados/list` y `GET /api/certificados/detail/{id}` | Registros existentes; un certificado asociado a participación no se atribuye a cada sesión. |
| Consultar documentos | `GET /api/certificados-documentos/list?id_certificado={id}` y `GET /api/certificados-documentos/detail/{id}` | Registros documentales existentes, sin ruta física pública. |
| Generación provisional existente | `POST /api/certificados/{id}/generar-documento` | JSON `{}`. Requiere un certificado existente por participación y el entorno provisional autorizado. Guarda un PDF de desarrollo real o recupera el mismo registro en un reintento; nunca marca emisión oficial. No se invocó contra la base principal. |
| Descargar con JWT | `GET /api/certificados-documentos/{id}/archivo` | Mantiene el flujo provisional existente, validación de archivo, permisos y ámbito. No se presenta como flujo oficial de prácticas o reconocimientos. |
| Verificar por código | `GET /api/certificados/verificar/{codigo}` | Público; devuelve el estado y las comprobaciones reales, sin PII innecesaria ni firma acreditada. Código inexistente: `Certificado no encontrado`, 404. |
| Recuperar PDF público auténtico | `GET /api/certificados/verificar/{codigo}?archivo=1` | Misma ruta pública existente, sin endpoint adicional. Solo sirve el archivo almacenado cuando su integridad y ambos estados son correctos y no es un documento de desarrollo. Si no existe autorización pública o no cumple disponibilidad e integridad, 403; código inexistente, 404; cambio de integridad durante la resolución, 409. `?archivo=1&descargar=1` descarga exactamente el mismo PDF autorizado. Devuelve application/pdf con private, no-store y nosniff. `url_documento` es relativa para funcionar con el proxy local y el dominio de despliegue. |
| Emitir oficialmente por sesión | **No hay operación habilitada** | El POST administrativo de creación de certificados no sustituye esta operación ni resuelve la sesión. No se propone un payload que finja emitir. |

Para el CRUD administrativo existente `POST /api/certificados/create`, `fecha_emision` debe ser una fecha explícita `YYYY-MM-DD`, `estado` es texto y debe indicarse exactamente uno de `id_participacion`, `id_practica` o `id_reconocimiento`, además de persona, tipo y notificado. No enviar estado booleano. En el CRUD documental usar `Vigente`/`Anulado`, no `GENERADO`/`ANULADO`. Registrar un certificado administrativo no demuestra que exista un PDF ni una emisión por sesión; el generador y la verificación comprueban el archivo por separado.

Una respuesta de verificación puede tener `registro_encontrado=true` e `integridad_verificada=false`: eso significa que existe un registro, pero su archivo falta o está alterado. No debe mostrarse Acreditado. La comprobación de SHA-256 no es una firma digital. La descarga no regenera el documento.

## Propuesta mínima y asuntos pendientes

`docs/session-certification-schema-proposal.sql` contiene **una propuesta no ejecutada**, sin tablas nuevas. Añade solo `id_actividad_sesion` a certificado, CHECK de origen, FK `(id_participacion,id_actividad_sesion)` a la clave única existente de asistencia y UNIQUE parcial `(id_participacion,id_actividad_sesion,id_tipo_certificado)`. La consulta posterior recuperaría beneficiario → participación → actividad, la sesión explícita y certificado → documento. El servicio deberá además comprobar misma actividad/persona, estados, tipo y ámbito bajo bloqueos antes de emitir. Se preservarán las filas históricas sin asignarles una sesión. La propuesta termina en ROLLBACK como protección de revisión y no se carga desde una migración. Se solicitó autorización explícita; autorizar las bibliotecas no autoriza modificar PostgreSQL.

Se corrigieron cuatro incompatibilidades reales del CRUD con PostgreSQL: estado del certificado validado/casteado como texto (varchar de 30 caracteres, sin inventar una nueva lista de estados); fecha_emision obligatoria y explícita, sin fecha automática; estados documentales limitados a Vigente/Anulado según el CHECK real; y validación de exactamente un origen según certificado_origen_check. No se reescribieron registros históricos ni se convirtieron silenciosamente estados booleanos antiguos. Las fixtures y aserciones se alinearon al esquema: no se permite dejar temporalmente un certificado sin origen; un cambio autorizado de origen debe realizarse completo en una sola actualización. Se conservan las pruebas de prácticas/reconocimientos y de historial sin escrituras. El CRUD sigue siendo administrativo y no sustituye una emisión oficial por sesión ni acredita firmas.

Composer audit confirmó **8 avisos en cinco componentes Symfony preexistentes**: http-foundation, http-kernel, mime, routing y yaml. Ninguno pertenece a las bibliotecas PDF/QR instaladas. Se solicitó autorización separada para actualizar esos componentes a versiones corregidas compatibles; no se actualizaron sin consentimiento. No se declara el despliegue listo o seguro solo porque pasen pruebas locales.

## Verificación ejecutada

- 25 pruebas documentales aprobadas, 140 aserciones. Incluyen PDF/QR reales, URL local, URL HTTPS de despliegue, rechazo de localhost en producción, tipo vigente/compatible, archivo perdido/corrupto, certificado huérfano, estados no vigentes, PDF público almacenado, reintentos y rollback de base/archivo ante error de persistencia.
- Suite completa configurada: **405 casos, 386 aprobados, 19 omitidos, 4.881 aserciones, 0 fallos y 0 errores**. Las omisiones corresponden a las pruebas PostgreSQL opcionales ya existentes; el grupo de escritura PostgreSQL continúa excluido por la configuración previa. No se conectaron las pruebas a PostgreSQL institucional ni se ampliaron omisiones para ocultar fallos. El renderizado PDF antes omitido ahora se ejecuta realmente.
- La regresión específica de CertificationModuleTest, ModuleIntegrationTest y CertificationReadinessTest aprobó 110 casos y 2.181 aserciones después de corregir los cuatro desajustes de esquema. Se añadieron cinco casos para fechas obligatorias, estado textual y origen único, conservando prácticas, reconocimientos y protección del historial.
- Pint, composer validate y git diff --check aprobados. No se ejecutaron migraciones ni scripts Composer, no hubo emisión institucional ni cambios de datos históricos, no se modificó Angular y no se hizo commit ni push.

---
# Revisión autorizada de pruebas del contrato dinámico por sesión

Fecha: 10 de noviembre de 2026. Esta revisión resuelve los 17 casos pendientes del diagnóstico anterior. No cambia el contrato ni el código de producción. No hay fallos pendientes en la suite configurada.

## Resultado y alcance

Se actualizaron **19 casos en 16 métodos**: los 17 casos fallidos, el caso PRESENTE del mismo método parametrizado (para reforzar conteo/elegibilidad) y la antigua prueba de migración (para comprobar seguridad de esquema sin invocarla). No se borraron métodos ni casos de datos, ni se añadieron exclusiones u omisiones. Los nombres de los métodos relativos a persistencia se ajustaron para describir su comportamiento actual. Las garantías de seguridad, asistencia, alcance, elegibilidad, duplicados, idempotencia, documentos y paginación se conservan o refuerzan.

**Errores reales del backend corregidos: 0.** Los 17 fallos correspondían a expectativas/fixtures desactualizadas. No se cambiaron los servicios ni controladores para satisfacerlas. La tabla siguiente identifica la expectativa anterior de cada caso y su sustitución legítima; todos se clasifican como prueba desactualizada.

## Auditoría de los 17 casos

Los nombres sin clase corresponden a `CertificationReadinessTest`; se conserva el nombre anterior para facilitar el seguimiento de las pruebas renombradas.

| Caso | Aserción anterior que fallaba | Contrato vigente y corrección aplicada |
| --- | --- | --- |
| 1. `test_session_evaluation_uses_policy_and_real_attendance`, AUSENTE | La primera fila debía tener `estado_elegibilidad=No elegible`. No había fila. | Un ausente no integra los beneficiarios. Se comprueba lista vacía, total elegibles 0, permiso de lectura y ausencia de certificados nuevos. |
| 2. Mismo método, estado `nn` | La primera fila debía estar `Pendiente de validación`. No había fila. | Una asistencia no presente queda fuera de candidatos. Se comprueba lista vacía y total elegibles 0, conservando el contrato y la protección de datos. |
| 3. Mismo método, sin asistencia | La primera fila debía estar `Pendiente de validación`. No había fila. | Sin asistencia PRESENTE no hay beneficiario. Se comprueba exclusión y total elegibles 0. |
| 4. `test_present_attendance_without_policy_is_pending_and_open_session_is_ineligible` | La sesión abierta debía devolver una fila `No elegible`. | Una sesión abierta devuelve candidatos vacíos, total elegibles 0 y `finalizada=false`; la lectura sigue habilitada. El presente de la sesión finalizada sin política sigue pendiente de validación. |
| 5. `test_beneficiary_query_count_does_not_grow_per_person` | Se esperaban 30 filas después de añadir 29 participaciones sin asistencias. | Se completan las fixtures con asistencia PRESENTE de los 29 nuevos participantes. Se conservan las 30 filas esperadas y la igualdad del número de consultas para detectar N+1. |
| 6. `test_two_present_participants_have_the_same_counters_on_list_detail_and_beneficiaries_and_can_finalize` | Antes del cierre se esperaba una fila en la página. | Antes del cierre: lista y total vacíos, con los contadores operativos intactos. Después del cierre: página de una fila y total 2. Se mantienen lista/detalle/contadores, cierre idempotente, estado de la actividad, otra sesión abierta y ausencia de certificados nuevos. |
| 7. `test_development_flow_closes_two_present_participants_and_persists_idempotent_preparations` | POST debía devolver 201 y crear dos preparaciones persistentes. | POST devuelve 200 y evalúa dos candidatos reales. Se compara el mismo conjunto al repetirlo, se verifican preparaciones vacías, IDs de preparación null, cero creaciones y consultas exclusivamente SELECT. No se registra falsamente un evento de preparación persistida. Se mantienen el cierre y los estados de actividad/otra sesión. |
| 8. `test_development_preparation_excludes_absent_participants_and_does_not_apply_to_global_certification` | Se esperaba una segunda fila ausente y crear una preparación. | Solo aparece la persona presente; el contador de ausentes sigue siendo 1. La evaluación POST devuelve un elegible sin persistencia y cero creaciones. Se mantiene la política independiente de certificación global. |
| 9. `test_development_preparation_blocks_open_session_pending_attendance_and_incompatible_type` | Una asistencia PENDIENTE debía figurar como fila de beneficiario. | No aparece como candidato y el contador pendiente conserva el registro. Cerrar/preparar continúa devolviendo 409 cuando corresponde; el tipo incompatible no habilita preparación. Se comprueba que no se crean certificados. |
| 10. `test_missing_preparation_schema_blocks_only_preparation_not_provisional_eligibility` | Se esperaba `puede_preparar=false`, código de esquema faltante y HTTP 409. | No hace falta una tabla adicional para evaluar. GET y POST permiten la evaluación autorizada, con `preparacion_persistida=false`, documento no atribuible y emisión bloqueada con motivo técnico. Se mantiene la independencia de la certificación global. |
| 11. `test_existing_certificate_without_session_identity_blocks_new_preparation` | Un certificado de la participación debía bloquear la evaluación. | Permite evaluar, pero no emitir por sesión. El certificado 9 solo aparece en la lista de participación; certificado/estado de sesión permanecen null. Se verifica SELECT exclusivamente y conservación del registro existente. |
| 12. `test_preparations_for_different_sessions_have_distinct_persistent_identities` | Se esperaban HTTP 201 e IDs de preparación diferentes guardados en una tabla. | Se verifican los IDs reales y diferentes de sesión/asistencia, la misma participación y conjuntos sin preparación persistida. La bandeja conserva el contexto de sesión seleccionado, sin atribuir un certificado. No se crean certificados. |
| 13. `test_existing_preparation_with_a_registered_document_is_recovered_without_repreparing` | El primer POST debía crear una preparación; luego se pretendía asociarle un documento. | Se representa un certificado/documento preexistente con vistas estáticas de SQLite en memoria. Ambos registros se conservan; se verifica el ID real del certificado en participación y que el documento no se atribuye a la sesión. GET/POST ejecutan solo SELECT y no crean preparaciones. |
| 14. `test_duplicate_attendance_never_creates_a_provisional_preparation` | Error por constante `SessionCertificatePreparation::TABLE` eliminada. | Se retira la dependencia de la tabla abandonada. Se conservan asistencia duplicada, elegibilidad pendiente, preparación deshabilitada y HTTP 409; se comprueba cero certificados nuevos. |
| 15. `test_missing_preparation_schema_keeps_session_closure_counters_beneficiaries_and_types_available` | Se esperaba bloqueo de preparación por falta de esquema. | Se mantienen listado/detalle, dos presentes, tipos, cierre individual, actividad Activa y beneficiarios consultables. Evaluar queda disponible sin persistencia; solo la emisión permanece bloqueada. La bandeja no devuelve un bloqueo de esquema para preparar. |
| 16. `UserSecurityTest::test_all_registered_protected_routes_reject_guests` | Se esperaban 182 rutas API y 179 protegidas. Existían 190 rutas. | El inventario actual tiene 190 rutas y 186 protegidas: incluye cinco rutas de certificación y tres documentales adicionales, una de estas pública. Se reconoce la verificación pública por código. Se comprueba HTTP 401 y ausencia de redirección en cada una de las 186 protegidas. No se omitió ninguna ruta protegida. |
| 17. `UserSecurityTest::test_an_institutional_cookie_without_permissions_does_not_authorize_any_crud` | Se esperaban 177 rutas sometidas a permisos; el filtro antiguo incluía la verificación pública. | Se mantienen los permisos de todos los CRUD y acciones protegidas. Al reconocer la verificación pública, quedan 184 rutas sujetas al test y cada una debe devolver HTTP 403 con JWT válido sin permisos. Las rutas de consulta/preparación nuevas quedan incluidas. |

El caso PRESENTE del método parametrizado mantiene `Elegible` y ahora verifica explícitamente una fila y un elegible. La antigua prueba `test_preparation_migration_cannot_execute_without_explicit_schema_authorization` se conserva como `test_dynamic_preparation_never_changes_schema_even_when_legacy_schema_flag_is_enabled`: compara el esquema antes/después y exige SELECT exclusivamente incluso con el indicador histórico activado. No carga ni invoca una migración. El helper provisional ya no crea una tabla de preparaciones. Se eliminaron dependencias de la constante de esa tabla, no comprobaciones de seguridad.

## Ejecución y límites de verificación

Primero pasaron los casos directamente afectados. Después se ejecutaron `CertificationReadinessTest`, `UserSecurityTest`, `SessionDynamicBeneficiariesTest` y `SessionBeneficiariesJwtTest`: **103 pruebas aprobadas y 1.484 aserciones**.

Finalmente se ejecutó la suite completa configurada:

```powershell
$env:ACTIVITY_REQUESTS_TEST_EXCLUSIVE = 'false'
php -d extension=pdo_sqlite vendor/bin/phpunit --no-progress
```

**Resultado: 384 casos; 364 aprobados, 20 omitidos, 4.756 aserciones, 0 fallos y 0 errores.** Las 20 omisiones ya existentes corresponden al entorno opcional de PostgreSQL y a una prueba de renderizado PDF/QR sin las dependencias disponibles. El grupo PostgreSQL que usa migraciones continúa excluido por la configuración previa de `phpunit.xml`; no se amplió esa exclusión. No se sustituyeron las dependencias PDF/QR para declarar esa funcionalidad probada. La variable mostrada solo afecta al proceso de pruebas y evita activar escrituras PostgreSQL; no modifica `.env` ni los datos de una base.

Pint (`vendor/bin/pint --dirty --format agent`) y `git diff --check` aprobados. La extensión SQLite estaba instalada y solo se cargó para estos procesos. No se instalaron dependencias. Las fixtures de la suite son efímeras en memoria; los casos actualizados de certificados preexistentes usan vistas de lectura y no generan una emisión.

## Capacidades para Angular y diagnóstico final

Angular conserva la consulta dinámica y paginada de beneficiarios de cualquier sesión finalizada, con IDs reales de sesión, asistencia, participación y persona, tipo evaluado, elegibilidad y motivos. Continúan los controles de JWT, permisos y ámbito, y las capacidades separadas de consulta, preparación evaluada sin persistencia y emisión.

La emisión independiente por sesión sigue bloqueada: `certificado.id_participacion` no almacena cuál de las sesiones de esa participación originó un certificado. `certificado_documento.id_certificado` tampoco resuelve esa identidad. Los certificados de participación no se muestran como emitidos para cada sesión. No se inventan asociaciones, fechas ni duplicados para sortear esa limitación.

No se modificó Angular, PostgreSQL institucional, datos existentes, esquema, migraciones, dependencias ni configuración de ejecución del proyecto. No se ejecutaron migraciones ni se emitieron certificados contra PostgreSQL. No se hizo commit ni push.

---
# Diagnóstico vigente: certificación dinámica sin cambios de esquema

Revisión: 10 de noviembre de 2026. Esta sección sustituye las conclusiones históricas de preparación persistida y propuestas de esquema conservadas debajo. Esas propuestas no forman parte del flujo vigente y no deben ejecutarse.

## Consulta y contrato

Se conserva `GET /api/activity-session/{id}/beneficiarios`, protegido por el JWT de cookie, los permisos de sesiones, certificados y participaciones, y el ámbito municipal del usuario. El parámetro de ruta se restringe a números y se comprueba la existencia y autorización de la sesión. Los filtros de contexto incoherentes devuelven 422, los recursos inexistentes 404, la autenticación faltante/inválida 401 y los permisos/ámbitos insuficientes 403.

La consulta usa el ID recibido con parámetros enlazados. Relaciona sesión y participación por actividad y comprueba mediante EXISTS la asistencia de esa participación en esa sesión. Equivale al JOIN comprobado en DBeaver para registros íntegros y evita multiplicar participaciones cuando existen asistencias repetidas. Aplica `UPPER(TRIM(...))` a Finalizada, Activo y PRESENTE antes de paginar en SQL. Los ausentes, las inscripciones inactivas, quienes carecen de asistencia presente y las sesiones abiertas no generan filas de candidatos. Una asistencia duplicada produce una sola fila pendiente de validación, con el detalle en `asistencias`, sin elegir arbitrariamente un ID de asistencia.

Se conserva el sobre `success/message/data`, `data.contexto`, `data.beneficiarios.data` y los metadatos/links del paginador (`total`, `current_page`, `per_page`, etc.). `page` debe ser >=1 y `per_page` entre 1 y 100. `total_elegibles` se evalúa sobre la actividad completa para la sesión solicitada y no depende de la página mostrada.

Cada fila incluye `id_sesion`, `id_actividad_sesion`, `nombre_sesion`, `id_participacion`, `id_persona`, `id_asistencia`, `estado_asistencia`, `asistencias`, `id_tipo_certificado`, `estado_elegibilidad` y `motivos`. Sin selección de tipo o sin política verificada, la lectura sigue disponible y la elegibilidad queda pendiente; un tipo no permitido no habilita candidatos para preparar. Se conserva la política provisional de desarrollo configurada por el backend, incluyendo su control de entorno/conexión y solicitud aprobada.

## Capacidades y preparación

- `puede_consultar_beneficiarios`: lectura autorizada, independiente del permiso de creación.
- `puede_preparar`: evaluación provisional disponible solo con política, tipo, elegibles y permiso de creación.
- `puede_emitir`: false para emisión independiente por sesión.
- `preparacion_persistida`: false; `preparaciones` permanece vacío.

`POST /api/activity-session/{id}/preparar-certificados` conserva su autorización y validaciones. Con condiciones válidas devuelve 200 y `estado_preparacion="Evaluada sin persistencia"`, junto al conjunto paginado de candidatos evaluados. Repetirlo vuelve a consultar las tablas existentes: no guarda una preparación ni reserva candidatos, no crea certificados ni documentos y no inventa fechas. `preparaciones_creadas=0` y `preparaciones_existentes=0`. Sin condiciones válidas devuelve 409; sin permisos, 403. No depende de una tabla de preparaciones y no la consulta aunque una instalación la tuviera.

## Limitación técnica de emisión y documentos

`certificado.certificado.id_participacion` permite verificar la participación y la persona. No permite identificar una sesión concreta: una participación puede tener asistencia en varias sesiones. `certificado_documento.id_certificado` identifica un documento de ese certificado y tampoco resuelve la sesión de origen. El generador documental existente verifica el contexto de actividad/participación/persona; generar o recuperar un PDF no demuestra una emisión por sesión.

Por ello los certificados coherentes existentes se exponen exclusivamente en `certificados_registrados_participacion`, con su ID y estado reales. No se multiplican filas por los certificados encontrados. `certificado_sesion`, `id_certificado`, `estado_certificado` e `id_certificado_documento` de la sesión permanecen null, e `identidad_sesion_disponible=false`. `estado_generacion="No determinable por esquema"` evita afirmar que un documento no existe o que está emitido para esa sesión. `motivos_bloqueo_emision` explica la ausencia de identidad persistente y el procedimiento oficial pendiente.

Emitir certificados diferentes por sesión no puede garantizarse inequívocamente con estas columnas. Se mantiene bloqueada esa emisión. La consulta y la preparación como evaluación dinámica permanecen disponibles. El CRUD administrativo sigue rechazando contexto de sesión; no se reinterpretan campos de práctica o reconocimiento ni se crea una fecha de emisión.

## Verificación de esta revisión

Se añadieron `SessionDynamicBeneficiariesTest` y `SessionBeneficiariesJwtTest`: 10 casos y 150 aserciones aprobados. Cubren estados normalizados, presentes/ausentes/inactivos/sin asistencia, sesión abierta, dos sesiones de una actividad, la misma participación en ambas, certificados/documentos preexistentes representados mediante vistas estáticas en memoria, ausencia de certificados, repetición de la preparación sin escrituras, asistencias duplicadas, paginación real, JWT válido/inválido/expirado, permisos, ámbito y validación de contexto. Las fixtures se alojan exclusivamente en SQLite en memoria; las vistas estáticas representan certificados existentes sin emitir certificados durante estas pruebas.

Comando: `php -d extension=pdo_sqlite vendor/bin/phpunit tests/Feature/SessionDynamicBeneficiariesTest.php tests/Feature/SessionBeneficiariesJwtTest.php --no-progress`. La extensión ya instalada se habilita solo para ese proceso, sin cambiar php.ini ni dependencias.

La suite histórica `CertificationReadinessTest` se auditó: 48 de 63 casos pasaron; 14 fallaron y 1 produjo error porque sus expectativas corresponden a listar inscritos sin presencia o persistir preparaciones en el esquema propuesto. No acredita el nuevo contrato. Se solicitó aprobación para actualizar esas pruebas existentes según la regla explícita de la habilidad de pruebas; mientras no llegue, se conservan.

La regresión adicional de AttendanceHttpIntegrationTest, AttendanceTest y UserSecurityTest ejecutó 72 casos: 70 pasaron y 2 fallaron por conteos fijos de rutas protegidas (182/177 esperadas frente a 190/185 existentes). Esta revisión no añadió rutas. Pint, sintaxis de los tres archivos PHP de producción y git diff --check aprobaron.

No se consultó ni modificó PostgreSQL institucional durante esta revisión. No se ejecutó DDL contra PostgreSQL, migraciones Artisan ni seeders; no se añadieron tablas, columnas, claves o migraciones. La propuesta de migración ya existente se dejó intacta. La auditoría histórica invocó su protección de autorización en memoria, que lanzó excepción antes de ejecutar DDL; las pruebas nuevas no la invocan. No se modificó Angular, .env ni dependencias. Sin commit ni push.

---
# Cierre de sesiones y certificación: auditoría y contrato

Fecha: 5 de noviembre de 2026. Laravel 13.30.1, PHP CLI 8.3.30, PHPUnit 12.5.34.

## Revisión vigente: funcionalidades disponibles sin ejecutar migraciones

La decisión actual es continuar con certificados_morales y su esquema existente. No se ejecutan migraciones, instalaciones, Docker, respaldos improvisados ni escrituras de prueba en esa base. Las propuestas y resultados históricos conservados debajo no autorizan su ejecución.

La verificación actual de PostgreSQL se realizó mediante Laravel dentro de una transacción REPEATABLE READ READ ONLY, comprobando read_only=on y terminando con rollback. Las lecturas de actividades, sesiones, participaciones, asistencias y tipos de certificado devolvieron success=true. Son verificaciones de consultas de Laravel, no una llamada HTTP autenticada desde Angular ni pruebas de escritura en PostgreSQL.

Datos reales observados: actividad 3 Activa con solicitud Aprobada; sesión 2 Activo; 2 inscritos vigentes, 2 presentes, 0 ausentes, 0 pendientes y 0 duplicados. La política provisional está habilitada para la identidad expresamente autorizada de la conexión actual en APP_ENV=local. El servicio devuelve motivos_bloqueo_finalizacion=[]; el GET autenticado calcula puede_finalizarse añadiendo el permiso efectivo del usuario. No se finalizó la sesión 2 desde Codex ni se cambió el estado de la actividad.

La consulta real de beneficiarios devolvió las dos participaciones, tipo 1 compatible y 2 No elegible con la sesión todavía abierta. Eso no significa que PRESENTE sea insuficiente según la regla provisional: exige también que la sesión real esté Finalizada. Las pruebas en memoria comprueban esa transición y la evaluación posterior sin alterar los registros actuales.

### Contratos que continúan disponibles

| Operación | Endpoint existente |
| --- | --- |
| Actividades y detalle | GET /api/activity y GET /api/activity/{id} |
| Sesiones y detalle | GET /api/activity-session y GET /api/activity-session/2 |
| Inscripciones | GET /api/participation; POST /api/participation con persona, actividad, tipo, fecha real de inscripción y estado |
| Guardar asistencia | POST /api/attendance/create; usar el ID real de participación y la sesión, estado y horas solo si se registran explícitamente |
| Recuperar asistencia | GET /api/attendance/list?id_actividad_sesion=2; filtrar además por id_participacion cuando corresponda |
| Corregir asistencia autorizada | PATCH /api/attendance/update/{id_asistencia} |
| Finalizar individualmente | POST /api/activity-session/2/finalizar, JSON {} |
| Beneficiarios | GET /api/activity-session/2/beneficiarios?id_tipo_certificado=1 |
| Tipos disponibles | GET /api/tipos-certificado/list |
| Preparación bloqueada | POST /api/activity-session/2/preparar-certificados, JSON {"id_tipo_certificado":1} |

Los tres tipos reales consultados están vigentes: 1 Capacitación, 2 Reconocimiento y 3 Prácticas. La regla provisional por sesión solo declara compatible el tipo 1. Listar un tipo no lo habilita automáticamente para esa modalidad. No se cambiaron las reglas de otros orígenes ni se ejecutaron sus flujos de escritura.

Todos los endpoints protegidos conservan cookie JWT HttpOnly, permisos efectivos y ámbito. Consultar, registrar asistencia, finalizar y preparar son acciones distintas. Un fallo de preparación no debe impedir que Angular consulte inscritos, asistencias, beneficiarios o el detalle de sesión.

### Bloqueo exclusivo de la preparación y estado documental no determinable

Se conserva HTTP 409 para la preparación sin relación persistente. La respuesta añade:

```json
{
  "codigo_bloqueo": "ESQUEMA_PREPARACION_NO_DISPONIBLE",
  "estado_preparacion": "Bloqueada",
  "puede_preparar": false,
  "puede_generar_pdf": false,
  "puede_emitir": false,
  "puede_firmar": false
}
```

Estos son campos de data, dentro del sobre success/message/data existente. motivos_bloqueo conserva la explicación textual. El código no sustituye 401/403: falta de sesión autenticada o de permisos sigue devolviendo el estado correspondiente antes de comprobar el procedimiento.

En beneficiarios y bandeja, codigo_bloqueo_preparacion identifica ese mismo bloqueo. identidad_sesion_disponible=false indica que no se puede atribuir un certificado a la sesión. beneficiarios.data[].estado_generacion devuelve ahora **No determinable por esquema** cuando falta esa identidad; no se informa No generado como si se hubiera demostrado que no existe un documento. id_preparacion y certificado_sesion permanecen null.

El endpoint de beneficiarios añade puede_consultar_beneficiarios=true tras autorizar la lectura, independientemente de puede_preparar=false. motivos_bloqueo_emision explica por separado que la emisión oficial/firma requieren su procedimiento institucional integrado. De esta forma la falta de persistencia no se confunde con falta de permisos ni con un módulo completo deshabilitado. Los campos anteriores y las rutas permanecen compatibles.

No se guardan preparaciones en campos de participación, práctica, reconocimiento u otros conceptos. No se inventa fecha_emision ni se crean certificados para suplir la tabla faltante. El generador existente requiere dompdf/dompdf y bacon/bacon-qr-code; siguen sin instalar y su prueba de renderizado se omite. Los controles de integridad, autenticación, idempotencia y documentos previos se pueden probar con las dependencias actuales, sin afirmar generación PDF/QR de extremo a extremo ni emisión oficial.

### Trabajo que puede continuar

Se pueden continuar el contrato y las pruebas del servicio, las validaciones, las capacidades y la integración de lectura con datos existentes. La escritura real de preparaciones queda pendiente de respaldo verificable y del cambio de esquema revisado y autorizado. La migración propuesta se conserva sin ejecutar ni modificar en esta revisión. No se cambia de base principal para resolver ese bloqueo.

La prueba nueva sin tabla de preparaciones comprueba listado/detalle, dos presentes, tipos disponibles, cierre individual, actividad Activa, beneficiarios consultables y bloqueo exclusivo del POST de preparación. Las pruebas de escritura se ejecutan en las fixtures aisladas existentes; las verificaciones automáticas de certificados_morales son exclusivamente de lectura.

Resultado de esta revisión: **120 pruebas seleccionadas aprobadas, 778 aserciones y 1 prueba de renderizado omitida**. Se ejecutaron AttendanceHttpIntegrationTest, CertificationReadinessTest, AttendanceTest y CertificateDocumentGenerationTest, más siete casos de ModuleIntegrationTest relativos a actividades, inscripciones, sesiones, asistencias, JWT y permisos; no se ejecutaron sus flujos de prácticas/reconocimientos. Pint y git diff --check aprobados. No se modificaron .env, dependencias, migraciones ni datos PostgreSQL en esta revisión; tampoco Angular, commit ni push.

## Estado vigente: conexión actual autorizada y preparación provisional por sesión

Esta sección sustituye las restricciones de conexión y preparación descritas en revisiones anteriores. El usuario autorizó continuar con la PostgreSQL ya conectada, sin levantar Docker ni otra base. La inspección actual se ejecutó con REPEATABLE READ READ ONLY y rollback: APP_ENV=local, conexión pgsql, base certificados_morales y usuario equipo_dev. Es la misma base antes denominada institucional en el informe; no se renombró ni se afirma que esté aislada. No se cargaron fixtures ni se ejecutaron pruebas de escritura sobre ella.

### Configuración local habilitada

Se actualizó únicamente la configuración local .env, sin cambiar credenciales, para activar la política provisional y autorizar la identidad concreta de la conexión actual. Se limpió la caché de configuración. Variables:

```dotenv
CERTIFICATION_DEVELOPMENT_CLOSURE_ENABLED=true
CERTIFICATION_DEVELOPMENT_CONNECTION_AUTHORIZED=true
CERTIFICATION_DEVELOPMENT_CONNECTION=pgsql
CERTIFICATION_DEVELOPMENT_DATABASE=certificados_morales
CERTIFICATION_DEVELOPMENT_USERNAME=equipo_dev
CERTIFICATION_DEVELOPMENT_SERVER_ADDRESS=IDENTIDAD_REAL_VERIFICADA_DEL_SERVIDOR
CERTIFICATION_DEVELOPMENT_SERVER_PORT=5432
CERTIFICATION_DEVELOPMENT_SESSION_TYPE_IDS=1
CERTIFICATION_DEVELOPMENT_SCHEMA_AUTHORIZED=false
```

La dirección concreta se registró en el archivo local ignorado por Git; el ejemplo anterior usa un marcador y no debe copiarse literalmente. El guard verifica el entorno real local/testing, el nombre de conexión, driver PostgreSQL, ausencia de redirecciones read/write e identidad real de base/usuario/dirección/puerto en la conexión de escritura. Producción y staging rechazan este modo aunque se copie una caché local. La autorización de una conexión permite operaciones funcionales de desarrollo por sus APIs, **no autoriza DDL, fixtures ni pruebas destructivas**. La verificación del entorno aislado anterior queda como alternativa para instalaciones que la utilicen; no se exige a esta conexión expresamente autorizada.

`session_closure` sigue siendo el mismo mecanismo y se identifica como DESARROLLO PROVISIONAL, sin aprobación municipal. Exige sesión Activo, al menos una inscripción Activo, asistencia PRESENTE o AUSENTE para cada inscripción vigente, ausencia de duplicados y actividad Activa con solicitud Aprobada. PENDIENTE, POR_REGULARIZAR, faltantes y estados desconocidos bloquean. No exige horas y no cierra la actividad ni otras sesiones. La consolidación no representa aprobación de regularizaciones institucionales no modeladas. JWT, permisos efectivos, ámbito, transacción, reintento idempotente y log del actor se conservan.

Verificación de lectura tras activar: sesión 2 de actividad 3 continúa Activo, actividad Activa, 2 inscritos vigentes, 2 presentes, 0 ausentes, 0 pendientes y 0 duplicados; el servicio devuelve `closure_blockers=[]`. **No se ejecutó el cierre real desde la auditoría ni se suplantó un usuario.** El GET autenticado combina esos requisitos con el permiso real para habilitar puede_finalizarse.

### Elegibilidad provisional por sesión

El tipo 1, Capacitación, está vigente en el catálogo real y fue declarado explícitamente en la lista local de tipos para este flujo. No se infiere compatibilidad por el nombre del tipo ni se habilitan todos los tipos. En una sesión realmente Finalizada: inscripción Activo + PRESENTE → Elegible provisional; AUSENTE → No elegible; asistencia faltante, desconocida o duplicada → Pendiente de validación. Sesión abierta → No elegible todavía. La evaluación confirma el estado de la sesión persistida, no solo el contexto recibido.

Las respuestas incluyen modo_certificacion/modo_elegibilidad=desarrollo_provisional. Las políticas institucionales continúan vacías y no se aplican estas reglas a actividad global, prácticas ni reconocimientos.

### Registro persistente de preparación: cambio preparado, no aplicado

El esquema real no tiene relación certificado-sesión y certificado.fecha_emision es NOT NULL. Para no inventar una emisión ni modificar certificados históricos, se preparó:

`database/migrations/development/2026_11_10_013039_create_session_certificate_preparations.php`

Esta alternativa reemplaza, para la preparación administrativa de este alcance, la propuesta anterior de agregar columnas a certificado. **No ejecutar ambas propuestas como si fueran pasos del mismo cambio.** El archivo docs/session-certification-schema-proposal.sql se conserva como propuesta histórica de identidad directa del certificado; no fue aplicado.

La nueva propuesta crea certificado.certificacion_sesion_preparacion con identidad de sesión, actividad, participación, persona y tipo; actor, fecha real de preparación, referencia provisional y modo desarrollo; estado Preparada y un id_certificado inicialmente NULL. La preparación tiene su propio id_preparacion: no se presenta como un certificado emitido ni se rellena fecha_emision.

Restricciones propuestas: UNIQUE(sesión,participación,tipo), UNIQUE(id_certificado) para una eventual vinculación, FK compuestas sesión-actividad y participación-actividad-persona, FK de tipo y actor y FK compuesta del certificado vinculado que confirma participación/persona/tipo. Las tres claves UNIQUE compuestas añadidas a padres respaldan esas FK. Todas las relaciones protegen el historial con RESTRICT. Solo se admite Preparada con certificado NULL o Vinculada con certificado real. No se cambia ninguna fila histórica ni la nulabilidad de fecha_emision. La reversión se niega si existen preparaciones.

El archivo está fuera del recorrido normal de migraciones y exige además CERTIFICATION_DEVELOPMENT_SCHEMA_AUTHORIZED=true antes de ejecutar DDL. Esa variable permanece false. **La tabla no existe en la base actual y el cambio no está aplicado ni validado con escrituras PostgreSQL.** La autorización para operar sobre la conexión no se interpreta como autorización para alterar su esquema. Se presentó al usuario el cambio concreto para su aprobación.

### Preparación por el endpoint existente

```http
POST /api/activity-session/2/preparar-certificados
Content-Type: application/json

{"id_tipo_certificado":1}
```

Mantiene JWT, ámbito y permisos actividades.ver, participantes.ver, emision.ver y emision.crear. Exige sesión Finalizada y tipo compatible; evalúa únicamente inscritos/participaciones/personas reales. No admite IDs de preparaciones, certificados, beneficiarios, actor, código, hash o archivo proporcionados por el cliente. Revalida el contexto dentro de la transacción.

Después de aprobar e instalar el esquema, la primera preparación devuelve 201 con preparaciones_creadas y una lista de registros persistidos; un reintento devuelve 200, preparaciones_creadas=0 y las mismas identidades. No altera preparaciones existentes ni vuelve a preparar un documento ya vinculado/registrado. Un certificado previo sin atribución inequívoca a una sesión bloquea con 409 en vez de inferir una sesión o crear un duplicado. Se conserva el registro del actor y no se crea un certificado con una fecha ficticia.

Campos relevantes de data: origen, modo=desarrollo, oficial=false, id_actividad, id_sesion, id_tipo_certificado, total_elegibles, preparaciones_creadas, preparaciones_existentes, preparaciones[], puede_preparar, puede_generar_pdf=false, puede_emitir=false y puede_firmar=false. Cada preparación informa id_preparacion, estado, identidad completa, fecha/actor, id_certificado si está realmente vinculado y estado_generacion. No generado significa que no hay un documento relacionado; Documento registrado describe un registro documental, sin afirmar verificación criptográfica o validez oficial.

**Respuesta actual en esta base: 409 por falta de la tabla**, incluso cuando la sesión se finalice y haya elegibles. No se presenta la persistencia como habilitada antes de aplicar el cambio autorizado.

### Consultas para Angular

| Acción | Endpoint y campos |
| --- | --- |
| Consultar sesión/tarjeta | GET /api/activity-session y GET /api/activity-session/2: estado, finalizada, puede_finalizarse, motivos_bloqueo_finalizacion y contadores |
| Finalizar | POST /api/activity-session/2/finalizar, cuerpo {}; el POST revalida y persiste solo la sesión |
| Ver beneficiarios | GET /api/activity-session/2/beneficiarios?id_tipo_certificado=1 |
| Elegibilidad y preparación | data.tipo_certificado_compatible, modo_certificacion, estado, total_elegibles, puede_preparar y motivos_bloqueo |
| Identidad existente | beneficiarios.data[].id_preparacion, estado_preparacion, certificado_sesion; data.preparaciones[] contiene los registros recuperables |
| Estado documental | estado_generacion, id_certificado_documento y estado_documento; no confundir con emisión oficial |
| Bandeja | GET /api/certificaciones/pendientes?origen=sesion&id_sesion=2&id_tipo_certificado=1: identidad_sesion_disponible, preparaciones y capacidades |

Los contadores y el total de elegibles del flujo provisional no dependen del tamaño de página. El permiso de consulta no habilita puede_preparar cuando falta emision.crear. La bandeja distingue tabla ausente (atribución no determinable) de tabla disponible sin certificados vinculados (cantidad conocida). El detalle de sesión conserva el bloqueo explícito de certificación oficial aunque una preparación de desarrollo esté permitida.

### PDF, QR y emisión

El generador existente sigue requiriendo dompdf/dompdf y bacon/bacon-qr-code. No están instalados ni se han autorizado cambios a Composer. La generación real continúa bloqueada y su prueba se omite; no se genera un PDF/QR ficticio para suplirlo. El generador de documentos administrativos existentes no transforma automáticamente una preparación en una emisión. Falta conectar su generación técnica con las preparaciones sin inventar fecha_emision, además de aprobar plantilla y procedimiento oficial antes de emitir o firmar.

### Evidencia de pruebas

Las pruebas funcionales en memoria cubren dos presentes, presente/ausente, pendientes, sesión abierta/finalizada, más de una sesión de actividad Activa, preparaciones recuperadas sin duplicar, certificados antiguos ambiguos, documento registrado existente, permisos 401/403, conflictos 409, validación 422 y ausencia de escrituras sin autorización. El guard de la propuesta se prueba sin ejecutar DDL. La capa de identidad PostgreSQL se reemplaza explícitamente en esas pruebas para aislar la lógica de negocio; no son una prueba de persistencia en PostgreSQL.

Sobre la conexión actual solo se ejecutaron inspecciones y evaluación de requisitos en READ ONLY con rollback. No se cargaron fixtures, no se levantó Docker, no se creó otra base y no se hizo commit ni push. Los informes anteriores que mencionan un entorno aislado describen revisiones históricas; no representan lo ejecutado en esta revisión.

Resultado del 10 de noviembre de 2026: **213 pruebas aprobadas, 2.664 aserciones; 1 prueba de renderizado omitida** por dependencias pendientes. Pint y git diff --check aprobados. La migración propuesta no se ejecutó; su DDL y restricciones no se presentan como probados en PostgreSQL. El recorrido de escritura se comprobó en las fixtures de pruebas en memoria, no sobre los registros actuales.

## Desarrollo provisional, documento con QR y restricciones actuales

Esta sección prevalece sobre los ejemplos de configuración anteriores. No se crearon migraciones nuevas ni se ejecutó ninguna; se retiró el archivo vacío generado antes de la instrucción de no hacer migraciones. No se modificó el esquema ni los datos institucionales. Docker Desktop fue detenido a petición del usuario; no se levantó un contenedor ni una base de pruebas. Angular, commit y push: sin cambios.

### Activación dentro del flujo de cierre existente

La variable `CERTIFICATION_DEVELOPMENT_CLOSURE_ENABLED=false` queda deshabilitada por defecto. Cuando se establece en true, el mismo `session_closure` contiene una política identificada como **DESARROLLO PROVISIONAL, sin aprobación municipal**; no se agrega otro endpoint ni otro servicio de cierre. Solo autoriza probar la transición Activo → Finalizada, inscripciones Activo, asistencias PRESENTE/AUSENTE, sin horas obligatorias. No configura elegibilidad oficial. Asistencias sin registrar, PENDIENTE, POR_REGULARIZAR, estados desconocidos y duplicados siguen bloqueando. El régimen not_applicable significa únicamente que esta prueba no consulta un trámite institucional de regularizaciones que aún no está modelado; no permite certificar ni declarar inexistentes trámites institucionales.

En cada evaluación de capacidades y antes de abrir la transacción de cierre se exige:

1. Entorno real del proceso Laravel local o testing, incluso si se ha copiado una caché de configuración de otro entorno.
2. Activación explícita y política marcada scope=development. Producción/staging la rechazan con 409.
3. Verificación existente `ActivityRequests\DevelopmentDatabase`: conexión por defecto activity_requests_test, PostgreSQL, host loopback, base certificados_morales_test, usuario certificados_morales_test_app, contraseña exclusiva y confirmación de exclusividad.
4. Sin DB_URL ni conexiones read/write que puedan redirigir las operaciones. Se comprueban también los datos efectivos de la conexión usada por Laravel.
5. Identidad real de base/usuario, rol no administrador y ausencia de permiso CONNECT a certificados_morales.
6. Marca en el catálogo de la base exclusiva: comentario gpc-isolated-session-closure:v1. No basta APP_ENV=local ni cambiar el nombre de la conexión.

Si no se puede comprobar cualquiera de esos puntos, puede_finalizarse=false y el POST devuelve 409 sin ejecutar el UPDATE. No se utiliza SQLite como alternativa para habilitar esta política en una instalación real. La configuración institucional por defecto continúa null cuando la activación está deshabilitada.

Configuración necesaria en un perfil local **que ya apunte a una base exclusiva preparada**, sin reutilizar credenciales institucionales:

```dotenv
APP_ENV=local
DB_CONNECTION=activity_requests_test
ACTIVITY_REQUESTS_DEVELOPMENT_ENABLED=true
ACTIVITY_REQUESTS_TEST_EXCLUSIVE=true
ACTIVITY_REQUESTS_TEST_HOST=127.0.0.1
ACTIVITY_REQUESTS_TEST_PORT=5432
ACTIVITY_REQUESTS_TEST_USERNAME=certificados_morales_test_app
ACTIVITY_REQUESTS_TEST_PASSWORD=CONTRASENA_EXCLUSIVA_LOCAL
CERTIFICATION_DEVELOPMENT_CLOSURE_ENABLED=true
```

El guard reutiliza la infraestructura local de solicitudes. No se modificó .env ni se activó la variable en la conexión actual. Un administrador de la base local aislada deberá comprobar su identidad y aislamiento y, únicamente allí, establecer la marca mediante `COMMENT ON DATABASE certificados_morales_test IS 'gpc-isolated-session-closure:v1'`. Este comentario no crea tablas ni columnas, no es una migración y no fue ejecutado por este trabajo. No aplicarlo sobre una base institucional para eludir el control.

`php artisan activity-requests:check-development --no-interaction` verifica la conexión y las tablas de solicitudes sin escribir. El cierre añade la comprobación de la marca. Con configuración cacheada hay que reconstruir la caché del perfil local de forma explícita; no se modificó la configuración activa de la instalación. La aplicación no cambia de conexión automáticamente al recibir una petición.

El flujo continúa siendo GET `/api/activity-session/{id}`, comprobar puede_finalizarse y motivos; POST `/api/activity-session/{id}/finalizar` con `{}`; GET posterior con finalizada=true y estado=Finalizada. La actividad permanece Activa, las otras sesiones intactas y el reintento no genera un segundo cierre. Conserva permisos efectivos, ámbito, cookie JWT y log de actor/referencia. Los cinco campos de capacidades y los contadores siguen disponibles en listado y detalle.

### Generación documental sin cambios de esquema

Se desarrolló `CertificateDocumentGenerator` para el **registro administrativo de certificado que ya existe por participación en actividad**. No crea registros de certificado, no atribuye un certificado a una sesión y no sustituye `/activity-session/{id}/preparar-certificados`, que permanece bloqueado. Prácticas y reconocimientos conservan sus operaciones anteriores; este generador provisional no cambia sus contratos.

```http
POST /api/certificados/{id_certificado}/generar-documento
Content-Type: application/json

{}
```

Requiere JWT, emision.ver/emision.crear efectivos y ámbito de la actividad del certificado. No admite código, hash, ruta ni actor proporcionados por el cliente. También exige el entorno aislado verificado anterior. La respuesta prevista de creación es 201, y un reintento válido devuelve 200 con la misma identidad documental:

```json
{
  "success": true,
  "message": "Documento de desarrollo guardado; no constituye emisión oficial.",
  "data": {
    "id_certificado_documento": 1,
    "id_certificado": 1,
    "codigo_verificacion": "CODIGO_HEXADECIMAL_DE_30_CARACTERES",
    "hash_certificado": "SHA256_DEL_PDF",
    "url_verificacion": "URL_CONFIGURADA_DEL_BACKEND/api/certificados/verificar/CODIGO",
    "url_descarga": "/api/certificados-documentos/1/archivo",
    "creado": true,
    "modo": "desarrollo",
    "oficial": false,
    "puede_emitir": false,
    "puede_firmar": false
  }
}
```

El ejemplo es ilustrativo, **no evidencia de generación ejecutada**. Se utiliza un código de 120 bits generado por el servidor y la restricción UNIQUE existente. Ese código apunta a la consulta de su documento y se incrusta como QR en el PDF. Se guarda el PDF en certificados/desarrollo dentro del disco local privado; en certificado.certificado_documento se guardan id_certificado, codigo_verificacion, hash_certificado SHA-256, ruta_archivo, fecha_generacion y estado Vigente, compatible con el CHECK real. No se agrega una columna QR ni se modifica fecha_emision del certificado.

La transacción bloquea el certificado existente para serializar generación/reintentos; los índices existentes impiden duplicar código/hash. Si falla el registro se elimina únicamente el archivo nuevo creado por la operación. Un documento previo ajeno a este flujo, anulado, ausente o alterado devuelve 409; no se sobrescribe ni se reemite silenciosamente. Los documentos generados tampoco se pueden modificar mediante el PATCH genérico y cambiarles el código que figura en el QR. El log registra el usuario y las identidades, sin claves privadas.

GET `/api/certificados-documentos/{id}/archivo` requiere JWT, permiso efectivo y ámbito; verifica ruta privada, estado y hash antes de descargar. GET `/api/certificados/verificar/{codigo}` es la consulta pública del QR, limitada por frecuencia y habilitada solo contra el mismo entorno aislado. Devuelve metadatos del registro, sin nombres, DNI ni rutas privadas. emision_oficial_verificada y firma_verificada permanecen false: no interpreta un registro administrativo como prueba criptográfica.

La plantilla resources/views/certificates/development.blade.php indica BORRADOR, SIN EMISIÓN OFICIAL NI FIRMA DIGITAL. No acredita asistencia ni elegibilidad; no se ha proporcionado una plantilla institucional aprobada. No se generan firmas, sellos ni QR ficticios. El QR contendrá una URL construida con APP_URL del backend y una ruta nombrada; debe configurarse con una dirección accesible para quien escanee.

### Dependencias y verificaciones pendientes

**No están instaladas ni autorizadas todavía** las dependencias dompdf/dompdf y bacon/bacon-qr-code consultadas al usuario. Composer no fue modificado. La implementación devuelve 409 explícito si falta el generador real; no registra un supuesto PDF ni sustituye el QR por una imagen ficticia. La prueba de renderizado real se omite hasta instalar las dependencias autorizadas. Por tanto, **PDF/QR no están habilitados ni comprobados de extremo a extremo en esta entrega**.

Las pruebas de documento existentes verifican autenticación, permisos, ausencia de escrituras sin aislamiento, rechazo de payload manipulado, recuperación idempotente de una fixture documental previa, integridad, documentos anulados, protección contra modificación de código y consulta sin datos personales. En esas pruebas el control de aislamiento se reemplaza explícitamente para probar el comportamiento documental sobre SQLite en memoria; no se presentan como comprobación PostgreSQL. El control real de aislamiento y la negativa en local no verificado/producción/staging se prueban aparte.

No se probó un cierre ni una escritura de generación sobre PostgreSQL institucional. Falta una conexión PostgreSQL local exclusiva disponible para validar persistencia/concurrencia real; no se levantará Docker ni se crearán migraciones para sustituirla.

Verificación final: **201 pruebas aprobadas, 2.574 aserciones**; 1 prueba de renderizado omitida por dependencias pendientes y 1 prueba PostgreSQL de cierre omitida por conexión exclusiva ausente. Pint y git diff --check aprobados. El árbol no contiene cambios ni archivos nuevos de migración.

## Revisión de política preparada y capacidades de cierre

Esta sección actualiza los contratos de cierre anteriores. **El bloqueo institucional no está resuelto ni desactivado**: la implementación permite el cierre con una política válida en pruebas, pero no hay una aprobación municipal documentada. `session_closure` permanece en null. No se ejecutó un cierre ni otra escritura en PostgreSQL institucional.

### Política preparada sin activar

`config/certification.php` ahora incluye `session_closure_proposal`, un borrador que el evaluador no consume como política activa:

```php
'session_closure' => null,
'session_closure_proposal' => [
    'reference' => null,
    'final_state' => 'Finalizada',
    'open_states' => ['Activo', 'activo'],
    'active_participation_states' => ['Activo'],
    'eligible_attendance_states' => ['PRESENTE'],
    'ineligible_attendance_states' => ['AUSENTE'],
    'require_entry' => false,
    'require_exit' => false,
    'regularizations' => null,
],
```

Decisiones concretas que debe validar la autoridad municipal competente:

1. Aprobar el cierre individual `Activo`/`activo` → `Finalizada`, dejando la actividad Activa y las otras sesiones intactas.
2. Confirmar `Activo` como inscripción vigente o proporcionar la lista exacta de estados aplicables. El padrón actual es por actividad, no una inscripción separada por sesión.
3. Confirmar PRESENTE y AUSENTE como estados consolidados. Faltantes, PENDIENTE, POR_REGULARIZAR y cualquier estado fuera de esas listas bloquean. AUSENTE puede permitir consolidar el cierre sin convertir a la persona en elegible.
4. Aprobar o modificar los booleanos propuestos `require_entry=false` y `require_exit=false`. Si son true, la implementación exige esas horas a todas las inscripciones vigentes; no las crea automáticamente. Otra regla para ausencias requiere adaptar el evaluador antes de habilitarla.
5. Resolver regularizaciones: solo se soporta `not_applicable` si la norma excluye trámites de regularización pendientes de consulta. Si hay que consultarlos, falta persistencia/procedimiento autorizado y el cierre debe permanecer bloqueado. No se presume que no hay trámites por tener 0 pendientes operativos.
6. Confirmar las facultades de cierre con los permisos efectivos existentes `actividades.ver` y `actividades.editar` y ámbito autorizado. Ser Administrador no sustituye esos permisos ni la aprobación.
7. Entregar la referencia real del acto que aprueba estas reglas; una cadena en configuración no acredita por sí misma su validez jurídica.

Después de aprobación, copiar la propuesta revisada a `session_closure`, completar `reference` y el régimen de regularizaciones autorizado, verificar en base aislada y reconstruir la configuración del despliegue con `php artisan config:cache --no-interaction`. No existe activación mediante HTTP y no se ejecutó ese comando en esta revisión. No basta copiar el borrador o completar solo reference: los null mantienen el bloqueo.

El evaluador exige ahora `final_state='Finalizada'` explícito y rechaza que ese valor figure también en `open_states`. Las configuraciones aprobadas que se preparen a partir de ejemplos anteriores deben añadir ese campo. No se habilitan destinos arbitrarios ni se toma el estado desde el payload. La elegibilidad y la preparación de certificados siguen configurándose por separado; no son requisitos para registrar asistencia ni sustituyen la política de cierre.

### Esquema, validación y límites

El inventario PostgreSQL respalda la relación sesión-actividad, inscripción-actividad/persona, asistencia-inscripción/sesión y UNIQUE(inscripción,sesión). `actividad_sesion.estado` es VARCHAR(30) NOT NULL sin CHECK/ENUM; soporta almacenar Finalizada, pero no prueba su aprobación institucional. El backend comprueba el contexto y deriva la actividad desde la sesión, comprueba inscripciones vigentes, estados consolidados, faltantes, duplicados, horas declaradas y actividad Activa con solicitud Aprobada. Las FK separadas de asistencia no prueban por sí solas que su sesión e inscripción sean de la misma actividad; se conserva la validación de Laravel.

El cierre sigue usando la misma transacción, bloqueos de sesión/actividad, revalidación de ámbito e idempotencia. Solo cambia `actividad_sesion.estado`; no crea certificados ni cierra la actividad. Conserva el evento de auditoría después del commit. El historial institucional inmutable y las regularizaciones todavía requieren persistencia autorizada; el log no los reemplaza. Una sesión ya Finalizada conserva su estado y admite reintento sin necesitar reactivar una política ni generar un segundo cierre.

### GET listado y detalle

GET `/api/activity-session` devuelve en cada `data[]` y GET `/api/activity-session/{id}` devuelve en `data`:

- `estado`: valor real persistido.
- `finalizada`: booleano, true únicamente si estado es Finalizada.
- `puede_finalizarse`: booleano que combina política/requisitos y permiso efectivo; false para sesiones finalizadas.
- `motivos_bloqueo_finalizacion`: lista de motivos. Distingue falta de política de falta de permiso; para una sesión ya finalizada devuelve [] y puede_finalizarse=false.
- `contadores`: el resumen común documentado debajo, independiente de las capacidades de cierre.

El listado obtiene requisitos de cierre por lote mediante el mismo servicio, sin consultas de validación por cada sesión. El detalle y el POST reutilizan ese evaluador. Los booleanos informativos no reemplazan la validación del POST. Se conservan los campos de capacidades de certificación previos, que siguen bloqueadas.

### Endpoint y pasos posteriores

Angular conserva `POST /api/activity-session/2/finalizar`, JSON `{}` y cookie JWT HttpOnly. Opcionalmente puede enviar el contexto consistente `{"id_actividad":3,"id_sesion":2,"origen":"sesion"}`. Un contexto diferente devuelve 422. Sin sesión autenticada devuelve 401; falta de permiso/ámbito, 403; falta de política o requisitos, 409; cierre autorizado/reintento, 200.

Tras un 200, volver a consultar detalle/listado: sesión Finalizada, finalizada=true, puede_finalizarse=false, actividad Activa y contadores conservados. Consultar después GET `/api/activity-session/2/beneficiarios?id_tipo_certificado={tipo}`. Esto no prepara certificados automáticamente. POST `/api/activity-session/2/preparar-certificados` conserva 409 mientras falten política de elegibilidad aprobada, identidad certificado-sesión, preparación documental e integración oficial de emisión. No se generan PDF, QR ni firmas simuladas.

### Pruebas de esta revisión

Se agregaron escenarios HTTP para borrador inactivo, referencia insuficiente sin decisión sobre regularizaciones, cierre con política exclusiva de pruebas sin política de elegibilidad, destino explícito obligatorio, origen/destino disjuntos, campos coincidentes en listado/detalle, sesión finalizada con política retirada, permiso de consulta sin edición, entrada/salida condicionales y consultas por lote. Se amplió la cobertura de pendientes a PENDIENTE y POR_REGULARIZAR. Se conserva cobertura de 401/403/409/422, dos presentes, duplicados, contexto, ausencia de certificados nuevos, actividad Activa y reintento idempotente.

Las pruebas HTTP ejecutadas usan la base SQLite en memoria existente. Se intentó la prueba PostgreSQL aislada de cierre y quedó omitida por falta de `MODULES_PG_TEST_URL`; no se utilizó la base institucional para reemplazarla. No se afirma validación PostgreSQL de escrituras o concurrencia.

Resultado actual: **163 pruebas aprobadas, 2.398 aserciones; 1 prueba PostgreSQL de cierre omitida**. Pint y `git diff --check` aprobados. Sin modificaciones a Angular, escrituras institucionales, commit ni push.

## Actualización del 9 de noviembre de 2026: cierre individual y contadores

### Diagnóstico comprobado

Consulta institucional exclusivamente de lectura, en transacción `REPEATABLE READ READ ONLY`, comprobando `transaction_read_only=on` y terminando con rollback:

| Recurso | Identidad y estado observados |
| --- | --- |
| Actividad | `id_actividad=3`, `Capacitacion Personal`, `Activa` |
| Sesión | `id_actividad_sesion=2`, `Sesión N°001`, `Activo` |
| Inscripciones | 2 personas inscritas, ambas con participación vigente según el valor observado `Activo` |
| Asistencias de esa sesión | 2 presentes, 0 ausentes, 0 pendientes, 0 duplicadas |

El valor 1 de la tarjeta no coincide con lo persistido. No se inspeccionó ni modificó Angular, por lo que no se atribuye a una consulta, caché o cálculo concreto del frontend. Antes de esta ampliación, `ActivityData::getActivities/getActivityById` devolvían filas de actividad y su origen, sin contadores de inscritos; `ActivitySessionData::getActivitySessions/getActivitySessionById` devolvían filas de sesión sin resumen. `ParticipationData::getParticipations` lista inscripciones (incluidas inactivas) y `AttendanceData::getAttendances` lista asistencias con filtros. Contar filas de esos listados, o la longitud de una página de beneficiarios, no constituye un contrato común de contadores.

El bloqueo de cierre está comprobado en configuración: `certification.session_closure=null`. No se encontró un acto municipal aprobando la política. El permiso del Administrador no sustituye esa decisión. No se ejecutó el POST de cierre sobre la sesión institucional.

### Contrato común de contadores para Angular

Se conserva el contrato anterior y se agrega `contadores` en:

- GET `/api/activity-session`: `data[].contadores`, para cada tarjeta de sesión.
- GET `/api/activity-session/{id}`: `data.contadores`, para detalle y modal.
- GET `/api/activity-session/{id}/beneficiarios`: `data.contadores`, independiente de la paginación de personas.
- POST `/api/activity-session/{id}/finalizar`: `data.sesion.contadores`, tanto en éxito como en bloqueo por política.

Los cuatro puntos reutilizan `CertificationReadiness::addAttendanceCounters`. El listado calcula todos sus resúmenes en una consulta por lote, sin una consulta adicional por cada sesión. No se agregaron rutas ni se cambió la autenticación, el ámbito o los permisos.

Ejemplo comprobado en la consulta de lectura para la sesión 2:

```json
{
  "inscritos_actividad_total": 2,
  "inscritos_actividad_vigentes": 2,
  "participantes_sesion": 2,
  "presentes": 2,
  "ausentes": 0,
  "pendientes": 0,
  "por_regularizar": null,
  "asistencias_duplicadas": 0,
  "configuracion_conteo_valida": true,
  "estados_inscripcion_vigentes": ["Activo"],
  "motivo_regularizaciones": "No existe un registro institucional de regularizaciones; no se puede determinar su cantidad."
}
```

`inscritos_actividad_total` cuenta personas distintas en todas las inscripciones de la actividad, incluyendo inactivas. Los demás contadores numéricos consideran únicamente personas con inscripción vigente. El esquema inscribe por actividad, sin una inscripción ni lista de convocados independiente por sesión; por eso `participantes_sesion` representa todos los inscritos vigentes actuales de su actividad. No representa un padrón histórico congelado al cierre. No se utilizan IDs de filas del frontend.

Los nombres operativos se configuran en `certification.attendance_summary`: inscripción `Activo`, presencia `PRESENTE`, ausencia `AUSENTE`. Son etiquetas de lectura compatibles con los datos y contratos existentes, no una norma de cierre ni elegibilidad. Cuando existe una política válida de cierre, sus `active_participation_states` determinan también las inscripciones que usa el resumen. Los estados se comparan exactamente, sin convertir mayúsculas/minúsculas.

Falta de asistencia, estado desconocido o duplicado de una persona se clasifican como `pendientes`; nunca se deduce AUSENTE a partir de una fila inexistente. Solo se consideran asistencias de la sesión y de participaciones de su actividad. Un duplicado no suma otra persona ni dos presentes: se informa en `asistencias_duplicadas` y bloquea una nueva finalización. Se conserva el UNIQUE de asistencia y no se modifican registros históricos para corregir contadores.

Con una configuración de conteo válida se cumple `participantes_sesion = presentes + ausentes + pendientes`. Una configuración malformada devuelve `configuracion_conteo_valida=false` y contadores clasificados en null, evitando ceros engañosos. `por_regularizar=null` significa cantidad no determinable; ni PENDIENTE ni un texto POR_REGULARIZAR demuestran un trámite institucional. No existe persistencia de ese procedimiento y no se informa falsamente cero. Las horas y requisitos para cerrar se evalúan por separado: 0 pendientes operativos no garantiza que una política que exige horas permita cerrar.

Angular debe utilizar este mismo objeto para tarjeta y modal y volver a consultar después de guardar/corregir asistencia. El POST de asistencia conserva su respuesta anterior; el resumen no se almacena ni se mantiene como contador desnormalizado. Peticiones realizadas en momentos diferentes pueden reflejar escrituras concurrentes diferentes. Los contadores no conceden facultades de emisión ni sustituyen `puede_finalizarse` y sus motivos.

### Configuración exacta propuesta, pendiente de autorización

Se conserva `session_closure=null`. La siguiente propuesta **no está activada**. Antes de reemplazar ese null, la autoridad municipal competente debe aprobar expresamente la transición individual, el padrón de inscripciones vigentes, la clasificación de asistencias, la ausencia de exigencia horaria propuesta y el tratamiento de regularizaciones. Debe aportar la referencia real del acto y confirmar quién puede finalizar con los permisos vigentes. La aprobación de una actividad no equivale a la aprobación de esta política.

```php
'session_closure' => [
    'reference' => null, // Sustituir solo por la referencia REAL de aprobación.
    'open_states' => ['Activo', 'activo'],
    'active_participation_states' => ['Activo'],
    'eligible_attendance_states' => ['PRESENTE'],
    'ineligible_attendance_states' => ['AUSENTE'],
    'require_entry' => false,
    'require_exit' => false,
    'regularizations' => 'not_applicable',
],
```

La referencia null hace que incluso una copia accidental permanezca bloqueada. `not_applicable` solo puede aprobarse si efectivamente no hay regularizaciones que consultar en este procedimiento. Si deben existir, falta diseñar/autorizar su persistencia y evaluación; no basta cambiar esa cadena. Si se aprueban horas obligatorias, usar true explícito y registrar las horas reales: el backend no las completa. La implementación actual exige las horas declaradas a todas las inscripciones vigentes, también cuando la asistencia se clasifica como incumplimiento; una norma que requiera otro tratamiento necesita una adaptación explícita y sus pruebas antes de habilitarla.

Con una política válida, una asistencia faltante o no consolidada bloquea; una ausencia explícitamente consolidada puede permitir finalizar y seguir siendo no elegible. También se exige actividad Activa y solicitud Aprobada vinculada. La referencia por sí sola no verifica validez jurídica: la aprobación debe comprobarse fuera del payload HTTP y quedar documentada institucionalmente.

El esquema auditado tiene `actividad_sesion.estado VARCHAR(30) NOT NULL`, sin CHECK ni ENUM de estados; se observaron `Activo` y `activo`, y en la lectura actual de la sesión 2 se comprobó `Activo`. `Finalizada` es la transición implementada, pendiente de aprobación institucional; cabe en esa columna. `actividad.estado` tiene CHECK Activa/Cerrada. El cierre básico no requiere DDL. La trazabilidad inmutable y las regularizaciones requieren persistencia adicional autorizada; el evento de log posterior al commit no las sustituye.

Después de aprobación: actualizar `config/certification.php`, verificar la política y el escenario en base aislada y reconstruir la configuración del despliegue con `php artisan config:cache --no-interaction`. Este trabajo no ejecutó ese comando ni activó la propuesta.

### Endpoint definitivo y capacidades

```http
POST /api/activity-session/2/finalizar
Content-Type: application/json

{}
```

Mantiene JWT en cookie HttpOnly, `actividades.ver` y `actividades.editar` efectivos y ámbito autorizado. No hay bypass de Administrador. Devuelve 401 sin autenticación, 403 por permiso/ámbito, 409 por política/requisitos/incidencias y 422 por contexto o campos prohibidos. Cuando procede, cambia solo `actividad_sesion.estado`, conserva la actividad Activa y las otras sesiones, devuelve `finalizada=true` y admite reintento idempotente. No crea certificados. El control usa bloqueo de sesión/actividad, transacción y revalidación del ámbito.

Para el botón: GET `/api/activity-session/2`, leer `data.puede_finalizarse`, `data.motivos_bloqueo_finalizacion` y `data.estado`; el POST siempre revalida. Hoy permanece bloqueado por aprobación/configuración pendiente.

Se conservan GET `/api/activity-session/2/beneficiarios?id_tipo_certificado={tipo}` y POST `/api/activity-session/2/preparar-certificados`. Para certificar a las dos personas aún hacen falta política de elegibilidad aprobada por tipo, cierre autorizado, identidad persistente certificado-sesión, preparación documental e integración oficial de emisión. La preparación sigue devolviendo 409 sin escrituras; no se generaron PDFs, QR ni firmas.

### Validación de esta ampliación

Se agregaron pruebas de dos presentes con resumen idéntico en listado/detalle/beneficiarios y cierre, independencia de paginación, inactivos, otras sesiones, faltantes, estados desconocidos, configuración malformada, duplicados históricos y padrón definido por la política. Se conserva la cobertura HTTP 401/403/409/422, idempotencia, horas, ausencia de certificados nuevos y actividad Activa. Los escenarios HTTP ejecutados usan la base SQLite en memoria existente; no equivalen a validación de concurrencia PostgreSQL.

Se agregó `PostgresModuleContractTest::test_two_present_participants_have_consistent_counters_and_individual_idempotent_closure`, que usa el DDL local real, dos inscripciones/asistencias, UNIQUE, la implementación real de cierre y rollback, bajo las protecciones existentes de base exclusiva. La fixture de solicitud aporta los campos leídos por cierre porque el DDL base de esa suite precede al módulo de solicitudes. La prueba de cierre y la de identidad propuesta se intentaron ejecutar y quedaron **omitidas: falta MODULES_PG_TEST_URL**. No hay servidor Docker disponible ni instalación PostgreSQL local detectada; no se sustituyó esa base por la institucional. La lectura institucional comprueba los contadores, no ejecuta cierre ni valida escrituras.

Resultado de esta revisión: **151 pruebas aprobadas, 2.325 aserciones; 2 pruebas PostgreSQL omitidas**. Pint y `git diff --check` aprobados.

Comandos:

```text
php -d extension=pdo_sqlite vendor/bin/phpunit tests/Feature/AttendanceHttpIntegrationTest.php tests/Feature/CertificationReadinessTest.php tests/Feature/AttendanceTest.php tests/Feature/PatchCrudTest.php tests/Feature/ModuleIntegrationTest.php tests/Feature/CertificationModuleTest.php tests/Feature/GestionCertificationProtectionTest.php --no-progress
php vendor/bin/phpunit tests/Feature/PostgresModuleContractTest.php --group postgresql --exclude-group none --filter "test_two_present_participants|test_proposed_session_identity" --no-progress
php vendor/bin/pint --dirty --format agent
git diff --check
```

No se modificó Angular, no se escribieron datos institucionales y no se hizo commit ni push. Las secciones siguientes conservan el informe previo y sus resultados históricos.

## Integración final: informe y contrato de asistencia

Esta sección actualiza el informe previo que se conserva debajo. No se escribió en PostgreSQL institucional, no se modificó Angular y no se hizo commit ni push. Se revisaron los cambios parciales existentes antes de ampliarlos. Boost/search-docs no están disponibles en esta sesión; se consultaron las fuentes instaladas del framework y su documentación oficial.

### 1. Causa comprobada y límites del diagnóstico

Se reprodujo HTTP 422 con JWT válido y Administrador con permisos efectivos: al deshabilitar exclusivamente `NormalizeAttendanceInput`, el POST en minúsculas no satisface los campos obligatorios en mayúsculas del controlador. La prueba `test_without_normalization_lowercase_create_reproduces_422_with_valid_cookie_and_permissions` conserva esa evidencia. Con el middleware activo, el mismo formato guarda y se recupera desde la base aislada. El controlador anterior también ignoraba campos minúsculos en PATCH, pudiendo responder éxito sin aplicar esos cambios; esto se comprobó por revisión del diff, no mediante una ejecución del controlador histórico.

No se dispone de la petición/respuesta fallida de Angular, sus cookies ni sus IDs seleccionados: no se afirma que esa incompatibilidad sea la única causa del incidente real. No se atribuyó el fallo a permisos. `User::permissionKeys()` ya concede al Administrador las capacidades activas previstas por la configuración del proyecto; la prueba usa sus tablas reales de roles/módulos/permisos en una base aislada, sin reemplazar el Gate. Desactivar el permiso efectivo de editar produce 403 incluso para Administrador.

### 2. Correcciones revisadas y completadas

- Normalización de campos minúsculos antes de comprobar el ámbito; se mantiene compatibilidad con mayúsculas. Dos alias con valores diferentes producen 422.
- Horas explícitas `HH:mm` o `HH:mm:ss`, conservando segundos. Presencia y horas son datos independientes: no se completa ninguna hora automáticamente y un PATCH de estado conserva las horas previas.
- Filtros de listado para recuperar la asistencia existente por actividad, sesión y participación. PATCH con campos ajenos al contrato devuelve 422 si no incluye ningún campo reconocido.
- Validación del contexto opcional `id_actividad` tanto al crear como al corregir; no se ignora un ID de actividad contradictorio. La participación y la sesión deben pertenecer a la misma actividad.
- Se conserva UNIQUE(participación, sesión), la comprobación transaccional y los bloqueos de registros certificados/finalizados. No se convierte un POST duplicado en una corrección silenciosa.
- Se agregaron compatibilidad explícita del tipo, indicador de sesión finalizada y una operación de preparación bloqueada, con pruebas de autorización, contexto y ausencia de escrituras.
- Las políticas malformadas, incluyendo listas con valores que no sean estados de texto, bloquean el flujo en lugar de producir un error 500 o habilitarlo.

### 3. Guardar, consultar y corregir asistencia

Todas las peticiones utilizan la cookie JWT HttpOnly del login; Angular debe enviarlas con credenciales y `Content-Type: application/json`. No colocar el JWT en el cuerpo. Permisos reales: `participantes.crear` para crear, `participantes.ver` para consultar y, para corregir, consulta más `participantes.editar`; además se comprueba el ámbito autorizado. No basta el nombre del rol.

Ejemplos con IDs **exclusivos de fixtures de prueba**; sustituirlos por los IDs recuperados del backend. `id_participacion` es la PK de inscripción en `certificado.participacion`, no el ID de persona ni el índice de una fila en Angular.

```http
POST /api/attendance/create
Content-Type: application/json

{"id_actividad":1,"id_participacion":1,"id_actividad_sesion":1,"estado":"PRESENTE"}
```

201 devuelve `data.ID_ASISTENCIA`, `ID_PARTICIPACION`, `ID_ACTIVIDAD_SESION`, `ESTADO`, `HORA_ENTRADA` y `HORA_SALIDA`. Las horas omitidas quedan NULL. `id_actividad` es opcional y solo comprueba contexto; se deriva la relación real de participación/sesión, sin agregar columnas.

```http
GET /api/attendance/list?id_actividad=1&id_actividad_sesion=1&id_participacion=1
GET /api/attendance/1
PATCH /api/attendance/update/1
Content-Type: application/json

{"estado":"AUSENTE"}
```

PUT también está soportado y conserva el comportamiento parcial existente. Para agregar/corregir una hora, enviar explícitamente `{"hora_entrada":"08:00:31"}`; NULL explícito elimina esa hora únicamente si la corrección está autorizada. Se comprueba salida >= entrada cuando ambas están presentes. Los campos de respuesta conservan MAYÚSCULAS.

No existe un catálogo institucional de estados de asistencia ni CHECK en el inventario PostgreSQL auditado: el registro admite texto no vacío de hasta 30 caracteres. `PRESENTE`, `AUSENTE` y `PENDIENTE` son ejemplos de registro/pruebas, no un catálogo municipal aprobado. No se normaliza el valor del estado. La política debe declarar los valores exactos que consolidan, cumplen o incumplen; un estado desconocido queda pendiente de validación y nunca se convierte automáticamente en elegible.

Un POST repetido para la misma participación/sesión devuelve 422 y conserva el registro existente. Recuperar su `ID_ASISTENCIA` y usar PATCH con permiso de editar mientras la sesión permita corrección. Una sesión Finalizada rechaza PATCH/DELETE con 409; crear asistencia en ella devuelve 422 con el motivo de regularización. Si la participación ya sustenta certificados, se conserva y devuelve 409. El procedimiento institucional de regularización posterior al cierre no existe todavía: no usar DELETE/POST, mover registros ni reabrir sesiones como sustituto.

### 4. Finalizar sesión

```http
POST /api/activity-session/1/finalizar
Content-Type: application/json

{}
```

También acepta `{"id_actividad":1,"id_sesion":1,"origen":"sesion"}` si coincide con la ruta. No enviar estado, usuario ni persona. Requiere `actividades.ver` y `actividades.editar` efectivos y ámbito de la sesión. Devuelve 200 con `data.finalizada=true`, `data.sesion` y `data.estado_actividad`; el reintento es idempotente. La actividad permanece Activa y las demás sesiones no se cierran. Con la configuración entregada devuelve 409 y `data.motivos_bloqueo`; falta de configuración no significa falta de permiso.

### 5. Paso institucional pendiente para habilitar cierre y elegibilidad

El mecanismo es `config/certification.php`: `session_closure=null`, `eligibility.sesion=[]` y `eligibility.actividad=[]` permanecen deshabilitados. No hay interruptor HTTP ni variable que apruebe una política.

La autoridad municipal competente debe aprobar y proporcionar la referencia del acto, estados exactos de inscripción vigente, estados consolidados de asistencia, tratamiento de ausencias/regularizaciones, exigencia o no de entrada/salida, transición de sesión y tipos de certificado aplicables. Este cambio técnico no determina qué órgano tiene esa competencia.

Una vez aprobada, completar en el servidor `session_closure` con `reference`, `open_states`, `active_participation_states`, `eligible_attendance_states`, `ineligible_attendance_states`, `require_entry`, `require_exit` y `regularizations`. Las listas deben contener texto no vacío de hasta 30 caracteres; los estados elegibles/no elegibles deben ser disjuntos. Los booleanos deben ser explícitos. Solo se admite `regularizations=not_applicable` si la norma realmente excluye regularizaciones; otro régimen requiere implementar su persistencia y evaluación.

Configurar por separado `eligibility.sesion.<ID_REAL_TIPO>` con la política aprobada y `require_closed_activity` explícito. Para el flujo solicitado debe ser false **solo si lo autoriza la norma**. La política global en `eligibility.actividad.<ID_REAL_TIPO>` es independiente y requiere también `required_sessions=all`. No copiar las políticas exclusivas de pruebas como normas municipales.

Validar en entorno aislado y reconstruir la configuración del despliegue mediante `php artisan config:cache --no-interaction` cuando se apruebe el cambio. No se ejecutó ese comando en este trabajo. El cierre básico no necesita columnas nuevas: usa el VARCHAR de estado existente, actividad Activa, solicitud Aprobada y consolidación de todas las inscripciones vigentes. La trazabilidad institucional inmutable y las regularizaciones sí necesitan diseño/persistencia adicional autorizados; el log actual después del commit no los sustituye.

### 6. Consultar elegibles y certificados existentes

```http
GET /api/activity-session/1/beneficiarios?id_tipo_certificado=1
GET /api/activity/1/beneficiarios-certificacion?id_tipo_certificado=1
GET /api/certificaciones/pendientes?origen=sesion&id_actividad=1&id_sesion=1&id_tipo_certificado=1
```

No se duplicaron rutas de elegibilidad. En beneficiarios, leer `data.beneficiarios.data[].estado_elegibilidad` y `motivos`: Elegible / No elegible / Pendiente de validación. El tipo debe estar vigente y tener política válida; PRESENTE por sí solo no basta. La ausencia solo excluye cuando la política define su valor. Se exige sesión Finalizada para la elegibilidad de sesión, sin reutilizar como equivalente la evaluación global.

`certificados_registrados_participacion` informa registros administrativos existentes. `certificado_sesion=null` y `certificados_atribuidos_sesion=null` significan identidad no demostrable con el esquema actual, **no ausencia demostrada** de certificados ni permiso para crear otro. No existe aún una preparación oficial persistida cuyo estado pueda recuperarse inequívocamente por sesión.

### 7. Preparar certificados: endpoint y bloqueo explícito

```http
POST /api/activity-session/1/preparar-certificados
Content-Type: application/json

{"id_tipo_certificado":1,"id_actividad":1,"id_sesion":1,"origen":"sesion"}
```

Solo `id_tipo_certificado` es obligatorio. JWT, ámbito y permisos efectivos `actividades.ver`, `participantes.ver`, `emision.ver` y `emision.crear`. Este último es el permiso administrativo existente, no un permiso formal de preparación inventado ni autoridad de firma.

Tras autorizar y validar contexto devuelve **409 sin escrituras**, incluso para una sesión con elegibles: `data.estado_preparacion=Bloqueada`, `puede_preparar=false`, `puede_emitir=false`, `puede_firmar=false`, `certificado_sesion=null` y `motivos_bloqueo`. Faltan identidad persistente, estados/fecha de preparación, procedimiento documental y soporte oficial. No genera PDF, QR ni firma. No enviar fecha_emision, estado, id_usuario o id_persona: se rechazan con 422. El CRUD `/api/certificados/create` no sustituye esta operación.

### 8. Campos para botones de Angular

| Acción o indicador | Campos a leer |
| --- | --- |
| Finalizar | GET `/api/activity-session/{id}`: `data.puede_finalizarse` y `data.motivos_bloqueo_finalizacion`; el POST revalida |
| Sesión finalizada | detalle: `data.estado`; beneficiarios: `data.finalizada` y `data.contexto.estado_sesion` |
| Compatibilidad del tipo | beneficiarios/bandeja: `tipo_certificado_compatible`: true con tipo vigente y política válida, false si no está vigente, null si falta selección/política válida |
| Tipos con política aplicable | bandeja: `data.data[].tipos_certificado_permitidos`; no implica emisión habilitada |
| Tiene elegibles | bandeja: `total_elegibles` del contexto/tipo; lista paginada de beneficiarios para identificar cuáles |
| Preparar / emitir / firmar | beneficiarios: `data.puede_preparar`, `puede_emitir`, `puede_firmar` y `motivos_bloqueo`; actualmente false |
| Certificado ya registrado | `beneficiarios.data[].certificados_registrados_participacion`; no atribuirlo a la sesión |

Los booleanos informativos no reemplazan autorización ni validación del POST. La bandeja usa `data.data[]`, mientras beneficiarios usa `data.beneficiarios.data[]`; respetar la paginación. 401 exige autenticación, 403 permiso/ámbito, 409 conflicto/bloqueo del procedimiento y 422 payload/relación inválidos.

### 9. Esquema pendiente y propuesta SQL

La propuesta exacta de abajo se entrega también en [session-certification-schema-proposal.sql](session-certification-schema-proposal.sql). No es una migración ejecutable automáticamente y **no se aplicó**. Agrega dos columnas NULL de actividad y sesión al certificado; mantiene persona, participación y tipo existentes. El origen se deriva inequívocamente: participación con sesión = sesión; participación sin sesión = actividad; práctica/reconocimiento conservan sus orígenes y CHECK exclusivo. Las FK compuestas impiden cruces de actividad/persona y los índices parciales impiden duplicados por identidad/tipo.

Antes de aprobar aplicación: auditar duplicados e inconsistencias históricos, sin inferir sesiones ni corregir automáticamente. Además se debe resolver `fecha_emision NOT NULL` y definir el registro de preparación, políticas/versionado e historial de cierre/regularización. La extensión de identidad por sí sola **no habilita emisión oficial**.

Se añadió `PostgresModuleContractTest::test_proposed_session_identity_rejects_duplicates_and_ambiguous_contexts`, que aplica exactamente el archivo SQL a esquemas de prueba, comprueba duplicado, contexto incompleto, actividad/persona incompatible y coexistencia de certificados globales y de prácticas. Hereda la protección existente: URL dedicada `MODULES_PG_TEST_URL`, `MODULES_PG_TEST_EXCLUSIVE=1`, base vacía llamada `certificados_morales_test`, sin heredar credenciales institucionales, DDL dentro de transacción y rollback. **Se intentó ejecutarla y quedó omitida por falta de la URL exclusiva; no se afirma que el DDL esté validado en PostgreSQL.**

Comando para ese entorno exclusivo, una vez provisionado y configuradas esas variables:

```text
php vendor/bin/phpunit tests/Feature/PostgresModuleContractTest.php --group postgresql --exclude-group none --filter test_proposed_session_identity --no-progress
```

### 10. Pruebas ejecutadas en esta revisión

Las pruebas funcionales existentes y nuevas usan SQLite en memoria con esquemas adjuntos; las operaciones pasan por Laravel, su middleware y persistencia aislada. No son evidencia de ejecución en PostgreSQL ni de una llamada de red desde Angular.

Se probaron guardado/recuperación, corrección, horas con segundos y NULL, ausencia, pendientes, sesión activa/finalizada, duplicados, contexto incorrecto, JWT ausente/inválido, permisos efectivos del Administrador y ámbito. Las pruebas de certificación cubren cierre bloqueado sin política, permitido con política exclusiva de prueba, actividad que permanece Activa, idempotencia, elegibilidad/no elegibilidad/pendiente, certificados administrativos previos, preparación bloqueada y respuestas 401/403/409/422. También políticas malformadas sin error 500.

```text
php -d extension=pdo_sqlite vendor/bin/phpunit tests/Feature/AttendanceHttpIntegrationTest.php tests/Feature/CertificationReadinessTest.php tests/Feature/AttendanceTest.php tests/Feature/PatchCrudTest.php tests/Feature/ModuleIntegrationTest.php tests/Feature/CertificationModuleTest.php tests/Feature/GestionCertificationProtectionTest.php --no-progress
php vendor/bin/pint --dirty --format agent
git diff --check
```

Resultado final: **146 pruebas funcionales aprobadas, 2.276 aserciones**, en los siete archivos indicados. La prueba PostgreSQL de identidad se intentó ejecutar: **1 omitida**, porque falta `MODULES_PG_TEST_URL`; no se conectó a la base institucional. Pint y `git diff --check` aprobados. PostgreSQL institucional: ninguna escritura. Emisión oficial, regularización persistente y concurrencia PostgreSQL: pendientes de soporte/autorización y entorno aislado.

## Resultado y límites

Se implementaron consultas reales de preparación operativa, beneficiarios y elegibilidad diferenciada, además de una operación transaccional de finalización individual. La configuración productiva mantiene el cierre y la evaluación positiva deshabilitados mientras no exista una política municipal aprobada. La preparación, emisión oficial y firma siguen bloqueadas: el modelo actual no identifica certificados por sesión y faltan el procedimiento documental y la integración criptográfica.

**No está completado el recorrido hasta emisión/firma.** No se ejecutaron migraciones ni escrituras en PostgreSQL institucional, no se modificó Angular y no se hizo commit ni push. Los estados calculados de la respuesta no se guardan como estados institucionales.

## Evidencia de PostgreSQL

[Inventario completo de columnas, FK, CHECK e índices](certification-schema-audit.json). Se consultaron los catálogos de PostgreSQL con transacciones `REPEATABLE READ, READ ONLY`, comprobando `transaction_read_only = on` y terminando con rollback. El inventario contiene 192 columnas, 106 restricciones y 50 índices; no contiene personas, documentos de identidad, credenciales ni claves privadas.

| Esquema | Tablas existentes |
| --- | --- |
| acceso | modulo, permiso, rol, rol_modulo, rol_modulo_permiso, usuario, usuario_rol |
| gestion | area, institucion, persona, persona_documento, persona_ponente, persona_practicante, persona_trabajador, practica, practica_supervisor, tipo_documento |
| certificado | actividad, actividad_firma, actividad_ponente, actividad_sesion, asistencia, certificado, certificado_documento, certificado_firma, consulta_certificado, firma, participacion, reconocimiento, solicitud_actividad, solicitud_decision, tipo_actividad, tipo_certificado, tipo_participacion |

Las entidades operativas están en `certificado`, no en `gestion`. Las FK reales enlazan:

- solicitud_actividad → area, usuario solicitante, tipo_actividad y actividad; actividad enlazada única. Un vínculo a actividad solo está permitido para una solicitud Aprobada. solicitud_decision enlaza solicitud y usuario responsable.
- actividad → area y tipo_actividad; actividad_sesion → actividad. Ambas relaciones tienen modelos Eloquent. Activity tiene sourceRequest, sessions y participations.
- participacion → actividad, persona y tipo_participacion. UNIQUE(persona, actividad): la inscripción es por actividad.
- asistencia → participacion y actividad_sesion. UNIQUE(participacion, sesión). La coincidencia de actividad se valida en el código; las dos FK separadas no la garantizan en SQL.
- actividad_ponente → actividad y persona_ponente → persona e institución. No existe vínculo de actuación del ponente con una sesión ni origen de certificado para ponente.
- practica → persona_practicante → persona; también area e institución. reconocimiento → persona_trabajador → persona y area. Ninguno exige actividad.
- certificado → persona, tipo_certificado y exactamente uno de participacion, practica o reconocimiento (`certificado_origen_check`). No tiene FK de sesión, área, actividad, plantilla o política.
- certificado_documento → certificado; codigo_verificacion y hash_certificado son únicos globalmente. certificado_firma → documento y firma; UNIQUE(documento, firma). firma → persona_trabajador, único por trabajador; contiene PFX y passphrase cifrada. actividad_firma asigna firma y orden a actividad.

### Estados permitidos frente a observados

| Entidad | Restricción real | Valores observados en esta consulta |
| --- | --- | --- |
| actividad | CHECK Activa/Cerrada | La respuesta de ejemplo conserva Activa |
| actividad_sesion | VARCHAR(30), NOT NULL, sin CHECK/ENUM | activo, Activo |
| participacion | VARCHAR, NOT NULL, sin catálogo de estados | Activo |
| asistencia | VARCHAR, NOT NULL, sin CHECK/ENUM | PRESENTE, nn |
| certificado | VARCHAR(30), NOT NULL, sin catálogo | Sin registros |
| certificado_documento | CHECK Vigente/Anulado | No se infirió emisión a partir de filas |
| practica | Texto sin política de conclusión | activo |
| reconocimiento | Texto sin política de validez | ca |
| solicitud_actividad | CHECK Borrador, Enviada, En revisión, Observada, Pendiente de aprobación, Aprobada, Rechazada | El inventario no publica solicitudes |
| solicitud_decision.tipo_decision | CHECK REVISION_CONFORME, OBSERVAR, DERIVAR_GERENCIA, APROBAR, RECHAZAR | No se modificaron decisiones |

`Finalizada` es la transición solicitada por este requerimiento y cabe en el VARCHAR real; **no era un estado observado ni un catálogo municipal previamente definido**. No se introdujo un estado de anulación de sesiones. Antes de habilitar el cierre se deben aprobar la transición, los estados de origen y las condiciones de consolidación.

Catálogos reales: tipo_certificado 1 Capacitación, 2 Reconocimiento, 3 Prácticas, todos activos en la consulta. tipo_participacion 1 Municipal, 2 Externo. Ninguna columna declara qué tipo permite certificación por sesión, actividad o ponente. El nombre Capacitación no basta para deducir ese alcance.

## Cambios implementados

- CertificationReadiness concentra la evaluación y la finalización; las reglas se leen de configuración del servidor, nunca de Angular.
- Finalización con bloqueo de sesión y actividad, transacción, hasta tres intentos ante deadlock y reintento idempotente. Comprueba ámbito nuevamente dentro de la transacción. Mantiene la actividad y las demás sesiones sin cambios y no crea certificados.
- Para una transición nueva exige política válida, estado de origen autorizado, actividad Activa y solicitud Aprobada vinculada. Examina las asistencias de inscripciones vigentes según esa política y bloquea faltantes, estados no consolidados y entrada/salida solo cuando la política los exige.
- Registra un evento de auditoría estructurado después del commit con usuario autenticado, actividad, sesión y referencia de política. Este log **no sustituye un historial institucional transaccional** ni proporciona por sí solo evidencia de cierre inmutable.
- El CRUD genérico no puede crear/finalizar una sesión como Finalizada ni reabrir o modificar una ya finalizada. Tampoco puede eliminarla. Las asistencias de una sesión finalizada no se pueden editar, trasladar o eliminar por CRUD; las nuevas requieren un procedimiento de regularización que aún no existe.
- Beneficiarios y bandeja usan consultas por lotes, sin consultas por persona. Paginación de 1 a 100, con filtros conservados en enlaces relativos de navegación. La bandeja carga las participaciones de las actividades de la página para evaluar los totales: no se presenta como optimizada para volúmenes masivos sin medición.
- El CRUD administrativo de certificados rechaza id_sesion, id_actividad_sesion y origen, evitando ignorar silenciosamente un contexto de sesión que no puede persistir.

No se modificaron los servicios de aprobación ni se duplicaron inscripciones.

## Configuración institucional pendiente

`config/certification.php` entrega `session_closure = null` y mapas de elegibilidad vacíos. Son bloqueos deliberados, no políticas municipales inventadas.

Una política requiere estos campos del servidor:

| Campo | Significado |
| --- | --- |
| reference | Referencia no vacía a la norma/acto aprobado |
| active_participation_states | Estados exactos que significan inscripción vigente |
| eligible_attendance_states | Estados exactos que cumplen la regla de asistencia |
| ineligible_attendance_states | Estados exactos que incumplen; disjuntos de los anteriores |
| require_entry, require_exit | Booleanos explícitos; no hay valores exigidos por defecto |
| regularizations | Solo se implementa not_applicable si la norma declara que no hay regularizaciones que consultar |
| open_states | Solo cierre: estados exactos que permiten finalizar |
| require_closed_activity | Solo elegibilidad: booleano explícito |
| required_sessions | Solo global: all, únicamente cuando la norma exige todas las sesiones |

Las políticas de elegibilidad se configuran por origen e ID real de tipo: `eligibility.sesion.<id>` y `eligibility.actividad.<id>`. Configurar un tipo para un origen constituye la declaración institucional de su aplicabilidad a ese origen. No hay selección automática por nombre ni porcentajes.

Si existen regularizaciones, otra fórmula de cumplimiento, sesiones opcionales o mínimos de horas, se requiere desarrollar esa política y su persistencia; no se debe usar not_applicable para eludirlo. Las políticas empleadas en tests están rotuladas como exclusivas de pruebas y nunca se cargan en producción.

## Contrato REST para Angular

Todas las rutas conservan JWT en cookie HttpOnly, middleware auth.cookie y el sobre `{success,message,data}`. No se cambió ninguna ruta existente. No había endpoint equivalente de pendientes ni de elegibilidad; certificados/list es un listado de registros administrativos, no de orígenes certificables.

| Método | Ruta relativa | Uso y permisos efectivos |
| --- | --- | --- |
| GET | /api/certificaciones/pendientes | actividades.ver + emision.ver; ámbito de área o administrador reconocido |
| GET | /api/activity-session/{id}/beneficiarios | actividades.ver + emision.ver + participantes.ver; autorización de sesión y área |
| GET | /api/activity/{id}/beneficiarios-certificacion | Los mismos permisos; autorización de actividad y área |
| POST | /api/activity-session/{id}/finalizar | actividades.ver + actividades.editar; autorización de sesión y área; sin privilegio de firma |

El GET existente `/api/activity-session/{id}` mantiene sus campos y añade `puede_finalizarse`, `motivos_bloqueo_finalizacion`, `puede_certificarse` y `motivos_bloqueo_certificacion`. Solo exige el permiso de consulta de actividades ya vigente; Gestión de Actividades puede consultar estas capacidades sin permisos de emisión ni datos de beneficiarios.

Los permisos pasan por Gate y ModulePermissions existentes: se consideran usuario, roles, módulos, asignaciones y permisos efectivos activos. El Administrador conserva el ámbito global que reconoce `isSystemAdministrator()`, pero no obtiene permisos de operación ausentes ni uso de claves ajenas. No se crearon permisos ficticios como sesiones.finalizar. La separación nueva de cierre y consulta usa ver/editar ya vigentes. La separación formal futura de preparar/emitir/anular exige aprobar su matriz de permisos; el catálogo observado solo tiene emision.ver/crear/editar/eliminar. firmas.firmar no apareció como permiso activo en la consulta.

### Parámetros

Bandeja: `origen=sesion|actividad` (sesion por defecto), `id_actividad`, `id_sesion` solo con origen sesion, `id_tipo_certificado`, `estado_certificacion=Bloqueada`, `page`, `per_page` (20 por defecto, máximo 100).

El filtro id_tipo_certificado selecciona la evaluación y sus contadores; no supone que el catálogo autorice preparar ese tipo. Hoy todas las filas tienen preparación Bloqueada. Se rechazan otros estados con 422, ya que no existe un flujo oficial de preparación persistido. La bandeja incluye sesiones todavía no finalizadas para explicar sus impedimentos; no afirma que estén listas para emisión.

Beneficiarios: mismos parámetros de paginación, `id_tipo_certificado` opcional y contexto opcional `origen`, `id_actividad`, `id_sesion`. Todo contexto debe coincidir con el recurso de la ruta; actividad no acepta sesión. Se devuelven los inscritos de la actividad, incluyendo su estado real; la elegibilidad distingue si la inscripción es vigente cuando existe política.

Finalizar: cuerpo vacío `{}` o contexto consistente:

```json
{"id_actividad":2,"id_sesion":1,"origen":"sesion"}
```

Los IDs anteriores corresponden al contexto de la respuesta real de bandeja auditada. Este ejemplo **no se ejecutó como escritura institucional**. estado, id_usuario e id_persona están prohibidos; la identidad sale de la cookie autenticada. En la configuración entregada esta petición devuelve 409 por falta de política y no actualiza filas.

### Respuestas

[Respuesta real de pendientes obtenida con datos de PostgreSQL](certification-pending-response.json). Se ejecutó la consulta del servicio dentro de una transacción READ ONLY, con ámbito global de auditoría; no es una prueba de autenticación HTTP contra producción. Los enlaces del artefacto se normalizaron localmente al contrato relativo, sin suponer un dominio de despliegue.

Datos reales de la primera fila observada: actividad 2, nombre hola, Activa, área 9; sesión 1, nombre isa, estado activo. Un inscrito, cero elegibles, cero no elegibles, un pendiente por no tener solicitud aprobada vinculada. No se publicaron nombres de beneficiarios institucionales.

Ejemplo de estructura de beneficiario, basado en la prueba aislada (no representa a una persona institucional):

```json
{
  "success":true,
  "message":"Evaluación de certificación obtenida correctamente.",
  "data":{
    "origen":"sesion",
    "contexto":{"id_actividad":1,"nombre_actividad":"Actividad de prueba","estado_actividad":"Activa","id_sesion":1,"nombre_sesion":"Sesión 1","estado_sesion":"Finalizada"},
    "beneficiarios":{
      "current_page":1,"per_page":20,"total":1,
      "data":[{
        "id_participacion":1,"id_persona":1,"nombre_completo":"Persona Prueba",
        "id_actividad":1,"id_sesion":1,"estado_inscripcion":"Activo",
        "asistencias":[{"id_participacion":1,"id_actividad_sesion":1,"estado":"PRESENTE","hora_entrada":null,"hora_salida":null}],
        "id_tipo_certificado":1,"estado_elegibilidad":"Pendiente de validación",
        "motivos":["Falta la política aprobada de elegibilidad, alcance del tipo de certificado y tratamiento de regularizaciones."],
        "certificados_registrados_participacion":[],"certificado_sesion":null
      }]
    },
    "puede_preparar":false,"puede_emitir":false,"puede_firmar":false,
    "puede_finalizarse":false,"motivos_bloqueo_finalizacion":[],
    "motivos_bloqueo":["No existe un procedimiento oficial integrado de preparación y emisión ni una política institucional configurada por defecto.","El certificado no dispone de una relación persistente con la sesión; requiere extensión de esquema autorizada."]
  }
}
```

El paginador real también incluye links, path, from, to y enlaces anterior/siguiente. Los certificados administrativos se referencian mediante id_certificado, id_participacion, id_tipo_certificado, estado textual y fecha_emision, sin rutas privadas ni credenciales. **No se atribuyen a la sesión**. `certificados_atribuidos_sesion`, `certificado_sesion` y `certificados_emitidos_verificados` son null porque el modelo no puede demostrarlo; null no significa cero.

| HTTP | Circunstancia |
| --- | --- |
| 200 | Lectura válida; cierre realizado o reintento de cierre ya realizado |
| 401 | Cookie ausente o autenticación inválida |
| 403 | Falta permiso efectivo o recurso fuera de área |
| 404 | Recurso inexistente, después de comprobar acceso general |
| 409 | Falta política de cierre, actividad no autorizada, estado incompatible, pendientes o conflicto transaccional |
| 422 | Contexto manipulado, campos prohibidos, paginación u origen inválidos |
| 500 | Error de infraestructura sin detalles SQL/credenciales en la respuesta |

Ejemplo real del contrato de bloqueo de cierre: `{"success":false,"message":"No se puede finalizar la sesión.","data":{"finalizada":false,"sesion":{},"motivos_bloqueo":["Falta una política aprobada de cierre, estados de asistencia y regularizaciones."]}}`. `sesion` se muestra abreviado; la implementación devuelve la fila real.

Angular puede navegar a su pantalla generar-emitir con id_actividad, id_sesion y origen, consultar el recurso correspondiente y presentar los motivos. Abrir la pantalla o consultar pendientes nunca prepara, emite o firma. No debe invocar certificados/create para simular una certificación por sesión.

## Elegibilidad separada

Por sesión: requiere actividad autorizada y sesión Finalizada; luego tipo vigente y política explícita, inscripción vigente, asistencia de esa sesión y requisitos declarados. La actividad puede seguir Activa cuando la política de sesión no exige cierre global. Sesión abierta → No elegible; autorización desconocida, política ausente, asistencia pendiente o regularización no modelada → Pendiente de validación. Ausencia solo es No elegible cuando la norma define el valor que representa ausencia. PRESENTE por sí solo no demuestra elegibilidad.

Global: evalúa por separado las sesiones que exige la política (solo está implementada la modalidad explícita all); permite exigir Cerrada cuando la política lo indica. No usa el resultado de una sesión como sustituto del cumplimiento global. Sin política permanece pendiente.

Prácticas y reconocimientos conservan sus modelos y CRUD independientes de actividades. No se agregó un evaluador de conclusión o validez sin reglas aprobadas. Los estados observados activo y ca no prueban elegibilidad. La bandeja nueva solo admite sesion/actividad; integrar práctica/reconocimiento es trabajo pendiente, no una funcionalidad presentada como completa. Ponentes requieren un origen institucional y política propios; no se tratan como asistentes.

## Propuesta exacta de identidad persistente, no aplicada

Se propone una futura migración `extend_certificate_session_origin`, ejecutable solo después de autorización y pruebas PostgreSQL aisladas. No se creó un archivo de migración autoejecutable para evitar incorporarlo accidentalmente a un despliegue. La propuesta conserva el CHECK de exactamente un origen raíz: la sesión es una precisión del origen participación, nunca un cuarto origen independiente ni una inscripción duplicada.

DDL propuesto (NO ejecutado):

```sql
ALTER TABLE certificado.participacion
  ADD CONSTRAINT participacion_identidad_actividad_unique
  UNIQUE (id_participacion, id_actividad, id_persona);
ALTER TABLE certificado.actividad_sesion
  ADD CONSTRAINT sesion_identidad_actividad_unique
  UNIQUE (id_actividad_sesion, id_actividad);
ALTER TABLE certificado.certificado
  ADD COLUMN id_actividad bigint NULL,
  ADD COLUMN id_actividad_sesion bigint NULL,
  ADD CONSTRAINT certificado_sesion_contexto_check CHECK (
    (id_actividad_sesion IS NULL AND id_actividad IS NULL)
    OR (id_actividad_sesion IS NOT NULL AND id_actividad IS NOT NULL
        AND id_participacion IS NOT NULL
        AND id_practica IS NULL AND id_reconocimiento IS NULL)
  ),
  ADD CONSTRAINT certificado_sesion_foreign
    FOREIGN KEY (id_actividad_sesion, id_actividad)
    REFERENCES certificado.actividad_sesion (id_actividad_sesion, id_actividad)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  ADD CONSTRAINT certificado_participacion_contexto_foreign
    FOREIGN KEY (id_participacion, id_actividad, id_persona)
    REFERENCES certificado.participacion (id_participacion, id_actividad, id_persona)
    ON DELETE RESTRICT ON UPDATE RESTRICT;
CREATE UNIQUE INDEX certificado_identidad_sesion_unique
  ON certificado.certificado (id_participacion, id_actividad_sesion, id_tipo_certificado)
  WHERE id_actividad_sesion IS NOT NULL;
CREATE UNIQUE INDEX certificado_identidad_global_unique
  ON certificado.certificado (id_participacion, id_persona, id_tipo_certificado)
  WHERE id_participacion IS NOT NULL AND id_actividad_sesion IS NULL;
CREATE UNIQUE INDEX certificado_identidad_practica_unique
  ON certificado.certificado (id_practica, id_persona, id_tipo_certificado)
  WHERE id_practica IS NOT NULL;
CREATE UNIQUE INDEX certificado_identidad_reconocimiento_unique
  ON certificado.certificado (id_reconocimiento, id_persona, id_tipo_certificado)
  WHERE id_reconocimiento IS NOT NULL;
```

Esta FK compuesta prueba que sesión y participación pertenecen a la misma actividad y que el beneficiario es el de la participación. Para los certificados globales anteriores, las dos columnas nuevas quedan NULL y el modelo sigue siendo compatible. La comprobación actual de persona/origen de prácticas y reconocimientos continúa siendo del backend; endurecerla en SQL requiere una propuesta adicional.

Antes de crear índices: detectar duplicados existentes e inconsistencias, sin corregirlos automáticamente. No inferir una sesión para certificados históricos con solo participación. Al existir duplicados, la migración debe detenerse y solicitar regularización. Los índices impiden una nueva identidad incluso si el certificado se anula: la reemisión conserva id_certificado y versiona documento con motivo_reemision; la rectificación necesita una regla específica y no libera la identidad por borrar filas.

Preparación futura: bloqueo del origen, reevaluación con la política vigente, inserción con identidad única, recuperación de la misma identidad en reintentos y manejo de 23505. El índice único es la garantía frente a inserciones concurrentes; una consulta previa por sí sola no lo es. Todavía no existe esa preparación implementada por sesión ni se probó este DDL con escrituras PostgreSQL.

También se requieren, antes de emisión oficial:

1. Política persistida/versionada que vincule tipo de certificado, modalidad, actividad/tipo aplicable y requisitos; historial de evaluación con referencia a esa versión.
2. Registro de cierre/regularización con actor, fecha, motivo, estado anterior/nuevo y política, transaccional e inmutable. El log agregado no ofrece esa garantía.
3. Contrato de preparación independiente de fecha_emision (actualmente DATE NOT NULL), estados municipales aprobados y plantilla/versionado del documento. No guardar una fecha ficticia de emisión para preparar.
4. Reparar la integridad participación/sesión de asistencia en PostgreSQL con contexto de actividad y FK compuestas, previa auditoría y autorización. Las FK actuales no bastan.
5. Permisos formales distintos para preparar, emitir, firmar y anular, aprobados sin equiparar administración con uso de PFX ajeno.

## Generación, documentos, firma y verificación

| Función | Estado real |
| --- | --- |
| Registro administrativo | CRUD existente; no prueba emisión oficial |
| Preparación por sesión | Bloqueada por falta de identidad persistente y reglas |
| Plantilla oficial y generador PDF | No implementados/configurados en este flujo |
| Registro de PDF existente | El servicio comprueba almacenamiento privado, cabecera %PDF- y calcula SHA-256; no genera PDF |
| Código de verificación | Campo persistido único aportado al registro; no hay generador oficial integrado |
| QR | No hay implementación oficial; no se simuló |
| Emisión | Sin procedimiento oficial conectado a elegibilidad/documento |
| Credenciales PFX | Gestión existente; no se leyeron ni usaron claves privadas durante la auditoría |
| Firma criptográfica | No implementada; registro manual de firma aplicada ya devuelve 409 |
| Verificación pública | No implementada; consulta_certificado es registro administrativo de consultas |
| Anulación/rectificación | CRUD/historial parcial; falta procedimiento oficial y política de revocación/reemisión |

Desfases preexistentes que impiden presentar el CRUD documental como listo para PostgreSQL: certificado.estado se valida/castea booleano frente a VARCHAR; tipo_certificado.estado se valida string frente a BOOLEAN; certificado_documento.estado acepta GENERADO/ANULADO frente a CHECK Vigente/Anulado; fecha_emision permite NULL en validación frente a NOT NULL; el código permite cero orígenes frente a CHECK exactamente uno. No se eligieron conversiones ni estados de emisión sin norma municipal. Los endpoints administrativos existentes deben revisarse antes de habilitar escritura institucional y no son una alternativa a los bloqueos del nuevo flujo.

## Pruebas y verificación

Las fixtures y políticas de prueba viven solo en SQLite en memoria. El PHP CLI tiene pdo_sqlite instalado pero deshabilitado por defecto; se cargó solo para el proceso de pruebas con `-d extension=pdo_sqlite`, sin cambiar php.ini ni dependencias.

Comandos de verificación:

```text
php -d extension=pdo_sqlite vendor/bin/phpunit tests/Feature/CertificationReadinessTest.php --no-progress
php -d extension=pdo_sqlite vendor/bin/phpunit tests/Feature/AttendanceTest.php tests/Feature/PatchCrudTest.php tests/Feature/ModuleIntegrationTest.php tests/Feature/CertificationModuleTest.php tests/Feature/GestionCertificationProtectionTest.php --no-progress
php vendor/bin/pint --dirty --format agent
```

La prueba nueva cubre autenticación, permisos y área, administrador con permisos efectivos, actividad activa con varias sesiones, cierre individual, reintento sin segundo evento ni certificado, pendientes, aprobación, elegibilidad por sesión/global, presente/ausente/pendiente según política explícita, entrada/salida condicionadas, bloqueo por regularización no modelada, tipo sin política, contexto manipulado, 401/403/404/409/422, paginación, consultas sin efectos y protección del CRUD.

Coberturas que **no se afirman**: emisión oficial de sesión, regularización aprobada persistente, índices/FK propuestos, reemisión institucional y concurrencia con conexiones PostgreSQL independientes. No se proporcionó una base PostgreSQL exclusiva de pruebas; no se usó la institucional como sustituto. La regresión existente de certificados/prácticas/reconocimientos usa fixtures aisladas y tampoco demuestra validez jurídica o interoperabilidad criptográfica.

Resultado definitivo: 131 pruebas y 2.209 aserciones aprobadas en los seis archivos indicados (25 pruebas nuevas). Pint y `git diff --check` aprobados. La fixture antigua de AttendanceTest se amplió con la columna real estado de sesión para poder ejercer la protección de cierre; no se eliminaron pruebas existentes. Los catálogos institucionales observados no se sustituyeron por los datos de las pruebas.
