# Cambio mínimo para remitir certificados a Gerencia

> Actualización posterior: autorizado el cambio directo; respaldo eliminado por
> solicitud expresa. La migración focalizada está preparada, pero la conexión
> equipo_dev carece de propiedad o pertenencia al rol propietario para ejecutar
> ALTER. No se aplicó SQL. Ver el estado vigente en
> [contrato de Etapa A](certificate-stage-a-api-contract.md).

## Revisión mínima para certificados por sesión — código implementado, SQL no aplicado

> Validación de la autorización condicionada (10 de noviembre de 2026):
> [preflight de PostgreSQL](certificate-stage-a-preflight.json). La estructura
> inspeccionada es compatible con este bloque mínimo, pero no se aplicó SQL:
> se generó un respaldo nativo completo con los clientes de DBeaver, pero falta
> comprobar su restauración en PostgreSQL nativo aislado (sin Docker).
> Ubicación y hash: [actualización de Etapa A](certificate-stage-a-api-contract.md).
> Se respetó la condición de detenerse sin modificar certificados_morales.

La confirmación ya soporta una alternativa que **solo modifica la tabla existente
certificado.certificado**. No necesita las columnas de documento, firma o decisiones
del diseño ampliado que se conserva más abajo como antecedente.

Bloqueos actuales confirmados por el inventario existente: no existe
`id_actividad_sesion` en certificado y `fecha_emision` es NOT NULL. La consulta
de asistencia permite conocer sesiones de una participación, pero no elegir la
que corresponde a un certificado concreto de forma permanente. La preparación
no puede inventar una fecha de emisión para satisfacer NOT NULL.

Impacto propuesto: una columna nullable de sesión, fecha de emisión nullable y
restricciones para identidad, duplicados y coherencia. Los registros anteriores
conservan todos sus valores y quedan con sesión NULL; no se infieren sesiones,
se rellenan fechas ni se convierten certificados históricos en certificados de sesión.
La FK reutiliza el UNIQUE participación/sesión ya existente en asistencia.
El CHECK de exactamente un origen raíz existente se mantiene sin cambios.

**SQL exacto para autorización posterior. No ejecutado; no es una migración.**

```sql
BEGIN;
SET LOCAL lock_timeout = '5s';
SET LOCAL statement_timeout = '60s';

ALTER TABLE certificado.certificado
    ADD COLUMN id_actividad_sesion BIGINT NULL,
    ALTER COLUMN fecha_emision DROP NOT NULL,
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
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    ADD CONSTRAINT certificado_sesion_sin_emision_check CHECK (
        fecha_emision IS NOT NULL OR (
            id_actividad_sesion IS NOT NULL
            AND estado IN ('Preparado', 'PendienteFirmas', 'Parcial', 'Firmado')
        )
    );

CREATE UNIQUE INDEX certificado_participacion_sesion_tipo_unique
    ON certificado.certificado
        (id_participacion, id_actividad_sesion, id_tipo_certificado)
    WHERE id_actividad_sesion IS NOT NULL;

COMMIT;
```

No aplicar este SQL sobre una estructura parcialmente extendida: cotejar primero
columnas y nombres de restricciones, verificar respaldo y obtener autorización
específica. No usar IF NOT EXISTS para ocultar una estructura incompatible.
El índice no elimina ni modifica duplicados anteriores globales; las identidades
por sesión se insertan únicamente después de habilitar esta relación.
El SQL aún requiere validación de ejecución en PostgreSQL de pruebas autorizado;
las pruebas de aplicación usan exclusivamente fixtures SQLite en memoria.

La API valida y bloquea participación, sesión, asistencia y actividad, comprobando
persona, misma actividad, Finalizada, Activo, PRESENTE y tipo configurado. El PDF
aprobado y su mensaje se conservan en los mismos bytes; código, ruta y hash usan
certificado_documento sin columnas nuevas. Los responsables se consultan por las
relaciones actuales; certificado_firma permanece sin registros pendientes ficticios.
El estado del trámite se conserva en certificado.estado. Actor/fecha de remisión
siguen en el log existente, no en una tabla de historial documental nueva.

