<?php

namespace App\Services\ActivityRequests;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class DevelopmentDatabase
{
    public function assertConfigured(): void
    {
        $connection = config('database.connections.activity_requests_test');
        if (! app()->environment('local', 'testing')
            || ! config('activity_requests_development.enabled')
            || ! config('activity_requests_development.exclusive')
            || config('database.default') !== 'activity_requests_test'
            || ! is_array($connection)
            || ($connection['driver'] ?? null) !== 'pgsql'
            || ($connection['database'] ?? null) !== 'certificados_morales_test'
            || ! in_array($connection['host'] ?? null, ['127.0.0.1', '::1', 'localhost'], true)
            || ! empty($connection['url'])
            || ! empty($connection['read'])
            || ! empty($connection['write'])
            || ($connection['username'] ?? null) !== 'certificados_morales_test_app'
            || ! is_string($connection['password'] ?? null)
            || $connection['password'] === '') {
            throw new RuntimeException('Integración deshabilitada: requiere entorno local/testing y conexión PostgreSQL exclusiva de pruebas.');
        }
    }

    public function assertConnected(): void
    {
        $this->assertConfigured();
        $identity = DB::selectOne('SELECT current_database() AS database_name, current_user AS user_name');
        if ($identity->database_name !== 'certificados_morales_test' || $identity->user_name !== 'certificados_morales_test_app') {
            throw new RuntimeException('La conexión no pertenece al entorno PostgreSQL autorizado de pruebas.');
        }
        $role = DB::selectOne('SELECT rolsuper, rolcreatedb, rolcreaterole FROM pg_roles WHERE rolname = current_user');
        if (! $role || $role->rolsuper || $role->rolcreatedb || $role->rolcreaterole) {
            throw new RuntimeException('La conexión de pruebas no debe usar un rol administrador de PostgreSQL.');
        }
        $institutional = DB::selectOne("SELECT EXISTS(SELECT 1 FROM pg_database WHERE datname = 'certificados_morales' AND has_database_privilege(current_user, oid, 'CONNECT')) AS accessible");
        if ($institutional->accessible) {
            throw new RuntimeException('El rol de pruebas no debe tener acceso CONNECT a la base institucional.');
        }
    }
}
