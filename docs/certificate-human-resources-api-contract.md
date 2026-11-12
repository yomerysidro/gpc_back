# Preparación y entrega administrativa de certificados

Todas las rutas usan el prefijo `/api` y el JWT existente en cookie HttpOnly.
Angular debe enviar credenciales. No se modificó Angular.
Las respuestas JSON conservan `{success, message, data}`. Un HTTP 409/403/422
no es una confirmación. Los PDFs usan `Content-Type: application/pdf`, bytes
que comienzan con `%PDF-`, caché privada y `X-Document-SHA256`.

## Confirmación del documento que ya existe en Gerencia

1. `POST /api/certificados-documentos/{id}/firma/revision-pdf`
   acepta `{modalidad: "SECUENCIAL"|"PARALELA", firmantes:
   [{id_persona_trabajador, orden_firma}]}` para revisar una selección todavía
   no guardada, o un cuerpo vacío para revisar la asignación guardada.
   Respuesta **JSON**, no binaria: `data.token_revision`, `data.hash_pdf`,
   `data.expires_at`, `data.vista_previa_pdf.tipo_contenido = "application/pdf"`
   y `data.vista_previa_pdf.contenido_base64`. Decodificar base64 para visualizar.
   No interpretar el sobre JSON completo como un PDF.
2. Si la selección aún no está guardada:
   `POST /api/certificados-documentos/{id}/firma/asignar`, cuerpo
   `{modalidad, firmantes, confirmar: true}` con exactamente la selección revisada.
   Conserva el contrato existente: la asignación y la aprobación del PDF son
   operaciones distintas. La asignación por sí sola no confirma el PDF.
3. `POST /api/certificados-documentos/{id}/firma/confirmar-pdf`, cuerpo
   `{token_revision, confirmar: true, hash_pdf}`. El hash es opcional para
   compatibilidad; si se envía debe coincidir exactamente. Respuesta **JSON**.
   Comprobar HTTP 200, `success === true`, `data.persistido === true`,
   `data.pdf_revisado_disponible === true`, `data.revision_pdf` y `data.hash_pdf`.
   Se confirma el archivo de esa vista previa, no otro PDF regenerado.
4. `GET /api/certificados-documentos/{id}/firma/pdf-autorizado` devuelve el PDF
   **binario** vigente. Angular debe usar `responseType: 'blob'` y comprobar
   `Content-Type`, contenido y el SHA-256 de los bytes contra el hash revisado.
   Los errores continúan siendo JSON aunque se haya pedido un blob.
   La descarga alternativa existente
   `GET /api/certificados-documentos/{id}/archivo?flujo=firmas` sigue disponible.

El mismo token confirmado retorna HTTP 200 y `data.reintento = true`, incluso
después de vencer el plazo inicial, mientras siga siendo la revisión vigente.
Otro token obsoleto, hash distinto, cambio de trabajadores/contexto, PDF corrupto
o revisión concurrente produce 409. Tokens de otro usuario producen 403.
Una revisión sin confirmar vence a los 30 minutos. No cambiar el contrato JSON
de la vista previa o confirmación para tratarlo como una descarga.

## Recursos Humanos: consulta sin migraciones

La bandeja consulta las relaciones existentes de actividad, sesión, participación,
certificado, documento y persona. No hay paso previo de envío ni recepción.
No ejecutar `docs/certificate-human-resources.sql`: es una propuesta anterior,
fuera de este flujo. No se crean tablas, columnas, migraciones ni registros de envío.
`CERTIFICATION_HR_AREA_IDS` no se necesita para consultar esta bandeja.

Todas las consultas usan el JWT existente y caché privada. Los permisos de los
roles activos se resuelven por las políticas actuales (`emision.ver`); por ejemplo,
el rol Digitador utilizado por RR. HH. en los datos base ya tiene ese permiso.
El ámbito se obtiene de `ActivityRequestData::authorizedAreas`, con los vínculos
laborales activos y vigentes de `gestion.persona_trabajador`. Cada actividad se
filtra por `actividad.id_area`. El administrador tampoco obtiene ámbito global
por utilizar estas rutas. No se otorgan nuevos permisos ni roles.

| Método | Ruta real | Uso y parámetros |
| --- | --- | --- |
| GET | `/api/rrhh/actividades` | Actividades con PDFs confirmados; `page` opcional |
| GET | `/api/rrhh/actividades/{idActividad}/sesiones` | Sesiones con certificados de esa actividad; `page` opcional |
| GET | `/api/rrhh/sesiones/{idSesion}/certificados` | Certificados por participante; `id_persona` y `page` opcionales |
| GET | `/api/rrhh/certificados/{idDocumento}` | Detalle del documento existente |
| GET | `/api/rrhh/certificados/{idDocumento}/pdf` | Visualizar PDF privado vigente |
| GET | `/api/rrhh/certificados/{idDocumento}/pdf?descargar=1` | Descargar los mismos bytes del PDF |
| GET | `/api/rrhh/certificados` | Bandeja agrupada compatible; filtros `id_actividad`, `id_sesion`, `id_persona`, `page` |