Por instrucción posterior, la remisión administrativa admite que todavía no haya
firmantes configurados: devuelve `asignacion_firmantes_pendiente=true`, sin crear
filas de firma. No permite considerar el documento firmado, emitirlo o entregarlo.
Las asignaciones que sí existan deben seguir siendo válidas y no duplicadas.

---

## Antecedente: diseño ampliado anterior

> Implementación posterior: [Etapa A y contratos](certificate-stage-a-api-contract.md).
> El texto siguiente conserva la propuesta previa; por instrucción posterior no se preparan ni ejecutan migraciones.

Propuesta del 10 de noviembre de 2026. **Diseñada, no aplicada ni habilitada.**
Se reutiliza la auditoría existente; no se consultó nuevamente PostgreSQL.
El SQL adjunto está íntegramente comentado y fuera de las migraciones.

## Decisión de diseño

No crear tablas. Extender cuatro existentes: `certificado`,
`certificado_documento`, `certificado_firma` y `solicitud_decision`.
Separar dos entregas: A, preparación/remisión/revisión real sin motor de firma;
B, firma criptográfica y emisión/entrega. La falta del motor no impide A.
Las validaciones de identidad, permisos, integridad e idempotencia son parte
del funcionamiento correcto y se conservan en ambas entregas.

La frase «habilita todo» se toma como objetivo final, manteniendo las prohibiciones
expresas de ejecutar ALTER/migraciones, escribir datos o modificar Angular.
Este entregable permite autorizar un cambio concreto, sin activar éxitos falsos.

## Reutilización exacta

| Tabla | Función conservada o ampliada |
| --- | --- |
| certificado.certificado | Identidad, beneficiario y tipo existentes; agregar sesión nullable; permitir fecha de emisión NULL hasta emisión real |
| certificado.participacion | Beneficiario y actividad; no duplicar participaciones |
| certificado.actividad_sesion | Sesión real y actividad; validar relación bajo bloqueo |
| certificado.asistencia | Pareja participación/sesión ya única: destino de FK compuesta |
| certificado.certificado_documento | PDF privado, código, hash y fecha existentes; agregar mensaje, estado de trámite y modalidad |
| certificado.actividad_firma | Fuente actual de los responsables de la actividad y orden de presentación |
| certificado.firma | Credencial vinculada al trabajador; conservar cifrado/ocultación del PFX; no usar fecha del registro como firma PDF |
| certificado.certificado_firma | Congelar los responsables del documento y posteriormente registrar sus firmas verificadas |
| certificado.solicitud_decision | Historial de Gerencia ampliado a documento mediante FK explícita y origen exclusivo; conservar las solicitudes actuales |

No usar `id_practica`, `id_reconocimiento`, rutas, observaciones o JSON para codificar
la sesión. No crear una segunda bandeja, tabla de remisiones ni tabla de firmantes.

## A. Datos indispensables para preparación y remisión

**Certificado:** `id_actividad_sesion BIGINT NULL`, FK compuesta con
`id_participacion` hacia asistencia y UNIQUE parcial participación/sesión/tipo.
Conservar exactamente un origen raíz; la sesión precisa la participación.
`fecha_emision` pasa a nullable: preparación no es emisión. El estado del
certificado nuevo puede ser `Preparado` (cabe en VARCHAR(30), sin CHECK actual),
y solo se vuelve `Vigente` en emisión efectiva. No reclasificar registros históricos.
Agregar índices parciales también para actividad global, práctica y reconocimiento
para impedir duplicados de identidad frente a cualquier escritor. Una anulación
no libera esa identidad: la rectificación versiona el documento del mismo certificado.

**Documento:** agregar `mensaje_aprobado TEXT`, `estado_flujo VARCHAR(24)` y
`modalidad_firma VARCHAR(12)`, todos nullable para no atribuir etapas históricas.
No cambiar el CHECK existente `estado IN ('Vigente','Anulado')`: vigencia y trámite
son dimensiones distintas. Nuevos documentos administrados exigen los tres campos.
Conservar `ruta_archivo`, `hash_certificado`, `codigo_verificacion` y
`fecha_generacion` existentes; no duplicarlos para remitir.

