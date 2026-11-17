# API institucional de Solicitudes, Actividades y gestión posterior

## Regla definitiva

Toda actividad nueva debe originarse en una solicitud revisada y derivada a la autoridad competente. Únicamente la aprobación en ActivityRequestData crea la actividad y su vínculo. Gestión de Actividades administra expedientes autorizados y conserva los registros históricos sin solicitud.

POST /api/activity se conserva como ruta de compatibilidad protegida, pero responde 403 para todos los usuarios, incluido Administrador. El Gate create de Activity también lo deniega y ActivityData::createActivity devuelve 403 sin insertar. No existe un parámetro de excepción. El único INSERT de actividad en el código de aplicación queda en la aprobación transaccional de ActivityRequestData. No se eliminan actividades históricas.

Las rutas HTTP del módulo se declaran en routes/api.php. Se eliminó routes/activity_requests_development.php, que solo era cargado por dos suites automatizadas; esas suites ahora utilizan la API institucional ya registrada. No se incorporó su middleware alternativo a la API. Bootstrap sigue cargando api.php. Las utilidades históricas de pruebas y su configuración no activan rutas adicionales ni se ejecutan sobre PostgreSQL institucional.

## Identidad, permisos y representación administrativa

Se conserva JWT mediante cookie HttpOnly access_token y Request::user(). No se aceptan IDs de solicitante o responsable enviados por Angular como identidad. Login, me, logout, guards y cookies mantienen su implementación existente.

User::isSystemAdministrator exige usuario persistido y activo, asignación real usuario_rol, rol activo, ID administrativo configurado y nombre reconocido. Un claim JWT o un nombre visible no otorga esas atribuciones.

La facultad de representar áreas se habilita explícitamente mediante module_permissions.administrator_request_represent_all_areas=true. Además exige Administrador verificado y permiso activo solicitudes.crear. No es un nuevo permiso ficticio en PostgreSQL ni una atribución concedida a todos los usuarios con crear: es una política administrativa explícita sobre la identidad y el permiso institucional existentes. Si se deshabilita la configuración, vuelve a aplicarse el ámbito laboral. No se modifican roles, permisos o vínculos institucionales.

ActivityRequestData::authorizedRequestAreas(User) contiene la regla compartida por GET authorized-areas y el middleware que valida crear/editar/enviar. Para el administrador representante consulta todas las áreas reales de gestion.area; para los demás utiliza authorizedAreas(personId), que exige vínculo laboral activo, fecha_ingreso no futura y fecha_fin no vencida. La consulta laboral sigue utilizándose para los ámbitos ordinarios de gestión; la representación de solicitudes no amplía por sí sola otras funciones.

GET /api/activity-requests/authorized-areas requiere solicitudes.crear y devuelve 200:

```json
{
  "success": true,
  "message": "Áreas autorizadas para solicitudes consultadas correctamente.",
  "data": [{"id_area": 5, "nombre": "Oficina General de Administración"}]
}
```

El ejemplo muestra la forma de una fila, no el conjunto completo del Administrador. Se devuelve un array no paginado, ordenado por nombre/ID, sin duplicados, con las áreas reales permitidas. Sin vínculos y sin representación: data=[]. Sin sesión: 401; sin crear: 403; error SQL: 500, nunca se oculta como array vacío. Los parámetros de usuario/persona no amplían el principal.

La lectura PostgreSQL confirmó las diez áreas: 1 Alcaldía; 2 Gerencia Municipal; 3 Oficina General de Atención al Ciudadano y Gestión Documentaria; 4 Oficina General de Asesoría Jurídica; 5 Oficina General de Administración; 6 Oficina General de Planeamiento y Presupuesto; 7 Gerencia de Administración Tributaria; 8 Gerencia de Desarrollo Territorial y Económico; 9 Gerencia de Servicios Municipales y Gestión Ambiental; 10 Gerencia de Desarrollo Social. Se consultan de la tabla, nunca desde esta enumeración. gestion.area solo tiene id_area/nombre: no existe un campo activo/inactivo que se pueda filtrar.

El Administrador real fue comprobado por lectura: representación=true, authorizedRequestAreas status=200 con las diez áreas y Gate create de Activity=false. Su empleo sigue vinculado al área 5. La solicitud conserva id_usuario_solicitante=actor autenticado e id_area=unidad solicitante elegida; no suplanta al jefe de otra área.

