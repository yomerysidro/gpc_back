@echo off

echo ==========================================
echo Iniciando GPC Backend - Docker
echo ==========================================

docker compose -f docker/docker-compose.dev.yml up -d --build

if %errorlevel% neq 0 (
    echo.
    echo ERROR: No se pudo iniciar Docker.
    pause
    exit /b %errorlevel%
)

echo.
echo ==========================================
echo GPC Backend iniciado correctamente
echo http://localhost:5017
echo ==========================================

docker compose -f docker/docker-compose.dev.yml ps

pause