**Responsables:** hacer nullable `certificado_firma.fecha_firma` y agregar
`estado_firma VARCHAR(16)`, `id_persona BIGINT`, `nombre_aprobado TEXT` y
`cargo_aprobado TEXT`. Los campos nuevos son nullable solo por compatibilidad
histórica; los responsables del circuito nuevo requieren todos esos datos.
UNIQUE documento/persona además del UNIQUE documento/firma existente. Esto evita
duplicar una autoridad con dos empleos/credenciales. Nombre y cargo son el snapshot
que coincide con el PDF aprobado, no un nuevo catálogo de personas o funcionarios.
Inicialmente estado `Pendiente`, fecha NULL. No rellenar firmas antiguas como
verificadas por defecto: NULL significa legado sin clasificación verificable.

El número de filas congeladas define los firmantes: uno, dos, tres, cuatro o más.
Debe existir al menos uno al confirmar. Se derivan de `actividad_firma`, trabajador
vigente y persona real, comprobando duplicados, cargo y ámbito. Una configuración
con responsables inválidos se informa para corregirla, sin omitirlos silenciosamente.
No seleccionar automáticamente dos ni añadir un administrador por su rol.
Cambiar la configuración de actividad después del envío no cambia los asignados.

`modalidad_firma` admite `Paralela` o `Secuencial` **sin valor por defecto**.
Debe ser elegida por quien tenga facultad institucional para definirla; la aprobación
del diseño no establece una política municipal. `orden_firma` existente sigue siendo
orden visual. En modalidad secuencial, órdenes iguales forman una etapa paralela;
solo pueden firmar las etapas cuyos órdenes menores estén completos. En paralela,
el orden no restringe al firmante. No agregar unicidad documento/orden.

**Historial:** ampliar `solicitud_decision` con `id_certificado_documento BIGINT NULL`
y `datos JSONB NOT NULL DEFAULT '{}'`. Hacer nullable su FK de solicitud y exigir
exactamente uno de los dos destinos. Conservar actor y fecha existentes. Los CHECK
actuales de tipos/estados se mantienen para decisiones de actividad; se añaden
CHECK específicos para eventos documentales. No guardar IDs documentales en la
FK de solicitud ni usar una solicitud de actividad ficticia.

Eventos documentales append-only con actor de sesión y fecha del servidor:
`PREPARAR`, `REMITIR`, `REVISION_CONFORME`, `OBSERVAR`, `ANULAR`; en B se añaden
`FIRMA_SOLICITADA`, `FIRMA_APLICADA`, `FIRMA_FALLIDA`, `EMITIR`, `ENTREGAR`.
`datos` contiene metadatos del evento: hash revisado, referencia al firmante y,
posteriormente, evidencia y hashes de revisiones. No contiene contraseñas o claves.
La preparación usa transición `SinRegistro → Preparado`. Revisar puede mantener
estado. El historial aporta quién/cuándo para cada operación, sin agregar seis
columnas de actor/fecha redundantes al documento. Listados GET no crean eventos;
si se requiere auditar revisiones, se usa POST explícito e idempotente.

La cuenta de aplicación debe carecer de UPDATE/DELETE sobre el historial y de
DELETE sobre evidencias. Los CASCADE antiguos no garantizan conservación ante SQL
directo: no otorgar borrado directo de certificados/documentos al rol operativo.
Confirmar el rol de despliegue antes de proponer GRANT/REVOKE; no asumir su nombre.

## Conservar exactamente el PDF aprobado

La vista previa actual no contiene código ni QR y no cambia de contrato.
No es posible agregar un QR después y afirmar que son los mismos bytes.
Por ello se añade una acción de **revisión del documento para firma**, sobre el
mismo controlador de preparación, antes de confirmar:

1. Resolver beneficiario/sesión/tipo, mensaje exacto y responsables reales.
2. Reservar código de 30 caracteres; generar una sola vez PDF con QR, sin imágenes
   de firma ni fecha de emisión ficticia. El QR señala «no registrado» hasta confirmar.
