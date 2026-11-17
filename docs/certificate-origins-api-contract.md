# Contrato de orígenes documentales para Angular

## Mensaje editable de Prácticas — contrato vigente

Se conserva el asistente administrativo y el origen `practica`. No requiere cambios de esquema ni de Angular para aceptar `mensaje` en la API. Se reutilizan el generador institucional, el manifiesto cifrado, el token de revisión, el PDF privado, QR y SHA-256.

### Consulta ligera

`GET /api/practices/{id}/evaluacion-certificacion` y `GET /api/practices/evaluacion-certificacion` incluyen `mensaje_predeterminado` construido con área y fechas reales, `mensaje`, `texto_editable` y `mensaje_estructurado_disponible`. En la lista están en cada elemento de `data.practicas`. No generan PDF, código, token ni Base64. Para una constancia existente, la evaluación no presenta el texto predeterminado como aprobado: `mensaje:null` y `texto_editable:false`; recuperar su texto guardado con la consulta siguiente.

`GET /api/certificaciones/practica/{id}/preparar?accion=consultar_preparacion` devuelve la evaluación y el mensaje predeterminado si no hay constancia (`persistido:false`, `recuperado:false`). Con documento devuelve `mensaje`, `mensaje_aprobado`, `id_certificado`, `id_certificado_documento`, `estado_flujo`, `url_documento` administrativa, `hash_pdf`, `texto_editable:false`, `persistido:true` y `recuperado:true`. No devuelve Base64 ni genera otro PDF; comprueba el archivo guardado y su aprobación. No admite `mensaje`, token ni cambios en esta consulta. Requiere los permisos de lectura y ámbito existentes; editar/preparar requiere además los permisos efectivos de creación.

El mensaje automático conserva la composición existente, por ejemplo: `Por haber realizado sus prácticas en Gerencia de Desarrollo Social, del 2026-10-02 al 2026-11-16.` Las fechas acreditadas también aparecen separadamente en el PDF con formato día/mes/año; editar el mensaje no cambia los datos acreditados.

### Revisión y confirmación

Revisar mediante `POST /api/certificaciones/practica/{id}/preparar`:

```json
{"accion":"revisar_documento","mensaje":"Por su dedicación y aporte durante sus prácticas."}
```

`mensaje` es opcional: al omitirlo se utiliza el predeterminado. Si se envía debe ser texto plano no vacío de hasta 1200 caracteres, con las mismas comprobaciones de seguridad de ponentes (sin HTML ni controles peligrosos o de dirección; admite saltos de línea). No admite `motivo`, identidad, fechas, tipo ni otros campos desconocidos. Los datos inválidos devuelven 422.

La revisión devuelve `mensaje`, `texto_editable:true`, `token_revision`, `expires_at`, `hash_pdf` y `vista_previa_pdf`. Repetir el mismo contenido y contexto reutiliza la revisión vigente. Revisar otro mensaje reemplaza la referencia vigente e impide confirmar el token anterior (409).

```json
{"accion":"confirmar_preparacion","mensaje":"Por su dedicación y aporte durante sus prácticas.","token_revision":"<64 caracteres hexadecimales>","confirmar":true}
```

Repetir exactamente el mensaje revisado al confirmar un texto personalizado. Omitirlo en ese caso devuelve 422 y enviar uno diferente devuelve 409. Para el predeterminado puede omitirse en ambas acciones. El manifiesto cifra y vincula mensaje, contexto acreditado, actor, código y SHA-256 del PDF; al confirmar se guarda una copia inmutable indexada por código en el almacenamiento privado existente. Se guardan los bytes exactos revisados, sin renderizar otro PDF. La respuesta incluye el mensaje aprobado y `texto_editable:false`. Los reintentos de confirmación recuperan la misma identidad; una nueva revisión de un origen con documento existente devuelve 409 y debe continuarse mediante la consulta ligera.

Confirmar conserva `PREPARADO`. El envío explícito a Gerencia y la confirmación administrativa mantienen sus endpoints y controles actuales. Tanto esas revisiones como la recuperación preservan el mensaje aprobado. No se sobrescriben certificados ni PDF históricos; sus mensajes se leen del manifiesto original o de la versión aprobada existente, sin reemplazarlos por el predeterminado actual. Una aprobación ausente o incoherente devuelve 409 en la recuperación, sin reconstruir texto.

### Regla operativa actual de prácticas

El estado de finalización predeterminado es exactamente `finalizado`; se respeta un `CERTIFICATION_PRACTICE_COMPLETION_STATE` explícito válido. Una variable vacía aplica el predeterminado. La finalización exige periodo ordenado y concluido, autenticación, permisos y ámbito, y es idempotente. La supervisión es informativa y opcional por defecto (`CERTIFICATION_PRACTICE_SUPERVISION_REQUIRED=false`): la cobertura parcial o ausente y la lista vacía de estados de supervisión no bloquean finalizar ni preparar. Sólo una activación explícita de esa configuración exige acreditación de supervisión. Los apartados históricos posteriores que describen bloqueos por política o supervisión documentan el comportamiento anterior; prevalece esta regla vigente.

## Consulta ligera y continuación del asistente

Usar `GET /api/certificaciones/ponente_sesion/{id_actividad_ponente}/preparar?accion=consultar_preparacion&modalidad_reconocimiento=sesion&id_sesion={id_sesion}`. También se admite la misma acción mediante POST en el contrato existente. GET rechaza cualquier acción de revisión o confirmación. Requiere autenticación, `emision.ver`, lectura del ponente, actividad, sesión y documento dentro del ámbito autorizado; consultar una preparación existente no requiere crear otra.

La consulta es de solo lectura: no genera PDF, códigos ni tokens, no reserva archivos ni confirma o remite documentos. Sin reconocimiento devuelve la evaluación, `recuperado:false`, `persistido:false` y las facultades de preparación. Con documento devuelve IDs persistidos, beneficiario, fechas, mensaje y motivo originales, `texto_editable:false`, hash, estados, `url_documento` y `puede_enviar_gerencia`; omite `vista_previa_pdf` y rutas físicas. Las restricciones de preparación no sustituyen el estado del documento existente.

Para visualizar el PDF usar `url_documento`, que apunta al endpoint administrativo `GET /api/certificados-documentos/{id_certificado_documento}/archivo?flujo=firmas`, con las mismas credenciales. Responde `application/pdf` después de validar ámbito, vigencia e integridad SHA-256 del archivo privado existente; no regenera el PDF. No usar la consulta pública para continuar el asistente.

Para un reconocimiento nuevo conservar POST con `accion:revisar_documento`: texto institucional predeterminado o `mensaje` y `motivo` personalizados; devuelve vista previa Base64 y `token_revision` sin persistir registros. Confirmar explícitamente mediante `accion:confirmar_preparacion`, `confirmar:true` y el token revisado; para texto personalizado repetir ambos textos aprobados. Devuelve PREPARADO. Los reintentos recuperan la misma identidad y no sobrescriben el PDF. La acción de revisión conserva Base64 para clientes que necesitan previsualización; usar la consulta ligera para filas, estados y recuperación del asistente.

PREPARADO permite continuar cuando `puede_enviar_gerencia:true`. Sólo la acción explícita del usuario debe llamar `POST /api/certificados-documentos/{id}/revisar-envio` y después `POST /api/certificados-documentos/{id}/enviar-gerencia` con su token y `confirmar:true`. PENDIENTE_FIRMA indica remisión persistida y consulta de Gerencia; no habilita otro envío. `remision.estado_persistido` aporta esa evidencia; `historial_en_bd:false` y `fecha_envio_en_bd:null` significan que el esquema no conserva fecha/historial, no deben fabricarse. FIRMADO/EMITIDO recuperan el estado administrativo existente con envío deshabilitado, sin aplicar firma o emisión. ANULADO bloquea recuperación como preparación vigente. Respetar siempre las facultades devueltas.

Un conflicto de integridad, texto inmutable, documento ambiguo o sesión ajena devuelve 409; permisos insuficientes 403 y datos inválidos 422. Tras una interrupción HTTP consultar primero el estado persistido; nunca interpretar el error como autorización para crear otro documento.

## Identidad y modalidades

Todas las rutas llevan prefijo `/api`, autenticación `auth.cookie` y envoltorio `{success,message,data}`. Los IDs son claves reales, nunca nombres ni posiciones de la lista.

