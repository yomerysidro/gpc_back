# Certificaciones y firmas

Se implementaron ocho entidades con el patrón Controller → Data → consultas DB,
respuestas del trait `ApiResponse` y modelos Eloquent. No se crearon ni ejecutaron
migraciones. No se modificó el módulo User.

## Archivos

Para cada entidad de esta lista existen exactamente estos tres archivos:

```text
app/
├── Http/Controllers/
│   ├── CertificateType/CertificateTypeController.php
│   ├── Certificate/CertificateController.php
│   ├── Recognition/RecognitionController.php
│   ├── CertificateDocument/CertificateDocumentController.php
│   ├── Signature/SignatureController.php
│   ├── CertificateSignature/CertificateSignatureController.php
│   ├── CertificateInquiry/CertificateInquiryController.php
│   └── ActivitySignature/ActivitySignatureController.php
├── Data/
│   ├── CertificateType/CertificateTypeData.php
│   ├── Certificate/CertificateData.php
│   ├── Recognition/RecognitionData.php
│   ├── CertificateDocument/CertificateDocumentData.php
│   ├── Signature/SignatureData.php
│   ├── CertificateSignature/CertificateSignatureData.php
│   ├── CertificateInquiry/CertificateInquiryData.php
│   └── ActivitySignature/ActivitySignatureData.php
└── Models/
    ├── CertificateType/CertificateType.php
    ├── Certificate/Certificate.php
    ├── Recognition/Recognition.php
    ├── CertificateDocument/CertificateDocument.php
    ├── Signature/Signature.php
    ├── CertificateSignature/CertificateSignature.php
    ├── CertificateInquiry/CertificateInquiry.php
    └── ActivitySignature/ActivitySignature.php

routes/api.php                              modificado
tests/Feature/CertificationModuleTest.php    creado
docs/certification-signatures.md               creado
composer.json / composer.lock               modificados por instalación de Boost
CLAUDE.md / boost.json                      generados por boost:install
```

Las clases se llaman `<Entidad>Controller`, `<Entidad>Data` y `<Entidad>`.
Los namespaces son `App\Http\Controllers\<Entidad>`, `App\Data\<Entidad>`
y `App\Models\<Entidad>`. User conserva su ubicación original `App\Http\Data\User`.

## API

| Entidad | Prefijo después de `/api/` | Parámetro de ID |
|---|---|---|
| CertificateType | tipos-certificado | idTipoCertificado |
| Certificate | certificados | idCertificado |
| Recognition | reconocimientos | idReconocimiento |
| CertificateDocument | certificados-documentos | idCertificadoDocumento |
| Signature | firmas | idFirma |
| CertificateSignature | certificados-firmas | idCertificadoFirma |
| CertificateInquiry | consultas-certificado | idConsultaCertificado |
| ActivitySignature | actividades-firmas | idActividadFirma |

Cada prefijo tiene seis rutas y cinco métodos de controlador:

| HTTP | Sufijo | Método |
|---|---|---|
| GET | /list | list() |
| GET | /detail/{id} | detail($id) |
| POST | /create | store(Request $request) |
| PUT | /update/{id} | update(Request $request, $id) |
| PATCH | /update/{id} | update(Request $request, $id) |
| DELETE | /delete/{id} | destroy($id) |

`list` admite `page`, `per_page` (1–100, predeterminado 20) y filtros exactos
por campos permitidos de cada entidad. Credenciales y rutas privadas no son filtros.
Devuelve el paginador dentro de `data`, conservando el sobre
`{success, message, data}`. No existe endpoint público de consulta o descarga.

POST y PUT exigen los campos obligatorios; PATCH valida los campos presentes
y comprueba las reglas de negocio contra el registro completo. Los opcionales
aceptan null. Solo se escriben campos autorizados. Las escrituras usan transacciones.

Estados HTTP: 201 al crear, 200 en otras operaciones exitosas, 401 sin sesión,
403 sin permiso, 404 inexistente, 422 validación, 409 duplicado o evidencia
protegida, 503 tabla/dependencia ausente y 500 error interno con mensaje genérico.

## Autorización pendiente