3. Conservar temporalmente PDF y manifiesto en disco privado, sin certificado en BD.
   Token aleatorio opaco de 256 bits, vinculado al actor y ámbito, caducidad 30 minutos.
   Manifiesto: contexto, texto exacto, responsables/cargos/órdenes, modalidad, código,
   hash del PDF, versión de plantilla y fecha de revisión. El cliente no elige rutas,
   hashes, actor, credenciales ni código. Limpieza de temporales solo al vencer y sin
   borrar archivos referenciados. Son artefactos temporales, no preparación administrativa.
4. El usuario revisa esos bytes. Confirmar acepta únicamente token y aceptación.
   Verificar token, propiedad, caducidad, hash, permisos, elegibilidad y que no cambió
   la configuración relevante. Si cambió, solicitar nueva revisión, nunca regenerar
   silenciosamente. El texto del manifiesto debe ser idéntico al confirmado.
5. Bloquear participación, sesión, asistencia y certificado; insertar/recuperar identidad
   con índices únicos. Copiar los mismos bytes a una ruta privada nueva con creación
   exclusiva; verificar hash. Registrar documento y responsables, y evento PREPARAR
   en una transacción. No inventar fecha de emisión; estado Preparado.
6. Reintento del mismo token recupera documento por código reservado incluso si el
   temporal ya se consumió; otro token para la misma identidad devuelve 409 con
   referencia al existente para usuario autorizado. No reemplazar documentos.
   Un conflicto de código con distinto hash/contexto no se acepta como reintento.

Archivo/BD no comparten transacción: escribir archivo antes del commit, no publicar
la ruta hasta confirmar BD; eliminar el archivo en rollback conocido, preservarlo
para conciliación si el resultado del commit es incierto. Mantener los guardas ya
existentes del generador. No enviar bytes de un PDF desconocido desde el frontend.

Para documentos ya persistidos se reutiliza el archivo si su contexto/mensaje y
responsables pueden probarse y el usuario revisa esos mismos bytes. No reconstruir
automáticamente un mensaje desde un PDF heredado ni adoptar una demostración como
documento oficial. Esa incorporación requiere revisión expresa, sin cambiar bytes.

## Remisión y estados

Estados propuestos de trámite, sujetos a aprobación de esta propuesta:

| Estado | Hecho que lo habilita |
| --- | --- |
| Preparado | PDF exacto confirmado, registrado y responsables congelados |
| PendienteFirmas | Remisión a Gerencia confirmada; ninguna firma verificada |
| Parcial | Al menos una firma verificada y faltan otras |
| Firmado | Todas las requeridas verificadas sobre la revisión actual |
| Emitido | Publicación administrativa autorizada con fecha de emisión real |
| Entregado | Entrega efectivamente acreditada, no solo descarga disponible |
| Observado | Observación administrativa; no habilita firma ni publicación |

Anulación conserva `estado=Anulado` y el último estado de trámite para el historial.
Un documento observado requiere rectificación y nueva revisión/versionado; no
sobrescribir su PDF ni cambiar responsables. Antes de firmas puede archivarse y
crearse nueva versión del mismo certificado. Con firmas, rectificación conserva
la revisión firmada y requiere nueva versión y nuevo código documental.

Al remitir: lock del documento, autorización de ámbito, vigencia, hash, existencia
de todos los responsables congelados y snapshot válido; transición Preparado →
PendienteFirmas más evento REMITIR en una sola transacción. Un segundo envío devuelve
200 con el mismo evento/fecha/actor, sin nuevo historial. No cambia emisión ni
notificación. No exige motor criptográfico. La tabla de firma no se marca Verificada.

En la implementación A se sustituye el 409 incondicional actual de
`SessionCertificatePreparation::sendToManagement()` por esta transacción y se
actualiza `CertificationReadinessController::respond()` para aceptar el envío
confirmado. No reutilizar el emisor de demostración ni sus restricciones de
entorno de desarrollo para el circuito administrativo: crear la operación oficial
dentro del servicio/controlador existente con permisos y persistencia reales.
Las banderas `puede_preparar`/`puede_enviar_gerencia` se calculan según los requisitos
de A, sin condicionarlas a que exista proveedor criptográfico. `puede_firmar`
depende de B. Así se eliminan los impedimentos artificiales sin perder controles.

