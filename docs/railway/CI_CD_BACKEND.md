# CI/CD de producción del backend en Railway

**Railway publica `gpc_back` desde `main` a través de su integración nativa con GitHub, con Wait for CI.** El flujo de publicación comienza con un PR aprobado de `dev-yomer` hacia `main`.

## GitHub Actions

`backend-ci.yml` valida los PR hacia `main` y cada SHA nuevo de `main` después del merge. El job de PHP comprueba el origen del PR y, en el push, que el SHA corresponda al merge de `dev-yomer` hacia la rama protegida. Los jobs validan PHP 8.3, Composer, PHPUnit con SQLite y PostgreSQL aislado, y el arranque de la imagen Docker con un volumen de prueba. Las pruebas de diagnóstico que requieren acceso explícito a sistemas externos se ejecutan por separado.

## Preparar GitHub

1. Protege `main` con una regla activa aplicada también a administradores y sin bypasses. Exige un PR con al menos una aprobación, descarta aprobaciones obsoletas y requiere los checks de GitHub Actions **PHP y pruebas** e **Imagen Docker**. Exige que la rama del PR esté actualizada antes de mergear. [Protección de ramas](https://docs.github.com/en/repositories/configuring-branches-and-merges-in-your-repository/managing-protected-branches/about-protected-branches)
2. Usa **Create a merge commit** o **Squash and merge** para que el SHA final sea distinto del probado en la rama del PR y quede asociado al merge. Evita directivas que omitan CI. Si GitHub ofrece **Restrict commit metadata**, agrega a `main` una restricción **Must not match a given regex pattern** sobre el mensaje de commit con `(?im)\[(skip ci|ci skip|no ci|skip actions|actions skip)\]|^skip-checks:[ \t]*true[ \t]*$`. [Directivas que omiten workflows](https://docs.github.com/en/actions/how-tos/manage-workflow-runs/skip-workflow-runs)
3. La integración nativa usa los permisos de la app de Railway en GitHub. Retira el token GitHub Actions `RAILWAY_TOKEN` del flujo anterior si se creó exclusivamente para este backend.

## Preparar Railway

1. En el servicio existente **gpc_back**, entorno **production**, configura el repositorio `yomerysidro/gpc_back`, rama `main`, **Auto Deploy** y **Wait for CI**. Concede a la app de Railway los permisos de GitHub que solicite. Mantén un único servicio de producción para este backend y desactiva los entornos automáticos de PR si están habilitados. [GitHub Autodeploys](https://docs.railway.com/deployments/github-autodeploys)
2. Conserva la raíz del proyecto y el Dockerfile existente. En Settings verifica el builder **Dockerfile**, el healthcheck `/up` con 120 segundos y el volumen privado en su punto de montaje actual. Replica en Settings los valores que dependan de `railway.json` antes del fin de su soporte el **1 de diciembre de 2026**. [Config as Code](https://docs.railway.com/config-as-code)
3. Conserva la instancia PostgreSQL privada, una réplica del backend con el volumen y las variables del servicio.

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

1. Aprueba y fusiona el PR de `dev-yomer` hacia `main`. Comprueba que **Backend CI** se ejecuta como push para el SHA final.
2. En **Railway → gpc_back → Deployments**, comprueba que el SHA figura una sola vez, pasa por **Waiting** mientras corre CI y se activa tras su éxito. Si CI falla, verifica que Railway omita ese SHA. Consulta `/up` y las verificaciones autenticadas según la política de producción.
3. Para revertir código, promueve la reversión por el mismo flujo de PR aprobado y CI.
