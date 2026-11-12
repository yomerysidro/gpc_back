# Etapa B: responsables pendientes y revisión de PDF

Contrato vigente: 2026-11-11. Autenticación JWT `access_token`, prefijo `/api`. Ninguna operación administrativa aplica una firma criptográfica ni emite certificados.

## Estado del esquema

La migración fue aplicada por el responsable PostgreSQL. La comprobación final confirma:

- `certificado_firma.id_persona_trabajador` referencia al trabajador real.
- `id_firma` y `fecha_firma` permiten NULL.
- CHECK exige un responsable y prohíbe fecha_firma sin credencial.
- `certificado_documento.modalidad_firma` admite PARALELA/SECUENCIAL.

No se volvieron a ejecutar propuestas SQL ni migraciones. No se crearon tablas. La asignación aplica una transacción, bloqueo del certificado/documento, validación de trabajador y orden y prevención de personas repetidas. Los índices reales se detallan en la comprobación controlada; no se presume que el DDL aplicado sea idéntico a una propuesta histórica.

Existe unicidad parcial por documento/trabajador. La unicidad de orden se valida en la aplicación dentro del bloqueo documental; no se añadió un índice nuevo. `requisitos.versionado_pdf_controlado` indica soporte mediante versiones privadas autenticadas, sin columnas nuevas; `vinculo_versiones` sigue informando si existe específicamente `id_documento_anterior`.

## Permisos

Consulta/progreso/PDF: `emision.ver` y ámbito. Asignación y revisión/aprobación del PDF: además `emision.editar`. `firmas.firmar` no se requiere para las operaciones administrativas ni para que un trabajador sea seleccionado. Se reserva a la futura firma auténtica.

Se conservan roles y áreas vigentes. Se configuraron únicamente `emision.ver` y `emision.editar` para el rol real `Gerencial` mediante las relaciones existentes de acceso; no se otorgó `firmas.firmar` ni acceso global. Ver [administrative-signers-api-contract.md](administrative-signers-api-contract.md) para el alcance y [administrative-signers-live-check.json](administrative-signers-live-check.json), sección `resolucion_permisos_gerencia`, para la validación con JWT del usuario real. El documento 2 permanece fuera del área de ese usuario y debe responder 404; la prueba de asignación dentro de su ámbito ajusta exclusivamente el contexto documental en una transacción revertida, sin cambios permanentes de áreas.

## Endpoints

Base documental: `/certificados-documentos/{id}`.

| Método y sufijo | Operación |
| --- | --- |
| `GET /firma/firmantes-disponibles` | Trabajadores reales vigentes y competentes; sin requisito de PFX o permiso criptográfico del trabajador |
| `POST /firma/asignar` | Guarda plan administrativo completo |
| `GET /firma/firmantes-asignados` | Consulta plan guardado y progreso |
| `GET /firma/progreso` | Modalidad, responsables, estado y facultades |
| `GET /firma/disponibilidad` | Alias de progreso |
| `GET /firma/verificacion` | Integridad del archivo; firma auténtica todavía no verificada |
| `GET /firma/pdf-autorizado` | Archivo privado exacto de ese documento, tras comprobar permiso, ámbito e integridad |
| `POST /firma/revision-pdf` | Genera una propuesta de nueva versión, con nombres/cargos y mensaje para revisar |
| `POST /firma/confirmar-pdf` | Aprueba una revisión privada del mismo documento sin sobrescribir el original |

El GET de PDF también permite consultar una versión anterior autorizada; el estado documental se consulta en progreso. No es descarga de un certificado emitido ni está expuesto públicamente.

## Guardar uno o varios responsables

```json
{
  "modalidad": "PARALELA",
  "firmantes": [
    {"id_persona_trabajador": 2, "orden_firma": 1},
    {"id_persona_trabajador": 3, "orden_firma": 2}
  ],
  "confirmar": true
}
```

IDs ilustrativos: seleccionar los disponibles para el documento. Solo se asigna a certificado PENDIENTE_FIRMA, documento Vigente e íntegro, sin emisión ni notificación. Se aceptan 1–100 responsables, órdenes únicos consecutivos desde 1, una sola asignación por persona y competencia laboral para el documento.

Modalidades PARALELA/SECUENCIAL, en mayúsculas. Para presentación ambas conservan el orden; la ejecución criptográfica futura respetará su modalidad. No hay firma criptográfica disponible hoy.