## Contratos HTTP propuestos

Son extensiones futuras, no rutas operativas hoy. Reutilizar controladores y sobre
`{success,message,data}`. JWT HttpOnly, Gates y ámbitos existentes en todas las
operaciones administrativas. Ningún parámetro del cliente concede facultades.

| Método y ruta `/api` | Entrada y resultado |
| --- | --- |
| POST `/activity-session/{id}/preparar-certificados` | `accion=vista_previa, formato=pdf`: **sin cambios** |
| Mismo POST | Nueva `accion=revisar_documento`, `id_participacion`, tipo opcional, mensaje exacto y modalidad autorizada; 200 con token, expires_at, PDF base64, hash informativo, firmantes y `persistido=false` |
| Mismo POST | Nueva `accion=confirmar_preparacion`, `token_revision`, `confirmar=true`; 201 creación o 200 reintento con IDs, código, estado Preparado, firmantes e `emitido=false` |
| Mismo POST | Ampliar `accion=enviar_gerencia` con `id_certificado_documento, confirmar=true`; 200 con `enviado=true`, IDs, estado, actor/fecha reales de REMITIR y `reintento` |
| GET `/certificados-documentos/list?bandeja=por_firmar` | Extender listado existente con page/per_page, estado_flujo, id_actividad y `asignados_a_mi`; solo ámbito permitido |
| GET `/certificados-documentos/detail/{id}` | Extender detalle existente con contexto, mensaje, firmantes, progreso, modalidad, enlace relativo al archivo, historial paginable y facultades |
| GET `/certificados-documentos/{id}/archivo` | Reutilizar descarga; exigir autorización e integridad. En A entrega PDF preparado al revisor, no al público |
| POST `/certificados-documentos/{id}/revisiones` | Nueva acción del controlador existente: decisión `REVISION_CONFORME` o `OBSERVAR`, observación cuando aplique y clave de idempotencia; evento, sin firma |
| GET `/certificados-documentos/{id}/historial` | Nueva acción sobre historial existente, paginada; solo eventos del documento permitido |
| POST `/certificados-documentos/{id}/firmar` | Solo B; clave de idempotencia y hash de revisión esperada; identidad/credencial resueltas por servidor; 202 o resultado previo |
| POST `/certificados-documentos/{id}/emitir` | Solo B; todas las firmas verificadas; fecha de emisión real, estado Emitido y evento, sin correo implícito |
| POST `/certificados-documentos/{id}/entregar` | Solo B; acreditar destinatario/mecanismo real y evento idempotente; no enviar notificación sin servicio autorizado |
| GET `/certificados/verificar/{code}` | Reutilizar QR; mostrar estado real, versión/hash y evidencia verificada; archivo público solo al publicar conforme a autorización |

Las tres acciones nuevas usan validación por acción y rechazan campos ajenos.
El envío antiguo basado en mensaje/huella debe seguir devolviendo una respuesta
explicativa: no interpretarlo como documento confirmado. Tras actualizar el cliente,
la acción nueva por ID será el único envío efectivo; no duplicar rutas de preparación.

Listado: paginador dentro de data; cada elemento incluye IDs, beneficiario autorizado,
actividad/sesión/tipo, código, estado de vigencia/trámite, remitido_por/fecha,
firmas `{requeridas,verificadas,pendientes}` y facultades. Detalle agrega cada firmante
`{id_certificado_firma,id_persona,id_firma,nombre,cargo,orden,estado,fecha_firma}`,
mensaje aprobado y modalidad. Nunca expone ruta PFX, passphrase, clave privada ni
URLs públicas para revisar documentos internos.

