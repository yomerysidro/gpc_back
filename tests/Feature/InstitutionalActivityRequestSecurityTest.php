<?php

namespace Tests\Feature;

use App\Http\Data\Activity\ActivityData;
use App\Http\Middleware\ActivityRequests\AuthorizeActivityRequest;
use App\Models\User\User;
use App\Services\Auth\JwtService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class InstitutionalActivityRequestSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'database.connections.sqlite.url' => null,
            'jwt.secret' => str_repeat('isolated-test-secret-', 4), 'app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);
        DB::purge('sqlite');
        foreach (['acceso', 'gestion', 'certificado'] as $schema) {
            DB::statement("ATTACH DATABASE ':memory:' AS $schema");
        }
        $definitions = [
            'gestion.area' => 'id_area INTEGER PRIMARY KEY, nombre TEXT',
            'gestion.persona' => 'id_persona INTEGER PRIMARY KEY, nombres TEXT, apellido_paterno TEXT, apellido_materno TEXT, correo TEXT, fecha_registro TEXT',
            'gestion.persona_ponente' => 'id_persona_ponente INTEGER PRIMARY KEY, id_persona INTEGER, id_institucion INTEGER, cargo TEXT, especialidad TEXT',
            'gestion.persona_trabajador' => 'id_persona_trabajador INTEGER PRIMARY KEY, id_persona INTEGER, id_area INTEGER, cargo TEXT, fecha_ingreso TEXT, fecha_fin TEXT, estado BOOLEAN',
            'acceso.usuario' => 'id_usuario INTEGER PRIMARY KEY, id_persona INTEGER, correo TEXT, password_hash TEXT, estado BOOLEAN, intentos_fallidos INTEGER DEFAULT 0, bloqueado_hasta TEXT, ultimo_acceso TEXT',
            'acceso.rol' => 'id_rol INTEGER PRIMARY KEY, nombre TEXT, estado BOOLEAN',
            'acceso.usuario_rol' => 'id_usuario_rol INTEGER PRIMARY KEY, id_usuario INTEGER, id_rol INTEGER',
            'acceso.modulo' => 'id_modulo INTEGER PRIMARY KEY, nombre TEXT, url TEXT, imagen TEXT, estado BOOLEAN',
            'acceso.permiso' => 'id_permiso INTEGER PRIMARY KEY, id_modulo INTEGER, nombre TEXT, clave TEXT, estado BOOLEAN',
            'acceso.rol_modulo' => 'id_rol INTEGER, id_modulo INTEGER',
            'acceso.rol_modulo_permiso' => 'id_rol INTEGER, id_modulo INTEGER, id_permiso INTEGER',
            'certificado.tipo_actividad' => 'id_tipo_actividad INTEGER PRIMARY KEY, nombre TEXT, descripcion TEXT, estado BOOLEAN',
            'certificado.actividad' => 'id_actividad INTEGER PRIMARY KEY, id_area INTEGER, id_tipo_actividad INTEGER, nombre TEXT, descripcion TEXT, fecha_inicio TEXT, fecha_fin TEXT, sede TEXT, estado TEXT',
            'certificado.actividad_sesion' => 'id_actividad_sesion INTEGER PRIMARY KEY, id_actividad INTEGER, nombre TEXT, fecha TEXT, hora_inicio TEXT, hora_fin TEXT, lugar TEXT, estado TEXT',
            'certificado.participacion' => 'id_participacion INTEGER PRIMARY KEY, id_actividad INTEGER, id_persona INTEGER, id_tipo_participacion INTEGER, fecha_inscripcion TEXT, estado TEXT',
            'certificado.tipo_participacion' => 'id_tipo_participacion INTEGER PRIMARY KEY, nombre TEXT, estado BOOLEAN',
            'certificado.actividad_ponente' => 'id_actividad_ponente INTEGER PRIMARY KEY, id_actividad INTEGER, id_persona_ponente INTEGER',
            'certificado.asistencia' => 'id_asistencia INTEGER PRIMARY KEY, id_participacion INTEGER, id_actividad_sesion INTEGER, hora_entrada TEXT, hora_salida TEXT, estado TEXT',
            'certificado.certificado' => 'id_certificado INTEGER PRIMARY KEY, id_participacion INTEGER',
            'certificado.solicitud_actividad' => 'id_solicitud_actividad INTEGER PRIMARY KEY, id_area INTEGER, id_tipo_actividad INTEGER, id_usuario_solicitante INTEGER, nombre TEXT, descripcion TEXT, justificacion TEXT, fecha_inicio_propuesta TEXT, fecha_fin_propuesta TEXT, sede_propuesta TEXT, estado TEXT, fecha_solicitud TEXT, id_actividad INTEGER',
            'certificado.solicitud_decision' => 'id_solicitud_decision INTEGER PRIMARY KEY, id_solicitud_actividad INTEGER, id_usuario_responsable INTEGER, tipo_decision TEXT, estado_anterior TEXT, estado_nuevo TEXT, observacion TEXT, fecha_decision TEXT',
            'certificado.firma' => 'id_firma INTEGER PRIMARY KEY, id_persona_trabajador INTEGER, ruta_archivo_pfx TEXT, passphrase_cifrada TEXT, fecha_firma TEXT',
            'certificado.certificado_firma' => 'id_certificado_firma INTEGER PRIMARY KEY, id_certificado_documento INTEGER, id_firma INTEGER, fecha_firma TEXT, orden_firma INTEGER',
        ];
        foreach ($definitions as $table => $columns) {
            DB::statement('CREATE TABLE '.$table.' ('.$columns.')');
        }
        DB::table('gestion.area')->insert([['id_area' => 1, 'nombre' => 'Área A de prueba'], ['id_area' => 2, 'nombre' => 'Área B de prueba']]);
        DB::table('certificado.tipo_actividad')->insert(['id_tipo_actividad' => 1, 'nombre' => 'Tipo de prueba', 'estado' => true]);
        foreach ([1 => 'Administrador', 2 => 'Digitador', 3 => 'Gerencial', 4 => 'Solicitante de prueba'] as $id => $name) {
            DB::table('acceso.rol')->insert(['id_rol' => $id, 'nombre' => $name, 'estado' => true]);
        }
    }

    private function user(int $id, int $roleId, int $area = 1): User
    {
        DB::table('gestion.persona')->insert(['id_persona' => $id, 'nombres' => 'Prueba', 'apellido_paterno' => (string) $id, 'fecha_registro' => today()]);
        DB::table('acceso.usuario')->insert(['id_usuario' => $id, 'id_persona' => $id, 'correo' => 'actor'.$id.'@example.test', 'password_hash' => Hash::make('only-test-password'), 'estado' => true]);
        DB::table('acceso.usuario_rol')->insert(['id_usuario' => $id, 'id_rol' => $roleId]);
        DB::table('gestion.persona_trabajador')->insert(['id_persona_trabajador' => $id, 'id_persona' => $id, 'id_area' => $area, 'fecha_ingreso' => today(), 'estado' => true]);

        return User::findOrFail($id);
    }

    private function grant(int $roleId, array $permissions): void
    {
        foreach ($permissions as $key) {
            $slug = explode('.', $key)[0];
            $module = DB::table('acceso.modulo')->where('url', '/'.$slug)->value('id_modulo');
            if ($module === null) {
                $module = DB::table('acceso.modulo')->insertGetId(['url' => '/'.$slug, 'nombre' => $slug, 'estado' => true], 'id_modulo');
            }
            DB::table('acceso.rol_modulo')->updateOrInsert(['id_rol' => $roleId, 'id_modulo' => $module], []);
            $permission = DB::table('acceso.permiso')->where('clave', $key)->value('id_permiso');
            if ($permission === null) {
                $permission = DB::table('acceso.permiso')->insertGetId(['id_modulo' => $module, 'nombre' => $key, 'clave' => $key, 'estado' => true], 'id_permiso');
            }
            DB::table('acceso.rol_modulo_permiso')->updateOrInsert(['id_rol' => $roleId, 'id_modulo' => $module, 'id_permiso' => $permission], []);
        }
    }

    private function cookieFor(User $user): void
    {
        Auth::forgetGuards();
        $this->withCredentials()->withUnencryptedCookie(JwtService::COOKIE, app(JwtService::class)->createToken($user));
    }

    private function payload(int $area = 1): array
    {
        return ['id_area' => $area, 'id_tipo_actividad' => 1, 'nombre' => 'Actividad de prueba aislada', 'justificacion' => 'Prueba',
            'fecha_inicio_propuesta' => '2026-12-01', 'fecha_fin_propuesta' => '2026-12-02', 'descripcion' => null, 'sede_propuesta' => null];
    }

    private function actors(): array
    {
        $owner = $this->user(4, 4);
        $reviewer = $this->user(2, 2);
        $manager = $this->user(3, 3);
        $this->grant(4, ['solicitudes.crear', 'solicitudes.editar', 'solicitudes.enviar', 'solicitudes.propias']);
        $this->grant(2, ['solicitudes.consultar', 'solicitudes.revisar', 'solicitudes.derivar', 'solicitudes.observar', 'solicitudes.historial']);
        $this->grant(3, ['solicitudes.consultar', 'solicitudes.aprobar', 'solicitudes.rechazar', 'solicitudes.observar', 'solicitudes.historial']);

        return [$owner, $reviewer, $manager];
    }

    private function pending(array $actors): int
    {
        [$owner, $reviewer] = $actors;
        $this->cookieFor($owner);
        $id = $this->postJson('/api/activity-requests', $this->payload() + ['id_usuario_solicitante' => 99])->assertCreated()->assertJsonPath('data.id_usuario_solicitante', 4)->json('data.id_solicitud_actividad');
        $this->putJson('/api/activity-requests/'.$id, ['nombre' => 'Corrección de prueba'])->assertOk();
        $this->postJson('/api/activity-requests/'.$id.'/submit')->assertOk();
        $this->cookieFor($reviewer);
        $this->getJson('/api/activity-requests?estado=Enviada')->assertOk()->assertJsonPath('data.data.0.id_solicitud_actividad', $id);
        $this->postJson('/api/activity-requests/'.$id.'/review')->assertOk();
        $this->postJson('/api/activity-requests/'.$id.'/derive')->assertOk();

        return $id;
    }

    public function test_cookie_authenticated_http_flow_records_manager_and_links_one_activity(): void
    {
        $actors = $this->actors();
        $this->cookieFor($actors[0]);
        $this->getJson('/api/areas/list')->assertOk();
        $this->getJson('/api/ativicty-type?estado=1')->assertOk();
        $id = $this->pending($actors);
        $this->cookieFor($actors[2]);
        $approved = $this->postJson('/api/activity-requests/'.$id.'/approve', ['id_usuario_responsable' => 99])->assertOk()->assertJsonPath('data.estado', 'Aprobada');
        self::assertNotNull($approved->json('data.id_actividad'));
        self::assertSame(1, DB::table('certificado.actividad')->count());
        self::assertSame(3, (int) DB::table('certificado.solicitud_decision')->where('tipo_decision', 'APROBAR')->value('id_usuario_responsable'));
        $this->postJson('/api/activity-requests/'.$id.'/approve')->assertConflict();
        $this->getJson('/api/activity-requests/decisions/history?per_page=1')->assertOk()->assertJsonPath('data.total', 3)->assertJsonPath('data.per_page', 1);
        $this->getJson('/api/activity-requests/'.$id.'/history')->assertOk()->assertJsonCount(3, 'data');
        $this->cookieFor($actors[0]);
        $this->getJson('/api/activity-requests/mine')->assertOk()->assertJsonPath('data.total', 1);
        $this->putJson('/api/activity-requests/'.$id, ['nombre' => 'Cambio'])->assertConflict();
    }

    #[TestWith(['approve', 'Aprobada', 1])]
    #[TestWith(['reject', 'Rechazada', 0])]
    public function test_observation_correction_resubmission_and_formal_decision(string $action, string $state, int $activityCount): void
    {
        $actors = $this->actors();
        $this->cookieFor($actors[0]);
        $id = $this->postJson('/api/activity-requests', $this->payload())->assertCreated()->json('data.id_solicitud_actividad');
        $this->postJson('/api/activity-requests/'.$id.'/submit')->assertOk();
        $this->cookieFor($actors[2]);
        $this->getJson('/api/activity-requests?'.http_build_query(['estado' => 'Pendiente de aprobación']))->assertOk()->assertJsonPath('data.total', 0);
        $this->postJson('/api/activity-requests/'.$id.'/approve')->assertConflict();
        self::assertSame(0, DB::table('certificado.solicitud_decision')->count());
        $this->cookieFor($actors[1]);
        $this->postJson('/api/activity-requests/'.$id.'/observe', ['observacion' => 'Corregir'])->assertOk();
        $this->cookieFor($actors[0]);
        $this->putJson('/api/activity-requests/'.$id, ['justificacion' => 'Corrección'])->assertOk();
        $this->postJson('/api/activity-requests/'.$id.'/submit')->assertOk();
        $this->cookieFor($actors[1]);
        $this->postJson('/api/activity-requests/'.$id.'/review')->assertOk();
        $this->postJson('/api/activity-requests/'.$id.'/derive')->assertOk();
        $this->cookieFor($actors[2]);
        $this->getJson('/api/activity-requests?'.http_build_query(['estado' => 'Pendiente de aprobación']))->assertOk()->assertJsonPath('data.data.0.id_solicitud_actividad', $id);
        $this->getJson('/api/activity-requests/'.$id.'/history')->assertOk()
            ->assertJsonPath('data.0.tipo_decision', 'OBSERVAR')->assertJsonPath('data.0.responsable_nombre', 'Prueba 2')
            ->assertJsonPath('data.1.tipo_decision', 'REVISION_CONFORME')->assertJsonPath('data.2.tipo_decision', 'DERIVAR_GERENCIA');
        $this->postJson('/api/activity-requests/'.$id.'/'.$action, ['observacion' => 'Decisión de prueba', 'id_usuario_responsable' => 99])->assertOk()->assertJsonPath('data.estado', $state);
        $this->postJson('/api/activity-requests/'.$id.'/'.$action, ['observacion' => 'Repetición'])->assertConflict();
        self::assertSame($activityCount, DB::table('certificado.actividad')->count());
        self::assertSame(4, DB::table('certificado.solicitud_decision')->count());
        $this->getJson('/api/activity-requests?'.http_build_query(['estado' => 'Pendiente de aprobación']))->assertOk()->assertJsonPath('data.total', 0);
        $this->getJson('/api/activity-requests/'.$id.'/review-detail')->assertOk()->assertJsonPath('data.estado', $state);
        $this->getJson('/api/activity-requests/decisions/history?per_page=1')->assertOk()
            ->assertJsonPath('data.data.0.estado_anterior', 'Pendiente de aprobación')
            ->assertJsonPath('data.data.0.estado_nuevo', $state)
            ->assertJsonPath('data.data.0.observacion', 'Decisión de prueba')
            ->assertJsonPath('data.data.0.id_usuario_responsable', 3)
            ->assertJsonPath('data.data.0.tipo_decision', $action === 'approve' ? 'APROBAR' : 'RECHAZAR');
        $this->cookieFor($actors[0]);
        $this->getJson('/api/activity-requests/mine')->assertOk()->assertJsonPath('data.data.0.estado', $state);
        $this->getJson('/api/activity-requests/'.$id.'/history')->assertOk()
            ->assertJsonPath('data.3.estado_nuevo', $state)->assertJsonPath('data.3.id_usuario_responsable', 3);
        $this->cookieFor($actors[1]);
        $this->getJson('/api/activity-requests?'.http_build_query(['estado' => $state]))->assertOk()->assertJsonPath('data.data.0.estado', $state);
        $this->getJson('/api/activity-requests/'.$id.'/review-detail')->assertOk()->assertJsonPath('data.estado', $state);
        $this->cookieFor($actors[2]);
        if ($activityCount === 1) {
            $this->grant(3, ['actividades.ver']);
            $this->getJson('/api/activity')->assertOk()->assertJsonPath('data.0.id_actividad',
                (int) DB::table('certificado.solicitud_actividad')->where('id_solicitud_actividad', $id)->value('id_actividad'));
        }
    }

    public function test_area_scope_filters_and_rejects_requests_outside_real_memberships(): void
    {
        $actors = $this->actors();
        $id = $this->pending($actors);
        $other = $this->user(5, 4, 2);
        $this->cookieFor($other);
        $this->postJson('/api/activity-requests', $this->payload())->assertForbidden();
        $this->getJson('/api/activity-requests/'.$id)->assertForbidden();
        $this->postJson('/api/activity-requests/'.$id.'/approve')->assertForbidden();
        $this->getJson('/api/activity-requests/mine')->assertOk()->assertJsonPath('data.total', 0);
        $this->cookieFor($actors[1]);
        $this->withHeader('X-Area-Id', '2')->getJson('/api/activity-requests')->assertForbidden();
    }

    #[TestWith([[]])]
    #[TestWith([['observacion' => '']])]
    #[TestWith([['observacion' => '   ']])]
    public function test_rejection_without_a_nonempty_reason_returns_422_without_side_effects(array $payload): void
    {
        $actors = $this->actors();
        $id = $this->pending($actors);
        $this->cookieFor($actors[2]);

        $this->postJson('/api/activity-requests/'.$id.'/reject', $payload)->assertUnprocessable()
            ->assertJsonPath('success', false)->assertJsonStructure(['data' => ['observacion']]);

        self::assertSame('Pendiente de aprobación', DB::table('certificado.solicitud_actividad')->where('id_solicitud_actividad', $id)->value('estado'));
        self::assertNull(DB::table('certificado.solicitud_actividad')->where('id_solicitud_actividad', $id)->value('id_actividad'));
        self::assertSame(2, DB::table('certificado.solicitud_decision')->count());
        self::assertSame(0, DB::table('certificado.actividad')->count());
    }

    public function test_multiple_memberships_require_valid_selection_for_creation_and_cannot_expand_lists(): void
    {
        $actors = $this->actors();
        DB::table('gestion.persona_trabajador')->insert(['id_persona' => 4, 'id_area' => 2, 'fecha_ingreso' => today(), 'estado' => true]);
        $this->cookieFor($actors[0]);
        $this->postJson('/api/activity-requests', $this->payload(2))->assertCreated();
        $this->withHeader('X-Area-Id', '1')->getJson('/api/activity-requests/mine')->assertOk()->assertJsonPath('data.total', 0);
        $this->withHeader('X-Area-Id', '99')->getJson('/api/activity-requests/mine')->assertForbidden();
    }

    public function test_administrator_gets_active_menu_and_configured_formal_approval_authority(): void
    {
        $actors = $this->actors();
        $id = $this->pending($actors);
        $administrator = $this->user(1, 1);
        $this->grant(2, ['actividades.ver', 'areas.ver', 'ponentes-firmantes.editar']);
        $this->cookieFor($administrator);
        $this->getJson('/api/auth/me')->assertOk()->assertJsonCount(4, 'modulos')->assertJsonFragment(['actividades.ver']);
        $this->getJson('/api/areas/list')->assertOk();
        $this->getJson('/api/activity-requests')->assertOk();
        $this->postJson('/api/activity-requests/'.$id.'/approve')->assertOk();
        self::assertSame(1, (int) DB::table('certificado.solicitud_decision')->where('tipo_decision', 'APROBAR')->value('id_usuario_responsable'));
    }

    #[TestWith(['approve', 'Aprobada', false, 1])]
    #[TestWith(['reject', 'Rechazada', false, 0])]
    #[TestWith(['approve', 'Aprobada', true, 1])]
    #[TestWith(['reject', 'Rechazada', true, 0])]
    public function test_verified_administrator_decides_institutional_requests_as_himself(string $action, string $state, bool $own, int $activities): void
    {
        $actors = $this->actors();
        $id = $this->pending($actors);
        $administrator = $this->user(1, 1, 2);
        DB::table('gestion.persona_trabajador')->where('id_persona', 1)->delete();
        DB::table('certificado.solicitud_actividad')->where('id_solicitud_actividad', $id)
            ->update(['id_usuario_solicitante' => $own ? 1 : 3]);
        $this->cookieFor($administrator);
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('capacidades_solicitudes.aprobar', true)
            ->assertJsonPath('capacidades_solicitudes.rechazar', true)
            ->assertJsonPath('capacidades_solicitudes.decidir_ambito_institucional', true)
            ->assertJsonPath('capacidades_solicitudes.decidir_propias', true);

        $response = $this->postJson('/api/activity-requests/'.$id.'/'.$action,
            ['observacion' => 'Decisión administrativa institucional', 'id_usuario_responsable' => 3])->assertOk()->assertJsonPath('data.estado', $state);

        self::assertSame($activities, DB::table('certificado.actividad')->count());
        self::assertSame($own ? 1 : 3, (int) DB::table('certificado.solicitud_actividad')->where('id_solicitud_actividad', $id)->value('id_usuario_solicitante'));
        $decision = DB::table('certificado.solicitud_decision')->orderByDesc('id_solicitud_decision')->first();
        self::assertSame(1, (int) $decision->id_usuario_responsable);
        self::assertSame('Decisión administrativa institucional', $decision->observacion);
        self::assertSame($state, $decision->estado_nuevo);
        if ($activities === 1) {
            self::assertNotNull($response->json('data.id_actividad'));
        } else {
            self::assertNull($response->json('data.id_actividad'));
        }
        $this->postJson('/api/activity-requests/'.$id.'/'.$action, ['observacion' => 'Repetición'])->assertConflict();
        self::assertSame($activities, DB::table('certificado.actividad')->count());
    }

    public function test_administrator_cannot_use_an_inactive_formal_permission(): void
    {
        $actors = $this->actors();
        $id = $this->pending($actors);
        $administrator = $this->user(1, 1);
        DB::table('acceso.permiso')->where('clave', 'solicitudes.aprobar')->update(['estado' => false]);
        $this->cookieFor($administrator);

        $this->postJson('/api/activity-requests/'.$id.'/approve')->assertForbidden();
        self::assertSame(0, DB::table('certificado.actividad')->count());
    }

    public function test_self_approval_and_private_key_reassignment_are_forbidden(): void
    {
        $actors = $this->actors();
        $id = $this->pending($actors);
        DB::table('certificado.solicitud_actividad')->where('id_solicitud_actividad', $id)->update(['id_usuario_solicitante' => 3]);
        $this->cookieFor($actors[2]);
        $this->postJson('/api/activity-requests/'.$id.'/approve')->assertForbidden();
        $administrator = $this->user(1, 1);
        $this->grant(2, ['ponentes-firmantes.ver', 'ponentes-firmantes.editar']);
        DB::table('certificado.firma')->insert(['id_firma' => 1, 'id_persona_trabajador' => 2, 'ruta_archivo_pfx' => 'firmas/test.pfx', 'passphrase_cifrada' => 'test-only']);
        $this->cookieFor($administrator);
        $this->patchJson('/api/firmas/update/1', ['id_persona_trabajador' => 1])->assertForbidden();
        $this->getJson('/api/firmas/detail/1')->assertOk()->assertJsonMissingPath('data.passphrase_cifrada')->assertJsonMissingPath('data.ruta_archivo_pfx');
    }

    public function test_login_me_logout_and_missing_permissions_use_real_cookie_guard(): void
    {
        $user = $this->user(4, 4);
        $response = $this->postJson('/api/auth/login', ['email' => $user->correo, 'password' => 'only-test-password'])->assertOk();
        $cookie = $response->baseResponse->headers->getCookies()[0];
        self::assertTrue($cookie->isHttpOnly());
        $this->withCredentials()->withUnencryptedCookie('access_token', $cookie->getValue())->getJson('/api/auth/me')->assertOk();
        $this->getJson('/api/activity-requests')->assertForbidden();
        $this->postJson('/api/auth/logout')->assertOk();
        Auth::forgetGuards();
        $this->withUnencryptedCookie('access_token', '')->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_empty_and_invalid_payloads_and_anonymous_requests(): void
    {
        $this->postJson('/api/activity-requests', $this->payload())->assertUnauthorized();
        $actors = $this->actors();
        $this->cookieFor($actors[0]);
        $this->postJson('/api/activity-requests', [])->assertUnprocessable();
        $this->postJson('/api/activity-requests', array_replace($this->payload(), ['nombre' => str_repeat('á', 101)]))->assertUnprocessable();
        $this->getJson('/api/activity-requests/mine')->assertOk()->assertJsonPath('data.data', []);
    }

    public function test_permission_plan_is_read_only_by_default_and_idempotent_when_explicitly_applied_in_memory(): void
    {
        $this->artisan('security:prepare-permissions')->assertExitCode(0);
        self::assertSame(0, DB::table('acceso.permiso')->count());
        $this->artisan('security:prepare-permissions', ['--apply' => true])->assertExitCode(0);
        $count = DB::table('acceso.rol_modulo_permiso')->count();
        $this->artisan('security:prepare-permissions', ['--apply' => true])->assertExitCode(0);
        self::assertSame($count, DB::table('acceso.rol_modulo_permiso')->count());
        self::assertSame(0, DB::table('acceso.rol_modulo_permiso as r')->join('acceso.permiso as p', 'p.id_permiso', '=', 'r.id_permiso')->where('r.id_rol', 1)->where('p.clave', 'solicitudes.aprobar')->count());
    }

    #[TestWith(['worker'])]
    #[TestWith(['role'])]
    #[TestWith(['module'])]
    #[TestWith(['permission'])]
    #[TestWith(['assignment'])]
    public function test_revoked_scope_or_permission_prevents_a_formal_decision(string $revoked): void
    {
        $actors = $this->actors();
        $id = $this->pending($actors);
        match ($revoked) {
            'worker' => DB::table('gestion.persona_trabajador')->where('id_persona', 3)->update(['fecha_fin' => today()->subDay()->toDateString()]),
            'role' => DB::table('acceso.rol')->where('id_rol', 3)->update(['estado' => false]),
            'module' => DB::table('acceso.modulo')->where('url', '/solicitudes')->update(['estado' => false]),
            'permission' => DB::table('acceso.permiso')->where('clave', 'solicitudes.aprobar')->update(['estado' => false]),
            'assignment' => DB::table('acceso.rol_modulo')->where('id_rol', 3)->delete(),
        };
        $this->cookieFor($actors[2]);

        $this->postJson('/api/activity-requests/'.$id.'/approve')->assertForbidden();
        self::assertSame(0, DB::table('certificado.actividad')->count());
    }

    public function test_a_role_with_administrator_display_name_but_wrong_id_is_not_system_administrator(): void
    {
        $user = $this->user(4, 4);
        DB::table('acceso.rol')->where('id_rol', 4)->update(['nombre' => 'Administrador']);
        $this->cookieFor($user);

        self::assertFalse($user->isSystemAdministrator());
        $this->getJson('/api/roles')->assertForbidden();
    }

    public function test_system_administrator_can_review_across_areas_without_formal_approval_power(): void
    {
        $owner = $this->user(4, 4, 2);
        $administrator = $this->user(1, 1, 1);
        $this->grant(4, ['solicitudes.crear', 'solicitudes.enviar']);
        $this->grant(2, ['solicitudes.consultar', 'solicitudes.revisar', 'solicitudes.derivar']);
        $this->cookieFor($owner);
        $id = $this->postJson('/api/activity-requests', $this->payload(2))->assertCreated()->json('data.id_solicitud_actividad');
        $this->postJson('/api/activity-requests/'.$id.'/submit')->assertOk();
        $this->cookieFor($administrator);

        $this->postJson('/api/activity-requests/'.$id.'/review')->assertOk();
        $this->postJson('/api/activity-requests/'.$id.'/derive')->assertOk();
        $this->postJson('/api/activity-requests/'.$id.'/approve')->assertForbidden();
    }

    public function test_management_keeps_history_access_after_returning_a_derived_request_for_correction(): void
    {
        $actors = $this->actors();
        $id = $this->pending($actors);
        $this->cookieFor($actors[2]);
        $this->postJson('/api/activity-requests/'.$id.'/observe', ['observacion' => 'Corregir expediente'])->assertOk();

        $this->getJson('/api/activity-requests/'.$id.'/history')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/activity-requests')->assertOk()->assertJsonPath('data.total', 1);
        $this->getJson('/api/activity-requests/decisions/history')->assertOk()->assertJsonPath('data.total', 3);
    }

    public function test_manager_can_read_own_draft_without_approving_it(): void
    {
        $actors = $this->actors();
        $this->grant(3, ['solicitudes.crear', 'solicitudes.propias']);
        $this->cookieFor($actors[2]);
        $id = $this->postJson('/api/activity-requests', $this->payload())->assertCreated()->json('data.id_solicitud_actividad');

        $this->getJson('/api/activity-requests/'.$id)->assertOk()->assertJsonPath('data.estado', 'Borrador');
        $this->getJson('/api/activity-requests/'.$id.'/history')->assertOk()->assertJsonPath('data', []);
        $this->postJson('/api/activity-requests/'.$id.'/approve')->assertForbidden();
    }

    public function test_administrator_creation_permission_does_not_expand_area_representation(): void
    {
        config(['module_permissions.administrator_request_represent_all_areas' => false]);
        $administrator = $this->user(1, 1, 1);
        $this->grant(1, ['solicitudes.crear', 'solicitudes.enviar', 'solicitudes.propias']);
        $this->cookieFor($administrator);
        self::assertTrue($administrator->isSystemAdministrator());
        self::assertContains('solicitudes.crear', $administrator->permissionKeys());

        $this->postJson('/api/activity-requests', $this->payload(2))->assertForbidden()
            ->assertJsonPath('data.codigo', 'SOLICITUD_AREA_NO_AUTORIZADA')
            ->assertJsonPath('data.id_area_solicitada', 2)
            ->assertJsonPath('data.id_areas_autorizadas', [1]);
        self::assertSame(0, DB::table('certificado.solicitud_actividad')->count());

        $id = $this->postJson('/api/activity-requests', $this->payload(1))->assertCreated()
            ->assertJsonPath('data.id_area', 1)->assertJsonPath('data.id_usuario_solicitante', 1)
            ->json('data.id_solicitud_actividad');
        $this->postJson('/api/activity-requests/'.$id.'/submit')->assertOk();
        self::assertSame(1, DB::table('certificado.solicitud_actividad')->count());
        self::assertSame(0, DB::table('certificado.actividad')->count());
    }

    public function test_administrator_without_current_membership_receives_actionable_area_error(): void
    {
        config(['module_permissions.administrator_request_represent_all_areas' => false]);
        $administrator = $this->user(1, 1, 1);
        $this->grant(1, ['solicitudes.crear']);
        DB::table('gestion.persona_trabajador')->where('id_persona', 1)->update(['fecha_fin' => today()->subDay()->toDateString()]);
        $this->cookieFor($administrator);

        $this->postJson('/api/activity-requests', $this->payload(1))->assertForbidden()
            ->assertJsonPath('data.codigo', 'SOLICITUD_SIN_AREA_VIGENTE')
            ->assertJsonPath('data.id_areas_autorizadas', []);
        self::assertSame(0, DB::table('certificado.solicitud_actividad')->count());
    }

    #[TestWith([1, 1])]
    #[TestWith([4, 4])]
    public function test_authorized_areas_contract_returns_only_current_principal_database_areas(int $userId, int $roleId): void
    {
        config(['module_permissions.administrator_request_represent_all_areas' => false]);
        $user = $this->user($userId, $roleId, 1);
        $this->grant($roleId, ['solicitudes.crear']);
        $this->cookieFor($user);

        $this->getJson('/api/activity-requests/authorized-areas?id_usuario=999&id_persona=999&id_area=2')->assertOk()
            ->assertExactJson([
                'success' => true,
                'message' => 'Áreas autorizadas para solicitudes consultadas correctamente.',
                'data' => [['id_area' => 1, 'nombre' => 'Área A de prueba']],
            ]);
        self::assertSame(0, DB::table('certificado.solicitud_actividad')->count());
    }

    public function test_authorized_areas_reject_anonymous_and_users_without_creation_permission(): void
    {
        $this->getJson('/api/activity-requests/authorized-areas')->assertUnauthorized();
        $user = $this->user(4, 4);
        $this->cookieFor($user);
        $this->getJson('/api/activity-requests/authorized-areas')->assertForbidden();
        $this->grant(4, ['solicitudes.propias']);
        $this->cookieFor($user);
        $this->getJson('/api/activity-requests/authorized-areas')->assertForbidden();
    }

    public function test_authorized_areas_without_current_membership_is_empty_and_creation_stays_forbidden(): void
    {
        config(['module_permissions.administrator_request_represent_all_areas' => false]);
        $user = $this->user(1, 1);
        $this->grant(1, ['solicitudes.crear']);
        DB::table('gestion.persona_trabajador')->where('id_persona', 1)->update(['estado' => false]);
        $this->cookieFor($user);

        $this->getJson('/api/activity-requests/authorized-areas')->assertOk()->assertJsonPath('data', []);
        $this->postJson('/api/activity-requests', $this->payload(1))->assertForbidden()
            ->assertJsonPath('data.codigo', 'SOLICITUD_SIN_AREA_VIGENTE');
        self::assertSame(0, DB::table('certificado.solicitud_actividad')->count());
    }

    public function test_area_lookup_deduplicates_and_excludes_inactive_expired_and_future_memberships(): void
    {
        $user = $this->user(4, 4);
        $this->grant(4, ['solicitudes.crear']);
        DB::table('gestion.area')->insert([
            ['id_area' => 3, 'nombre' => 'Área futura de prueba'],
            ['id_area' => 4, 'nombre' => 'Área vencida de prueba'],
            ['id_area' => 5, 'nombre' => 'Área inactiva de prueba'],
        ]);
        DB::table('gestion.persona_trabajador')->insert([
            ['id_persona' => 4, 'id_area' => 1, 'estado' => true, 'fecha_ingreso' => today()->toDateString(), 'fecha_fin' => null],
            ['id_persona' => 4, 'id_area' => 2, 'estado' => true, 'fecha_ingreso' => today()->toDateString(), 'fecha_fin' => today()->toDateString()],
            ['id_persona' => 4, 'id_area' => 3, 'estado' => true, 'fecha_ingreso' => today()->addDay()->toDateString(), 'fecha_fin' => null],
            ['id_persona' => 4, 'id_area' => 4, 'estado' => true, 'fecha_ingreso' => today()->subDays(2)->toDateString(), 'fecha_fin' => today()->subDay()->toDateString()],
            ['id_persona' => 4, 'id_area' => 5, 'estado' => false, 'fecha_ingreso' => today()->toDateString(), 'fecha_fin' => null],
        ]);
        $this->cookieFor($user);

        $areas = $this->getJson('/api/activity-requests/authorized-areas')->assertOk()->assertJsonCount(2, 'data')->json('data');
        self::assertSame([1, 2], array_column($areas, 'id_area'));
        foreach ($areas as $area) {
            $this->postJson('/api/activity-requests', $this->payload($area['id_area']))->assertCreated()
                ->assertJsonPath('data.id_usuario_solicitante', 4)->assertJsonPath('data.id_area', $area['id_area']);
        }
        $this->postJson('/api/activity-requests', $this->payload(3))->assertForbidden()
            ->assertJsonPath('data.id_areas_autorizadas', [1, 2]);
        self::assertSame(2, DB::table('certificado.solicitud_actividad')->count());
    }

    public function test_authorized_area_query_failure_is_500_instead_of_a_permission_or_empty_response(): void
    {
        $user = $this->user(4, 4);
        $this->grant(4, ['solicitudes.crear']);
        $this->cookieFor($user);
        DB::statement('DROP TABLE gestion.persona_trabajador');

        $this->getJson('/api/activity-requests/authorized-areas')->assertInternalServerError()->assertJsonPath('success', false);
        $this->postJson('/api/activity-requests', $this->payload())->assertInternalServerError();
        self::assertSame(0, DB::table('certificado.solicitud_actividad')->count());
    }

    public function test_admin_consultation_capabilities_do_not_require_formal_decision_permission(): void
    {
        config(['module_permissions.administrator_formal_permissions' => [], 'module_permissions.administrator_request_institutional_scope' => false]);
        $actors = $this->actors();
        $administrator = $this->user(1, 1);
        $this->cookieFor($administrator);

        $this->getJson('/api/auth/me')->assertOk()
            ->assertJsonPath('capacidades_solicitudes.consultar_revision', true)
            ->assertJsonPath('capacidades_solicitudes.consultar_gerencia', true)
            ->assertJsonPath('capacidades_solicitudes.consulta_administrativa_global', true)
            ->assertJsonPath('capacidades_solicitudes.aprobar', false)
            ->assertJsonPath('capacidades_solicitudes.rechazar', false);
        $this->getJson('/api/activity-requests?estado='.urlencode('Pendiente de aprobación'))->assertOk()->assertJsonPath('data.total', 0);
        $this->cookieFor($actors[1]);
        $this->getJson('/api/auth/me')->assertOk()
            ->assertJsonPath('capacidades_solicitudes.consultar_revision', true)
            ->assertJsonPath('capacidades_solicitudes.consultar_gerencia', false)
            ->assertJsonPath('capacidades_solicitudes.aprobar', false);
        $this->cookieFor($actors[2]);
        $this->getJson('/api/auth/me')->assertOk()
            ->assertJsonPath('capacidades_solicitudes.consultar_revision', false)
            ->assertJsonPath('capacidades_solicitudes.consultar_gerencia', true)
            ->assertJsonPath('capacidades_solicitudes.aprobar', true)
            ->assertJsonPath('capacidades_solicitudes.rechazar', true);
    }

    public function test_administrator_reads_foreign_drafts_details_and_histories_without_labor_membership(): void
    {
        config(['module_permissions.administrator_request_represent_all_areas' => false]);
        $actors = $this->actors();
        $administrator = $this->user(1, 1);
        DB::table('gestion.persona_trabajador')->where('id_persona', 1)->delete();
        $this->cookieFor($actors[0]);
        $id = $this->postJson('/api/activity-requests', $this->payload())->assertCreated()->json('data.id_solicitud_actividad');
        $this->cookieFor($administrator);

        $this->getJson('/api/activity-requests')->assertOk()->assertJsonPath('data.total', 1);
        $this->getJson('/api/activity-requests/'.$id)->assertOk();
        $this->getJson('/api/activity-requests/'.$id.'/review-detail')->assertOk();
        $this->getJson('/api/activity-requests/'.$id.'/history')->assertOk()->assertJsonPath('data', []);
        $this->getJson('/api/activity-requests/decisions/history')->assertOk();
        $this->postJson('/api/activity-requests', $this->payload())->assertForbidden();
        $this->postJson('/api/activity-requests/'.$id.'/approve')->assertConflict();
    }

    public function test_rrhh_does_not_read_another_applicants_draft_before_submission(): void
    {
        $actors = $this->actors();
        $this->cookieFor($actors[0]);
        $id = $this->postJson('/api/activity-requests', $this->payload())->assertCreated()->json('data.id_solicitud_actividad');
        $this->cookieFor($actors[1]);

        $this->getJson('/api/activity-requests')->assertOk()->assertJsonPath('data.total', 0);
        $this->getJson('/api/activity-requests/'.$id.'/review-detail')->assertForbidden();
        $this->getJson('/api/activity-requests/'.$id.'/history')->assertForbidden();
        $this->cookieFor($actors[0]);
        $this->getJson('/api/activity-requests/'.$id)->assertOk();
        $this->postJson('/api/activity-requests/'.$id.'/submit')->assertOk();
        $this->cookieFor($actors[1]);
        $this->getJson('/api/activity-requests?estado=Enviada')->assertOk()->assertJsonPath('data.total', 1);
        $this->getJson('/api/activity-requests/'.$id.'/review-detail')->assertOk();
    }

    public function test_request_and_decision_read_contracts_include_real_names_without_private_user_fields(): void
    {
        $actors = $this->actors();
        $id = $this->pending($actors);
        $this->getJson('/api/activity-requests?per_page=1')->assertOk()
            ->assertJsonPath('data.total', 1)->assertJsonPath('data.per_page', 1)
            ->assertJsonPath('data.data.0.area_nombre', 'Área A de prueba')
            ->assertJsonPath('data.data.0.tipo_actividad_nombre', 'Tipo de prueba')
            ->assertJsonPath('data.data.0.solicitante_nombre', 'Prueba 4')
            ->assertJsonMissingPath('data.data.0.applicant')->assertJsonMissingPath('data.data.0.password_hash');
        $this->getJson('/api/activity-requests/'.$id.'/review-detail')->assertOk()
            ->assertJsonPath('data.solicitante_nombre', 'Prueba 4')->assertJsonPath('data.area_nombre', 'Área A de prueba');
        $this->getJson('/api/activity-requests/decisions/history?per_page=1')->assertOk()
            ->assertJsonPath('data.data.0.area_nombre', 'Área A de prueba')
            ->assertJsonPath('data.data.0.solicitante_nombre', 'Prueba 4')->assertJsonPath('data.data.0.responsable_nombre', 'Prueba 2');
        $this->cookieFor($actors[0]);
        $this->getJson('/api/activity-requests/'.$id)->assertOk()->assertJsonPath('data.solicitante_nombre', 'Prueba 4');
        $this->getJson('/api/activity-requests/mine')->assertOk()->assertJsonPath('data.data.0.area_nombre', 'Área A de prueba');
    }

    public function test_derivation_does_not_grant_a_manager_competence_over_another_area(): void
    {
        $actors = $this->actors();
        DB::table('gestion.persona_trabajador')->where('id_persona', 3)->update(['id_area' => 2]);
        $id = $this->pending($actors);
        $this->cookieFor($actors[2]);
        $this->getJson('/api/activity-requests?'.http_build_query(['estado' => 'Pendiente de aprobación']))->assertOk()->assertJsonPath('data.total', 0);
        $this->getJson('/api/activity-requests/'.$id)->assertForbidden()->assertJsonPath('data.codigo', 'SOLICITUD_AREA_NO_AUTORIZADA');
        $this->postJson('/api/activity-requests/'.$id.'/approve')->assertForbidden();
        self::assertSame('Pendiente de aprobación', DB::table('certificado.solicitud_actividad')->where('id_solicitud_actividad', $id)->value('estado'));
        self::assertSame(0, DB::table('certificado.actividad')->count());
    }

    private function directActivity(int $area = 1): array
    {
        return ['id_area' => $area, 'id_tipo_actividad' => 1, 'nombre' => 'Registro directo aislado',
            'fecha_inicio' => '2026-12-01', 'fecha_fin' => '2026-12-02', 'estado' => 'Activa'];
    }

    public function test_administrator_represents_all_request_areas_but_cannot_create_activities_directly(): void
    {
        Log::spy();
        $administrator = $this->user(1, 1);
        $this->grant(2, ['actividades.ver', 'actividades.crear', 'actividades.editar', 'solicitudes.crear']);
        $this->cookieFor($administrator);
        $areas = $this->getJson('/api/activity-requests/authorized-areas')->assertOk()->assertJsonCount(2, 'data')->json('data');
        $this->getJson('/api/areas/list?uso=actividades')->assertForbidden();

        $id = null;
        foreach ($areas as $area) {
            $id = $this->postJson('/api/activity-requests', $this->payload($area['id_area']) + ['id_usuario_solicitante' => 99])->assertCreated()
                ->assertJsonPath('data.id_usuario_solicitante', 1)->assertJsonPath('data.id_area', $area['id_area'])->json('data.id_solicitud_actividad');
        }
        $this->postJson('/api/activity', $this->directActivity(2))->assertForbidden();
        self::assertSame(2, DB::table('certificado.solicitud_actividad')->count());
        self::assertSame(0, DB::table('certificado.actividad')->count());
        self::assertSame(0, DB::table('certificado.solicitud_decision')->count());
        Log::shouldHaveReceived('info')->with('solicitud.representacion_administrativa', ['id_solicitud_actividad' => $id, 'id_usuario_registrador' => 1, 'id_area_solicitante' => 2])->once();
    }

    public function test_common_activity_creator_is_restricted_to_current_areas(): void
    {
        $user = $this->user(4, 4);
        $this->grant(4, ['actividades.ver', 'actividades.crear', 'actividades.editar', 'actividades.eliminar']);
        $this->cookieFor($user);
        $this->getJson('/api/areas/list?uso=actividades')->assertForbidden();
        $this->postJson('/api/activity', $this->directActivity())->assertForbidden();
        $id = DB::table('certificado.actividad')->insertGetId($this->directActivity(), 'id_actividad');
        $other = DB::table('certificado.actividad')->insertGetId($this->directActivity(2), 'id_actividad');
        $this->postJson('/api/activity', $this->directActivity(2))->assertForbidden();
        $this->getJson('/api/activity')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id_actividad', $id);
        $this->getJson('/api/activity/'.$other)->assertForbidden();
        $this->patchJson('/api/activity/'.$other, ['estado' => 'Cerrada'])->assertForbidden();
        $this->patchJson('/api/activity/'.$id, ['id_area' => 2])->assertForbidden();
        $this->deleteJson('/api/activity/'.$other)->assertForbidden();
        DB::table('gestion.persona_trabajador')->where('id_persona', 4)->update(['estado' => false]);
        $this->getJson('/api/activity')->assertOk()->assertJsonPath('data', []);
        $this->postJson('/api/activity', $this->directActivity())->assertForbidden();
        self::assertSame(2, DB::table('certificado.actividad')->count());
    }

    public function test_request_approval_origin_prevents_recreation_or_deletion_through_direct_crud(): void
    {
        $actors = $this->actors();
        $id = $this->pending($actors);
        $this->cookieFor($actors[2]);
        $activity = $this->postJson('/api/activity-requests/'.$id.'/approve')->assertOk()->json('data.id_actividad');
        $this->grant(3, ['actividades.ver', 'actividades.crear', 'actividades.eliminar']);
        $this->getJson('/api/activity/'.$activity)->assertOk()->assertJsonPath('data.origen', 'solicitud')->assertJsonPath('data.id_solicitud_actividad', $id);
        $this->getJson('/api/activity')->assertOk()->assertJsonPath('data.0.id_solicitud_actividad', $id);
        $this->postJson('/api/activity', $this->directActivity() + ['id_solicitud_actividad' => $id])->assertForbidden();
        $this->postJson('/api/activity-requests/'.$id.'/approve')->assertConflict();
        $this->deleteJson('/api/activity/'.$activity)->assertConflict();
        self::assertSame(1, DB::table('certificado.actividad')->count());
        self::assertSame($activity, (int) DB::table('certificado.solicitud_actividad')->where('id_solicitud_actividad', $id)->value('id_actividad'));
    }

    #[TestWith([['estado' => 'ACTIVO']])]
    #[TestWith([['fecha_fin' => '2026-11-30']])]
    #[TestWith([['nombre' => '']])]
    #[TestWith([['id_area' => 99]])]
    #[TestWith([['id_usuario_responsable' => 1]])]
    public function test_direct_activity_invalid_payload_has_no_side_effects(array $changes): void
    {
        $user = $this->user(4, 4);
        $this->grant(4, ['actividades.crear']);
        $this->cookieFor($user);
        $this->postJson('/api/activity', array_replace($this->directActivity(), $changes))->assertForbidden();
        self::assertSame(0, DB::table('certificado.actividad')->count());
        self::assertSame(0, DB::table('certificado.solicitud_actividad')->count());
    }

    public function test_session_and_participant_queries_respect_activity_area_and_admin_scope(): void
    {
        $user = $this->user(4, 4);
        $administrator = $this->user(1, 1);
        $this->grant(4, ['actividades.ver', 'participantes.ver']);
        foreach ([1, 2] as $area) {
            DB::table('certificado.actividad')->insert(['id_actividad' => $area, ...$this->directActivity($area)]);
            DB::table('certificado.actividad_sesion')->insert(['id_actividad_sesion' => $area, 'id_actividad' => $area]);
            DB::table('certificado.participacion')->insert(['id_participacion' => $area, 'id_actividad' => $area]);
        }
        $this->cookieFor($user);
        foreach (['activity-session', 'participation'] as $route) {
            $this->getJson('/api/'.$route)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id_actividad', 1);
            $this->getJson('/api/'.$route.'/2')->assertForbidden();
        }
        $this->cookieFor($administrator);
        foreach (['activity-session', 'participation'] as $route) {
            $this->getJson('/api/'.$route)->assertOk()->assertJsonCount(2, 'data');
            $this->getJson('/api/'.$route.'/2')->assertOk();
        }
    }

    public function test_request_permission_does_not_grant_direct_activity_creation(): void
    {
        $user = $this->user(4, 4);
        $this->grant(4, ['solicitudes.crear']);
        $this->cookieFor($user);
        $this->postJson('/api/activity', $this->directActivity())->assertForbidden();
        $this->getJson('/api/areas/list?uso=actividades')->assertForbidden();
    }

    public function test_approved_activity_accepts_participants_speakers_sessions_and_attendance_without_new_activity(): void
    {
        $actors = $this->actors();
        $id = $this->pending($actors);
        $this->cookieFor($actors[2]);
        $activity = $this->postJson('/api/activity-requests/'.$id.'/approve')->assertOk()->json('data.id_actividad');
        $this->grant(2, ['actividades.ver', 'actividades.crear', 'actividades.editar', 'participantes.ver', 'participantes.crear',
            'ponentes-firmantes.ver', 'ponentes-firmantes.crear']);
        DB::table('certificado.tipo_participacion')->insert(['id_tipo_participacion' => 1, 'nombre' => 'Municipal', 'estado' => true]);
        DB::table('gestion.persona_ponente')->insert([['id_persona_ponente' => 1, 'id_persona' => 3], ['id_persona_ponente' => 2, 'id_persona' => 2]]);
        $this->cookieFor($actors[1]);
        $payload = ['id_actividad' => $activity, 'id_persona' => 4, 'id_tipo_participacion' => 1, 'fecha_inscripcion' => '2026-12-01', 'estado' => 'Activo'];
        $participation = $this->postJson('/api/participation', $payload)->assertCreated()->json('data.id_participacion');
        $this->postJson('/api/participation', $payload)->assertConflict();
        $speaker = ['id_actividad' => $activity, 'id_persona_ponente' => 1];
        $this->postJson('/api/activity-speaker', $speaker)->assertCreated();
        $this->postJson('/api/activity-speaker', $speaker)->assertConflict();
        $this->postJson('/api/activity-speaker', ['id_actividad' => $activity, 'id_persona_ponente' => 2])->assertCreated();
        $this->getJson('/api/activity-speaker')->assertOk()->assertJsonCount(2, 'data');
        $session = $this->postJson('/api/activity-session', ['id_actividad' => $activity, 'nombre' => 'Sesión autorizada',
            'fecha' => '2026-12-01', 'hora_inicio' => '09:00', 'hora_fin' => '10:00', 'estado' => 'Activa'])->assertCreated()->json('data.id_actividad_sesion');
        $attendance = ['ID_PARTICIPACION' => $participation, 'ID_ACTIVIDAD_SESION' => $session, 'HORA_ENTRADA' => '09:00', 'ESTADO' => 'Presente'];
        $this->postJson('/api/attendance/create', $attendance)->assertCreated();
        $this->postJson('/api/attendance/create', $attendance)->assertUnprocessable();
        $this->patchJson('/api/activity/'.$activity, ['nombre' => 'Cambio no autorizado'])->assertConflict();
        $this->patchJson('/api/activity/'.$activity, ['estado' => 'Cerrada'])->assertOk()->assertJsonPath('data.id_solicitud_actividad', $id);
        self::assertSame(1, DB::table('certificado.actividad')->count());
        self::assertSame(1, DB::table('certificado.participacion')->count());
        self::assertSame(2, DB::table('certificado.actividad_ponente')->count());
        $other = $this->user(5, 4, 2);
        $this->grant(4, ['actividades.ver', 'ponentes-firmantes.ver', 'ponentes-firmantes.crear', 'participantes.ver', 'participantes.crear']);
        $this->cookieFor($other);
        $this->getJson('/api/activity-speaker')->assertOk()->assertJsonPath('data', []);
        $this->getJson('/api/attendance/list')->assertOk()->assertJsonPath('data', []);
        $this->postJson('/api/activity-speaker', $speaker)->assertForbidden();
        $this->postJson('/api/attendance/create', $attendance)->assertForbidden();
        DB::table('acceso.rol')->insert(['id_rol' => 5, 'nombre' => 'Sin atribuciones de prueba', 'estado' => true]);
        $this->cookieFor($this->user(6, 5));
        $this->postJson('/api/attendance/create', $attendance)->assertForbidden();
    }

    public function test_pending_state_without_recorded_review_and_derivation_cannot_create_an_activity(): void
    {
        $actors = $this->actors();
        $this->cookieFor($actors[0]);
        $id = $this->postJson('/api/activity-requests', $this->payload())->assertCreated()->json('data.id_solicitud_actividad');
        DB::table('certificado.solicitud_actividad')->where('id_solicitud_actividad', $id)->update(['estado' => 'Pendiente de aprobación']);
        $this->cookieFor($actors[2]);
        $this->postJson('/api/activity-requests/'.$id.'/approve')->assertConflict();
        self::assertSame(0, DB::table('certificado.actividad')->count());
        self::assertSame(0, DB::table('certificado.solicitud_decision')->count());
    }

    public function test_programmatic_direct_creation_is_disabled_and_routes_remain_centralized(): void
    {
        $response = ActivityData::createActivity($this->directActivity());
        self::assertFalse($response['success']);
        self::assertSame(403, $response['status']);
        self::assertSame(0, DB::table('certificado.actividad')->count());
        $routes = array_values(array_filter(app('router')->getRoutes()->getRoutes(), fn ($route) => str_starts_with($route->uri(), 'api/activity-requests')));
        self::assertCount(15, $routes);
        foreach ($routes as $route) {
            self::assertContains(AuthorizeActivityRequest::class, $route->gatherMiddleware());
            self::assertContains('auth.cookie', $route->gatherMiddleware());
        }
        self::assertFileDoesNotExist(base_path('routes/activity_requests_development.php'));
    }

    public function test_rejection_only_manager_has_the_same_approval_process_read_scope(): void
    {
        $actors = $this->actors();
        $pendingId = $this->pending($actors);
        $this->cookieFor($actors[0]);
        $draftId = $this->postJson('/api/activity-requests', $this->payload())->assertCreated()->json('data.id_solicitud_actividad');
        $sentId = $this->postJson('/api/activity-requests', $this->payload())->assertCreated()->json('data.id_solicitud_actividad');
        $this->postJson('/api/activity-requests/'.$sentId.'/submit')->assertOk();
        $permissionId = DB::table('acceso.permiso')->where('clave', 'solicitudes.aprobar')->value('id_permiso');
        DB::table('acceso.rol_modulo_permiso')->where('id_rol', 3)->where('id_permiso', $permissionId)->delete();
        $this->cookieFor($actors[2]);

        $this->getJson('/api/auth/me')->assertOk()
            ->assertJsonPath('capacidades_solicitudes.consultar_gerencia', true)
            ->assertJsonPath('capacidades_solicitudes.aprobar', false)
            ->assertJsonPath('capacidades_solicitudes.rechazar', true);
        $this->getJson('/api/activity-requests')->assertOk()->assertJsonPath('data.total', 1);
        $this->getJson('/api/activity-requests/'.$draftId)->assertForbidden();
        $this->getJson('/api/activity-requests/'.$sentId)->assertForbidden();
        $this->postJson('/api/activity-requests/'.$pendingId.'/approve')->assertForbidden();
        $this->postJson('/api/activity-requests/'.$pendingId.'/reject', ['observacion' => 'Decisión aislada de prueba'])->assertOk();
    }
}
