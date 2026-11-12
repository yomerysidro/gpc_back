<?php

namespace Tests\Feature;

use App\Models\User\User;
use App\Services\Auth\JwtService;
use App\Services\CertificationReadiness;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SessionBeneficiariesJwtTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'database.connections.sqlite.url' => null,
            'jwt.secret' => str_repeat('isolated-attendance-secret-', 3), 'app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);
        DB::purge('sqlite');
        foreach (['acceso', 'gestion', 'certificado'] as $schema) {
            DB::statement("ATTACH DATABASE ':memory:' AS {$schema}");
        }
        foreach ([
            'gestion.area' => 'id_area INTEGER PRIMARY KEY, nombre TEXT',
            'gestion.persona' => 'id_persona INTEGER PRIMARY KEY, nombres TEXT, apellido_paterno TEXT, apellido_materno TEXT',
            'gestion.persona_trabajador' => 'id_persona_trabajador INTEGER PRIMARY KEY, id_persona INTEGER, id_area INTEGER, estado BOOLEAN, fecha_ingreso DATE, fecha_fin DATE',
            'acceso.usuario' => 'id_usuario INTEGER PRIMARY KEY, id_persona INTEGER, correo TEXT, password_hash TEXT, estado BOOLEAN, bloqueado_hasta TEXT',
            'acceso.rol' => 'id_rol INTEGER PRIMARY KEY, nombre TEXT, estado BOOLEAN',
            'acceso.usuario_rol' => 'id_usuario_rol INTEGER PRIMARY KEY, id_usuario INTEGER, id_rol INTEGER',
            'acceso.modulo' => 'id_modulo INTEGER PRIMARY KEY, nombre TEXT, url TEXT, estado BOOLEAN',
            'acceso.permiso' => 'id_permiso INTEGER PRIMARY KEY, id_modulo INTEGER, clave TEXT, estado BOOLEAN',
            'acceso.rol_modulo' => 'id_rol INTEGER, id_modulo INTEGER',
            'acceso.rol_modulo_permiso' => 'id_rol INTEGER, id_modulo INTEGER, id_permiso INTEGER',
            'certificado.actividad' => 'id_actividad INTEGER PRIMARY KEY, id_area INTEGER, nombre TEXT, estado TEXT',
            'certificado.actividad_sesion' => 'id_actividad_sesion INTEGER PRIMARY KEY, id_actividad INTEGER, nombre TEXT, estado TEXT',
            'certificado.participacion' => 'id_participacion INTEGER PRIMARY KEY, id_actividad INTEGER, id_persona INTEGER, estado TEXT',
            'certificado.asistencia' => 'id_asistencia INTEGER PRIMARY KEY, id_participacion INTEGER, id_actividad_sesion INTEGER, estado TEXT NOT NULL, hora_entrada TEXT, hora_salida TEXT, UNIQUE(id_participacion,id_actividad_sesion)',
            'certificado.certificado' => 'id_certificado INTEGER PRIMARY KEY, id_participacion INTEGER, id_persona INTEGER, id_tipo_certificado INTEGER, estado TEXT, fecha_emision DATE',
            'certificado.tipo_certificado' => 'id_tipo_certificado INTEGER PRIMARY KEY, nombre_tipo TEXT, estado BOOLEAN',
            'certificado.solicitud_actividad' => 'id_solicitud_actividad INTEGER PRIMARY KEY, id_actividad INTEGER, estado TEXT',
        ] as $table => $columns) {
            DB::statement("CREATE TABLE {$table} ({$columns})");
        }
    }

    private function authenticate(bool $administrator = true): void
    {
        DB::table('gestion.persona')->insert(['id_persona' => 1, 'nombres' => 'Prueba', 'apellido_paterno' => 'Aislada']);
        DB::table('acceso.usuario')->insert(['id_usuario' => 1, 'id_persona' => 1, 'correo' => 'isolated@example.test', 'estado' => true]);
        $role = $administrator ? 1 : 2;
        DB::table('acceso.rol')->insert(['id_rol' => $role, 'nombre' => $administrator ? 'Administrador' : 'Operador', 'estado' => true]);
        DB::table('acceso.usuario_rol')->insert(['id_usuario_rol' => 1, 'id_usuario' => 1, 'id_rol' => $role]);
        foreach (['participantes', 'actividades', 'emision'] as $index => $module) {
            $moduleId = $index + 1;
            DB::table('acceso.modulo')->insert(['id_modulo' => $moduleId, 'nombre' => $module, 'url' => '/'.$module, 'estado' => true]);
            DB::table('acceso.rol_modulo')->insert(['id_rol' => $role, 'id_modulo' => $moduleId]);
            foreach (['ver', 'crear', 'editar', 'eliminar'] as $action) {
                $permission = DB::table('acceso.permiso')->insertGetId(['id_modulo' => $moduleId, 'clave' => $module.'.'.$action, 'estado' => true], 'id_permiso');
                if (! $administrator) {
                    DB::table('acceso.rol_modulo_permiso')->insert(['id_rol' => $role, 'id_modulo' => $moduleId, 'id_permiso' => $permission]);
                }
            }
        }
        DB::table('gestion.area')->insert([['id_area' => 1, 'nombre' => 'Prueba A'], ['id_area' => 2, 'nombre' => 'Prueba B']]);
        DB::table('gestion.persona_trabajador')->insert(['id_persona_trabajador' => 1, 'id_persona' => 1, 'id_area' => 1, 'estado' => true, 'fecha_ingreso' => '2020-01-01']);
        DB::table('certificado.actividad')->insert([['id_actividad' => 1, 'id_area' => 1, 'nombre' => 'Prueba', 'estado' => 'Activa'], ['id_actividad' => 2, 'id_area' => 2, 'nombre' => 'Fuera de ámbito', 'estado' => 'Activa']]);
        DB::table('certificado.actividad_sesion')->insert([['id_actividad_sesion' => 1, 'id_actividad' => 1, 'nombre' => 'Primera', 'estado' => 'Activo'], ['id_actividad_sesion' => 2, 'id_actividad' => 2, 'nombre' => 'Otra', 'estado' => 'Activo']]);
        DB::table('certificado.participacion')->insert([['id_participacion' => 1, 'id_actividad' => 1, 'id_persona' => 1, 'estado' => 'Activo'], ['id_participacion' => 2, 'id_actividad' => 2, 'id_persona' => 1, 'estado' => 'Activo']]);
        DB::table('certificado.tipo_certificado')->insert(['id_tipo_certificado' => 1, 'nombre_tipo' => 'Capacitación', 'estado' => true]);
        DB::table('certificado.solicitud_actividad')->insert(['id_solicitud_actividad' => 1, 'id_actividad' => 1, 'estado' => 'Aprobada']);
        Auth::forgetGuards();
        $this->withCredentials()->withUnencryptedCookie(JwtService::COOKIE, app(JwtService::class)->createToken(User::findOrFail(1)));
    }

    private function seedAttendance(): void
    {
        DB::table('certificado.asistencia')->insert(['id_asistencia' => 1, 'id_participacion' => 1, 'id_actividad_sesion' => 1, 'estado' => 'PRESENTE', 'hora_entrada' => '08:00:30']);
    }

    private function enablePolicy(): void
    {
        config(['certification.development_closure_enabled' => true,
            'certification.development_session_type_ids' => [1],
            'certification.session_closure' => [
                'scope' => 'development', 'reference' => 'Isolated fixture',
                'active_participation_states' => ['Activo'], 'eligible_attendance_states' => ['PRESENTE'],
                'ineligible_attendance_states' => ['AUSENTE'], 'require_entry' => false, 'require_exit' => false,
                'regularizations' => 'not_applicable', 'final_state' => 'Finalizada', 'open_states' => ['Activo'],
            ]]);
        $this->partialMock(CertificationReadiness::class)->shouldReceive('developmentClosureBlockers')->andReturn([]);
    }

    public function test_valid_cookie_jwt_can_read_candidates_without_create_permission(): void
    {
        $this->authenticate(false);
        $this->seedAttendance();
        $this->enablePolicy();
        DB::table('certificado.actividad_sesion')->where('id_actividad_sesion', 1)->update(['estado' => 'Finalizada']);
        DB::table('acceso.permiso')->where('clave', 'emision.crear')->update(['estado' => false]);
        Auth::forgetGuards();
        DB::enableQueryLog();
        $this->getJson('/api/activity-session/1/beneficiarios?id_tipo_certificado=1')->assertOk()
            ->assertJsonPath('data.beneficiarios.data.0.estado_elegibilidad', 'Elegible')
            ->assertJsonPath('data.puede_consultar_beneficiarios', true)->assertJsonPath('data.puede_preparar', false);
        $permissionQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'rol_modulo_permiso'));
        self::assertCount(1, $permissionQueries);
        DB::disableQueryLog();
        $this->postJson('/api/activity-session/1/preparar-certificados', ['id_tipo_certificado' => 1])->assertForbidden();
        Auth::forgetGuards();
        $this->getJson('/api/activity-session/2/beneficiarios')->assertForbidden();
        DB::table('acceso.permiso')->where('clave', 'emision.ver')->update(['estado' => false]);
        Auth::forgetGuards();
        $this->getJson('/api/activity-session/1/beneficiarios')->assertForbidden();
        $this->assertDatabaseCount('certificado.certificado', 0);
    }

    public function test_expired_cookie_jwt_cannot_consult_beneficiaries(): void
    {
        config(['jwt.secret' => str_repeat('isolated-jwt-fixture-', 4)]);
        $token = JWT::encode(['sub' => 1, 'iat' => time() - 120, 'exp' => time() - 60], config('jwt.secret'), 'HS256');
        $this->withCredentials()->withUnencryptedCookie(JwtService::COOKIE, $token)
            ->getJson('/api/activity-session/1/beneficiarios')->assertUnauthorized();
    }
}