- `ponente_actividad`: `id_origen = id_actividad_ponente`, modalidad `actividad`, sesión NULL. Sólo admite vínculos históricos/globales sin sesión. Conserva documentos existentes y preparación autorizada por actividad.
- `ponente_sesion`: `id_origen = id_actividad_ponente`, modalidad `sesion`. Exige un vínculo persistente con `id_actividad_sesion`, coincidente con `id_sesion` solicitado y perteneciente a `id_actividad`.
- `reconocimiento`: reconocimiento municipal preexistente, identificado por `id_reconocimiento`. No permite preparar un reconocimiento ya vinculado a sesión a través de esta modalidad genérica.
- `practica`: práctica existente, identificada por `id_practica`.

Una asignación histórica no acredita intervención en ninguna sesión. No se migra, borra ni reasigna automáticamente. El mismo ponente puede tener una asignación en cada sesión, además de su vínculo histórico. Se comprueba identidad por `persona_ponente.id_persona`; personas distintas con nombres iguales siguen siendo distintas.

La jerarquía obligatoria es actividad → sesión → asignación de ponente → reconocimiento → documento enviado a Gerencia. `actividad_ponente.id_actividad` sigue siendo NOT NULL. Tanto la ruta anidada como el servicio de consulta comprueban la actividad padre de la sesión; la FK compuesta del SQL protege también las escrituras directas. No se exige crear primero un vínculo histórico adicional: cada asignación de sesión conserva su propia actividad padre. Al asignar se bloquean actividad, sesión, registro de ponente y persona dentro de una transacción para conservar la identidad durante la escritura.

## Esquema real confirmado en Neon — 2026-11-16

Laravel conecta por `pgsql` a `certificados_morales`, usuario `equipo_dev`, esquema actual `public`, servidor Neon. Se verificaron columnas, FK/CHECK, índices y triggers mediante los catálogos de PostgreSQL. No se ejecutaron migraciones ni se reaplicó `docs/session-speakers-schema-proposal.sql`: ese archivo es una propuesta anterior y NO representa el esquema instalado.

- `actividad_ponente`: actividad y ponente obligatorios; sesión nullable para conservar históricos. FK compuesta `fk_actividad_ponente_sesion_actividad` con RESTRICT. UNIQUE por sesión/ponente y UNIQUE parcial para históricos. El CHECK `chk_actividad_ponente_requiere_sesion` es NOT VALID: conserva filas históricas NULL, pero exige sesión en nuevas inserciones y actualizaciones. No crear nuevos vínculos globales mediante el CRUD genérico.
- `reconocimiento`: trabajador nullable, `id_actividad_ponente` con FK RESTRICT y UNIQUE parcial. CHECK `num_nonnulls(id_persona_trabajador,id_actividad_ponente)=1`: exactamente uno de ambos destinatarios. NO existe `id_tipo_certificado` en esta tabla. Laravel utiliza el tipo real en `certificado.id_tipo_certificado`; no exige la columna ausente. Si un entorno anterior sí la tiene, conserva compatibilidad al guardarla.
- `certificado`: se conservan FK y CHECK de participación/asistencia y origen único. Para ponentes, participación y `id_actividad_sesion` permanecen NULL. La sesión procede de reconocimiento → asignación → sesión; la identidad de persona_ponente → persona.
- No hay triggers de usuario en actividad_ponente, reconocimiento ni certificado en esta instancia. Laravel comprueba identidad, tipo, contexto y documentos, y bloquea los vínculos mediante transacciones; no se afirma que existan los triggers de la propuesta antigua.

Los vínculos históricos sin sesión se consultan exclusivamente por actividad. Su preparación previa conserva la referencia laboral histórica; los nuevos reconocimientos por sesión utilizan asignación de ponente y trabajador NULL, incluso para ponentes municipales. Los externos no necesitan vínculo laboral ni asistencia de participante. Los firmantes administrativos sí deben ser trabajadores municipales vigentes y habilitados.

No se alteraron registros históricos, certificados, PDFs, QR ni hashes. Las pruebas reales están protegidas por `SESSION_SPEAKERS_NEON_TESTS=1`, usan transacciones exteriores con ROLLBACK y savepoints para errores esperados; comparan registros y hashes de los archivos reales antes/después. La preparación y revisión PDF usan un disco de pruebas separado, nunca el almacenamiento de PDFs reales. Las secuencias PostgreSQL pueden consumir números aun con ROLLBACK; no se restauran secuencias ni se reutilizan IDs.

## Operaciones de ponentes

| Método y ruta | Parámetros / respuesta real | Permisos |
| --- | --- | --- |
| GET `/activity/{activity}/sessions/{id}/ponentes` | `activity`: actividad; `id`: sesión. Devuelve `id_actividad`, `id_sesion`, `ponentes_disponibles`, `vinculos`. Disponibles: `id_persona_ponente`, `id_persona`, nombres y apellidos. Vínculos: `id_actividad_ponente`, `id_actividad`, `id_actividad_sesion`, `id_persona_ponente`, `id_persona`, nombres y apellidos reales. Sesión sin vínculos: `vinculos:[]`. | actividades.ver, ponentes-firmantes.ver; área autorizada y políticas de sesión/persona ponente |
| POST misma ruta | JSON `{ "id_persona_ponente": 5 }`. Devuelve 200 con `data.vinculo` persistido. No acepta actividad/sesión del body. | anteriores y ponentes-firmantes.crear |
| DELETE misma ruta | JSON `{ "id_actividad_ponente": 9 }`. Devuelve 200 con `data.desvinculado:true`. Sólo permite quitar vínculos de esa sesión sin reconocimientos asociados. | lectura de sesión/ponentes y ponentes-firmantes.eliminar |
| GET `/activity-session/{id}/ponentes-certificacion` | Modalidad `sesion`; `ponentes_sesion` contiene exclusivamente asignaciones de esa sesión con `reconocimiento`. `ponentes_actividad` conserva vínculos globales NULL con `reconocimiento_por_actividad`, sin atribuirlos a sesiones. | emision.ver, actividades.ver, ponentes-firmantes.ver; ámbito de área |
| GET `/activity/{id}/ponentes-certificacion` | Modalidad `actividad`, sesión NULL, `ponentes` globales con evaluación `reconocimiento`. | mismos permisos y ámbito |
| GET `/activity-speaker` | CRUD previo; ahora incluye la columna nullable cuando existe en el esquema. | políticas previas de ponentes |
| GET `/certificaciones/catalogos-origenes` | Tipos de actividad y certificado activos, independientes; no hay equivalencia automática entre sus IDs. | emision.ver y actividades.ver |

POST duplicado dentro de la misma sesión devuelve 409, incluso con dos filas de persona_ponente para la misma persona. Sesiones distintas permiten el mismo beneficiario. Una sesión de otra actividad devuelve 409. No hay endpoint de reasignación de sesión. El CRUD genérico impide cambiar/eliminar asignaciones de sesión: usar la operación específica de desvinculación. También conserva vínculos históricos que sustentan documentos.

## Elegibilidad por sesión

Cada `ponentes_sesion[].reconocimiento` devuelve `origen:ponente_sesion`, `id_origen`, `modalidad_reconocimiento:sesion`, `estado_elegibilidad`, `puede_preparar`, `puede_generar_pdf`, `puede_emitir:false` y `motivos_bloqueo`. Si es elegible añade identidad de persona/ponente/vínculo/actividad/sesión, nombres reales, `sesion`, `fecha_sesion`, tipo vigente, `certificados_existentes` y `puede_consultar_certificado_existente`. Si está bloqueado puede faltar el contexto completo; Angular debe usar los IDs del vínculo y el `id_sesion` del contenedor, sin fabricarlos ni considerar elegible el resultado.

Requisitos reales:

1. Persona y ponente existentes, vínculo persistente y coincidencia de actividad/sesión.
2. Sesión `Finalizada`, fecha real no futura dentro del periodo de la actividad.
3. Actividad `Activa` o `Cerrada`, periodo ordenado y solicitud persistida `Aprobada`. Por sesión no se exige que hayan terminado las demás sesiones.
4. Asignación real del ponente a esa sesión. El reconocimiento referencia esa asignación con trabajador NULL; se admiten ponentes externos sin relación laboral. La modalidad histórica por actividad conserva sus requisitos anteriores.
5. Un único tipo activo cuyo nombre coincide con la configuración de Reconocimiento en `tipo_certificado`. No se usan IDs fijos ni el tipo de capacitación.
6. Permisos efectivos de creación de certificados, documentos y reconocimientos para habilitar las facultades de preparación.