El contrato previo por `id_firma` sigue compatible para credenciales existentes, pero el flujo sin PFX debe enviar `id_persona_trabajador`. Cada elemento admite exactamente uno de los identificadores, nunca ambos. No enviar archivos, contraseñas, rutas, nombres, cargos, estados ni fechas.

Respuesta 200: `completada:true`, `persistido:true`, `reintento:false` y progreso. Igual plan: 200, reintento=true. Otro plan: 409; no hay sustitución automática. Se conservan certificado pendiente y fecha_emision=NULL. Las asignaciones directas guardan id_firma=NULL, fecha_firma=NULL y condición pendiente.

## Consultar

El progreso devuelve:

- `firmantes_asignados`: trabajador, persona, nombre, cargo, ID/nombre de área, orden_firma, id_firma nullable, fecha_firma y estado Pendiente.
- `modalidad`, `modalidad_persistida`, `asignacion_documental_persistida`, `origen_firmantes`.
- `documento.firmantes`: prioriza las asignaciones por documento sobre la configuración antigua por actividad.
- `requisitos.asignacion_sin_credencial`: soporte estructural real.
- `pdf_revisado_disponible` y `requiere_nueva_revision_pdf`: estado del contenido aprobado frente al plan actual.
- `firma_verificada:false`, emisión/entrega deshabilitadas.

Los datos laborales consultados reflejan RR.HH. actual. Cambiar nombre/cargo después de aprobar una revisión invalida la coincidencia de su plan y requiere nueva revisión. El PDF aprobado conserva sus bytes.

## Revisión del mismo documento y aprobación explícita

La asignación prepara automáticamente `data.revision_pdf` cuando el PDF necesita incorporar responsables. Contiene token, vista previa y `requiere_confirmacion:true`. Guardar responsables no activa por sí solo una revisión sin aprobación. Si falta la fuente del texto aprobado o el contenido no cabe en una página, se conserva la asignación y `revision_pdf.preparada:false` informa el bloqueo concreto; nunca se declara que el PDF ya contiene los nombres.

También puede solicitarse explícitamente:

```json
POST /certificados-documentos/2/firma/revision-pdf
{}
```

**No se envía un texto nuevo.** El servidor recupera el mensaje y contexto del manifiesto cifrado de preparación que coincide con el código y SHA-256 original. Para revisiones sucesivas usa la última aprobación verificable. Si el cliente envía `mensaje` por compatibilidad, debe coincidir exactamente con el aprobado; cualquier sustitución devuelve 422. Si no existe una fuente vinculada al original devuelve 409, sin inventar texto ni extraerlo de un PDF arbitrario.

Los nombres y cargos se consultan en las relaciones laborales reales: se usa el plan persistido cuando no se envía selección, o la selección temporal descrita abajo cuando se envían `firmantes` y `modalidad`. Se necesita contexto real de sesión y entre 1 y 8 responsables vigentes. El PDF presenta espacio vacío, línea, nombre y cargo en orden, diseño municipal A4 horizontal de una sola página, el mismo código y QR. No se imprime la leyenda «Documento administrativo · La vigencia y las firmas se consultan mediante el código de verificación». No se dibujan rúbricas ni se exige PFX.

La respuesta incluye `id_certificado`, `id_certificado_documento`, `codigo_verificacion` originales, `token_revision`, `hash_pdf`, `expires_at`, `mensaje`, `firmantes`, `requiere_confirmacion:true`, `persistido:false` y `vista_previa_pdf.contenido_base64`. La propuesta permanece privada durante 30 minutos y pertenece al usuario que la solicitó. Para aprobar:

```json
POST /certificados-documentos/2/firma/confirmar-pdf
{"token_revision":"<token recibido>","confirmar":true}
```

Se revalidan JWT/permisos/ámbito, estado pendiente, texto/contexto aprobado, plantilla, integridad del original y de la propuesta, modalidad y responsables. Cambios de cargo, archivo, contexto o plan invalidan la revisión. Otra aprobación concurrente devuelve 409.

