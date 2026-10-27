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
| Certificate | belongsTo tipoCertificado; belongsTo reconocimiento opcional; hasMany documentos |
| Recognition | hasMany certificados |
| CertificateDocument | belongsTo certificado; hasMany firmas y consultas |
| Signature | hasMany certificadosFirmas y actividadesFirmas |
| CertificateSignature | belongsTo documento y firma |
| CertificateInquiry | belongsTo documento |
| ActivitySignature | belongsTo firma |

Se declaran explícitamente tablas y PK en mayúsculas, sin timestamps automáticos,
según los campos solicitados. Se configuran fillable, hidden y casts de fechas,
fechas/horas, enteros y booleanos según corresponda.

No existen modelos ni migraciones de PERSONA, PERSONA_TRABAJADOR, PARTICIPACION,
PRACTICA o ACTIVIDAD en el repositorio. Sus FK se validan mediante consultas a
las tablas correspondientes, sin inventar modelos de otros módulos. Las relaciones
Eloquent con esas entidades quedan pendientes hasta que existan sus modelos reales.

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
  certificado y clave privada. Recibe `PASSPHRASE` y usa `Crypt::encryptString`
  para guardar `PASSPHRASE_CIFRADA`, verificando el límite de 255 caracteres.
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

Resultado de la suite completa: **16 pruebas aprobadas, 240 aserciones**
(14 pruebas del módulo y 2 preexistentes). Las 48 rutas nuevas están registradas.
Se ejecutaron Laravel Pint y verificación de whitespace con `git diff --check`.

## Límites de despliegue

1. La consulta de solo lectura al esquema configurado falló. No se puede afirmar
   que las tablas existan ni que sus nombres, nulabilidad y PK coincidan con la BD
   real. La implementación usa los nombres exactos del requerimiento y devuelve
   un error controlado si faltan tablas; debe contrastarse con el DDL institucional.
2. Se necesitan las tablas externas y sus columnas reales para validar los orígenes.
   Se acepta ID_PERSONA directo o ID_PERSONA_TRABAJADOR enlazado a ID_PERSONA.
   Un origen cuya titularidad no pueda comprobarse se rechaza.
3. Se necesitan políticas administrativas reales antes de habilitar acceso.
4. Se necesita una integración criptográfica que proporcione evidencia verificable
   para habilitar escrituras en CertificateSignature. No se simula éxito.
5. Las comprobaciones de duplicados usan transacciones y bloqueos de filas relacionadas.
   La garantía absoluta ante concurrencia requiere restricciones únicas en el esquema
   real (especialmente nombres y códigos globales). No se agregaron restricciones,
   índices ni migraciones por instrucción del usuario. No se verificó concurrencia
   contra MySQL/PostgreSQL con SQLite en memoria.
