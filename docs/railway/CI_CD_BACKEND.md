# CI/CD de producción del backend en Railway

El código se publica mediante **Backend Deploy Railway**, desde GitHub Actions, tras la revisión del SHA y una ejecución manual de la cuenta propietaria del repositorio. Railway construye el Dockerfile raíz a partir de los archivos versionados de ese SHA.

## Ramas y validaciones

**`main` es la única rama de producción autorizada.** Integra los cambios de las ramas de trabajo en `development` según el flujo del repositorio y abre el PR de promoción a `main`. Al hacer merge, espera la CI del SHA final de `main` antes de autorizar su publicación manual. Los pushes y merges de ramas intermedias solo ejecutan CI.

- `backend-ci.yml` se ejecuta en pushes y PR hacia `main`, `development` y `dev-*`. Comprueba el lock, requisitos de plataforma y sintaxis con PHP 8.3. Ejecuta PHPUnit con SQLite en memoria y el contrato específico con un PostgreSQL 15 desechable. Las suites diagnósticas institucionales optativas quedan fuera cuando requieren acceso explícito a sistemas externos. CI usa credenciales ficticias y carece de acceso a secretos de Railway.
- La CI también construye el Dockerfile, arranca la imagen con un puerto distinto de 80 y un volumen de prueba, comprueba `/up`, permisos de escritura y la parada del contenedor. La imagen se queda en el runner.
- `backend-deploy-railway.yml` recibe únicamente `workflow_dispatch`. Verifica que el actor y quien reejecuta sean la cuenta propietaria, que la rama elegida sea `main` y esté protegida, y que el SHA explícito sea el de la ejecución. Exige una CI de **push** exitosa para el mismo SHA en `main`, con ambos trabajos aprobados. Una CI de PR por sí sola no acredita el commit final. El flujo vuelve a verificar la punta de la rama antes de subir el archivo generado por `git archive`.
- Una sola publicación puede ejecutarse por vez en el repositorio. Antes de subir, Railway CLI comprueba si el servicio ya tiene un despliegue en curso. Se exige un resultado JSON terminal de éxito para el despliegue etiquetado con el SHA y el número de ejecución. Luego se consulta el `/up` público mediante HTTPS. Los logs del proceso quedan en Railway para evitar copiarlos al log de Actions.

## Preparar GitHub

