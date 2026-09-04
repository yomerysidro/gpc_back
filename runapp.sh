#!/bin/sh

set -e

echo "=========================================="
echo "Iniciando GPC Backend - Docker"
echo "=========================================="

docker compose \
    -f docker/docker-compose.dev.yml \
    up -d --build

echo ""
echo "=========================================="
echo "GPC Backend iniciado correctamente"
echo "http://localhost:5017"
echo "=========================================="

docker compose \
    -f docker/docker-compose.dev.yml \
    ps