No se exige ni se crea participación/asistencia del ponente. La asignación no es firma digital. Un antecedente del mismo beneficiario/sesión/tipo deshabilita preparar otro; se recupera el documento existente.

## Preparación y recuperación

POST `/certificaciones/ponente_sesion/{id_actividad_ponente}/preparar`:

```json
{"accion":"revisar_documento","modalidad_reconocimiento":"sesion","id_sesion":1}
```

Devuelve 200: `modo:administrativo`, `persistido:false`, `token_revision`, `expires_at` (30 minutos), `mensaje`, `hash_pdf`, `vista_previa_pdf:{contenido_base64,tipo_contenido}`, `origen`, `id_origen`, modalidad, IDs de vínculo/actividad/sesión. Si se omiten ambos textos, se conserva la composición automática anterior. La modalidad ponente_sesion admite ahora mensaje y motivo personalizados según el contrato de texto editable descrito más abajo. No admite HTML, nombres, persona, tipo, QR ni código suministrados por el cliente.

```json
{"accion":"confirmar_preparacion","modalidad_reconocimiento":"sesion","id_sesion":1,"token_revision":"<64 caracteres hexadecimales>","confirmar":true}
```

Devuelve 201 al crear y 200 al repetir exactamente la misma revisión. Incluye `creado`, `preparacion_persistida`, `id_reconocimiento`, `id_certificado_documento`, código y detalle del flujo existente. Guarda reconocimiento y certificado `PREPARADO`, sin fecha de emisión, y documento `Vigente`, con PDF/hash exactos de la revisión. No envía a Gerencia. La fecha de registro del reconocimiento es hoy; la fecha acreditada de intervención es `fecha_sesion`.

La confirmación bloquea actividad, vínculos, identidad y tipo; relee el contexto y rechaza cambios. Otro token no duplica una raíz existente. Un reconocimiento de sesión sin certificado recuperable bloquea regularización (409), sin crear otro. El vínculo permanece protegido desde la preparación, incluso antes de confirmar responsables.

Recuperar el borrador desde la respuesta de preparación y `certificados_existentes`, usando GET `/certificados-documentos/detail/{id}?flujo=firmas`. Los catálogos GET `/certificados/list` y GET `/certificados-documentos/list` conservan sus estados visibles anteriores: no listan el borrador PREPARADO antes del envío explícito. Admiten filtros `id_reconocimiento`, `id_actividad`, `id_sesion`, `id_persona`, `id_tipo_certificado` cuando corresponda al catálogo existente. La identidad devuelta conserva `origen:ponente_sesion`, modalidad y sesión. GET `/certificados-documentos/{id}/archivo` descarga el archivo privado vigente bajo los permisos existentes.

Para actividad se conserva POST `/certificaciones/ponente_actividad/{id}/preparar` con modalidad explícita `actividad` y sin sesión. En esa ruta antigua, solicitar modalidad `sesion` sigue devolviendo 409: usar `ponente_sesion` con un vínculo real. Nunca hay conversión silenciosa.

Los orígenes genéricos mantienen GET `/reconocimientos/{id}/evaluacion-certificacion`, GET `/practices/{id}/evaluacion-certificacion` y POST `/certificaciones/{reconocimiento|practica}/{id}/preparar`, sin modalidad. Sus requisitos institucionales continúan en configuración: sin referencia y estados autorizados no son elegibles. Las prácticas comprueban periodo, institución y supervisor vigente que cubra el periodo. No se agregan esos requisitos a Capacitación.

## Revisión, envío explícito y Gerencia

Se reutilizan las rutas documentales existentes, sin ampliar permisos globales. Consultar catálogo, detalle, descargar y consultar RR. HH. requiere `emision.ver` y el ámbito existente. Preparar requiere `emision.ver`, `emision.crear`, `actividades.ver`, `ponentes-firmantes.ver` y `reconocimientos.crear`. Revisar/enviar a Gerencia, consultar firmantes disponibles, revisar/asignar/confirmar responsables requiere `emision.ver` y `emision.editar`, además del ámbito y estado documental exigidos. Estas confirmaciones administrativas no ejecutan la operación criptográfica `firmas.firmar`. Los detalles completos de firmantes se conservan en `administrative-signers-api-contract.md` y los de gestión documental en `certificate-stage-a-api-contract.md` / `certificate-stage-b-api-contract.md`.

1. POST `/certificados-documentos/{documento}/revisar-envio` con `{}`: genera revisión de envío.
2. POST `/certificados-documentos/{documento}/enviar-gerencia` con `token_revision` y `confirmar:true`: acción explícita; cambia a `PENDIENTE_FIRMA` cuando cumple las condiciones existentes. Guardar el borrador no ejecuta esta operación.
3. GET `/certificados-documentos/{documento}/firma/firmantes-disponibles`: funcionarios municipales habilitados.
4. POST `/certificados-documentos/{documento}/firma/revision-pdf` con `modalidad:PARALELA|SECUENCIAL` y `firmantes:[{id_persona_trabajador,orden_firma}]`: PDF revisable con responsables.
5. POST `/certificados-documentos/{documento}/firma/asignar` con el mismo plan y `confirmar:true`.
6. POST `/certificados-documentos/{documento}/firma/confirmar-pdf` con `token_revision` y `confirmar:true`: conserva versión original y confirma la revisión exacta.

Código único, QR, SHA-256 y almacenamiento/versionado privado siguen en los servicios documentales existentes. El PDF de sesión muestra beneficiario real, función como ponente, actividad, nombre de sesión, fecha y mensaje. No se regeneran documentos históricos confirmados. Confirmar responsables es una confirmación administrativa; no acredita una firma criptográfica.

## RR. HH. y estados

GET `/rrhh/certificados` conserva `actividades` para participantes y usa `otros_origenes` para reconocimientos/prácticas, incluidos ponentes por sesión. Los elementos incluyen `origen`, `modalidad_reconocimiento`, `id_actividad_ponente`, `id_reconocimiento`, `id_actividad`, `id_sesion`, `sesion`, `fecha_sesion`, beneficiario, tipo, código y documento real. Los filtros por actividad/sesión usan contexto aprobado sin atribuir asistencia al ponente.

Sólo se ofrecen como disponibles después de confirmar el PDF con responsables habilitados. Se conservan los indicadores administrativos `pdf_confirmado`, `preparado_para_entrega`, `disponible_rrhh`; no se inventa entrega ni emisión oficial. El original conserva bytes, código y hash. GET `/certificados/verificar/{codigo}` mantiene la comprobación pública de integridad sin habilitar descargas o afirmar firma oficial por estar preparado.

## Errores

| HTTP | Tratamiento |
| --- | --- |
| 401 | Sin autenticación válida; volver a autenticar. |
| 403 | Permiso/política insuficiente, ámbito ajeno o revisión de otro usuario. No habilitar por un flag del frontend. |
| 404 | Sesión, ponente, vínculo, origen o documento inexistente/no accesible en su ámbito. |
| 409 | Esquema requerido ausente en otros entornos, sesión de otra actividad, duplicado, vínculo histórico usado como sesión, no finalizada, evidencia institucional insuficiente, vínculo con reconocimiento protegido, contexto cambiado, revisión caducada o integridad inválida. Conservar documento anterior y mostrar motivo. |
| 422 | Payload inválido, modalidad omitida/incompatible, campos extra en vinculación/preparación o confirmación ausente. |

Las evaluaciones GET con requisitos pendientes devuelven 200 con `estado_elegibilidad:Pendiente de validación` y facultades false. Los permisos se comprueban antes de entregar el contexto. Los mensajes concretos proceden del backend.

## Texto editable de reconocimiento por sesión

Se reutiliza `POST /api/certificaciones/ponente_sesion/{id_actividad_ponente}/preparar`, con `auth.cookie`, permisos y elegibilidad existentes. Esta edición sólo aplica a `ponente_sesion`; Capacitación, reconocimiento por actividad y prácticas conservan sus contratos.

Vista previa editable:

```json
{
  "accion": "revisar_documento",
  "modalidad_reconocimiento": "sesion",
  "id_sesion": 1,
  "mensaje": "Por compartir sus conocimientos y contribuir al desarrollo de la sesión.",
  "motivo": "Participación como ponente y aporte técnico."
}
```