Los identificadores de las dos rutas de documento son `id_certificado_documento`,
no `id_certificado`. La confirmación, firmas y emisión de Gerencia conservan
sus rutas y contratos del apartado anterior. Consultar desde RR. HH. no congela
ni modifica la revisión de Gerencia.

Actividades, sesiones y certificados por sesión responden JSON con el sobre
`{success, message, data}`; la página está dentro de `data`:

```json
{
  "success": true,
  "message": "La operación ha tenido éxito",
  "data": {
    "data": [],
    "current_page": 1,
    "per_page": 50,
    "total": 0,
    "last_page": 1
  }
}
```

`data.data` contiene actividades con `id_actividad`, `actividad`, `id_area`,
`total_sesiones`, `total_certificados`; o sesiones con `id_sesion`, `id_actividad`,
`sesion`, `fecha_sesion`, `total_certificados`; o los detalles de certificados.
`message` es informativo; Angular debe comprobar `success` y los datos.

El detalle y cada certificado incluyen:

- `id_certificado`, `id_certificado_documento`, `id_persona`, `id_participacion`.
- `nombre_completo`, `tipo_certificado`, `id_tipo_certificado`.
- `id_actividad`, `actividad`, `id_sesion`, `sesion`, `fecha_sesion`.
- `codigo_verificacion`, `hash_pdf`, `hash_pdf_original`, `revision_pdf`.
- `pdf_confirmado`, `estado_documental`, `estado_certificado`, `estado_firma`, `fecha_emision`.
- `firma_verificada: false`, `emision_oficial_verificada: false`, `registro_entrega_disponible: false`.

`revision_pdf` es el identificador vigente del versionado privado. Puede ser
nulo para una preparación inicial confirmada cuyo marcador de aprobación
existente está en `motivo_reemision` (`v: 0`, hash del plan `p`). En ambos casos
se utiliza el archivo ya aprobado, sin regenerarlo. El hash vigente puede diferir
del original al incorporar firmantes; la identidad y el código se conservan.
Los documentos sin preparación confirmada no se incluyen en los listados.
No se elige arbitrariamente entre dos documentos vigentes del mismo certificado.
Las participaciones cuyo beneficiario o actividad no coinciden se excluyen.

La bandeja agrupada `/api/rrhh/certificados` conserva
`data.actividades[].sesiones[].certificados[]`, con `pagina`, `total` y
`por_pagina`. Una actividad o sesión puede continuar en otra página.

Para el PDF usar `withCredentials: true`, `observe: 'response'` y
`responseType: 'blob'`. La respuesta exitosa es binaria `application/pdf`;
`X-Document-SHA256` contiene la huella de esos bytes. `Content-Disposition`
permite distinguir visualización de descarga. Los errores son JSON, aun cuando
Angular haya solicitado un blob. El servidor comprueba tanto el PDF original
como la revisión vigente. Las rutas físicas y los archivos privados no se publican.

Esta bandeja muestra documentos `PREPARADO` o `PENDIENTE_FIRMA`, vigentes y sin
fecha de emisión, con una preparación confirmada. Un PDF sin firma auténtica
sigue siendo administrativo. Angular debe mostrar el estado real; consultar o
descargar no lo convierte en `FIRMADO` o `EMITIDO` y no registra una entrega.

Errores: 401 sin JWT; 403 sin permiso o sin ámbito laboral vigente; 404 para IDs
inexistentes, fuera del ámbito o con relaciones inconsistentes; 409 si la revisión
no está confirmada, su aprobación fue alterada o la integridad del PDF falla;
422 para filtros o parámetros inválidos. Un listado filtrado sin coincidencias
responde 200 con `total: 0`.

## Operaciones administrativas anteriores

Las rutas POST de `enviar-rrhh`, `recibir`, `disponible` y `entregar` se conservan
por compatibilidad, pero responden error y no escriben datos. No deben usarse
para habilitar consultas. `/api/rrhh/certificados/{id}/historial` devuelve
`{disponible:false, historial:[]}` dentro de `data` si el documento está autorizado:
no existe un historial de entrega en este flujo y no se fabrica uno.

Las rutas anteriores `/api/mis-certificados` y `/api/mis-certificados/{id}/pdf`
no amplían acceso a participantes: sin un registro previo que habilite la entrega,
la lista está vacía y el documento responde 404. La bandeja de RR. HH. funciona
independientemente de esos registros y de esas rutas.
## Bandeja existente «Certificados emitidos»