La creación administrativa deja en el log existente solicitud.representacion_administrativa con id_solicitud_actividad, id_usuario_registrador e id_area_solicitante. La base conserva quién registró y para qué área, pero no conserva de forma histórica el modo/fundamento de representación ni la asignación de facultades vigente en ese momento. Propuesta mínima, NO aplicada: columnas registrada_en_representacion boolean y fundamento_representacion text en solicitud_actividad, escritas exclusivamente por el servidor, y validación del acto de atribución. Si se necesita diferenciar registrador y solicitante jurídico, hace falta definir esa distinción antes de añadir id_usuario_registrador; no se inventa otra identidad ahora. El log no sustituye una auditoría transaccional PostgreSQL.

## Capacidades de sesión y responsabilidades

GET /api/auth/me conserva user, modulos y permisos y devuelve capacidades_solicitudes:

```json
{
  "consultar_revision": true,
  "consultar_gerencia": true,
  "consultar_historial": true,
  "consulta_administrativa_global": true,
  "revisar": true,
  "derivar": true,
  "aprobar": true,
  "rechazar": true,
  "decidir_ambito_institucional": true,
  "decidir_propias": true,
  "representar_areas": true
}
```

Es el objeto de capacidades del Administrador con los permisos activos actuales, anidado bajo capacidades_solicitudes; no es un nuevo sobre de auth/me. Las capacidades de interfaz no sustituyen la autorización de cada petición.

- Administrador verificado: representa todas las áreas con la configuración indicada, consulta/revisa administrativamente y decide con la facultad institucional configurada. administrator_formal_permissions incluye aprobar/rechazar activos; administrator_request_institutional_scope autoriza decisiones globales y propias. Se mantienen estados, revisión previa, transacción e integridad. Nunca usa claves privadas de terceros.
- Digitador/RR. HH.: consultar, revisar, observar, derivar e historial según asignaciones activas y ámbito laboral real. El usuario leído tiene áreas 5 y 10; la representación del Administrador no amplía la competencia de RR. HH. sobre las otras áreas. Si se necesita revisión institucional global de ese perfil debe definirse y asignarse expresamente.
- Gerencial: consultar, aprobar/rechazar/observar e historial según sus permisos y ámbito. El usuario leído tiene empleo en Alcaldía, área 1; existe Gerencia Municipal como área 2 pero no se presume una delegación global por el nombre del rol. Los usuarios ordinarios no deciden solicitudes propias.
- Gestión de actividades/sesiones: actividades.ver/editar/eliminar y actividades.crear para crear sesiones, NO actividades. Participantes y asistencia: participantes.ver/crear/editar/eliminar. Ponentes: ponentes-firmantes.ver/crear/editar/eliminar. Cada operación mantiene su Gate y ámbito.

## Rutas exactas de Solicitudes

Todas requieren JWT. Prefijo de permisos: solicitudes. Respuestas del módulo: {success,message,data}. R es una solicitud; P<R> un paginador Laravel dentro de data; D una decisión.

| Método y ruta | Permiso | Transición/ámbito | Respuesta |
|---|---|---|---|
| GET /api/activity-requests/authorized-areas | crear | Regla compartida de representación/empleo | 200, áreas[] |
| POST /api/activity-requests | crear | Área permitida, nuevo Borrador, actor real | 201, R |
| PUT /api/activity-requests/{requestId} | editar | Propietario, área permitida, Borrador/Observada | 200, R |
| POST /api/activity-requests/{requestId}/submit | enviar | Propietario, Borrador/Observada a Enviada | 200, R |
| GET /api/activity-requests/mine | propias | Solo propietario y ámbito autorizado | 200, P<R> |
| GET /api/activity-requests | consultar | Área/etapa autorizada; Admin consulta global | 200, P<R> |
| GET /api/activity-requests/{requestId} | propias si propia; consultar si ajena | Propietario o consulta autorizada | 200, R |
| GET /api/activity-requests/{requestId}/review-detail | consultar | Área/etapa autorizada | 200, R |
| GET /api/activity-requests/{requestId}/history | propias si propia; historial si ajena | Propietario o consulta autorizada | 200, D[] |
| GET /api/activity-requests/decisions/history | historial | Área/etapa autorizada | 200, P<D> |
| POST /api/activity-requests/{requestId}/review | revisar | Enviada a En revisión; REVISION_CONFORME | 200, R |
| POST /api/activity-requests/{requestId}/observe | observar y revisar; en Gerencia observar y aprobar | Enviada/En revisión/Pendiente de aprobación a Observada | 200, R |
| POST /api/activity-requests/{requestId}/derive | revisar y derivar | En revisión a Pendiente de aprobación; DERIVAR_GERENCIA | 200, R |
| POST /api/activity-requests/{requestId}/approve | aprobar | Pendiente de aprobación a Aprobada; crea/vincula actividad | 200, R |
| POST /api/activity-requests/{requestId}/reject | rechazar | Pendiente de aprobación a Rechazada; no crea actividad | 200, R |

