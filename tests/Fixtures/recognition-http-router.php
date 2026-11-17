<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

if (PHP_SAPI !== 'cli-server' || ! getenv('RECOGNITION_HTTP_FIXTURE')) {
    http_response_code(404);
    exit;
}

require dirname(__DIR__, 2).'/vendor/autoload.php';
$application = require dirname(__DIR__, 2).'/bootstrap/app.php';
$request = Request::capture();
$application->instance('request', $request);
$kernel = $application->make(Kernel::class);
$kernel->bootstrap();
$fixture = json_decode(file_get_contents(getenv('RECOGNITION_HTTP_FIXTURE')), true, 512, JSON_THROW_ON_ERROR);
config($fixture['config']);
$readOnly = $fixture['postgresql_read_only'] ?? false;
if ($readOnly) {
    if (config('database.default') !== 'pgsql') {
        throw new RuntimeException('La prueba Neon requiere conexión PostgreSQL de sólo lectura.');
    }
    DB::purge('pgsql');
    DB::beginTransaction();
    DB::statement('SET TRANSACTION READ ONLY');
} else {
    DB::purge('sqlite');
}
foreach ($fixture['schemas'] as $schema => $path) {
    if (! in_array($schema, ['gestion', 'certificado', 'acceso'], true)) {
        throw new RuntimeException('Esquema de prueba no admitido.');
    }
    DB::statement('ATTACH DATABASE ? AS '.$schema, [$path]);
}
try {
    $response = $kernel->handle($request);
    $response->send();
    $kernel->terminate($request, $response);
} finally {
    if ($readOnly && DB::transactionLevel() > 0) {
        DB::rollBack();
    }
}
