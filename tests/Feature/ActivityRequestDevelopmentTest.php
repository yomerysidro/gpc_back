<?php

namespace Tests\Feature;

use App\Http\Middleware\ActivityRequests\DevelopmentAccess;
use App\Services\ActivityRequests\DevelopmentDatabase;
use Database\Seeders\ActivityRequestDevelopmentSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\Fixtures\ExercisesActivityRequestDevelopment;
use Tests\TestCase;

class ActivityRequestDevelopmentTest extends TestCase
{
    use ExercisesActivityRequestDevelopment;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'database.connections.sqlite.url' => null,
            'activity_requests_development.enabled' => true, 'activity_requests_development.seed_password' => 'only-for-automated-tests-password']);
        DB::purge('sqlite');
        foreach (['gestion', 'acceso', 'certificado'] as $schema) {
            DB::statement("ATTACH DATABASE ':memory:' AS $schema");
        }
        $definitions = [
            'gestion.area' => 'id_area INTEGER PRIMARY KEY, nombre TEXT',
            'gestion.persona' => 'id_persona INTEGER PRIMARY KEY, nombres TEXT, apellido_paterno TEXT, apellido_materno TEXT, correo TEXT, fecha_registro TEXT',
            'gestion.persona_trabajador' => 'id_persona_trabajador INTEGER PRIMARY KEY, id_persona INTEGER, id_area INTEGER, cargo TEXT, fecha_ingreso TEXT, fecha_fin TEXT, estado BOOLEAN',
            'acceso.usuario' => 'id_usuario INTEGER PRIMARY KEY, id_persona INTEGER, correo TEXT, password_hash TEXT, estado BOOLEAN',
            'acceso.rol' => 'id_rol INTEGER PRIMARY KEY, nombre TEXT, estado BOOLEAN',
            'acceso.usuario_rol' => 'id_usuario_rol INTEGER PRIMARY KEY, id_usuario INTEGER, id_rol INTEGER',
            'acceso.modulo' => 'id_modulo INTEGER PRIMARY KEY, nombre TEXT, url TEXT, estado BOOLEAN',
            'acceso.permiso' => 'id_permiso INTEGER PRIMARY KEY, id_modulo INTEGER, nombre TEXT, clave TEXT, estado BOOLEAN',
            'acceso.rol_modulo' => 'id_rol INTEGER, id_modulo INTEGER',
            'acceso.rol_modulo_permiso' => 'id_rol INTEGER, id_modulo INTEGER, id_permiso INTEGER',
            'certificado.tipo_actividad' => 'id_tipo_actividad INTEGER PRIMARY KEY, nombre TEXT, descripcion TEXT, estado BOOLEAN',
            'certificado.actividad' => 'id_actividad INTEGER PRIMARY KEY, id_area INTEGER, id_tipo_actividad INTEGER, nombre TEXT, descripcion TEXT, fecha_inicio TEXT, fecha_fin TEXT, sede TEXT, estado TEXT',
            'certificado.solicitud_actividad' => 'id_solicitud_actividad INTEGER PRIMARY KEY, id_area INTEGER, id_tipo_actividad INTEGER, id_usuario_solicitante INTEGER, nombre TEXT, descripcion TEXT, justificacion TEXT, fecha_inicio_propuesta TEXT, fecha_fin_propuesta TEXT, sede_propuesta TEXT, estado TEXT, fecha_solicitud TEXT, id_actividad INTEGER',
            'certificado.solicitud_decision' => 'id_solicitud_decision INTEGER PRIMARY KEY, id_solicitud_actividad INTEGER, id_usuario_responsable INTEGER, tipo_decision TEXT, estado_anterior TEXT, estado_nuevo TEXT, observacion TEXT, fecha_decision TEXT',
        ];
        foreach ($definitions as $table => $columns) {
            DB::statement('CREATE TABLE '.$table.' ('.$columns.')');
        }
        $this->mock(DevelopmentDatabase::class)->shouldReceive('assertConnected')->andReturnNull();
        $this->seed(ActivityRequestDevelopmentSeeder::class);
    }

    public function test_environment_guard_rejects_current_nonisolated_configuration(): void
    {
        $this->app->instance(DevelopmentDatabase::class, new DevelopmentDatabase);

        $request = Request::create('http://localhost/api/activity-requests', 'POST', server: ['REMOTE_ADDR' => '127.0.0.1']);
        self::assertSame(503, app(DevelopmentAccess::class)->handle($request, fn () => response()->json([]))->getStatusCode());
    }

    public function test_remote_requests_are_rejected_even_with_authenticated_principal(): void
    {
        $this->actor('gerencia');

        $request = Request::create('http://localhost/api/activity-requests', server: ['REMOTE_ADDR' => '192.0.2.1']);
        self::assertSame(403, app(DevelopmentAccess::class)->handle($request, fn () => response()->json([]))->getStatusCode());
    }

    #[TestWith(['production'])]
    #[TestWith(['staging'])]
    public function test_environment_configuration_never_allows_production_or_staging(string $environment): void
    {
        $this->validDevelopmentConfiguration();
        $this->app['env'] = $environment;
        $this->expectException(RuntimeException::class);

        (new DevelopmentDatabase)->assertConfigured();
    }

    #[TestWith(['activity_requests_development.enabled', false])]
    #[TestWith(['activity_requests_development.exclusive', false])]
    #[TestWith(['database.default', 'pgsql'])]
    #[TestWith(['database.connections.activity_requests_test.database', 'certificados_morales'])]
    #[TestWith(['database.connections.activity_requests_test.host', 'institutional.invalid'])]
    #[TestWith(['database.connections.activity_requests_test.username', 'postgres'])]
    #[TestWith(['database.connections.activity_requests_test.password', null])]
    #[TestWith(['database.connections.activity_requests_test.url', 'pgsql://unexpected.invalid/database'])]
    #[TestWith(['database.connections.activity_requests_test.read', ['host' => 'institutional.invalid']])]
    #[TestWith(['database.connections.activity_requests_test.write', ['host' => 'institutional.invalid']])]
    public function test_unsafe_configuration_is_rejected_before_connecting(string $key, mixed $value): void
    {
        $this->validDevelopmentConfiguration();
        config([$key => $value]);
        $this->expectException(RuntimeException::class);

        (new DevelopmentDatabase)->assertConfigured();
    }

    private function validDevelopmentConfiguration(): void
    {
        config([
            'activity_requests_development.enabled' => true,
            'activity_requests_development.exclusive' => true,
            'database.default' => 'activity_requests_test',
            'database.connections.activity_requests_test' => [
                'driver' => 'pgsql', 'host' => '127.0.0.1', 'database' => 'certificados_morales_test',
                'username' => 'certificados_morales_test_app', 'password' => 'only-a-test-configuration',
            ],
        ]);
    }
}