Crear exige id_area, id_tipo_actividad, nombre, justificacion, fecha_inicio_propuesta y fecha_fin_propuesta. descripcion y sede_propuesta admiten null. Nombre máximo 100, sede 100, descripción 255. Fechas propuestas Y-m-d; fin igual o posterior al inicio. No hay borrador incompleto porque las columnas obligatorias existen también en Borrador. PUT combina el subconjunto recibido con el expediente persistido y valida el resultado.

```json
{
  "id_area": 5,
  "id_tipo_actividad": 1,
  "nombre": "Nombre de actividad",
  "descripcion": "Descripción opcional",
  "justificacion": "Fundamento de la solicitud",
  "fecha_inicio_propuesta": "2026-12-01",
  "fecha_fin_propuesta": "2026-12-02",
  "sede_propuesta": "Sede propuesta"
}
```

Es una plantilla de contrato, no un registro insertado. Usar IDs de catálogos reales. Enviar no lleva cuerpo. Revisar/derivar/aprobar admiten {} o {"observacion":"texto"}; observar/rechazar requieren observacion no vacía. No enviar IDs de actor, estado, fecha_solicitud ni id_actividad para controlar esos datos.

Estados exactos: Borrador, Enviada, En revisión, Observada, Pendiente de aprobación, Aprobada, Rechazada. Enviar y aprobar revalidan el expediente. Aprobar/rechazar exige además revisión conforme y derivación registradas, bajo el bloqueo de la solicitud. La aprobación conserva decisión, actividad, vínculo y cambio de estado en una transacción; una repetición devuelve 409. Crear/enviar nunca crea actividades. ENVIAR no se inserta en solicitud_decision porque no es un tipo admitido por su CHECK.

Listado/mine admiten estado, id_area, fecha_desde, fecha_hasta, page y per_page (1..100; defecto 20). Historial global admite id_area, id_solicitud_actividad, id_usuario_responsable, tipo_decision, fecha_desde, fecha_hasta, page y per_page. Tipos: REVISION_CONFORME, OBSERVAR, DERIVAR_GERENCIA, APROBAR, RECHAZAR. No existe búsqueda textual ni versión de concurrencia en el contrato. X-Area-Id puede seleccionar un ámbito más estrecho válido; no concede otro ámbito.

RR. HH.: GET /api/activity-requests?estado=Enviada; después puede consultar En revisión. Gerencia: GET /api/activity-requests?estado=Pendiente%20de%20aprobaci%C3%B3n. Una solicitud Enviada no aparece en esa cola ni se puede aprobar. Los borradores ajenos quedan reservados al propietario o administración autorizada. Gerencia conserva consulta de expedientes previamente derivados aunque vuelvan a Observada.

Los registros R incluyen IDs, nombre, descripción, justificación, fechas propuestas, sede, estado, fecha_solicitud e id_actividad. Listados/detalles añaden area_nombre, solicitante_nombre y tipo_actividad_nombre mediante relaciones Eloquent por lote. D incluye id_solicitud_decision, id_solicitud_actividad, id_usuario_responsable, tipo_decision, estado_anterior, estado_nuevo, observacion, fecha_decision y responsable_nombre. El global añade solicitud_nombre, área/solicitante e IDs. Las relaciones ausentes devuelven nombres null; no se inventan Área #n o Usuario #n. No se serializan datos privados completos de persona/usuario.