Se exige enviar ambos campos cuando se proporciona alguno. `mensaje`: string no vacío, máximo 1.200 caracteres. `motivo`: string no vacío, máximo 500 caracteres. Se reutiliza el patrón de texto plano de Capacitación: se rechazan HTML, controles y marcas bidireccionales; se conserva el escape Blade, saltos de línea y validación de página única. Los valores devueltos por el servidor son la composición revisada y deben reutilizarse al confirmar. No se aceptan campos adicionales como persona, actividad, origen, tipo, código, QR, hash o PDF del cliente. La sesión debe corresponder a la asignación real.

El generador institucional recibe el mensaje personalizado y el contexto acreditado. La plantilla muestra un motivo separado, mensaje, identidad real, actividad, sesión, fecha y QR/código. Sólo estos reconocimientos editables usan el formato compacto institucional ya existente para conservar una página. La vista previa devuelve `mensaje`, `motivo`, `mensaje_estructurado_disponible:true`, `texto_editable:true`, `requiere_confirmacion:true`, `recuperado:false`, `token_revision`, `expires_at`, `hash_pdf` y PDF base64. No persiste certificado ni envía a Gerencia.

Confirmación del PDF revisado:

```json
{
  "accion": "confirmar_preparacion",
  "modalidad_reconocimiento": "sesion",
  "id_sesion": 1,
  "mensaje": "Por compartir sus conocimientos y contribuir al desarrollo de la sesión.",
  "motivo": "Participación como ponente y aporte técnico.",
  "token_revision": "<token recibido en la vista previa>",
  "confirmar": true
}
```

El token de 30 minutos referencia el manifiesto privado cifrado que contiene actor, asignación/origen, beneficiario, actividad, sesión, tipo, fechas y evidencia institucional, ambos textos, código y SHA-256 del PDF y hash de plantilla. Cada nueva revisión válida sustituye la revisión vigente de ese actor/asignación, bajo bloqueo del origen; la referencia vigente almacena únicamente el hash del token, cifrado. Confirmar un token anterior o textos diferentes devuelve 409 y exige otra revisión. Omitir textos al confirmar una revisión personalizada devuelve 422. Una revisión de otro actor devuelve 403. Un PDF alterado se rechaza por hash. No se registra el token ni el contenido en logs nuevos.

### Persistencia compatible con Neon

Se verificó el esquema real de `certificados_morales`: `reconocimiento.motivo` y `reconocimiento.descripcion` son VARCHAR(500); no existe `certificado_documento.mensaje_aprobado`. No se crearon columnas ni se ejecutaron migraciones.

- Motivo: `certificado.reconocimiento.motivo`, y copia inmutable en el contexto del manifiesto aprobado.
- Mensaje: `message` y `context.texto_reconocimiento.mensaje` del manifiesto privado cifrado ya utilizado por Capacitación. No se trunca para guardarlo en descripcion ni se inventa una columna SQL.
- Versión de contenido: `context.texto_reconocimiento.v=1`, junto a `motivo` y `mensaje`. El contexto queda vinculado al código y al hash del PDF preparado. La versión con responsables conserva el mismo contenido aprobado usando el versionado existente.

Las confirmaciones releen y bloquean la identidad/elegibilidad, comparan el contexto y los textos, y guardan los bytes exactos de la vista previa. Repetir la confirmación correcta devuelve el mismo documento, sin duplicarlo. Beneficiario, asignación, actividad, sesión, tipo y código se determinan en el servidor; certificado.id_actividad_sesion sigue NULL conforme a la FK de asistencia existente.

### Recuperación e inmutabilidad

Una vez guardada la preparación, el contenido es inmutable en este flujo, igual que la recuperación de Capacitación. Volver a solicitar revisar_documento con los mismos textos, o sin ambos para recuperar un registro previo, devuelve el PDF guardado: `recuperado:true`, `persistido:true`, `creado:false`, `texto_editable:false`, `requiere_confirmacion:false`, `token_revision:null`, `mensaje`, `mensaje_aprobado`, `motivo`, código, hash y PDF base64. No genera otro PDF ni cambia sus bytes. Solicitar textos distintos para una preparación existente devuelve 409; no los ignora silenciosamente.

`GET /api/certificados-documentos/detail/{id_documento}?flujo=firmas` devuelve también `mensaje`, `mensaje_aprobado`, `motivo`, `texto_editable:false`, `mensaje_estructurado_disponible:true` y `mensaje_persistido_en_pdf:true`, además de identidad y estado existentes. La bandeja RR. HH. conserva estos textos cuando corresponde. Los documentos antiguos sin el bloque de contenido v1 recuperan su mensaje aprobado anterior, sin regeneración ni reinterpretación del motivo. Si falta el manifiesto aprobado no se inventa texto a partir de datos nuevos.

Se mantienen revisión/envío explícito a Gerencia, selección de trabajadores autorizados, revisión y confirmación del PDF y disponibilidad Listo para entrega. No hay firma criptográfica, emisión oficial ni entrega registrada. No se alteró Angular.

### Verificación de esta entrega

La inspección PostgreSQL READ ONLY aprobó 1 caso y 9 aserciones, incluida comparación de registros y hashes históricos. La prueba controlada en Neon aprobó 1 caso y 29 aserciones: vínculo nuevo temporal, motivo SQL, mensaje cifrado, preparación, recuperación con PDF íntegro y revisión de envío, con ROLLBACK y almacenamiento de prueba aislado. No se enviaron documentos de prueba a Gerencia real. Las secuencias pueden consumir números con ROLLBACK; no se restauran ni se reutilizan IDs.

Las 19 pruebas focalizadas finales aprobaron 174 aserciones: textos/HTML, validación, tokens vigentes y alterados, bytes del PDF, recuperación, duplicados, históricos, errores 401/403/409/422 y flujo completo hasta confirmación y RR. HH. en entorno aislado. Los fallos iniciales detectaron desbordamiento de una página por el motivo adicional; se corrigió usando el formato compacto existente. Se actualizó la expectativa anterior de 409 para recuperación, que ahora devuelve el documento guardado con 200 y flags de sólo lectura. La regresión ampliada aprobó 403 pruebas y 5.882 aserciones en orígenes, Capacitación, reconocimientos por actividad, prácticas, Gerencia, documentos, módulos y seguridad. Después del ajuste adicional de los indicadores de contenido para Angular, se repitieron las 19 focalizadas con resultado aprobado. Pint, sintaxis PHP y git diff --check finalizaron correctamente.

## Constancias de Prácticas — integración para Angular

Se conserva el origen `practica`, el generador documental y el flujo administrativo existentes. No se añadieron tablas, migraciones, permisos ni una segunda implementación PDF. Los cambios funcionales se limitan a la validación y presentación de prácticas; los otros orígenes conservan su comportamiento.

### Política institucional pendiente

Se comprobaron los nombres exactos de configuración:

- `CERTIFICATION_PRACTICE_POLICY_REFERENCE`: referencia real de la autorización municipal aprobada.
- `CERTIFICATION_PRACTICE_APPROVED_STATES`: estados exactos de práctica autorizados por esa política, separados por `|`.
- `CERTIFICATION_PRACTICE_SUPERVISOR_STATES`: estados exactos de supervisión acreditada, separados por `|`.

En Neon, la referencia está vacía y ambas listas están vacías. No se inventó ni activó una política. Un estado `activo` y el transcurso del tiempo no acreditan por sí solos una conclusión aprobada. La habilitación institucional requiere los valores aprobados reales y aplicar la configuración mediante el procedimiento habitual del despliegue.

### Elegibilidad y recuperación

`GET /api/practices/{id}/evaluacion-certificacion` requiere autenticación, permiso `emision.ver`, permiso de consulta de prácticas y ámbito de área. Devuelve `practica` con identidad real, institución, área, carrera, fechas y estado; `supervisores`; `estado_elegibilidad`; `motivos_bloqueo`; `puede_preparar`; `puede_generar_pdf`; `certificados_existentes` y `puede_consultar_certificado_existente`. Las facultades de preparación requieren además el permiso efectivo de creación existente.

La práctica debe tener identidad y asociaciones coherentes, beneficiario/carrera/institución/área no vacíos, periodo ordenado y concluido, estado autorizado y supervisión acreditada. Tanto la asignación del supervisor como su vínculo laboral deben cubrir todo el periodo. Se conserva la admisión de fecha de fin NULL como vínculo abierto. No se exige que el practicante sea trabajador ni que tenga participación/asistencia de Capacitación.