**Se conserva la misma identidad documental y no se modifica PostgreSQL.** No se crea otro certificado, fila documental ni código. El versionado se persiste en archivos privados `certificados/versiones/{id_documento}/`: PDF inmutable, manifiesto cifrado autenticado e inmutable y selector de versión vigente. Un bloqueo por documento serializa la aprobación y el selector se reemplaza atómicamente. Cada manifiesto vincula certificado/documento/código, ruta y hash originales, hash de la versión anterior, nuevo hash, responsables aprobados, texto/contexto, actor y fecha de aprobación documental. La descarga verifica la aprobación contra el archivo inmutable y falla con 409 si detecta alteraciones; no vuelve silenciosamente a una versión diferente.

El PDF original y su SHA-256 permanecen en la misma fila de PostgreSQL y en su archivo original. La revisión aprobada tiene archivo y SHA-256 propios. El estado permanece PENDIENTE_FIRMA, con fecha_emision NULL e id_firma/fecha_firma NULL.

Respuesta de confirmación: `completada:true`, `persistido:true`, `persistencia:archivo_privado_versionado`, mismos IDs y código, `revision_pdf` como identificador interno de revisión y progreso. Tanto `/firma/pdf-autorizado` como `/archivo?flujo=firmas` entregan el PDF aprobado vigente usando **el mismo ID y URL documental**. `documento.hash_pdf` corresponde a la versión servida y `documento.hash_pdf_original` conserva el SHA-256 inicial. La revisión no contiene una firma criptográfica ni constituye emisión oficial.

## Vista previa dinámica antes de asignar

`POST /api/certificados-documentos/{id}/firma/revision-pdf` acepta ahora una selección sin guardar. Requiere JWT válido (`access_token`), `emision.ver`, `emision.editar`, documento pendiente y ámbito autorizado. El cuerpo sin selección conserva la revisión del plan ya asignado.

```json
{
  "modalidad": "SECUENCIAL",
  "firmantes": [
    {"id_persona_trabajador": 10, "orden_firma": 1},
    {"id_persona_trabajador": 11, "orden_firma": 2}
  ]
}
```

Los IDs son ilustrativos: usar trabajadores reales de `firmantes-disponibles`. Modalidad: `PARALELA` o `SECUENCIAL`; entre 1 y 8 trabajadores/personas distintos, órdenes consecutivos desde 1. Se rechazan nombres, cargos u otros campos del cliente. Nombre completo y cargo se resuelven en Gestión, sin exigir PFX. Se conserva texto aprobado, beneficiario, actividad, sesión, QR, código y formato municipal del generador existente.

Respuesta HTTP 200 con envoltura habitual `data`:

```json
{
  "data": {
    "token_revision": "<64 caracteres hexadecimales>",
    "hash_pdf": "<SHA-256 del PDF temporal>",
    "expires_at": "<fecha ISO 8601, vigencia de 30 minutos>",
    "id_certificado_documento": 1,
    "id_certificado": 1,
    "codigo_verificacion": "<código existente>",
    "mensaje": "<texto aprobado existente>",
    "modalidad": "SECUENCIAL",
    "firmantes": [
      {
        "id_persona_trabajador": 10, "id_persona": 10, "id_area": 1,
        "nombre": "Responsable de prueba Apellido Materno", "cargo": "Cargo de prueba", "area": "Área de prueba",
        "disponible_asignacion": true, "vigente": true, "habilitado": false,
        "origen_habilitacion": "asignacion_documental", "id_firma": null,
        "credencial_registrada": false, "credencial_verificada": false, "credencial_valida": null,
        "orden_firma": 1, "fecha_firma": null
      },
      {
        "id_persona_trabajador": 11, "id_persona": 11, "id_area": 1,
        "nombre": "Segunda Responsable Real", "cargo": "Directora institucional", "area": "Área de prueba",
        "disponible_asignacion": true, "vigente": true, "habilitado": false,
        "origen_habilitacion": "asignacion_documental", "id_firma": null,
        "credencial_registrada": false, "credencial_verificada": false, "credencial_valida": null,
        "orden_firma": 2, "fecha_firma": null
      }
    ],
    "persistido": false,
    "requiere_confirmacion": true,
    "vista_previa_pdf": {"contenido_base64": "<PDF base64>", "tipo_contenido": "application/pdf"}
  }
}
```

`persistido:false` indica que no se guardó una asignación ni se activó una revisión: únicamente existen PDF y manifiesto temporales privados. No se escribe en la base, no se crea certificado/código, no se modifica el original ni su hash, estado o notificación. Cada cambio de trabajadores, modalidad u orden puede solicitar un nuevo token y PDF.