`facultades` calculadas por servidor: `ver`, `descargar`, `remitir`, `revisar`,
`firmar`, `emitir`, `entregar`; cada una incluye motivo si no está disponible.
Reutilizar permisos de emisión y Gerencia para preparar/remitir/revisar según la
matriz autorizada; no equiparar `solicitudes.aprobar` automáticamente a firmar.
Para firma exigir permiso formal `firmas.firmar`, persona autenticada igual al
responsable, credencial propia y autorización documental. El administrador puede
administrar/revisar según sus permisos, pero no usar PFX ajeno.

401 sesión inválida; 403 sin facultad/ámbito/titularidad; 404 recurso no visible o
inexistente; 409 revisión cambiada/duplicidad/transición incompatible; 422 contenido
o distribución inválidos. Dependencia temporal caída: 503 y estado conservado.
Los reintentos válidos no son conflictos ni generan filas nuevas.

Ejemplo de datos del envío exitoso (los valores los determina el servidor):

```json
{
  "success": true,
  "message": "Documento remitido a Gerencia.",
  "data": {
    "id_certificado": 123,
    "id_certificado_documento": 456,
    "estado_flujo": "PendienteFirmas",
    "enviado": true,
    "emitido": false,
    "reintento": false,
    "remision": {"id_evento": 789, "id_usuario": 12, "fecha": "2026-11-10T15:00:00-05:00"},
    "firmas": {"requeridas": 3, "verificadas": 0, "pendientes": 3}
  }
}
```

Son valores ilustrativos del contrato, no certificados ni funcionarios existentes.
`fecha` se serializa con zona ISO 8601 desde el evento TIMESTAMPTZ.

Idempotencia de revisión/entrega/firma: UUID por intención, guardado en el historial
con UNIQUE documental; repetir exige mismo actor, tipo, hash y payload normalizado.
Una clave reutilizada con otro contenido devuelve 409. El servidor guarda el
resultado, no solo la clave. Las fechas/actores provienen de ese evento original.

## Gerencia: misma opción «Documentos por firmar»

Conectar esa opción al listado documental extendido. La fase A permite consultar,
revisar/observar, ver responsables y descargar el PDF exacto. No hace falta otro
menú ni una solicitud de actividad artificial. El estado PendienteFirmas prueba
remisión, no firma. La consulta no inserta filas ni vuelve a asignar responsables.

La fase B habilita firmar en el mismo detalle solo para el titular autorizado.
Los demás ven progreso. Completadas y verificadas todas las firmas, quien tenga
facultad emite/publica; entonces se habilita consulta/descarga del beneficiario.
Conservar certificados emitidos e historial en los módulos actuales.

## B. Motor criptográfico y modificaciones posteriores

La auditoría y composer.json/lock no muestran un motor de firma PDF integrado.
Los servicios actuales generan PDF/QR y OpenSSL comprueba PFX; ninguno aplica ni
valida firmas PDF sucesivas. No se instaló ni se seleccionó un proveedor concreto.

Integración a autorizar: adaptador servidor para proveedor con firma PDF incremental,
verificación independiente de todas las firmas, identidad del certificado, cadena
de confianza y política de revocación; compatibilidad PHP 8.3 mediante API/SDK o
servicio aislado. La elección exacta depende del proveedor y licencia autorizados.
Antes de instalar: presentar paquete/versión/licencia/costo, compatibilidad, manejo
de claves y pruebas de dos y cuatro firmas sucesivas. No sustituir el generador.

Firma gráfica es imagen; no se propone. Firma digital PDF firma bytes/revisiones;
QR referencia el registro; escudo identifica la institución. Sello de tiempo
requiere TSA y token verificable: una fecha del servidor no lo sustituye. TSA es
una integración adicional si la política autorizada la exige; no requisito para A.

Cambios de B sobre las mismas tablas: documento conserva ruta/hash originales
aprobados y ruta/hash actuales existentes pasan a señalar la última revisión
verificada. Agregar reserva de operación y hash esperado para controlar concurrencia;
historial incorpora UUID de idempotencia. Cada resultado guarda, en `datos`, hashes,
rutas privadas de entrada/salida, proveedor, identificador de operación, evidencia
sanitizada, identidad del titular y fecha verificable. Guardar todas las revisiones
privadas sin sobrescribir. Acceder al historial no debe revelar rutas a usuarios.

