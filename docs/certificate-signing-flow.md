# Flujo de certificados y firmas: resultado del 10 de noviembre de 2026

> Actualización posterior: [Etapa A implementada y contratos HTTP](certificate-stage-a-api-contract.md).
> El informe de auditoría siguiente conserva el estado anterior; el código nuevo
> requiere columnas ausentes; no se preparan ni ejecutan migraciones.

> Propuesta posterior solicitada: [cambio mínimo sin tablas nuevas](certificate-management-minimal-design.md)
> y [SQL A/B íntegramente comentado](certificate-management-minimal-proposal.sql).
> Es un diseño para autorización e implementación; no cambia el estado operativo descrito aquí.

El circuito completo no está habilitado. Esta auditoría confirma impedimentos de
persistencia y ausencia de un motor de firma PDF. No se aplicaron migraciones,
escrituras institucionales, notificaciones, cambios de Angular, commit ni push.

## Evidencia y componentes reutilizados

[Inventario real](certificate-signing-flow-audit.json): consulta de las diez tablas
solicitadas y las dos tablas de solicitudes/decisiones de Gerencia en
`certificados_morales`, transacción `READ ONLY`, finalizada con rollback. El
inventario contiene columnas, restricciones e índices, sin PFX ni datos personales.
Se revisaron modelos, Data, servicios, rutas, JWT, permisos y contrato de sesiones.
Boost/search-docs no estuvo disponible; se inspeccionaron las fuentes instaladas.
Versiones confirmadas con Composer: Laravel 13.30.1, Dompdf 3.1.6,
BaconQrCode 3.1.1, JWT 7.2.1 y PHPUnit 12.5.34.

Se conservan `CertificateDocumentGenerator`, `SessionCertificatePreparation`,
la plantilla existente, QR, código aleatorio único, disco privado, comprobación
SHA-256, descarga autorizada, JWT en cookie y Gates/ámbitos. Gerencia usa
`solicitud_actividad` y `solicitud_decision`: sus FK son solicitudes de actividad,
no documentos de certificados. Reutilizarlas con un ID documental sería incorrecto.

## Persistencia y asignaciones

`certificado_documento` guarda PDF, código único, hash único y fecha de generación.
Su CHECK solo acepta `Vigente`/`Anulado`; no modela preparación ni remisión.
`certificado.fecha_emision` es DATE NOT NULL. No hay mensaje aprobado, actor y fecha
de preparación/envío, decisión documental ni conjunto congelado de responsables.
Guardar un PDF permite conservar sus bytes, pero no resuelve estas carencias.
Por tanto no se guardan documentos como pendientes ni se marca una vista previa
como preparación persistente.

`actividad_firma` relaciona actividad con múltiples credenciales y un orden
positivo. `firma` vincula una credencial a un trabajador real, que aporta persona
y cargo. `certificado_firma` relaciona documento con múltiples firmas, con UNIQUE
documento/firma y `fecha_firma` TIMESTAMP NOT NULL. Representa aplicaciones de
firma, no asignaciones pendientes: no se insertan fechas ficticias.

Las relaciones admiten 1, 2, 3, 4 y más firmantes, sin límite fijo declarado.
La capacidad visual depende del contenido y se valida renderizando. Los bloques
existentes usan una columna centrada o dos columnas con filas adicionales. Se
rechaza ahora con 409 una persona configurada mediante varios trabajadores,
evitando duplicar al mismo responsable. Se consultan trabajadores vigentes y
cargos reales; roles o asignación visual no acreditan facultad criptográfica.
`orden_firma` no demuestra una política obligatoria de ejecución. No existe
configuración persistente de firma paralela/secuencial ni se inventó una.

## PDF y contenido aprobado

Se conserva A4 horizontal, 297 × 210 mm, DejaVu Sans y el escudo ya incorporado
en `resources/images/escudo-morales.png`. No se sustituyó el escudo ni se acreditó
su autenticidad con una fuente institucional nueva. No se afirma un tamaño legal
único ni una aprobación municipal de tipografía.

La vista previa conserva exactamente el mensaje recibido, escapado, sin recorte.
El generador persistente ahora también devuelve 422 si el render produce más de
una página, antes de escribir archivo o registro. Las vistas previas ya tenían
esa protección. No hay imágenes de firmas ni sellos simulados.

## Firma, archivo definitivo, entrega y QR

No existe servicio de firma criptográfica PDF, validación de firmas, TSA confiable
ni proveedor configurado. OpenSSL valida el contenedor PFX y la correspondencia
de clave/certificado en su gestión; no firma el PDF. El registro manual de firmas
aplicadas permanece bloqueado con 409. No se utilizan claves de otras personas.

No existe actualización firmada incremental implementada. El archivo registrado
se recupera y valida contra su hash; los reintentos no lo regeneran. Para habilitar
firmas sucesivas hará falta un proveedor que preserve revisiones, valide las firmas
anteriores y la nueva, conserve trazabilidad y publique el nuevo hash solo tras
verificar los bytes finales. El hash actual identifica el PDF almacenado sin
acreditar firma. Ningún cambio de hash debe presentarse como firma verificada.

El QR existente apunta al código persistido y único del documento; su consulta
comprueba registro, estados e integridad. La respuesta declara
`firma_verificada=false` y `emision_oficial_verificada=false`; el nombre público
depende de la configuración. No se crea otro certificado por consulta o firma.
No hay entrega oficial tras firmas completas, publicación administrativa ni
notificación automática implementadas. No se marca `notificado` sin envío real.

## Contratos actuales para Angular y Gerencia