Errores: 401 sin sesión; 403 sin atribución o ámbito; 404 inexistente o mutación de propietario ajeno; 409 estado incompatible, falta de revisión/derivación, repetición o integridad; 422 campos/filtros/motivo inválidos; 500 consulta fallida sin SQL/secretos. No se define un error de negocio 400 para este flujo. data.data=[] representa una página vacía, con total=0 y metadatos reales. Los errores por área incluyen codigo SOLICITUD_AREA_NO_AUTORIZADA o SOLICITUD_SIN_AREA_VIGENTE, id_area_solicitada e id_areas_autorizadas. El sobre de auth/me conserva su contrato histórico.

## Gestión de actividades autorizadas

GET /api/activity y GET /api/activity/{idActivity} se conservan con actividades.ver. Devuelven arrays/detalle, sin paginación nueva, con id_solicitud_actividad y origen. origen=solicitud cuando existe la relación; sin_solicitud para registros históricos sin vínculo, sin inventar autorización retroactiva. Activity::sourceRequest reutiliza solicitud_actividad.id_actividad y se carga por lote.

PUT/PATCH /api/activity/{idActivity} exige actividades.editar y consulta. En una actividad vinculada no se permiten cambios por CRUD en id_area, id_tipo_actividad, nombre, descripcion, fecha_inicio, fecha_fin o sede: devuelve 409 si cambia el valor. Se necesita un procedimiento de modificación de autorización, no implementado. El estado operativo permite Activa/Cerrada, por ejemplo {"estado":"Cerrada"}; conserva el vínculo y el expediente de autorización. Las sesiones se administran dentro del periodo autorizado. Se mantienen las protecciones de certificados existentes.

DELETE /api/activity/{idActivity} exige actividades.eliminar y consulta; devuelve 409 cuando hay solicitud vinculada, participaciones, sesiones, ponentes o firmas. No se borran registros históricos durante esta actualización.

POST /api/activity responde 403 con success=false, message indicando que se use una solicitud revisada/aprobada y data=[]. Se conserva 401 sin JWT. GET /api/areas/list?uso=actividades también devuelve 403 porque su finalidad anterior era el registro directo; GET /api/areas/list sin ese parámetro conserva el catálogo general autorizado y GET /api/ativicty-type?estado=1 conserva su URL. La API de solicitudes es ahora la única fuente de áreas para iniciar actividades nuevas.

Dependencias identificadas: el servicio y la pantalla Angular de Nueva actividad y sus pruebas de integración aún pueden llamar POST /activity; no se modificaron. Los clientes recibirán un rechazo explícito, no una creación silenciosa ni una solicitud inventada. Las pruebas backend anteriores de creación directa se adaptaron a la regla nueva y conservan fixtures históricos exclusivamente aislados para probar edición/consulta.

## Participantes, sesiones y asistencia

Relaciones reales: gestion.persona -> certificado.participacion (id_persona, id_actividad, id_tipo_participacion) -> certificado.actividad. UNIQUE(id_persona,id_actividad) impide una segunda inscripción de la misma persona, independientemente del tipo. La capa Data rechaza duplicados con 409 y preserva la restricción física. Tipos leídos: Municipal (1) y Externo (2), activos. No representan automáticamente Asistente/Ponente.

- GET/POST /api/participation, GET/PUT/PATCH/DELETE /api/participation/{idParticipation}. Crear requiere id_persona, id_actividad existente, id_tipo_participacion, fecha_inscripcion y estado. Permisos participantes.*. Respuesta de crear 201; repetición 409; referencias/campos inválidos 422.
- GET/POST /api/activity-session, GET/PUT/PATCH/DELETE /api/activity-session/{idActivitySession}. Crear requiere id_actividad, nombre, fecha, hora_inicio, hora_fin y estado; lugar opcional. Permisos actividades.*; crear sesiones sigue usando actividades.crear aunque crear Activity esté prohibido. Horarios H:i o H:i:s conforme al validador existente.
- GET /api/attendance/list, GET /api/attendance/{idAttendance}, POST /api/attendance/create, PUT/PATCH /api/attendance/update/{idAttendance}, DELETE /api/attendance/delete/{idAttendance}. Permisos participantes.*, no se inventa un módulo asistencias. Crear usa ID_PARTICIPACION, ID_ACTIVIDAD_SESION, ESTADO y opcionalmente HORA_ENTRADA/HORA_SALIDA en H:i. Las referencias deben pertenecer a la misma actividad; UNIQUE(id_participacion,id_actividad_sesion). La validación de duplicado existente devuelve 422.