Campos adicionales: `periodo_supervision_cubierto` indica cobertura temporal de supervisión y vínculo laboral; `supervision_acreditada` añade el estado de supervisión autorizado. `periodo_transcurrido_valido` sólo acredita el periodo. `cumplimiento_requisitos_acreditado` y `aprobacion_acreditada` permanecen NULL si hay bloqueos, incluido catálogo documental ausente/ambiguo. `puede_emitir` sigue false. Una constancia existente impide preparar otra y habilita su consulta; no se regenera ni duplica.

Los datos laborales añadidos a la evaluación no cambian la estructura histórica del contexto aprobado almacenado en los manifiestos. Los registros relacionados se bloquean al confirmar la preparación y se relee la evidencia; un cambio que invalide requisitos rechaza la confirmación.

### Endpoints y payloads

1. Revisar constancia: `POST /api/certificaciones/practica/{id_practica}/preparar` con `{"accion":"revisar_documento"}`. Devuelve revisión temporal, token, mensaje, hash y PDF base64. No registra certificado ni envía a Gerencia.
2. Guardar: misma ruta con `{"accion":"confirmar_preparacion","token_revision":"<token recibido>","confirmar":true}`. Devuelve 201 al crear y 200 al repetir la misma revisión. Persiste beneficiario real, tipo Prácticas del catálogo activo e `id_practica`; participación, reconocimiento, sesión y fecha de emisión permanecen NULL. Conserva estado PREPARADO y PDF privado.
3. Recuperar al recargar Angular: cada elemento de `certificados_existentes` incluye ahora `id_certificado_documento`. Usar ese ID con `GET /api/certificados-documentos/detail/{id_documento}?flujo=firmas`. Es NULL si no existe un documento Vigente único del mismo beneficiario; en ese caso se requiere revisión administrativa, sin elegir un documento arbitrario ni preparar otro certificado. Descargar con permisos: `GET /api/certificados-documentos/{id_documento}/archivo`.
4. Revisar envío: `POST /api/certificados-documentos/{id_documento}/revisar-envio` con `{}`. Enviar explícitamente: `POST /api/certificados-documentos/{id_documento}/enviar-gerencia` con token de esa revisión y `confirmar:true`.
5. Responsables: `GET /api/certificados-documentos/{id_documento}/firma/firmantes-disponibles`. Revisar PDF: `POST .../firma/revision-pdf` con `modalidad:PARALELA|SECUENCIAL` y `firmantes:[{id_persona_trabajador,orden_firma}]`. Asignar: `POST .../firma/asignar` con el mismo plan y `confirmar:true`. Confirmar administrativamente: `POST .../firma/confirmar-pdf` con token de la revisión PDF y `confirmar:true`.
6. Consultar: `GET .../firma/progreso` y `GET /api/rrhh/certificados`. Tras confirmar el PDF, `disponible_rrhh:true`, `etiqueta_estado:"Listo para entrega"`, estado PENDIENTE_FIRMA. No acredita firma criptográfica, emisión oficial ni entrega registrada.

Los errores conservan los códigos existentes: 401/403 por autenticación, permisos o ámbito; 404 para práctica/documento inexistente; 409 por evidencia insuficiente, duplicados, revisión cambiada/caducada o incoherencia; 422 para payload inválido.

### Portal Público

La constancia confirmada aparece en `POST /api/portal-certificados/consultar-dni` con `origen:practica`, bajo las mismas comprobaciones de integridad, disponibilidad y minimización existentes. PREPARADO y PDF no confirmado no se ofrecen como disponibles. Consultar el DNI no autoriza acceso al PDF. Sin política expresa de publicación y código autorizado, `pdf_publicado:false`, `url_documento:null`, `url_descarga:null` y solicitud pública de archivo rechazada con 403.

La publicación sigue dependiendo de `CERTIFICATION_PUBLIC_PDF_POLICY_REFERENCE` y `CERTIFICATION_PUBLIC_PDF_CODES`, independientemente de la aprobación de la práctica. Se conserva la verificación por código, el QR original, la versión PDF confirmada y los hashes. No se publicó ninguna constancia real.

### Estado real comprobado y validación de esta entrega

Neon `certificados_morales`, usuario `equipo_dev`: practicante 2 → persona 3 → práctica 1; Universidad Peruana Union; Gerencia de Desarrollo Social; carrera sistemas. Periodo 02/10/2026–19/11/2026, estado `activo`. Supervisión 07/10/2026–14/11/2026, estado `Activo`, responsable laboral 4 con vínculo 29/09/2026–22/11/2026. El vínculo laboral sí abarca la práctica, pero la asignación de supervisión no la cubre por ambos extremos. Catálogo: tipo Prácticas, ID 3, activo. No hay constancias existentes para esa práctica.

A fecha 16/11/2026 permanece bloqueada por periodo pendiente, política de práctica/supervisión no configurada y supervisión insuficiente. No se alteraron fechas, estados ni registros para hacerla elegible. Debe existir acreditación real y aprobación institucional; el mero vencimiento de la fecha no resuelve la supervisión ni autoriza el estado.

`PracticeCertificatesNeonTest` usa exclusivamente REPEATABLE READ / READ ONLY contra registros existentes. Comprueba esquema, restricciones, índice, evaluación y endpoint autorizado, y compara los registros y los SHA-256 de los PDFs reales antes/después. No prepara documentos ni realiza envíos reales. La ejecución final aprobó 1 caso y 24 aserciones.

El recorrido elegible completo, los bloqueos, duplicados, beneficiario/tipo, contexto histórico, PDF/QR/SHA-256, Gerencia, confirmación, Listo para entrega y Portal Público con publicación/revocación se ejecutan en SQLite en memoria y almacenamiento simulado, con una política sintética limitada al proceso de pruebas. Nunca se utiliza esa política para datos reales. La ejecución focalizada inicial aprobó 24 casos y 255 aserciones. La regresión ampliada aprobó 385 casos y 5.760 aserciones (orígenes, seguridad, beneficiarios, elegibilidad, Gerencia, documentos, módulos y protección de datos). Una comprobación adicional de recarga detectó 404 al usar el ID de certificado para un borrador PREPARADO. Se completó únicamente la evaluación de prácticas con el ID del documento vigente; no se alteró el comportamiento del endpoint genérico ni la lógica de Gerencia. Tras ese ajuste y las pruebas de documentos ausentes/ambiguos/de otro beneficiario, la suite final CertificateOriginsTest aprobó 68 casos y 581 aserciones. La ruta privada documental de recuperación y el flujo completo del Portal Público quedaron aprobados. No se repitió la regresión ampliada después de este último ajuste exclusivo de prácticas. Pint, sintaxis PHP y git diff --check finalizaron correctamente.

Ejecución de la integración real de esta entrega, exclusivamente de lectura:

```powershell
$env:PRACTICE_CERTIFICATES_NEON_READ_ONLY='1'
php vendor/phpunit/phpunit/phpunit --configuration phpunit.postgresql.xml tests/Feature/PracticeCertificatesNeonTest.php
```

Los fixtures y políticas sintéticas se ejecutan sólo con SQLite en memoria y archivos de prueba. No se generaron constancias reales ni se enviaron documentos de prueba a Gerencia real. No se modificó la política de publicación ni la configuración institucional del entorno.
## Validación vigente — 2026-11-16

Regresiones focalizadas aprobadas: 366 casos, 5.555 aserciones, en CertificateOriginsTest, SessionDynamicBeneficiariesTest, CertificationReadinessTest, UserSecurityTest, CertificateManagementStageATest, CertificateSigningStageBTest, CertificateDocumentGenerationTest, ModuleIntegrationTest y GestionCertificationProtectionTest. No se ejecutó la suite completa del proyecto. La cobertura de sesión usa también un esquema sin reconocimiento.id_tipo_certificado y beneficiario sin trabajador, pasando por preparación idempotente, Gerencia, revisión/asignación/confirmación de responsables y disponibilidad RR. HH. Conserva las regresiones de reconocimiento histórico por actividad y de Capacitación.

La primera integración real SessionSpeakersNeonTest aprobó 3 casos y 79 aserciones: conexión y esquema, operaciones HTTP/servicio de asignación con restricciones PostgreSQL, preparación/recuperación y confirmación administrativa. Cada caso hizo ROLLBACK y comparó los datos y hashes reales antes/después. El recorrido PDF usa almacenamiento de pruebas separado; nunca escribe PDFs en el disco real. Los actores HTTP son usuarios existentes con permisos controlados por el test; la autenticación y permisos se cubren adicionalmente en las suites automatizadas de seguridad.