Aunque el trámite autorice firmas paralelas, escribir un PDF requiere serializar
las revisiones: una operación criptográfica activa por documento. No es un orden
jerárquico de autoridades. Reservar con lock y commit antes de llamar al proveedor;
usar UUID como idempotencia remota. Timeout incierto conserva reserva y requiere
conciliar por ese UUID, no firmar otra vez ciegamente. Al recibir resultado:
verificar firma nueva y anteriores, identidad, bytes y hash de entrada; escribir
nueva revisión exclusiva; bloquear documento y aplicar compare-and-swap del hash
esperado. Registrar firma Verificada y fecha real, actualizar ruta/hash actuales,
evento y estado en una transacción. Publicación de resultado fallida conserva
la revisión para conciliación. Jamás volver a renderizar con Dompdf después de firmar.

No basta contar filas: estado Firmado exige todas las asignaciones requeridas
Verificadas y validación de todas las firmas en la última revisión. El QR mantiene
el mismo código documental mientras se agregan firmas; el hash público es el
de los bytes finales, y debe distinguirse del hash del preparado. Emisión cambia
fecha administrativa en BD, no agrega una fecha al PDF ya firmado. Si el diseño
requiere fecha textual de emisión, debe resolverse y aprobarse antes de firmar;
no insertar una fecha ficticia en preparación.

## Autorización y orden de implementación

1. Aprobar el SQL A, los estados propuestos y la matriz institucional/modalidad.
2. Implementar adaptaciones de modelos/validadores/Gates y aislamiento de historiales:
   consultas antiguas siempre filtran `id_solicitud_actividad`; nunca mezclan eventos
   documentales ni exponen permisos de solicitudes sobre certificados.
3. Probar SQL A en base exclusiva autorizada; conservar legado con campos nuevos NULL,
   verificar duplicados antes de índices, no deducir sesiones ni corregir datos solos.
4. Aplicar A solo con autorización específica. Implementar revisión del archivo final,
   confirmación, responsables pendientes, remisión idempotente y consultas de Gerencia.
5. Probar 1/2/3/4/más firmantes, mensaje/bytes idénticos, concurrencia/reintentos,
   401/403/409/422, historia sin alteración y ausencia de emisión/notificación al remitir.
6. Aprobar proveedor/dependencias y SQL B; implementar firma incremental y conciliación.
7. Probar firma parcial/completa, orden/paralelismo configurado, rechazo de credenciales
   ajenas, proveedor caído, firma ya aplicada, preservación y hash final.
8. Habilitar publicación/entrega autorizadas y consulta QR sin crear nuevos certificados.

Requiere autorización de esquema: SQL A y, posteriormente, SQL B. Requiere
autorización de dependencias/servicios: proveedor, SDK/licencia y TSA si corresponde.
Requiere definición institucional: facultades, modalidad y publicación/entrega.
No es necesario autorizar una tabla nueva ni rehacer PDF/QR. El diseño no exige
que esas autorizaciones lleguen juntas: A puede entrar en servicio antes de B.

Sin migraciones pueden desarrollarse los controladores/servicios/contratos y pruebas
con fixtures aisladas, reutilizar la vista previa/descarga actual y preparar el
adaptador de Gerencia. **No puede persistirse correctamente el nuevo flujo con el
esquema actual.** Esta propuesta no elimina controles ni marca éxitos para ocultarlo.
No se habilitó código de producción en este turno: el entregable pedido es el diseño.

## Verificación de este entregable

SQL basado en columnas/restricciones del inventario existente; no es una nueva
auditoría ni una migración ejecutada. No se ejecutó SQL, instalación, test con escritura
institucional, cambios de Angular, commit ni push. La validación sintáctica/funcional
de PostgreSQL queda para la base exclusiva autorizada. No se atribuyen las 165 pruebas
del turno anterior a este diseño nuevo.

SQL: [propuesta completa A/B, comentada](certificate-management-minimal-proposal.sql).