La pantalla existente usa `EmisionCertificadosService` y estas rutas. El backend
ahora agrega los preparados a esas mismas consultas, conservando los históricos:

| Método | Ruta | Identificador y uso |
| --- | --- | --- |
| GET | `/api/certificados-documentos/list` | Documentos históricos y preparados confirmados; sin `bandeja` |
| GET | `/api/certificados/list` | Certificados históricos y preparados, sin duplicar `id_certificado` |
| GET | `/api/certificados/detail/{idCertificado}` | Relación del certificado preparado que consulta la pantalla existente |
| GET | `/api/certificados-documentos/{idDocumento}/archivo` | Descargar el PDF vigente confirmado, privado |
| GET | `/api/certificados-documentos/{idDocumento}/firma/pdf-autorizado` | Misma revisión vigente; contrato binario de Gerencia |
| GET | `/api/certificados-documentos/{idDocumento}/firma/progreso` | Gerencia consulta la revisión y su disponibilidad administrativa para RR. HH. |

Las rutas de Gerencia con `bandeja=por_firmar` mantienen su contrato y no se
sustituyen por el catálogo. Las operaciones de firma y emisión oficial tampoco
se alteran. El soporte anterior de esquemas sin vínculo de sesión se conserva.

Filtros compartidos del catálogo: `id_actividad`, `id_sesion`, `id_persona`,
`id_certificado`, `id_tipo_certificado`, `id_participacion`, `page`, `per_page`
(de 1 a 100), `estado_certificado` y `estado_preparacion=PREPARADO_ENTREGA`.
En documentos, `estado` conserva `Vigente`/`Anulado` y admite el filtro derivado
`PREPARADO_ENTREGA`. En certificados, `estado` filtra el estado criptográfico
persistido o acepta ese mismo filtro derivado. Ejemplo:

`GET /api/certificados-documentos/list?id_actividad=1&id_sesion=2&estado_preparacion=PREPARADO_ENTREGA`

El sobre es `{success,message,data}`, con la paginación tradicional dentro de
`data`: `data`, `current_page`, `per_page`, `total`, `last_page`, `from`, `to`.
No se cambia el significado de `estado` para ocultar diferencias del frontend.
Los elementos preparados exponen campos adicionales:

```json
{
  "id_certificado": 1,
  "id_certificado_documento": 1,
  "estado": "Vigente",
  "estado_certificado": "PENDIENTE_FIRMA",
  "estado_preparacion": "PREPARADO_ENTREGA",
  "etiqueta_estado": "Preparado para entrega",
  "preparado_para_entrega": true,
  "disponible_rrhh": true,
  "pdf_confirmado": true,
  "fecha_emision": null,
  "firma_verificada": false,
  "emision_oficial_verificada": false,
  "registro_entrega_disponible": false
}
```

Es una muestra del contrato, no evidencia de un registro real. Cada respuesta
real incluye sus IDs, código, hashes original y vigente, revisión, actividad,
sesión, participante y `responsables_administrativos`. En la lista y detalle de
certificados, `estado` sigue siendo `PENDIENTE_FIRMA` o el estado real de ese
certificado; `estado_documental` conserva el estado del documento.

La etiqueta que debe presentar Angular para estos elementos es `etiqueta_estado`.
Se conserva `estado` por compatibilidad. No se modificó Angular: la aplicación
puede consumir estos campos mediante sus servicios existentes, sin crear una
pantalla ni cambiar IDs o archivos. La pantalla actual conserva los textos y
controles que ya tenía; estos cambios del backend no añaden botones al frontend.

`disponible_rrhh` expresa disponibilidad derivada, no envío ni recepción.
Requiere documento vigente, certificado administrativo sin fecha de emisión,
aprobación existente vinculada a su hash y un plan de responsables guardado que
coincida con esa revisión. No se incluyen borradores sin confirmar ni PDFs sin
responsables. Consultar no vuelve a asignar personas, generar archivos ni
confirmar el documento. No se admite elegir entre dos documentos vigentes.

Las consultas usan las políticas documentales existentes y sus ámbitos. RR. HH.
necesita `emision.ver`; los registros se filtran por sus áreas vigentes. Gerencia
mantiene su ámbito documental actual, incluida únicamente la autorización
administrativa que el sistema ya tuviera. La disponibilidad no concede permisos
nuevos ni permite descargar documentos ajenos. La autorización y la integridad
se vuelven a comprobar al descargar el archivo.

Los certificados históricos sin documento siguen presentes en /api/certificados/list cuando su ámbito permite consultarlos. No se les atribuye PDF confirmado ni disponibilidad para RR. HH. y no se genera un documento para incluirlos.