Las 48 rutas usan `auth:sanctum`. Además se comprueban políticas nativas de Laravel:
`viewAny` y `create` sobre la clase del modelo; `view`, `update` y `delete` sobre
el registro. Las operaciones individuales también requieren `viewAny` para
evitar revelar la existencia de registros a personas sin acceso administrativo.

El repositorio no define políticas, roles o permisos administrativos. Por ello,
**autenticarse no habilita este módulo: se obtiene 403 hasta integrar las políticas
con las reglas reales de la institución**. No se agregaron usuarios privilegiados,
roles inventados ni un sistema paralelo de permisos. Las políticas deben evaluar
los atributos institucionales del registro cuando corresponda. Las respuestas
de Signature omiten tanto la contraseña cifrada como la ruta PFX.

## Relaciones

| Modelo | Relaciones Eloquent |
|---|---|
| CertificateType | hasMany certificados |
| Certificate | belongsTo tipoCertificado, reconocimiento opcional y participation opcional; hasMany documentos |
| Recognition | hasMany certificados |
| CertificateDocument | belongsTo certificado; hasMany firmas y consultas |
| Signature | hasMany certificadosFirmas y actividadesFirmas |
| CertificateSignature | belongsTo documento y firma |
| CertificateInquiry | belongsTo documento |
| ActivitySignature | belongsTo firma y activity |

Se declaran explícitamente tablas y columnas en minúsculas, con tablas del módulo
calificadas por el esquema `certificado`, sin timestamps automáticos,
según los campos solicitados. Se configuran fillable, hidden y casts de fechas,
fechas/horas, enteros y booleanos según corresponda.

Existen los modelos Activity y Participation después del merge. Sus relaciones
internas se completaron utilizando esas clases reales. No existen modelos de
`gestion.persona`, `gestion.persona_trabajador`, `gestion.persona_practicante`,
`gestion.practica`, `gestion.area` o `gestion.persona_ponente` en esta rama.
Sus referencias se comprueban con consultas calificadas, sin duplicar modelos.

## Validaciones y conservación

- CertificateType: longitudes, nombre sin duplicar y protección frente a eliminación
  con certificados o modificación del nombre de un tipo utilizado.
- Certificate: FK existentes, máximo un origen, origen perteneciente a la misma
  persona, combinación persona/tipo/origen sin duplicar, coherencia de notificación
  y fecha posterior o igual a emisión. Los datos de identidad/origen/emisión no
  se alteran si existen documentos. El estado y la notificación pueden actualizarse.
- Recognition: trabajador existente, campos requeridos, fechas y longitudes;
  no se modifica ni elimina si está vinculado a certificados.
- CertificateDocument: código único, archivo privado existente con cabecera PDF,
  hash SHA-256 calculado leyendo el archivo, contenido sin duplicar para el mismo
  certificado y motivo obligatorio para reemisiones. El cliente no puede enviar
  el hash. Solo admite GENERADO y ANULADO; este CRUD no certifica firmas.
  Las modificaciones se bloquean cuando existen firmas o consultas asociadas.
- Signature: trabajador existente, archivo real debajo de `firmas/` en el disco local
  privado; comprobación de contenedor PKCS#12, contraseña y correspondencia entre
  certificado y clave privada. Recibe `passphrase` y usa `Crypt::encryptString`
  para guardar `passphrase_cifrada`, verificando el límite de 255 caracteres.
  Nunca recibe ciphertext elegido por el cliente. En PATCH puede omitirse la
  contraseña para conservarla. No modifica ni elimina referencias utilizadas.
- CertificateSignature: FK y orden positivo, reglas de unicidad documento/firma y
  documento/orden. Crear o modificar devuelve 409 mientras no exista integración
  de firma verificable: aceptar un JSON manual no prueba una firma criptográfica.
- CertificateInquiry: documento existente, DNI de ocho dígitos, longitudes y
  fecha/hora. Registro exclusivamente administrativo. Las consultas históricas
  son inmutables y su modificación/eliminación devuelve 409.
- ActivitySignature: actividad y firma existentes, orden positivo, pares actividad/firma
  y actividad/orden únicos; bloqueo de cambios/borrado si la firma tiene aplicaciones
  históricas. No se exige que la secuencia de órdenes sea consecutiva.