Todas las rutas siguientes llevan `/api`. Las administrativas requieren JWT,
permisos efectivos y ámbito; autenticarse no concede facultad para firmar.
Las listas CRUD usan `success/message/data` y paginación. No son bandejas
de expedientes firmados ni evidencia de verificación criptográfica.

| Operación | Contrato real |
| --- | --- |
| Beneficiarios | GET `/activity-session/{id}/beneficiarios?id_tipo_certificado={tipo}` |
| Vista previa | POST `/activity-session/{id}/preparar-certificados`, `accion=vista_previa`, `formato=pdf`, `id_participacion`, opcionales `id_tipo_certificado` y `mensaje`; 200 con PDF base64 no persistido |
| Firmantes configurados | `data.firmantes_configurados` de la vista previa: IDs de persona/trabajador/firma, nombre, cargo, orden, roles y banderas de verificación falsas |
| Configuración por actividad | GET `/actividades-firmas/list?id_actividad={id}`; GET `/firmas/list` para credenciales disponibles sin secretos; no asignación documental |
| Confirmar preparación oficial | No existe operación persistente habilitada; no usar CRUD para eludir el bloqueo |
| Enviar a Gerencia | Mismo POST de preparación con `accion=enviar_gerencia`, `confirmar=true`, `id_participacion`, `mensaje`, `huella_composicion` y tipo opcional; 409 sin escritura |
| Pendientes | GET `/certificaciones/pendientes`: evaluación de certificación; no bandeja de firmas documentales |
| Revisar archivo registrado | GET `/certificados-documentos/{id}/archivo`; verifica autorización, estado e integridad |
| Consultar aplicaciones registradas | GET `/certificados-firmas/list?id_certificado_documento={id}`; no confirma verificación criptográfica |
| Firmar | No existe endpoint criptográfico; POST `/certificados-firmas/create` rechaza registro manual con 409 |
| Progreso parcial/completo | No disponible: faltan asignaciones congeladas y evidencia verificable |
| Recuperar certificado registrado | GET `/certificados/list`, GET `/certificados/detail/{id}`, descarga documental anterior; no garantizan emisión final |
| Publicar/entregar | Sin operación habilitada; no simularla cambiando el estado por CRUD |
| Verificar QR | GET `/certificados/verificar/{code}`; `?archivo=1` solo cuando la respuesta permite ver documento |

El envío devuelve `enviado=false`, `puede_enviar_gerencia=false`,
`puede_entregar=false`, `mensaje_persistido=false`, `estado_flujo=null`,
`codigo_bloqueo=PERSISTENCIA_FLUJO_NO_DISPONIBLE` y motivos en el sobre existente.
Una huella de composición desactualizada devuelve 409. No hay éxito administrativo
por el simple intento. La vista previa conserva su contrato previo sin cambios.

## Cambios estructurales mínimos pendientes de autorización

1. Para sesión: referencia nullable desde certificado a sesión, FK compuesta a
   asistencia participación/sesión y UNIQUE parcial participación/sesión/tipo,
   con coherencia del origen. La propuesta comentada existente no se ejecutó.
   No se infiere sesión desde participación ni se aprovecha otro origen.
2. Para todos los orígenes: persistencia del contenido/composición aprobados,
   autor y fecha de preparación, estado administrativo independiente de vigencia
   y de fecha de emisión; remisión documental con actor, fecha e idempotencia.
3. Asignación congelada documento/responsable única, cargo aprobado, estado pendiente,
   modalidad autorizada y orden solo cuando corresponda. No reutilizar
   `certificado_firma.fecha_firma` como fecha de asignación.
4. Historial documental de decisiones y resultados del proveedor con hashes de
   revisiones, identidad autorizada y fechas reales. Gerencia necesita referencia
   a documento; las FK actuales solo admiten solicitudes de actividad.

No se proponen estados municipales definitivos sin aprobación. La implementación
de estos requisitos exige autorización específica de esquema y elección del
proveedor criptográfico. El bloqueo de identidad por sesión sigue siendo exclusivo
de ese origen; las carencias de remisión/firma afectan al circuito documental común.

## Verificación y límites

Las pruebas usan exclusivamente SQLite en memoria y almacenamiento falso. Los
cuatro casos de distribución renderizan PDF real con Dompdf y comprueban una
página y MediaBox 841.890 × 595.280 puntos (A4 horizontal). También se comprueba
el rechazo de una persona duplicada. No se dispuso de rasterizador PDF para una
inspección visual de cada página; la comprobación de geometría y paginación no
equivale a esa inspección ni acredita todos los textos/cargos posibles.

Se añade una regresión que fuerza desbordamiento en generación persistente y
exige 422, cero registros documentales y cero archivos. Se conservan las pruebas
de mensaje exacto, Unicode, desbordamiento de vista previa, reintentos, QR,
integridad, recuperación, JWT, permisos y respuestas 401/403/409/422.
No se afirma cobertura exitosa de firma parcial/completa, firmas incrementales,
modalidades secuenciales/paralelas, entrega o TSA: no están implementadas.

Comandos ejecutados:

```text
composer show --direct
php artisan route:list --path=api/certificados --except-vendor --no-interaction
php -d extension=pdo_sqlite vendor/bin/phpunit tests/Feature/CertificateDocumentGenerationTest.php tests/Feature/SessionDemonstrationIssuanceTest.php tests/Feature/CertificationModuleTest.php tests/Feature/CertificationReadinessTest.php tests/Feature/SessionBeneficiariesJwtTest.php --no-progress
php vendor/bin/pint --dirty --format agent
git diff --check
```

Resultado del conjunto final: **165 pruebas aprobadas, 2219 aserciones**, sin
fallos. Pint y `git diff --check` aprobados. No se ejecutaron pruebas de escritura
en PostgreSQL ni migraciones. Los cambios locales previos se conservaron.