Cancelar: `DELETE /api/certificados-documentos/{id}/firma/revision-pdf`, cuerpo `{"token_revision":"<token>"}`. Comprueba usuario, documento y permisos y elimina los archivos temporales; responde `data.descartado:true`, `data.persistido:false`, `data.id_certificado_documento`. Es idempotente para un token ya eliminado. Nunca revierte asignaciones guardadas ni elimina revisiones vigentes.

Guardado mediante las APIs existentes, en este orden:

1. `POST /api/certificados-documentos/{id}/firma/asignar`: misma `modalidad` y lista de `firmantes` revisados, más `confirmar:true`.
2. Solo si la asignación se completó, `POST /api/certificados-documentos/{id}/firma/confirmar-pdf`: `{"token_revision":"<token de la vista previa elegida>","confirmar":true}`. Usar ese token; la asignación puede devolver otra propuesta por compatibilidad.
3. Declarar el flujo completo únicamente si la confirmación responde `completada:true` y `pdf_revisado_disponible:true`. Consultar `GET /api/certificados-documentos/{id}/firma/pdf-autorizado` para los bytes exactos revisados y confirmados.

Las dos peticiones no son una transacción distribuida. La asignación es transaccional e idempotente para el mismo plan; si la confirmación falla, el plan puede quedar guardado pendiente de revisión, pero el PDF vigente permanece sin reemplazar. No declarar éxito global ni emitir/notificar. Consultar progreso y reintentar la confirmación con el mismo token si sigue vigente; si expiró, solicitar una nueva revisión del plan guardado. Un plan diferente ya guardado responde 409 y no se reemplaza silenciosamente.

La confirmación compara trabajadores, nombres/cargos resueltos, orden y modalidad con el hash del plan revisado, además de contexto, plantilla, código e integridad. Usa bloqueos existentes y comparación de versión previa para rechazar aprobaciones concurrentes. HTTP 401: JWT ausente/inválido; 403: permisos o token ajeno; 404: documento fuera del ámbito; 422: selección inválida; 409: contexto/plan/versión cambiados, token ausente/caducado o PDF alterado. No se aplica firma criptográfica ni se marca FIRMADO/EMITIDO.

## Nuevos certificados desde Etapa A (compatibilidad)

`POST /activity-session/{id}/preparar-certificados`, acción `revisar_documento`, conserva el campo opcional `firmantes` con trabajador/orden (1–8), y modalidad_firma `Paralela`/`Secuencial`. Obtiene nombres/cargos reales, revisa y confirma el PDF exacto y guarda el plan pendiente. Confirmar deja el certificado PREPARADO; la remisión administrativa existente lo lleva a PENDIENTE_FIRMA, sin emisión. No exige permiso criptográfico a los trabajadores.

La aprobación inicial guarda en `motivo_reemision` un descriptor `v=0`, `p=hash del plan`, `u=actor`, sin antecedente: permite reconocer que los nombres ya estaban en el PDF aprobado inicialmente. No obliga a regenerarlo inmediatamente después de prepararlo. Los documentos anteriores sin descriptor aprobado se consideran pendientes de revisión visual al asignar responsables.

## Validación realizada

Las pruebas PHPUnit verifican dos nombres y cargos dentro de los streams de texto del PDF generado, su orden, texto y código aprobados, A4 horizontal y una página; confirmación y descarga por el mismo ID; un solo certificado y documento; conservación de la fila, bytes y SHA-256 originales; ausencia de firma/emisión; rechazo de sustitución de texto, manipulación, caducidad, falta de permisos y aprobaciones concurrentes. La prueba de Antón únicamente consulta PostgreSQL en READ ONLY y prepara una vista previa privada con sus dos responsables reales; no modifica la base ni activa una revisión sin confirmación.

## Firma y emisión

`POST /firma/aplicar` continúa bloqueado para firma auténtica; exige su autorización formal y un motor seguro todavía ausente. `POST /emitir` y `/archivo-final` continúan bloqueados. Ni la habilitación, asignación, revisión ni aprobación documental acreditan firma digital o autorizan entrega oficial. El QR público mantiene las restricciones de documentos pendientes.

Para integrar firma oficial faltan PFX/P12 legítimos, carga y custodia segura, verificación de identidad/vigencia/confianza/revocación y motor de firma incremental PDF con verificación posterior. No se necesita otra migración para asignar administrativamente por trabajador.