Los documentos, las firmas aplicadas y las consultas históricas no se borran.
Sus DELETE devuelven 409. Los demás DELETE comprueban dependencias antes de borrar.
No se eliminan archivos físicos, incluso al eliminar una referencia no utilizada.

## Archivos privados

El servidor debe disponer previamente de archivos en el disco `local` privado:
`certificados/<archivo>.pdf` o `firmas/<archivo>.pfx`. No se implementaron carga,
generación de PDF, descargas, firmas criptográficas ni validación pública.
Las rutas públicas, traversals y archivos que resuelvan fuera de esos directorios
se rechazan. El hash representa los bytes del archivo en el momento del registro;
el almacenamiento institucional debe impedir su sustitución posterior.

## Verificación

Se ejecutan pruebas con SQLite exclusivamente en memoria, fixtures de esquema
creadas por los tests y disco privado falso. Ninguna prueba migra o escribe en
la base institucional. En este entorno PDO SQLite está instalado, pero requiere
habilitarlo solo para el proceso de pruebas:

```powershell
php -d extension=pdo_sqlite vendor/bin/phpunit tests/Feature/CertificationModuleTest.php
php artisan route:list --path=api --except-vendor
```

La suite cubre las 48 rutas, autenticación y autorización denegada, CRUD permitido,
PUT/PATCH, paginación, FK, orígenes, duplicados, 404/422/409, confidencialidad,
historial, hash sobre bytes y un PFX real generado únicamente como fixture de pruebas.
También comprueba el contrato CRUD original de User en memoria.

Resultado de la suite completa posterior a la corrección del merge:
**85 pruebas aprobadas, 1822 aserciones**. Incluye los dos bloques del módulo,
90 rutas administrativas y regresiones de User. Las pruebas de autorización usan
políticas limitadas a modelos y usuarios de prueba, sin Gate global permisivo.
Se ejecutaron Laravel Pint y verificación de whitespace con `git diff --check`.

## Límites de despliegue

1. No se consultó la conexión remota en esta corrección: no está confirmada como
   desarrollo o pruebas. No se puede afirmar
   que las tablas existan ni que sus nombres, nulabilidad y PK coincidan con la BD
   real. La implementación usa los nombres exactos del requerimiento y devuelve
   un error controlado si faltan tablas; debe contrastarse con el DDL institucional.
2. Las consultas siguen el contrato confirmado: participación mediante
   `certificado.participacion.id_persona`; reconocimiento mediante
   `gestion.persona_trabajador.id_persona`; práctica mediante
   `gestion.practica.id_persona_practicante` y `gestion.persona_practicante.id_persona`.
   Se conserva la regla de cero o un origen. Debe comprobarse el DDL físico.
3. Se necesitan políticas administrativas reales antes de habilitar acceso.
4. Se necesita una integración criptográfica que proporcione evidencia verificable
   para habilitar escrituras en CertificateSignature. No se simula éxito.
5. Las comprobaciones de duplicados usan transacciones y bloqueos de filas relacionadas.
   La garantía absoluta ante concurrencia requiere restricciones únicas en el esquema
   real (especialmente nombres y códigos globales). No se agregaron restricciones,
   índices ni migraciones por instrucción del usuario. No se verificó concurrencia
   contra MySQL/PostgreSQL con SQLite en memoria.

## Integración posterior al merge

Las 15 entidades consultan `certificado.*` y sus dependencias `gestion.*`.
No se cambió el search_path. Las validaciones de actividades seleccionan la misma
conexión predeterminada que sus Data, sin forzar PostgreSQL en las pruebas SQLite.

Las 90 rutas requieren Sanctum y autorización por operación. Actividades utiliza
AuthorizeModuleOperation con las capacidades nativas viewAny, view, create,
update y delete. Sin políticas institucionales devuelve 403. Se agregó HasApiTokens
al modelo autenticable existente; una prueba con Bearer confirma que autenticar
no equivale a autorizar. La correspondencia del usuario, roles y permisos con
el esquema `acceso` continúa pendiente de integrar.

Las seis rutas administrativas de User también requieren Sanctum y autorización
por operación sobre el modelo autenticable actual. UserSecurityTest comprueba
401 sin sesión, 403 sin políticas antes de consultar datos y lectura sin permisos
de escritura. En total hay 96 rutas administrativas protegidas y `/api/user`.