La ejecución final ampliada del ponente externo aprobó 1 caso y 32 aserciones contra Neon. Creó persona y ponente temporales sin trabajador, preparó y recuperó el mismo reconocimiento, comprobó tipo Reconocimiento y sesión/participación NULL en certificado, envió a Gerencia, consultó responsables mediante el servicio real, revisó PDF, asignó responsables, confirmó administrativamente y comprobó disponible_rrhh=true, etiqueta Listo para entrega y presencia en la bandeja de RR. HH. Comprobó también que el PDF original conservara su código y SHA-256. Terminó con ROLLBACK y comparación aprobada de registros y hashes de los archivos reales. La consulta ampliada de esquema y límites también aprobó 1 caso y 10 aserciones.

Ejecución real explícita en PowerShell:

```powershell
$env:SESSION_SPEAKERS_NEON_TESTS='1'
php -d memory_limit=1G vendor/phpunit/phpunit/phpunit --configuration phpunit.postgresql.xml tests/Feature/SessionSpeakersNeonTest.php
```

Los intentos reales intermedios registraron fallos de DNS/pérdida de conexión y respuestas 500; no se consideran ejecuciones aprobadas. La ejecución final externa sí completó todas sus aserciones. Neon tiene idle_in_transaction_session_timeout=5min; la prueba larga usa SET LOCAL a 20min, manteniendo lock_timeout=3s y statement_timeout=20s. Ese ajuste se revierte con la transacción y no modifica la configuración persistente. No se atribuye a ese límite, por sí solo, la causa de todos los cortes.

Las extensiones SQLite y memory_limit utilizadas en las regresiones se habilitaron sólo para los procesos de prueba. No se cambiaron PHP, dependencias ni configuración de producción. Las primeras ejecuciones focalizadas detectaron expectativas antiguas de bloqueo por ausencia de trabajador y una identidad de actor de prueba que dejó de ser trabajador al simular al externo; ambas se corrigieron y los casos se repitieron aprobados. Pint, sintaxis PHP y git diff --check finalizaron correctamente.

## Reconocimientos municipales individuales: modalidad académica explícita

Esta sección prevalece sobre la exigencia institucional genérica anterior únicamente para los individuales seleccionados en desarrollo. No modifica el contrato de Capacitación, Prácticas ni Ponentes.

Configuración (opt-in, valores predeterminados deshabilitados):

```dotenv
CERTIFICATION_RECOGNITION_ACADEMIC_ENABLED=true
CERTIFICATION_RECOGNITION_ACADEMIC_IDS=1,28
CERTIFICATION_RECOGNITION_ACADEMIC_STATES=ca|PREPARADO
```

Sólo tiene efecto con `APP_ENV=local` o `development`. Producción, staging y testing mantienen la política institucional aunque las variables académicas estén activadas. Los IDs y estados son listas exactas verificadas para este desarrollo; `ca` no significa aprobado. Un ID no seleccionado vuelve a la política institucional estricta (`CERTIFICATION_RECOGNITION_POLICY_REFERENCE` y `CERTIFICATION_RECOGNITION_APPROVED_STATES`). Anulación/cancelación, beneficiario inexistente, falta de persona/área/nombres, título/motivo/fecha inválidos, modalidad de ponente y tipo documental inexistente o ambiguo siguen bloqueando. Permisos y ámbito se comprueban antes de evaluar; la variable no otorga acceso. No se cambia el estado del reconocimiento ni se acredita una aprobación institucional ficticia.

### Identificadores y consulta compatible

`GET /api/reconocimientos/{id_reconocimiento}/evaluacion-certificacion` requiere autenticación, `emision.ver`, `reconocimientos.ver` y ámbito. El ID procede de `GET /api/reconocimientos/list`: no es el ID de persona, trabajador, certificado ni asignación de ponente. Devuelve `origen:reconocimiento`, `id_origen`, `id_reconocimiento`, `id_persona`, `id_persona_trabajador`, `reconocimiento.nombres` y apellidos reales, `modalidad_reconocimiento`, `modalidad_preparacion:academica|institucional`, `estado_elegibilidad`, `puede_preparar`, `puede_generar_pdf`, `motivos_bloqueo` y `aprobacion_institucional_acreditada`. En modo académico este último es siempre false.

Para conservar el validador Angular individual, `id_actividad` e `id_sesion` superiores son NULL. Las relaciones de otra modalidad se informan en `contexto_origen_real`. Los documentos de ponentes nunca se ofrecen como individuales: se devuelven en `certificados_otro_origen`, con `certificados_existentes:[]`, `puede_consultar_certificado_existente:false`, `puede_preparar:false` y motivo de modalidad incorrecta. Su consulta y recuperación se realizan con el origen original; no se convierten en individuales. Los documentos individuales conservan `certificados_existentes`, su estado de certificado e `id_certificado_documento`; `documentos_existentes` distingue el estado documental. `estado_reconocimiento:PREPARADO` no acredita la existencia de un PDF ni el estado documental PREPARADO.

Un origen inexistente devuelve 404; permisos o ámbito insuficientes, 403. Sin beneficiario/área verificables, 409 con motivo específico y sin autorización de preparación. Los errores de conexión o timeout SQL conservan 503; ninguna excepción se convierte en elegibilidad verdadera.

### Flujo documental reutilizado

- Consulta ligera: `GET /api/certificaciones/reconocimiento/{id_reconocimiento}/preparar?accion=consultar_preparacion` (también POST con esa acción). No genera PDF ni token. Sin documento devuelve evaluación y `recuperado:false`; con uno devuelve su identidad persistida, mensaje, hash, estado y facultad de envío, con `recuperado:true`, `texto_editable:false` y sin Base64. Requiere lectura documental y ámbito, no creación.
- Revisión: `POST /api/certificaciones/reconocimiento/{id_reconocimiento}/preparar` con `{"accion":"revisar_documento"}`. Mensaje predeterminado: título y motivo reales. La API también admite `mensaje` (máximo 1200) y `motivo` (máximo 500), ambos juntos, texto plano no vacío, sin HTML ni controles. La pantalla Angular individual actual utiliza el mensaje predeterminado; la edición API no implica modificar Angular.
- Previsualización: respuesta con mensaje, motivo, `modalidad_preparacion`, PDF Base64 institucional, QR/código, `hash_pdf` SHA-256, token de revisión y caducidad. No registra certificado ni envía a Gerencia. Se reutiliza la revisión vigente para el mismo usuario y contenido; una revisión nueva invalida el token anterior.
- Confirmación de preparación: misma ruta POST con `{"accion":"confirmar_preparacion","token_revision":"<token>","confirmar":true}`; repetir los textos si se personalizaron. Devuelve 201 al crear y 200 al reintentar la misma revisión. Certificado PREPARADO, fecha de emisión NULL y documento Vigente. Conserva PDF, hash, código y manifiesto privado cifrado; no cambia el reconocimiento histórico. No duplica ni sobrescribe una preparación existente.
- Recuperación compatible con la pantalla actual: evaluación ? `GET /api/certificados/detail/{id_certificado}` ? `GET /api/certificados-documentos/detail/{id_certificado_documento}?flujo=firmas`. También se dispone de la consulta ligera indicada arriba. Los documentos conservan `origen:reconocimiento` y beneficiario real.
- Envío explícito: `POST /api/certificados-documentos/{id}/revisar-envio`, seguido de `POST /api/certificados-documentos/{id}/enviar-gerencia` con token y `confirmar:true`. Transición a PENDIENTE_FIRMA. Preparar por sí solo no remite.
- Confirmación administrativa real: rutas existentes `/{id}/firma/firmantes-disponibles`, `/firma/revision-pdf`, `/firma/asignar`, `/firma/confirmar-pdf` y `/firma/progreso` bajo `/api/certificados-documentos`. Mantienen `emision.ver`, `emision.editar`, ámbito y responsables habilitados. La disponibilidad para RR. HH. procede de esa confirmación; el modo académico no la simula ni aplica firma criptográfica. Documento ANULADO no habilita preparar de nuevo ni entregar.

### Diagnóstico de los cinco registros de Neon

Consulta real de `certificados_morales`, 16/11/2026:

| Reconocimiento | Beneficiario | Modalidad real | Situación individual |
| --- | --- | --- | --- |
| 1 | persona 1, trabajador 1, Antón Talían, área 5 | individual; estado `ca` | Elegible académicamente por selección explícita; sin aprobación institucional |
| 2 | persona 2, trabajador 2, Maria Perez, área 5 | ponente por actividad; manifiesto `ponente_actividad`, asignación 1 | Bloqueado como individual; certificado 9/documento 10 PREPARADO, recuperar por su origen |
| 24 | persona 1, Antón Talían; asignación 17, actividad 3, sesión 5, área 5 | ponente por sesión; trabajador NULL válido | Bloqueado como individual; certificado 23/documento 22 PENDIENTE_FIRMA |
| 27 | persona 2, Maria Perez; asignación 21, actividad 4, sesión 8, área 9 | ponente por sesión; trabajador NULL válido | Bloqueado como individual; certificado 27/documento 26 PENDIENTE_FIRMA |
| 28 | persona 2, trabajador 2, Maria Perez, área 5 | individual; estado del reconocimiento `PREPARADO`, sin documento | Elegible académicamente; PREPARADO del origen no sustituye preparar el certificado |

Los fallos de verificación tenían dos causas comprobadas en el código del cliente (sólo lectura) y PostgreSQL: 24/27 no tenían trabajador porque son ponentes por sesión, por lo que la evaluación genérica fallaba al autorizar el área o por sus INNER JOIN; el 2 sí se evaluaba, pero Angular intentaba recuperar como individual un documento cuyo origen persistido era `ponente_actividad`, y su validador lo rechazaba. Se corrige la consulta y la clasificación en Laravel, manteniendo sus datos y documentos originales.

Las pruebas Neon están opt-in en `IndividualRecognitionsNeonTest` (`INDIVIDUAL_RECOGNITIONS_NEON=1`, grupo `postgresql`), con transacción, ROLLBACK obligatorio, comparación de huellas de tablas antes/después y almacenamiento falso separado. No ejecutan migraciones ni regeneran documentos históricos. Los identificadores de secuencia consumidos por INSERT de prueba pueden dejar huecos, como cualquier transacción PostgreSQL revertida.

Validacion final: suite ampliada de 458 pruebas y 6.252 aserciones aprobada; repeticion focalizada final de 9 pruebas academicas y 80 aserciones aprobada; Neon, 2 pruebas y 128 aserciones aprobadas con ROLLBACK y huellas de tablas/archivos sin cambios. Pint, sintaxis PHP y git diff --check aprobados. Las consultas individuales reales respondieron aproximadamente entre 0,6 y 1,3 segundos en la ultima ejecucion. Angular se inspecciono en modo de solo lectura y no se modifico. No se ejecutaron migraciones, commit ni push.

## Mensaje individual sin motivo obligatorio y consulta del documento original

Este apartado actualiza los requisitos de texto y los campos administrativos de los apartados anteriores. Se conservan la elegibilidad académica de 1/28, el control institucional de producción y todos los permisos y ámbitos existentes. No se cambian rutas ni Angular.

### Mensaje editable individual

`POST /api/certificaciones/reconocimiento/{id_reconocimiento}/preparar` admite `mensaje` por sí solo, sin exigir `motivo`. Se aplica la misma validación de texto plano no vacío, sin HTML, controles ni marcas de dirección, de hasta 1200 caracteres Unicode. `motivo` sigue siendo opcional (máximo 500) para compatibilidad con clientes anteriores. Por sesión se conserva el contrato previo de mensaje y motivo.

```json
{"accion":"revisar_documento","mensaje":"Mensaje municipal aprobado."}
```

La revisión devuelve el mensaje aprobado, el PDF de previsualización, su SHA-256 y el token ligado al usuario, origen, contenido y caducidad. No envía ni confirma administrativamente. Para guardar:

```json
{"accion":"confirmar_preparacion","mensaje":"Mensaje municipal aprobado.","token_revision":"<token revisado>","confirmar":true}
```

Un mensaje diferente al revisado devuelve 409. Sin mensaje personalizado se omite `mensaje` en ambas acciones y se usa el predeterminado del reconocimiento. El texto se conserva en el manifiesto privado cifrado y en los bytes del PDF aprobado. La consulta devuelve `texto_editable:false` para un documento existente; revisar otra vez un individual ya preparado o confirmado devuelve 409 y no sobrescribe su PDF. No se cambia el motivo del registro histórico. La evaluación anuncia `texto_editable:true` únicamente cuando el usuario puede preparar ese reconocimiento.

### Metadatos administrativos sin regeneración

Se reutilizan:

- `GET /api/reconocimientos/{id_reconocimiento}/evaluacion-certificacion`.
- `GET /api/certificaciones/reconocimiento/{id_reconocimiento}/preparar?accion=consultar_preparacion` (también POST con esa acción).
- `GET /api/certificados-documentos/detail/{id_certificado_documento}?flujo=firmas` para el detalle original.
- `GET /api/certificados-documentos/{id_certificado_documento}/archivo?flujo=firmas` para acceder al PDF administrativo con autenticación.

Las dos consultas de reconocimiento incluyen:

| Campo | Contenido real |
| --- | --- |
| `id_reconocimiento`, `modalidad_reconocimiento` | ID del registro consultado y modalidad original: individual, actividad o sesion |
| `reconocimiento`, `id_persona` | Identidad real ya verificada; la identidad documental se incluye también en `documento_existente.beneficiario` |
| `contexto_origen_real` | `origen`, `id_origen`, `id_actividad_ponente`, `id_actividad`, `id_sesion` originales |
| `documentos_existentes` | Lista de documentos administrativos consultables en el ámbito del usuario, con identificadores, beneficiario, modalidad, estados y facultades |
| `documento_existente` | Documento único vigente; si sólo hay un documento no vigente se conserva su metadata con acceso al PDF bloqueado; si hay ambigüedad, NULL y motivo explícito |
| `id_certificado`, `id_certificado_documento` | Identificadores del documento seleccionado, o NULL cuando no hay uno inequívoco consultable |
| `estado_certificado` | Estado SQL real, como PREPARADO o PENDIENTE_FIRMA; no se sustituye por una confirmación simulada |
| `estado_documento` | Estado SQL del documento, como Vigente o Anulado; es independiente del estado del certificado y del reconocimiento |
| `url_documento` | Ruta administrativa relativa del PDF existente y permitido; NULL si falta el archivo, no está vigente o el acceso no está autorizado |
| `puede_visualizar`, `puede_descargar` | Facultades administrativas sobre el archivo; no autorizan entregar ni acreditan firma institucional |
| `puede_consultar_documento_original` | Existe metadata documental autorizada, incluso cuando el archivo no puede descargarse |
| `motivo_documento` | Motivo concreto cuando falta el documento, archivo, vigencia, permiso o ámbito, o hay varios documentos |

Cada elemento de `documentos_existentes` conserva `origen:reconocimiento|ponente_actividad|ponente_sesion`, `id_origen`, modalidad, beneficiario, ambos IDs documentales, estados, URL, facultades (`ver`, `visualizar`, `descargar`) y `puede_preparar_como_individual:false`. `integridad_verificada:null` indica que una consulta de metadata no ha calculado el hash del PDF; la descarga existente sigue verificando la integridad completa antes de servirlo. En el detalle se conservan `facultades.ver` para metadata y se añaden `puede_visualizar`, `puede_descargar`, `facultades.visualizar` y `motivo_documento`; una URL no se inventa para un archivo ausente.

Para 2/24/27, `origen:reconocimiento` superior identifica la consulta de evaluación, no convierte el documento en individual. Usar `documento_existente.origen` o `contexto_origen_real.origen` al continuar con su flujo original. Se conservan `certificados_otro_origen` y `certificados_existentes:[]` para evitar que el validador individual anterior intente recuperar un ponente como individual; `puede_consultar_certificado_existente` sigue refiriéndose al flujo individual, mientras que `puede_consultar_documento_original` anuncia la consulta administrativa de otro origen. `puede_preparar:false` se mantiene.

La consulta ligera de otro origen devuelve la metadata del documento original con `recuperado:true`, `persistido:true`, `texto_editable:false`, sin Base64 ni token. Sin documento consultable devuelve el motivo y no ofrece URL. Los IDs 24 y 27 se resuelven por su asignación real a sesión y actividad; el 2 conserva el origen por actividad de su manifiesto aprobado. Los documentos visibles se filtran con las consultas de autorización existentes y, para el origen por actividad histórico, se comprueba también el área de su actividad original.

