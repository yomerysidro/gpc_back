# Registro administrativo de funcionarios sin PFX

Contrato vigente: 2026-11-11. Prefijo `/api`, JWT en cookie `access_token`; envoltorio `success`, `message`, `data`. No se modificó Angular.

## Persistencia real y separación de conceptos

La migración aplicada permite guardar responsables por documento en `certificado.certificado_firma`: trabajador real, orden, `id_firma=NULL` y `fecha_firma=NULL`. La modalidad se guarda en `certificado.certificado_documento.modalidad_firma`.

**No existe un catálogo independiente de habilitaciones.** El modelo permitido es una habilitación documental: seleccionar un trabajador y autorizarlo como responsable de un documento pendiente. No se crean tablas, no se modifican trabajadores y no se utilizan `firma`, PFX ni contraseñas para representar esta habilitación.

`gestion.persona_trabajador` aporta vínculo laboral, cargo e ID de área; `gestion.persona` aporta nombres/apellidos y `gestion.area` el nombre del área. Se exige vínculo activo, fecha de ingreso no futura, cese no vencido y nombre, apellido paterno y cargo no vacíos.

La autorización para **asignar** pertenece al usuario gestor del documento. El trabajador seleccionado no necesita cuenta de usuario ni `firmas.firmar`. Su selección explícita por un gestor autorizado constituye la habilitación para ese documento, sin otorgar facultad criptográfica.

## Permisos y ámbitos

- Consulta: `emision.ver`.
- Habilitación por documento, asignación y revisión de PDF: también `emision.editar` y ámbito del documento.
- Solicitud de catálogo independiente, actualmente bloqueada: `ponentes-firmantes.editar` además de consulta.
- Firma criptográfica: `firmas.firmar`, separado y reservado para una futura operación real. Mantener bloqueada mientras no haya credenciales y motor verificados.
- Emisión oficial: bloqueada; asignar no cambia estado a FIRMADO/EMITIDO ni fecha_emision.

Los no administradores se limitan a áreas de sus vínculos laborales vigentes. El área enviada como filtro no amplía ese ámbito. La competencia del trabajador se limita al área documental o a una configuración por actividad ya existente y legítima.

El rol real `Gerencial` (ID 3) tiene asignados explícitamente los permisos existentes `emision.ver` y `emision.editar`, con su vínculo al módulo en `acceso.rol_modulo` y las dos concesiones en `acceso.rol_modulo_permiso`. No se modificaron otros roles ni se otorgaron permisos de crear/eliminar certificados, gestionar credenciales o `firmas.firmar`. No se amplían sus ámbitos: los documentos de otras áreas siguen devolviendo 404. Sus permisos de solicitudes no se convierten automáticamente en permisos de certificados.

## Trabajadores y habilitados

| Método y ruta | Resultado |
| --- | --- |
| `GET /funcionarios-firmantes/trabajadores` | Todos los trabajadores vigentes disponibles dentro del ámbito |
| `GET /funcionarios-firmantes` | Trabajadores que ya tienen una habilitación documental persistida en documento Vigente con certificado PREPARADO o PENDIENTE_FIRMA |
| `POST /funcionarios-firmantes/habilitar` | Guarda una habilitación para un documento real; no crea catálogo independiente |

Los GET admiten únicamente `id_area`, entero positivo opcional. Devuelven `data.funcionarios`, sin duplicar al trabajador por varios documentos o roles:

```ts
interface FuncionarioFirmante {
  id_persona_trabajador: number;
  id_persona: number;
  id_area: number | null;
  area: string | null;
  nombre: string;
  cargo: string;
  vigente: boolean;
  disponible_asignacion: boolean;
  habilitado: boolean;
  origen_habilitacion: 'asignacion_documental';
  id_firma: number | null;
  credencial_registrada: boolean;
  credencial_verificada: false;
  credencial_valida: null;
}
```

`habilitado` refleja una asignación documental vigente real; no significa autorización para firmar criptográficamente. Un trabajador disponible puede tener habilitado=false hasta que se guarde su primera asignación. La cuenta del gestor debe conservar sus permisos para gestionar el documento.

Los GET también devuelven `catalogo_independiente:false`, `registro_independiente_disponible:false`, `requiere_pfx:false`, `requiere_password:false` y `origen:relaciones_laborales_y_permisos_existentes` (campo conservado por compatibilidad; la habilitación se determina por asignación documental).

## Habilitar administrativamente

```json
{
  "id_persona_trabajador": 2,
  "id_certificado_documento": 2,
  "orden_firma": 1,
  "modalidad": "SECUENCIAL",
  "confirmar": true
}
```

Usar exclusivamente IDs consultados en el entorno real. El endpoint reutiliza la asignación de Etapa B y devuelve 200, `completada:true`, `persistido:true`, `tipo_registro:habilitacion_documental`, `catalogo_independiente:false` y el progreso del documento. Repetir el mismo plan devuelve `reintento:true`; no lo duplica. Este atajo configura un único responsable; para varios usar `/firma/asignar` con el plan completo. No agrega ni reemplaza silenciosamente un plan existente.

Si se envía solo trabajador y confirmación, sigue respondiendo **409**, `persistido:false`, `HABILITACION_INDEPENDIENTE_NO_DISPONIBLE`: no hay dónde guardar un registro global. Angular debe solicitar documento/orden/modalidad o utilizar directamente la asignación. No mostrar éxito para esta respuesta.

Campos PFX, password, rutas, nombres, cargos, fechas o estados del cliente se rechazan. Nombre y cargo se consultan en RR.HH.

## Asignación y consulta por documento

Ver [certificate-stage-b-api-contract.md](certificate-stage-b-api-contract.md) para modalidad, varios responsables, orden, consulta y revisión del PDF. La asignación prepara una revisión pendiente de confirmación con los firmantes persistidos, conservando certificado, documento, código/QR y texto aprobado. Las revisiones se almacenan en archivos privados autenticados; no generan otro certificado, fila documental ni código ni modifican el original. Tras confirmar, la misma URL privada sirve la versión vigente.

`credencial_registrada` solo informa existencia de datos en la tabla de credenciales. `credencial_valida:null` indica que no se ha verificado criptográficamente: no mostrar “credencial válida”. Nunca se exponen contraseña ni ruta del PFX. La carga segura de PFX/P12 legítimos permanece separada.

## Verificación real

El resultado de la prueba controlada contra `certificados_morales` está en [administrative-signers-live-check.json](administrative-signers-live-check.json). Se usa JWT con una cuenta real, se guarda una habilitación documental, se recupera desde PostgreSQL y se revierte la transacción. El archivo conserva los valores comprobados, el resultado de Gerencia y el hash original. No se ejecutan migraciones ni se crean bases/tablas.