Se inspeccionaron, sin fusionarlas ni ejecutarlas, las referencias Git disponibles
de `origin/dev-juan`. Su migración de acceso declara `acceso.usuario` con
`id_usuario`, `id_persona`, `correo` y `password_hash`, sin timestamps. Su modelo
institucional, sus roles/permisos y su autenticación JWT en cookie no están
incorporados en esta rama, que conserva `users` y Sanctum. Por tanto, cambiar solo
el esquema de la tabla dejaría columnas y contratos incompatibles. Se necesita
decidir si se integra la autenticación JWT existente o se adapta el contrato
institucional a Sanctum. No se inventó una tabla `acceso.users`, permisos ni una
ubicación para tokens. La migración técnica de Sanctum disponible sigue declarando
`personal_access_tokens` sin esquema; su existencia física no se ha verificado.

Las participaciones certificadas conservan beneficiario y datos de origen; las
participaciones con asistencias no pueden reasignarse. Las sesiones con asistencia
no pueden trasladarse de actividad. Los cambios del periodo deben incluir todas
las sesiones existentes. Actividades y sesiones que sustentan certificados
conservan sus datos históricos. No se agregan, modifican ni borran asistencias
de participaciones ya certificadas. Los borrados de actividad también comprueban
ponentes y firmas asignadas. Los 15 UPDATE filtran columnas autorizadas y valores
sin cambios; hash y ciphertext siguen bajo control exclusivo del backend.

La regla posteriormente confirmada es una asistencia por participación y sesión.
AttendanceData rechaza duplicados en creación y actualización, excluye el propio
registro en PUT/PATCH y conserva el bloqueo de la participación durante la
transacción. No se agregó ni ejecutó una restricción en la base institucional.

ModuleIntegrationTest ejecuta ambos bloques con esquemas adjuntos SQLite en
memoria, prueba CRUD de actividades y su relación con certificados, protección
de rutas, permisos por operación, históricos y columnas reales de UPDATE.
SQLite no acredita restricciones PostgreSQL, aislamiento ni concurrencia real.

## Validación PostgreSQL pendiente después de integrar Gestión

La configuración habitual apunta a PostgreSQL remoto. APP_ENV=local no acredita
que la base sea exclusiva de desarrollo o pruebas. No se conectó a ese servidor,
no se consultaron registros o metadatos y no se ejecutaron migraciones.
Las migraciones locales son una referencia declarada; no prueban el DDL aplicado.
No se ha confirmado que sus decisiones funcionales sean el contrato definitivo.

| Diferencia | Contrato Laravel actual | Migración local | Pendiente |
|---|---|---|---|
| tipo_certificado.estado | String | BOOLEAN | Confirmar representación de API |
| certificado.estado | Boolean | VARCHAR(30), sin catálogo de estados | Definir estados y conversión |
| Origen de certificado | Cero o uno | CHECK exactamente uno | Confirmar si se permiten borradores sin origen |
| certificado.fecha_emision | Nullable | DATE NOT NULL | Confirmar momento de creación/emisión |
| certificado_documento.estado | GENERADO/ANULADO | CHECK Vigente/Anulado | Acordar vocabulario y compatibilidad de clientes |
| actividad.estado | String libre de hasta 20 caracteres | CHECK Activa/Cerrada | Confirmar catálogo definitivo |
| Asistencia | Una por participación/sesión | UNIQUE compuesto | Código alineado; comprobar restricción aplicada |
| Firma | Unicidad trabajador/PFX | UNIQUE por trabajador | Confirmar renovación o múltiples credenciales |
| Hash documental | Se comprueba por certificado | UNIQUE global | Confirmar alcance de unicidad |
| Fechas de documentos y firmas aplicadas | API/casts de fecha sin hora | TIMESTAMP | Definir precisión y serialización; DATE puede guardarse a medianoche |
| firma.fecha_firma | Fecha obligatoria | DATE nullable | No confundir con certificado_firma.fecha_firma, que es TIMESTAMP |
| practica.fecha_fin | Nullable | DATE NOT NULL | Confirmar prácticas en curso |
| Institución | País obligatorio; tipo máximo 30 | País nullable; tipo máximo 50 | Confirmar contrato de entrada |