No se ejecuta el generador al evaluar, consultar ni descargar documentos existentes. La metadata no calcula firmas, facultades de remisión ni progreso completo para obtener sólo el estado; comprueba los permisos, el ámbito, la versión documental y la existencia del archivo. Las consultas de reconocimiento se sirven con `Cache-Control: private, no-store`.

Validación de esta ampliación: 255 pruebas y 3.387 aserciones aprobadas en orígenes, esquema existente y confirmación administrativa; repetición final del mensaje y `texto_editable`, 2 pruebas y 35 aserciones aprobadas. Neon: 2 pruebas y 216 aserciones aprobadas, incluida descarga administrativa real de documentos 10 (reconocimiento 2, PREPARADO), 22 (24, PENDIENTE_FIRMA) y 26 (27, PENDIENTE_FIRMA). Los individuales 1 y 28 continuaron elegibles y se verificaron mensaje personalizado sin motivo, texto predeterminado, contenido exacto del PDF, confirmación, recuperación y bloqueo de edición posterior. Las consultas de metadata no consultaron firmas ni generaron PDFs. ROLLBACK y comparación de huellas de tablas y archivos históricos aprobados. Pint, sintaxis PHP y git diff --check aprobados. Sin migraciones, modificaciones de Angular, commit ni push.

## Auditoría HTTP autenticada sin escrituras en Neon

La implementación funcional existente se conserva. Esta auditoría añade pruebas de transporte HTTP real y documentación; no modifica controladores, servicios, autenticación ni permisos. Las peticiones por red usan la cookie `access_token` emitida por `JwtService`; el middleware comprueba el JWT y carga el usuario y sus permisos desde la base. No se utiliza `actingAs` en el servidor HTTP.

| Petición comprobada | Entorno | HTTP observado |
| --- | --- | --- |
| GET `/api/reconocimientos/{1,28,2,24,27}/evaluacion-certificacion` | Servidor local conectado a Neon, usuario existente 1 | 200 en los cinco |
| GET `/api/certificaciones/reconocimiento/{id}/preparar?accion=consultar_preparacion` | Mismos cinco registros reales | 200, sin PDF Base64 ni token |
| GET `/api/certificados-documentos/detail/{10,22,26}?flujo=firmas` | Neon, usuario existente autorizado | 200 |
| GET `/api/certificados-documentos/{10,22,26}/archivo?flujo=firmas` | PDFs originales en almacenamiento privado | 200, contenido PDF |
| POST `/api/certificaciones/reconocimiento/28/preparar`, `revisar_documento` con sólo `mensaje` | Datos reales de Neon en transacción READ ONLY; vista previa y manifiesto en almacenamiento aislado | 200, texto exacto comprobado dentro del PDF |
| POST misma ruta, `confirmar_preparacion`, mensaje y token revisados | HTTP real con SQLite y archivos aislados | 201; repetir devuelve 200, un solo certificado |
| GET consulta ligera y detalle después de confirmar | HTTP real, SQLite aislado | 200, mensaje aprobado recuperado y `texto_editable:false` |
| GET archivo individual después de confirmar | HTTP real, SQLite aislado | 200, bytes y SHA-256 iguales a la revisión |
| POST confirmación con mensaje distinto o revisión de un individual ya persistido | HTTP real, SQLite aislado | 409, sin sobrescritura |
| GET evaluación sin cookie | Servidor real conectado a Neon y servidor aislado | 401 esperado |
| GET evaluación con JWT válido y usuario sin permisos | HTTP real, usuario restringido de la fixture aislada | 403 esperado |

No existe un usuario activo de Neon sin `emision.ver` que permita probar allí el 403 sin cambiar permisos o crear usuarios. Se cubre con la misma autenticación y autorización en el servidor aislado. No se ejecuta una confirmación válida sobre Neon: confirmarla exige INSERT y contradice la prohibición de modificar PostgreSQL. Su confirmación completa, recuperación, detalle y descarga se verifican por TCP en la fixture aislada; los IDs creados allí no son IDs de Neon.

Valores reales conservados:

| Reconocimiento | `documento_existente.origen` | `id_certificado` | `id_certificado_documento` | `estado_certificado` | `estado_documento` |
| --- | --- | --- | --- | --- | --- |
| 2 | ponente_actividad | 9 | 10 | PREPARADO | Vigente |
| 24 | ponente_sesion | 23 | 22 | PENDIENTE_FIRMA | Vigente |
| 27 | ponente_sesion | 27 | 26 | PENDIENTE_FIRMA | Vigente |

Para el usuario autorizado los tres devuelven `documento_existente.facultades.ver:true`, `.visualizar:true`, `.descargar:true`, `puede_visualizar:true`, `puede_descargar:true` y su URL administrativa real. `puede_preparar:false` no impide esa consulta. Los beneficiarios son Maria Perez (persona 2) para 2/27 y Antón Talían (persona 1) para 24.

Ejemplo de campos JSON observados en la evaluación del 24 (subconjunto de la respuesta, sin cambiar nombres):

```json
{
  "origen": "reconocimiento",
  "id_origen": 24,
  "puede_preparar": false,
  "texto_editable": false,
  "documento_existente": {
    "origen": "ponente_sesion",
    "id_origen": 17,
    "id_reconocimiento": 24,
    "id_certificado": 23,
    "id_certificado_documento": 22,
    "beneficiario": {"id_persona": 1, "nombre": "Antón Talían"},
    "estado_certificado": "PENDIENTE_FIRMA",
    "estado_documento": "Vigente",
    "url_documento": "/api/certificados-documentos/22/archivo?flujo=firmas",
    "puede_visualizar": true,
    "puede_descargar": true,
    "facultades": {"ver": true, "visualizar": true, "descargar": true}
  }
}
```

Se contrastó en modo de sólo lectura con las interfaces y servicios actuales de Angular. No hay diferencias de nombres en los campos comprobados. La evaluación conserva `origen:reconocimiento`, `id_actividad:null` e `id_sesion:null` superiores para su validador; el origen y ámbito documental reales están en `documento_existente` y `contexto_origen_real`. En evaluación usar `documento_existente.facultades.ver/descargar`; en el detalle, `facultades.ver/descargar`. No confundir `estado_documento:Vigente` con `estado_certificado:PREPARADO|PENDIENTE_FIRMA`, ni interpretar ninguno como confirmación administrativa. La pantalla municipal actual consume esos metadatos y envía `mensaje` sin exigir `motivo`.

El 28 real devuelve `texto_editable:true`, `puede_preparar:true` y `mensaje_predeterminado:"DESTACADO. E"`; la consulta ligera admite que `mensaje` esté ausente y conserva `mensaje_predeterminado`. Angular ya utiliza `mensaje ?? mensaje_predeterminado`. La revisión real se probó con `mensaje:"Mensaje municipal verificado por HTTP."`. El mensaje aprobado de una preparación persistida se recupera mediante `mensaje_aprobado`, con `texto_editable:false`.

Las pruebas de red son opt-in: `INDIVIDUAL_RECOGNITIONS_HTTP_READ_ONLY=1` y filtro `test_live_http_jwt_read_only` en `IndividualRecognitionsNeonTest` (grupo `postgresql`); `INDIVIDUAL_RECOGNITIONS_HTTP_ISOLATED=1` y filtro `test_individual_28_lifecycle_over_real_http` en `CertificateOriginsTest`. La primera usa el servidor de `APP_URL` (o `CERTIFICATE_HTTP_BASE_URL`) y sólo acepta host local para enviar la cookie. La revisión Neon adicional fuerza READ ONLY y usa almacenamiento temporal. La segunda exporta las fixtures SQLite existentes y arranca un servidor TCP con el middleware real; no conecta a PostgreSQL. En Windows la ejecución debe permitir iniciar y cerrar ese proceso hijo. Se comparan huellas de tablas y archivos originales antes/después para detectar cualquier modificación.

Resultado final de la auditoría: HTTP Neon de sólo lectura, 1 prueba y 100 aserciones aprobadas; HTTP TCP aislado con JWT, 1 prueba y 33 aserciones aprobadas; regresión focalizada de mensajes, política académica, metadatos originales, Capacitación, Prácticas y Ponentes, 30 pruebas y 514 aserciones aprobadas. Las ejecuciones finales se realizaron secuencialmente para evitar compartir simultáneamente la caché de fuentes de Dompdf. Pint, sintaxis PHP y git diff --check aprobados. Sin cambios funcionales nuevos, escrituras en Neon, cambios de autenticación/permisos, modificaciones de Angular, alteraciones de PDFs históricos, commit ni push.