AuthorizeModuleOperation aplica el ámbito de áreas al detalle, mutaciones y SQL de listados de actividad, sesiones, participaciones, ponentes y asistencia. Administrador verificado tiene gestión global con los permisos efectivos; un usuario común no puede apuntar a otra área mediante un ID destino. Un error de consulta de autorización devuelve JSON controlado, sin SQL. No se amplían los Gates de los demás módulos.

Los registros históricos sin solicitud se conservan administrables conforme a sus permisos. Que una persona esté vinculada como ponente no genera una participación o asistencia automática. Las pruebas comprueban que una actividad aprobada admite esas operaciones sin insertar otra actividad.

## Ponentes y certificación: estructura y propuesta pendiente

Existe gestion.persona_ponente (id_persona_ponente, id_persona, id_institucion nullable, cargo, especialidad) y certificado.actividad_ponente (id_actividad_ponente, id_actividad, id_persona_ponente). La FK y UNIQUE(id_actividad,id_persona_ponente) permiten varios ponentes distintos y evitan duplicar la asignación. No se crea otra relación.

GET/POST /api/activity-speaker y GET/PUT/PATCH/DELETE /api/activity-speaker/{idActivitySpeaker} utilizan los controladores existentes y permisos ponentes-firmantes.*. Para vincular: {"id_actividad":3,"id_persona_ponente":1}, con IDs autorizados reales. Crear devuelve 201, repetición 409, referencias inválidas 422. La ficha se gestiona mediante GET /api/person-speakers/list, GET /api/person-speakers/detail/{id}, POST /api/person-speakers/create, PUT /api/person-speakers/update/{id} y DELETE /api/person-speakers/delete/{id}; no es una participación ordinaria.

certificado.certificado referencia exactamente uno de id_participacion, id_reconocimiento o id_practica, además de id_persona/id_tipo_certificado. certificado_documento referencia id_certificado. No existe id_actividad_ponente como origen de certificado. Tipos de certificado leídos: Capacitación, Reconocimiento y Prácticas. No se crea un tipo Ponente ni se reutiliza Externo como si expresara ese rol.

Propuesta mínima para certificar una asignación de ponente, NO implementada: añadir id_actividad_ponente nullable en certificado, FK a actividad_ponente y ampliar certificado_origen_check para exigir exactamente uno de los cuatro orígenes. Ampliar validación de beneficiario para comprobar persona_ponente.id_persona y la actividad autorizada; adaptar tipos/plantillas, integridad/unicidad y protección de documentos emitidos. Requiere aprobación de esquema y definición institucional del certificado; no se ejecutaron migraciones o datos. Si un ponente también participa, esa inscripción debe ser real e independiente y su certificado de participación no prueba automáticamente su función como ponente.

Carencia detectada en emisión actual: el CHECK PostgreSQL exige exactamente un origen, mientras businessRules de CertificateData solo rechaza más de uno y algunas pruebas antiguas permiten dejar cero. Debe alinearse ese módulo y sus contratos en la etapa de emisión; no se utiliza esa ausencia para emitir certificados de ponentes ni se afirma que SQLite confirme esa integridad PostgreSQL.

## Reconocimientos y prácticas: propuesta separada

Reconocimientos ya tiene API /api/reconocimientos/{list,detail/{id},create,update/{id},delete/{id}}, JWT/Gates, permiso reconocimientos.*, referencia al trabajador y validación de fechas/beneficiario en emisión. Prácticas mantiene /api/practices/{list,detail/{id},create,update/{id},delete/{id}}, permisos practicantes.*, vínculo con persona_practicante, institución, área, periodo y supervisión existente. Certificados de reconocimiento/práctica usan esos orígenes y comprueban correspondencia con la persona. No requieren una actividad ni se les crea una ficticia.

