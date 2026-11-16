<?php

namespace Tests\Feature;

use App\Http\Middleware\NormalizeAttendanceInput;
use App\Models\User\User;
use App\Services\Auth\JwtService;
use App\Services\CertificateAttendanceProtection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AttendanceHttpIntegrationTest extends TestCase
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

    private function prepareIndependentSessions(): void
    {
        $this->authenticate(false);
        DB::statement('ALTER TABLE certificado.certificado ADD COLUMN id_actividad_sesion INTEGER');
        DB::statement('CREATE TABLE certificado.certificado_documento (id_certificado_documento INTEGER PRIMARY KEY, id_certificado INTEGER, codigo_verificacion TEXT, hash_certificado TEXT, ruta_archivo TEXT, estado TEXT)');
        DB::table('certificado.actividad')->where('id_actividad', 1)->update(['nombre' => 'Capacitación Personal']);
        DB::table('certificado.actividad_sesion')->where('id_actividad_sesion', 1)->update(['nombre' => 'Sesión 001']);
        DB::table('certificado.actividad_sesion')->insert(['id_actividad_sesion' => 3, 'id_actividad' => 1, 'nombre' => 'Sesión 002', 'estado' => 'Activo']);
        DB::table('gestion.persona')->insert(['id_persona' => 3, 'nombres' => 'No seleccionado']);
        DB::table('certificado.participacion')->insert(['id_participacion' => 3, 'id_actividad' => 1, 'id_persona' => 3, 'estado' => 'Activo']);
        $this->seedAttendance();
        DB::table('certificado.certificado')->insert(['id_certificado' => 1, 'id_participacion' => 1, 'id_persona' => 1,
            'id_tipo_certificado' => 1, 'id_actividad_sesion' => 1, 'estado' => 'PENDIENTE_FIRMA']);
        Storage::fake('local');
        $pdf = '%PDF-1.4 Certificado sesión 001 con código y QR originales';
        Storage::disk('local')->put('certificados/original.pdf', $pdf);
        DB::table('certificado.certificado_documento')->insert(['id_certificado_documento' => 1, 'id_certificado' => 1,
            'codigo_verificacion' => 'CODIGO-ORIGINAL', 'hash_certificado' => hash('sha256', $pdf), 'ruta_archivo' => 'certificados/original.pdf', 'estado' => 'Vigente']);
    }

    public function test_session_002_attendance_is_independent_and_preserves_the_session_001_certificate_and_pdf(): void
    {
        $this->prepareIndependentSessions();
        $certificate = (array) DB::table('certificado.certificado')->first();
        $document = (array) DB::table('certificado.certificado_documento')->first();
        $pdf = Storage::disk('local')->get('certificados/original.pdf');
        $id = $this->postJson('/api/attendance/create', ['id_participacion' => 1, 'id_actividad_sesion' => 3, 'estado' => 'AUSENTE'])
            ->assertCreated()->json('data.ID_ASISTENCIA');
        $this->patchJson('/api/attendance/update/'.$id, ['estado' => 'PRESENTE'])->assertOk();
        $this->patchJson('/api/attendance/update/'.$id, ['estado' => 'AUSENTE'])->assertOk();
        $this->assertDatabaseHas('certificado.asistencia', ['id_asistencia' => 1, 'id_actividad_sesion' => 1, 'estado' => 'PRESENTE']);
        $this->assertDatabaseHas('certificado.asistencia', ['id_asistencia' => $id, 'id_actividad_sesion' => 3, 'estado' => 'AUSENTE']);
        $this->patchJson('/api/attendance/update/'.$id, ['id_actividad_sesion' => 1])->assertConflict();
        $this->patchJson('/api/attendance/update/1', ['estado' => 'AUSENTE'])->assertConflict();
        $this->deleteJson('/api/attendance/delete/1')->assertConflict();
        self::assertFalse(CertificateAttendanceProtection::forSession(3));
        self::assertTrue(CertificateAttendanceProtection::forSession(1));
        $this->deleteJson('/api/attendance/delete/'.$id)->assertOk();
        self::assertSame($certificate, (array) DB::table('certificado.certificado')->first());
        self::assertSame($document, (array) DB::table('certificado.certificado_documento')->first());
        self::assertSame($pdf, Storage::disk('local')->get('certificados/original.pdf'));
        $this->assertDatabaseCount('certificado.certificado', 1);
    }

    public function test_session_selection_is_explicit_additive_idempotent_and_does_not_mark_presence(): void
    {
        $this->prepareIndependentSessions();
        $this->getJson('/api/activity-session/3/participantes/candidatos')->assertOk()->assertJsonCount(2, 'data.participantes')
            ->assertJsonPath('data.participantes.0.seleccionado', false);
        $this->getJson('/api/activity-session/3/participantes')->assertOk()->assertJsonCount(0, 'data.participantes');
        foreach ([1, 2] as $attempt) {
            $this->postJson('/api/activity-session/3/participantes', ['ids_participacion' => [1]])->assertOk()
                ->assertJsonCount(1, 'data.participantes')->assertJsonPath('data.participantes.0.estado_asistencia', 'AUSENTE')
                ->assertJsonPath('data.participantes.0.hora_entrada', null);
        }
        $this->assertDatabaseCount('certificado.asistencia', 2);
        $this->assertDatabaseMissing('certificado.asistencia', ['id_participacion' => 3, 'id_actividad_sesion' => 3]);
        $this->postJson('/api/activity-session/3/participantes', ['ids_participacion' => [3, 2]])->assertUnprocessable();
        $this->assertDatabaseMissing('certificado.asistencia', ['id_participacion' => 3, 'id_actividad_sesion' => 3]);
        $this->deleteJson('/api/activity-session/3/participantes/1')->assertOk();
        $this->deleteJson('/api/activity-session/1/participantes/1')->assertConflict();
        $this->assertDatabaseHas('certificado.asistencia', ['id_participacion' => 1, 'id_actividad_sesion' => 1, 'estado' => 'PRESENTE']);
        $this->assertDatabaseCount('certificado.certificado', 1);
    }

    public function test_session_selection_requires_jwt_permissions_area_and_valid_unique_enrollments(): void
    {
        $this->getJson('/api/activity-session/1/participantes')->assertUnauthorized();
        $this->prepareIndependentSessions();
        $this->getJson('/api/activity-session/2/participantes')->assertNotFound();
        $this->postJson('/api/activity-session/3/participantes', ['ids_participacion' => [1, 1]])->assertUnprocessable();
        $this->postJson('/api/activity-session/3/participantes', ['ids_participacion' => [999]])->assertUnprocessable();
        DB::table('acceso.permiso')->where('clave', 'participantes.crear')->update(['estado' => false]);
        $this->postJson('/api/activity-session/3/participantes', ['ids_participacion' => [1]])->assertForbidden();
        $this->getJson('/api/activity-session/3/participantes/candidatos')->assertOk();
        DB::table('acceso.permiso')->where('clave', 'participantes.eliminar')->update(['estado' => false]);
        $this->deleteJson('/api/activity-session/1/participantes/1')->assertForbidden();
        $this->assertDatabaseCount('certificado.asistencia', 1);
    }

    private function seedAttendance(): void
    {
        DB::table('certificado.asistencia')->insert(['id_asistencia' => 1, 'id_participacion' => 1, 'id_actividad_sesion' => 1, 'estado' => 'PRESENTE', 'hora_entrada' => '08:00:30']);
    }

    public function test_lowercase_create_with_cookie_persists_and_is_retrievable_without_invented_hours(): void
    {
        $this->authenticate();
        $id = $this->postJson('/api/attendance/create', ['id_participacion' => 1, 'id_actividad_sesion' => 1, 'estado' => 'PRESENTE'])
            ->assertCreated()->assertJsonPath('data.ESTADO', 'PRESENTE')->assertJsonPath('data.HORA_ENTRADA', null)->json('data.ID_ASISTENCIA');
        $this->getJson('/api/attendance/'.$id)->assertOk()->assertJsonPath('data.ID_PARTICIPACION', 1)->assertJsonPath('data.ESTADO', 'PRESENTE');
        $this->assertDatabaseHas('certificado.asistencia', ['id_asistencia' => $id, 'estado' => 'PRESENTE', 'hora_entrada' => null, 'hora_salida' => null]);
    }

    public function test_lowercase_patch_persists_status_and_preserves_recorded_seconds(): void
    {
        $this->authenticate();
        $this->seedAttendance();
        $this->patchJson('/api/attendance/update/1', ['estado' => 'AUSENTE'])->assertOk()->assertJsonPath('data.ESTADO', 'AUSENTE')->assertJsonPath('data.HORA_ENTRADA', '08:00:30');
        $this->getJson('/api/attendance/1')->assertOk()->assertJsonPath('data.ESTADO', 'AUSENTE');
        $this->assertDatabaseHas('certificado.asistencia', ['id_asistencia' => 1, 'estado' => 'AUSENTE', 'hora_entrada' => '08:00:30']);
    }

    public function test_accepts_seconds_without_rounding_or_adding_exit_time(): void
    {
        $this->authenticate();
        $this->postJson('/api/attendance/create', ['ID_PARTICIPACION' => 1, 'ID_ACTIVIDAD_SESION' => 1, 'ESTADO' => 'PRESENTE', 'HORA_ENTRADA' => '08:00:31'])
            ->assertCreated()->assertJsonPath('data.HORA_ENTRADA', '08:00:31')->assertJsonPath('data.HORA_SALIDA', null);
    }

    public function test_conflicting_aliases_return_422_and_cannot_override_scope(): void
    {
        $this->authenticate(false);
        $this->postJson('/api/attendance/create', ['ID_PARTICIPACION' => 1, 'id_participacion' => 2, 'ID_ACTIVIDAD_SESION' => 1, 'ESTADO' => 'PRESENTE'])->assertUnprocessable();
        $this->postJson('/api/attendance/create', ['id_participacion' => 2, 'id_actividad_sesion' => 2, 'estado' => 'PRESENTE'])->assertForbidden();
        $this->assertDatabaseCount('certificado.asistencia', 0);
    }

    public function test_filtered_list_locates_existing_record_for_correction(): void
    {
        $this->authenticate();
        $this->seedAttendance();
        DB::table('certificado.asistencia')->insert(['id_asistencia' => 2, 'id_participacion' => 2, 'id_actividad_sesion' => 2, 'estado' => 'nn']);
        $this->getJson('/api/attendance/list?id_actividad=1&id_actividad_sesion=1&id_participacion=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.ID_ASISTENCIA', 1);
        $this->getJson('/api/attendance/list?id_actividad_sesion=999')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_unknown_patch_field_returns_422_instead_of_false_success(): void
    {
        $this->authenticate();
        $this->seedAttendance();
        $this->patchJson('/api/attendance/update/1', ['presente' => true])->assertUnprocessable();
        $this->assertDatabaseHas('certificado.asistencia', ['id_asistencia' => 1, 'estado' => 'PRESENTE']);
    }

    public function test_duplicate_create_does_not_overwrite_existing_record(): void
    {
        $this->authenticate();
        $this->seedAttendance();
        $this->postJson('/api/attendance/create', ['ID_PARTICIPACION' => 1, 'ID_ACTIVIDAD_SESION' => 1, 'ESTADO' => 'AUSENTE'])->assertUnprocessable();
        $this->assertDatabaseCount('certificado.asistencia', 1);
        $this->assertDatabaseHas('certificado.asistencia', ['id_asistencia' => 1, 'estado' => 'PRESENTE']);
    }

    public function test_administrator_uses_effective_permissions_without_blanket_bypass(): void
    {
        $this->authenticate();
        $this->seedAttendance();
        self::assertTrue(User::findOrFail(1)->isSystemAdministrator());
        self::assertContains('participantes.editar', User::findOrFail(1)->permissionKeys());
        DB::table('acceso.permiso')->where('clave', 'participantes.editar')->update(['estado' => false]);
        $this->patchJson('/api/attendance/update/1', ['ESTADO' => 'AUSENTE'])->assertForbidden();
        $this->assertDatabaseHas('certificado.asistencia', ['id_asistencia' => 1, 'estado' => 'PRESENTE']);
    }

    public function test_finalized_session_rejects_correction_with_409(): void
    {
        $this->authenticate();
        $this->seedAttendance();
        DB::table('certificado.actividad_sesion')->where('id_actividad_sesion', 1)->update(['estado' => 'Finalizada']);
        $this->patchJson('/api/attendance/update/1', ['ESTADO' => 'AUSENTE'])->assertConflict();
        $this->assertDatabaseHas('certificado.asistencia', ['id_asistencia' => 1, 'estado' => 'PRESENTE']);
    }

    public function test_missing_cookie_and_invalid_cookie_return_401(): void
    {
        $this->postJson('/api/attendance/create', [])->assertUnauthorized();
        $this->withCredentials()->withUnencryptedCookie(JwtService::COOKIE, 'invalid')->postJson('/api/attendance/create', [])->assertUnauthorized();
    }

    public function test_explicit_activity_context_cannot_be_silently_ignored(): void
    {
        $this->authenticate();
        $this->postJson('/api/attendance/create', ['id_actividad' => 2, 'id_participacion' => 1, 'id_actividad_sesion' => 1, 'estado' => 'PRESENTE'])->assertUnprocessable();
        $this->assertDatabaseCount('certificado.asistencia', 0);
        $this->seedAttendance();
        $this->patchJson('/api/attendance/update/1', ['id_actividad' => 2, 'estado' => 'AUSENTE'])->assertUnprocessable();
        $this->assertDatabaseHas('certificado.asistencia', ['id_asistencia' => 1, 'estado' => 'PRESENTE']);
    }

    public function test_without_normalization_lowercase_create_reproduces_422_with_valid_cookie_and_permissions(): void
    {
        $this->authenticate();
        $this->withoutMiddleware(NormalizeAttendanceInput::class);

        $this->postJson('/api/attendance/create', ['id_participacion' => 1, 'id_actividad_sesion' => 1, 'estado' => 'PRESENTE'])
            ->assertUnprocessable()->assertJsonStructure(['data' => ['ID_PARTICIPACION', 'ID_ACTIVIDAD_SESION', 'ESTADO']]);
        $this->assertDatabaseCount('certificado.asistencia', 0);
    }
}
