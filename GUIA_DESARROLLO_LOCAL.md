# Guía De Desarrollo Local (Docker) - gpp-back

Este es el archivo que debes seguir para preparar tu entorno y programar el backend sin instalar PHP, Composer ni extensiones en tu sistema operativo.

Objetivo:
- Ejecutar Laravel dentro de Docker.
- Trabajar con cambios en vivo (sin rebuild por cada archivo).
- Conectarse a la base de datos remota por VPN (no se levanta DB local).

---

## 1. Qué necesitas antes de empezar

### Requisitos mínimos
- Git instalado.
- Docker Desktop (Windows/macOS) o Docker Engine + Docker Compose plugin (Linux).
- Acceso VPN activo a la red interna donde vive la base de datos.

### Verificación rápida de herramientas
Ejecuta en terminal:

```bash
git --version
docker --version
docker compose version
```

Si alguno falla, instala esa herramienta antes de continuar.

---

## 2. Clonar el proyecto

```bash
git clone <URL_DEL_REPO> -b <RAMA>
cd gpp-back
```

Si ya tienes el repo, solo entra a la carpeta del proyecto.

---

## 3. Preparar variables de entorno

1. Crear `.env` desde la plantilla de desarrollo:

```bash
cp .env.dev.example .env
```

2. Editar `.env` y completar al menos:
- `APP_URL=http://localhost:5017`
- `DB_CONNECTION=pgsql`
- `DB_HOST=<host_remoto_por_vpn>`
- `DB_PORT=<puerto_db>`
- `DB_DATABASE=<nombre_db>`
- `DB_USERNAME=<usuario_db>`
- `DB_PASSWORD=<password_db>`

3. Confirmar que la VPN esté conectada antes de probar la app.

---

## 4. Levantar el entorno local

### Opción A: Script (recomendado)

#### Windows
```bat 
.\runapp.bat

```

#### Linux/macOS
```bash
chmod +x runapp.sh
./runapp.sh
```

### Opción B: Comando directo

```bash
docker compose -f docker/docker-compose.dev.yml up -d --build
```

Este comando:
- Construye la imagen de desarrollo (`docker/dev.Dockerfile`).
- Levanta el contenedor `app`.
- Expone la API en `http://localhost:5017`.

---

## 5. Bootstrap de Laravel dentro del contenedor

Con el contenedor arriba, ejecutar:

```bash
docker compose -f docker/docker-compose.dev.yml exec app composer install
docker compose -f docker/docker-compose.dev.yml exec app php artisan key:generate
docker compose -f docker/docker-compose.dev.yml exec app php artisan config:clear
```

Opcional (si aplica en tu flujo):

```bash
docker compose -f docker/docker-compose.dev.yml exec app php artisan migrate
```

Importante: en este proyecto normalmente se usa DB remota compartida; no ejecutes migraciones si no corresponde al entorno.

---

## 6. Cómo programar día a día

- Edita código en tu IDE local.
- El proyecto está montado como volumen en el contenedor.
- Los cambios se reflejan en cada request (sin rebuild continuo).

URL local:
- `http://localhost:5017`

---

## 7. Comandos frecuentes

Entrar al contenedor:

```bash
docker compose -f docker/docker-compose.dev.yml exec app bash
```

Ver logs en vivo:

```bash
docker compose -f docker/docker-compose.dev.yml logs -f app
```

Detener entorno:

```bash
docker compose -f docker/docker-compose.dev.yml down
```

Reconstruir desde cero (cuando cambie Dockerfile/config base):

```bash
docker compose -f docker/docker-compose.dev.yml down
docker compose -f docker/docker-compose.dev.yml up -d --build
```

---

## 8. Solución de problemas comunes

### 1) "No conecta a la base"
- Verifica VPN activa.
- Revisa `DB_HOST`, `DB_PORT`, usuario y password en `.env`.
- Prueba conectividad desde el contenedor:

```bash
docker compose -f docker/docker-compose.dev.yml exec app bash
# dentro del contenedor:
php -m | grep -E 'pdo|pgsql'
```

### 2) "Puerto 5017 ya está en uso"
- Cambia el mapeo de puerto en `docker/docker-compose.dev.yml`.
- Ejemplo: `"5018:80"`.

### 3) "Composer lento"
- Es normal en primera instalación.
- Luego mejora por cache de volumen `composer-cache`.

### 4) "Cambios no se reflejan"
- Revisa que el contenedor esté arriba.
- Ejecuta:

```bash
docker compose -f docker/docker-compose.dev.yml exec app php artisan config:clear
docker compose -f docker/docker-compose.dev.yml exec app php artisan cache:clear
```

### 5) "docker compose no existe"
- Usa `docker-compose` (comando clásico) o actualiza Docker.

---

## 9. Alcance de esta guía

Esta guía es solo para desarrollo local.

No modifica:
- `Dockerfile` raíz de CI/CD.
- `.gitlab-ci.yml`.

Si necesitas cambios de pipeline/deploy, coordinar con DevOps antes de modificar.

---

## 10. Checklist de validación final

1. `docker compose -f docker/docker-compose.dev.yml ps` muestra `app` en `Up`.
2. `http://localhost:5017` responde.
3. `composer install` terminó sin errores.
4. `php artisan key:generate` ejecutó correctamente.
5. La API conecta a la DB remota por VPN.

Si todo esto se cumple, el entorno está listo para programar.