1. Promueve ambos workflows a `main` por PR. GitHub requiere que el workflow de despliegue exista en la rama predeterminada para habilitar `workflow_dispatch`. [Eventos de GitHub Actions](https://docs.github.com/en/actions/reference/workflows-and-actions/events-that-trigger-workflows#workflow_dispatch)
2. En **Settings → Branches** o **Rules → Rulesets**, protege `main`. Exige PR y los checks de **Backend CI** llamados **PHP y pruebas** e **Imagen Docker**. Cuando el plan permita restricciones y revisión por propietarios, aplícalas a los workflows y archivos Docker.
3. Crea el entorno **production** en **Settings → Environments** y restringe las ramas de despliegue a la autorizada. Activa **Required reviewers** y **Prevent self-review** si el plan los admite y dispones de un segundo revisor. GitHub Free, Pro y Team ofrecen Required Reviewers solo en repositorios públicos. En repositorios privados con protección de ramas pero sin revisores de entorno, la autorización obligatoria de este flujo es el disparo manual por la persona propietaria. Si el plan tampoco admite protección para esta rama privada, el flujo se bloqueará. Habilita la protección antes de publicar. [Protecciones de entornos](https://docs.github.com/en/actions/reference/workflows-and-actions/deployments-and-environments) · [Protección de ramas](https://docs.github.com/en/repositories/configuring-branches-and-merges-in-your-repository/managing-protected-branches/about-protected-branches)
4. Configura lo siguiente en el entorno `production` de GitHub siempre que sea posible.

| Tipo | Nombre | Valor |
| --- | --- | --- |
| Secreto | `RAILWAY_TOKEN` | Project Token del entorno de producción, generado en Railway |
| Variable | `RAILWAY_PROJECT_ID` | ID del proyecto |
| Variable | `RAILWAY_ENVIRONMENT_ID` | ID del entorno de producción |
| Variable | `RAILWAY_SERVICE_ID` | ID del servicio Laravel |
| Variable | `RAILWAY_HEALTH_URL` | URL HTTPS pública terminada en `/up` |

Si solo se dispone de secretos a nivel de repositorio, asegúrate de que únicamente la persona propietaria tenga capacidad de crear o ejecutar workflows modificables con acceso a esos secretos antes de añadir el token. Un colaborador con permiso de escritura podría crear otro workflow para usar un secreto de repositorio. `GITHUB_TOKEN` se genera automáticamente y solo tiene los permisos de lectura declarados.

## Preparar Railway

1. En el entorno de producción crea o identifica un servicio **vacío para Laravel** y el servicio privado PostgreSQL. Para un backend existente, desactiva **Auto-deploy** en el servicio y desconecta su fuente GitHub si estaba enlazada. Revisa que no exista otra integración, servicio o webhook que publique este backend al recibir un push. Los cambios de código se envían solo por la CLI del workflow. **Wait for CI** en la integración nativa seguiría siendo un despliegue automático. [Auto-deploy de Railway](https://docs.railway.com/deployments/github-autodeploys)
2. Deja la ruta raíz del proyecto en el valor por defecto y usa la configuración raíz `railway.json`. Quita los overrides de Start Command, Pre-deploy Command, Dockerfile y puerto fijo si los hubiera. Conserva una réplica con el volumen. El proceso maestro necesita UID 0 para inicializar el punto de montaje. Los procesos web trabajan como `www-data`.
3. Añade el volumen privado con el montaje indicado abajo. Configura la red pública en el servicio Laravel y su dominio HTTPS antes de la verificación de Actions. Mantén PostgreSQL en la red privada.
4. Configura las variables de servicio y verifica el estado de la base y el volumen antes de autorizar el primer SHA.

La imagen reutiliza PHP 8.3 FPM, Nginx y Supervisor. Composer instala desde `composer.lock` con las extensiones reales del runtime, incluida `pdo_pgsql`, y ejecuta el descubrimiento de paquetes durante el build. Vite se construye con `npm ci`. Nginx escucha en `0.0.0.0:$PORT` y en IPv6. Sin `PORT` usa 80 para Compose. Supervisor recibe la señal de parada y detiene Nginx y PHP-FPM con `QUIT`. Los errores de Nginx y el canal Laravel `stderr` se consultan en Railway. [Dockerfile en Railway](https://docs.railway.com/builds/dockerfiles) · [Healthchecks](https://docs.railway.com/deployments/healthchecks)

### Variables del servicio Laravel

| Variable | Valor |
| --- | --- |
| `APP_ENV`, `APP_DEBUG` | `production`, `false` |
| `APP_URL`, `FRONTEND_URL` | Orígenes HTTPS exactos del backend y de Angular, sin barra final |
| `APP_KEY`, `JWT_SECRET` | Claves privadas del entorno. Conserva las claves históricas cuando correspondan. `JWT_SECRET` requiere al menos 32 bytes; para una instalación nueva, genera 32 bytes aleatorios y codifícalos en hexadecimal |
| `LOG_CHANNEL`, `LOG_LEVEL` | `stderr`, `info` |
| `FILESYSTEM_DISK` | `local` |
| `SESSION_SECURE_COOKIE`, `SESSION_HTTP_ONLY` | `true`, `true` |
| `SESSION_SAME_SITE`, `SESSION_DOMAIN` | `lax`, dejar dominio sin definir para cookie del host |
| `SESSION_DRIVER`, `CACHE_STORE` | `database`, `database` si el esquema verificado contiene ambas tablas requeridas |
| `CERTIFICATION_PUBLIC_VERIFICATION_BASE_URL` | Opcional, URL HTTPS aprobada para los enlaces de verificación |
| `MAIL_MAILER` y credenciales `MAIL_*` | Configura SMTP institucional si se usa recuperación de contraseña. Evita el canal de correo `log` en producción |

Railway suministra `PORT` y `RAILWAY_ENVIRONMENT_ID`. En Railway, Laravel confía en el encabezado `X-Forwarded-Proto` del proxy para detectar HTTPS. El navegador debe llamar al backend por HTTPS. Usa dominios personalizados bajo el mismo dominio registrable, por ejemplo `app.municipio.example` y `api.municipio.example`, y verifica que el navegador los considere del mismo sitio. Angular debe usar `withCredentials: true` para las solicitudes autenticadas. CORS admite solo `FRONTEND_URL` y credenciales. `access_token` conserva `HttpOnly`, `Secure` y `SameSite=Strict`; `SESSION_SAME_SITE` configura únicamente la sesión Laravel. Las rutas API del guard JWT carecen del middleware CSRF del grupo web. Restringe el control de los subdominios del mismo sitio a servicios de confianza. [Proxy público de Railway](https://docs.railway.com/networking/public-networking/specs-and-limits)

### PostgreSQL privado

Sustituye `Postgres` por el nombre exacto del servicio si es distinto. Configura estas referencias en **Variables** del backend. Deja `DB_URL` sin definir para que no prevalezca una URL antigua de Neon.

| Variable Laravel | Valor Railway |
| --- | --- |
| `DB_CONNECTION` | `pgsql` |
| `DB_HOST` | `${{Postgres.PGHOST}}` |
| `DB_PORT` | `${{Postgres.PGPORT}}` |
| `DB_DATABASE` | `${{Postgres.PGDATABASE}}` |
| `DB_USERNAME` | `${{Postgres.PGUSER}}` |
| `DB_PASSWORD` | `${{Postgres.PGPASSWORD}}` |
| `DB_SSLMODE` | `require`, tras confirmar que la instancia privada admite TLS |

Desde el contenedor, `php artisan migrate:status --no-interaction` consulta el historial sin ejecutarlo. Para confirmar solo conectividad, usa `SELECT 1` desde una sesión de diagnóstico de solo lectura en la red privada. Compara el backup y el esquema de destino, incluidas tablas, vistas, secuencias, permisos y requisitos de sesión y caché. El repositorio no contiene un backup operativo para acreditar esa comparación. El arranque y el workflow carecen de comandos de migración, restauración o seeding. `composer setup` es un flujo local y ejecuta migraciones; no forma parte del build ni de la publicación. [PostgreSQL en Railway](https://docs.railway.com/databases/postgresql)

### Volumen de documentos

Monta un volumen del backend en **`/var/www/httpdocs/storage/app/private`**. Los servicios de certificados usan explícitamente el disco Laravel `local` y conservan rutas relativas, documentos y manifiestos en ese directorio. El arranque exige ese montaje en Railway y asigna su raíz a `www-data`. Haz la copia de los archivos históricos por un canal autorizado antes del corte, manteniendo la estructura y dando lectura/escritura a `www-data` en el contenido importado. Compara inventario y hashes SHA-256 con el origen, y conserva un respaldo consistente con PostgreSQL y la `APP_KEY`. La copia de históricos es una operación independiente. El flujo de código no la ejecuta. No crees enlaces públicos al directorio privado. [Volúmenes de Railway](https://docs.railway.com/volumes)

## Publicar, comprobar y revertir

1. Tras el merge, espera que **Backend CI** quede verde en el push de la rama protegida. Revisa el commit exacto y copia su SHA completo de 40 caracteres.
2. Desde la cuenta propietaria, abre **Actions → Backend Deploy Railway → Run workflow**. Elige la rama autorizada, pega el SHA y confirma. Si configuraste revisores, el job espera su aprobación.
3. Comprueba que Actions registra el SHA y `HTTP 200`, y que **Deployments** de Railway muestra ese mismo SHA como activo. El `/up` confirma arranque de Laravel. Verifica aparte la consulta de solo lectura, respuesta 401 sin sesión, preflight para Angular, login, cookie y descarga autenticada de un PDF histórico. Confirma que el acceso sin autorización se rechaza y que el hash del PDF descargado coincide con el inventario. Las pruebas autenticadas deben seguir la política operativa de producción.
4. Si falla Actions, usa el SHA y el identificador de ejecución para localizar el despliegue y consultar **Build Logs**, **Deploy Logs** y el healthcheck en Railway. Cancelar Actions después de la subida puede dejar el despliegue activo en Railway. Para detener uno aún en curso, cancélalo también en **Deployments** y confirma su estado antes de reintentar.
5. Para revertir código, abre un PR que revierta el cambio en la rama protegida, espera la CI del nuevo SHA y autoriza manualmente ese SHA. El volumen y la base permanecen adjuntos. Si debes detener el servicio como medida de incidente, usa los controles de Railway y verifica la inactividad antes de autorizar otra publicación.
