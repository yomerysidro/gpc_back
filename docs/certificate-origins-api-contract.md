# Contrato de orígenes documentales para Angular

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