No se modificaron estos contratos funcionales ni el DDL para hacer pasar pruebas.
Hay además restricciones de Laravel más estrictas que la migración, como
descripción/motivo de reconocimiento y determinados campos de consulta.
Los CASCADE internos del DDL local tampoco equivalen a conservación histórica:
los guardas Data protegen la API, pero no operaciones SQL realizadas por fuera.

### Pruebas disponibles y barreras de seguridad

- La suite ordinaria usa SQLite en memoria y excluye el grupo `postgresql`.
- `PostgresModuleSafetyTest` comprueba sin conectarse que se rechacen URLs de la
  base institucional, falta de confirmación y parámetros que cambien el destino.
- `PostgresModuleContractTest` está preparado, pero no ejecutado contra PostgreSQL.
  Solo se selecciona con `phpunit.postgresql.xml`, exige MODULES_PG_TEST_URL
  apuntando exactamente a certificados_morales_test y MODULES_PG_TEST_EXCLUSIVE=1.
  No hereda host, usuario ni contraseña de la conexión habitual.
- Incluso con esas opciones rechaza bases con tablas o esquemas funcionales
  preexistentes. En una ejecución futura autorizada crearía únicamente gestion
  y certificado mediante las migraciones locales dentro de una transacción,
  con rollback al terminar; no ejecuta down, migrate:fresh, db:wipe ni acceso.
- La suite preparada cubre las 25 tablas/PK, siete FK entre esquemas, restricciones,
  CRUD representativos, históricos y asistencia. No acredita todavía los 25 CRUD
  completos ni concurrencia, archivos PFX o firma digital en PostgreSQL.

Por la instrucción vigente de no cambiar la BD no se ejecutará esta suite con
una conexión PostgreSQL configurada. Requiere autorización posterior, una base
vacía exclusiva y confirmación del contrato funcional antes de utilizarla.

### Integración posterior con Seguridad y Acceso

La lógica Data de Gestión y Certificaciones no consulta User, roles ni JWT.
Se mantiene Sanctum en las 140 rutas de los dos módulos y Gates por operación.
Sin una política autorizante las operaciones quedan denegadas.
Al integrar el módulo institucional habrá que adaptar auth:sanctum en rutas,
guard/middleware de cookies JWT, usuario autenticable acceso.usuario, resolución
de Gates/Policies y permisos viewAny/view/create/update/delete. Las políticas de
fixtures usan User provisional solo en pruebas; no son permisos institucionales.
No se deben reemplazar los guardas de titularidad, históricos ni validaciones Data.

## Estabilización de Gestión antes de integrar Seguridad

Las diez clases Data de Gestión filtran ahora cada UPDATE por columnas autorizadas
y valores realmente distintos. Los PUT conservan sus validaciones obligatorias:
omitir un campo opcional conserva su valor, y enviar NULL explícito lo borra si
la validación actual lo permite. Una petición sin cambios no emite UPDATE.
La PK y columnas no registradas no se escriben desde la capa Data.
Las actualizaciones de institución y supervisión también usan transacción y
bloqueo de fila; las demás mantienen las transacciones previas.

Se cerraron además estos caminos de modificación histórica:

- Un trabajador con reconocimientos conserva su persona original incluso si
  todavía no se ha creado un certificado. Se mantiene el bloqueo por firmas.
- Una supervisión vinculada a una práctica certificada no puede trasladarse ni
  cambiar sus datos de evidencia. Se comprueban práctica original y destino,
  bloqueando las filas relacionadas durante la transacción.
- Un documento de una persona con certificados no puede cambiar de titular,
  tipo o número. Se permiten solicitudes sin cambios de identidad.

GestionCertificationProtectionTest ejecuta el ciclo crear/listar/detallar/
actualizar/eliminar de las diez entidades, comprueba las columnas de SQL reales,
los UPDATE omitidos cuando no hay cambios, campos opcionales, FK, permisos y
las nuevas restricciones históricas. Son fixtures SQLite, no registros reales.

No se han resuelto mediante suposiciones las discrepancias funcionales de la
tabla anterior. No se ha validado físicamente PostgreSQL ni su concurrencia.
Los cambios del merge 835eb43 y el trabajo local anterior se conservan sin
commits, merges nuevos, modificación de acceso ni adaptación a JWT.