Propuesta posterior: definir para cada procedimiento autoridad competente, acto/constancia que valida el mérito o la culminación, ámbitos, evidencia, vigencia y separación de funciones. Solo después representar su autorización y auditoría en el módulo propio y exigirla antes de emitir. No reutilizar solicitud_actividad ni estados nuevos por analogía. La estructura actual de esos módulos no acredita por sí sola un procedimiento formal de aprobación equivalente al de actividades. No se modifica ese esquema ni se crean expedientes artificiales aquí.

## Integración Angular y verificación

Nueva actividad debe dirigir al formulario de Solicitudes o mostrar que requiere solicitud; no usar POST /activity ni /areas/list?uso=actividades. Usar authorized-areas para todas las opciones y no inferir ámbitos del nombre del rol. Mantener fechas propuestas y flujo RR. HH./Gerencia. Al aprobar, abrir data.id_actividad mediante GET /api/activity/{id}; no emitir otro POST. Mostrar origen/solicitud y habilitar gestión de participantes, sesiones, ponentes y asistencia según permisos. En actividades aprobadas, impedir edición de los datos autorizados y permitir operaciones admitidas por el servidor. Refrescar auth/me para capacidades, incluida representar_areas; no guardar JWT en localStorage ni enviar IDs de actor.

CORS/cookies mantienen origen exacto autorizado, supports_credentials=true y withCredentials del cliente; conservar hostname/sitio compatible. No se modificó Angular. No se hicieron commit/push.

Resultado ejecutado: php -d extension=pdo_sqlite vendor/bin/phpunit, 288 casos, 269 correctos, 3887 aserciones, 19 omitidos y cero fallos finales. Se ejecutaron route:list, sintaxis PHP, Pint y git diff --check. La suite institucional comprueba representación y actor real, coincidencia de áreas mostradas/aceptadas, 401/403/409/422, creación directa bloqueada, actividad única, revisión/derivación obligatorias, gestión posterior, varios ponentes sin inscripciones artificiales, ámbitos y regresión de reconocimientos/prácticas. No se eliminan pruebas anteriores: las expectativas de creación directa se actualizaron a su rechazo y las pruebas de desarrollo usan ahora api.php. Las escrituras automatizadas se realizan exclusivamente en SQLite en memoria. PostgreSQL institucional se inspeccionó en transacciones READ ONLY para metadatos, restricciones, catálogos y facultades reales. No se ejecutaron migraciones/seeders institucionales ni altas o decisiones de prueba; las pruebas opcionales de escritura PostgreSQL permanecen omitidas si no hay entorno aislado autorizado. Los resultados SQLite no acreditan concurrencia ni escrituras PostgreSQL.

## Archivos de esta revisión

Aplicación: routes/api.php; config/module_permissions.php; app/Models/User/User.php; app/Services/Auth/ModulePermissions.php; app/Http/Middleware/ActivityRequests/AuthorizeActivityRequest.php; app/Http/Middleware/AuthorizeModuleOperation.php; app/Http/Data/ActivityRequests/ActivityRequestData.php; app/Http/Controllers/ActivityRequests/ActivityRequestController.php; app/Http/Data/Activity/ActivityData.php; app/Http/Controllers/Activity/ActivityController.php; app/Http/Controllers/Area/AreaController.php; app/Http/Data/ActivitySpeaker/ActivitySpeakerData.php; app/Http/Controllers/ActivitySpeaker/ActivitySpeakerController.php; app/Http/Data/Attendance/AttendanceData.php; app/Http/Controllers/Attendance/AttendanceController.php.

Eliminado tras comprobar sus consumidores: routes/activity_requests_development.php. El seeder de desarrollo existente se alineó con las claves institucionales de revisión/derivación/aprobación/rechazo para las fixtures aisladas; no se ejecutó contra PostgreSQL institucional.

Pruebas adaptadas: ActivityRequestDevelopmentTest, ActivityRequestDevelopmentPostgresTest, InstitutionalActivityRequestSecurityTest, ModuleIntegrationTest, PatchCrudTest, AttendanceTest, UserSecurityTest, PostgresModuleContractTest y ExercisesActivityRequestDevelopment. Se conservan los casos existentes, con expectativas acordes a la regla nueva. El presente documento sustituye el contrato anterior de registro directo.
