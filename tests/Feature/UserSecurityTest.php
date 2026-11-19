<?php

namespace Tests\Feature;

use App\Models\Activity\Activity;
use App\Models\User\User;
use App\Services\Auth\JwtService;
use Firebase\JWT\JWT;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Route as RegisteredRoute;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UserSecurityTest extends TestCase
{
    private const REQUESTS = [
        ['GET', '/api/usuarios'], ['GET', '/api/usuarios/1'], ['POST', '/api/usuarios'],
        ['PUT', '/api/usuarios/1'], ['PATCH', '/api/usuarios/1/estado'],
        ['PATCH', '/api/usuarios/1/desbloquear'], ['POST', '/api/usuarios/1/restablecer-password'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'database.connections.sqlite.url' => null, 'jwt.secret' => str_repeat('fixture-only-', 8), 'jwt.ttl' => 10, 'app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);
        DB::purge('sqlite');
        foreach (['acceso', 'gestion', 'certificado'] as $schema) {
            DB::statement("ATTACH DATABASE ':memory:' AS $schema");
        }
        $tables = [
            'gestion.persona' => ['id_persona', 'nombres apellido_paterno apellido_materno telefono direccion correo fecha_registro'],
            'gestion.tipo_documento' => ['id_tipo_documento', 'nombre estado'],
            'gestion.persona_documento' => ['id_persona_documento', 'id_persona id_tipo_documento numero_documento estado'],
            'gestion.area' => ['id_area', 'nombre'],
            'gestion.persona_trabajador' => ['id_persona_trabajador', 'id_persona id_area estado fecha_ingreso fecha_fin'],
            'acceso.usuario' => ['id_usuario', 'id_persona correo password_hash estado intentos_fallidos bloqueado_hasta ultimo_acceso'],
            'acceso.rol' => ['id_rol', 'nombre estado'],
            'acceso.modulo' => ['id_modulo', 'nombre url imagen estado'],
            'acceso.permiso' => ['id_permiso', 'id_modulo nombre clave valor comentario estado'],
            'acceso.usuario_rol' => ['id_usuario_rol', 'id_usuario id_rol'],
            'acceso.rol_modulo' => ['id_rol_modulo', 'id_rol id_modulo'],
            'acceso.rol_modulo_permiso' => ['id_rol_modulo_permiso', 'id_rol id_modulo id_permiso'],
            'certificado.actividad' => ['id_actividad', 'id_area id_tipo_actividad nombre descripcion fecha_inicio fecha_fin sede estado'],
        ];
        foreach ($tables as $name => [$key, $fields]) {
            Schema::create($name, function (Blueprint $table) use ($key, $fields, $name) {
                $table->increments($key);
                foreach (explode(' ', $fields) as $field) {
                    if (str_starts_with($field, 'id_') || $field === 'intentos_fallidos') {
                        $table->integer($field)->nullable()->default(0);
                    } elseif ($field === 'estado') {
                        $table->boolean($field)->default(true);
                    } else {
                        $table->string($field)->nullable();
                    }
                }
                if ($name === 'acceso.usuario') {
                    $table->unique('correo');
                }
            });
        }
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    private function actor(string $roleName = 'Fixture'): User
    {
        DB::table('gestion.persona')->insert(['id_persona' => 1, 'nombres' => 'Usuario', 'apellido_paterno' => 'Prueba', 'fecha_registro' => '2026-01-01']);
        DB::table('gestion.tipo_documento')->insert(['id_tipo_documento' => 1, 'nombre' => 'DNI', 'estado' => true]);
        DB::table('gestion.persona_documento')->insert(['id_persona' => 1, 'id_tipo_documento' => 1, 'numero_documento' => '12345678', 'estado' => 'Activo']);
        DB::table('acceso.usuario')->insert(['id_usuario' => 1, 'id_persona' => 1, 'correo' => 'actor@example.test', 'password_hash' => Hash::make('fixture-password'), 'estado' => true, 'intentos_fallidos' => 0]);
        DB::table('acceso.rol')->insert(['id_rol' => 1, 'nombre' => $roleName, 'estado' => true]);
        DB::table('acceso.usuario_rol')->insert(['id_usuario' => 1, 'id_rol' => 1]);

        return User::findOrFail(1);
    }

    private function grant(array $keys): void
    {
        foreach ($keys as $key) {
            [$slug] = explode('.', $key, 2);
            $module = DB::table('acceso.modulo')->where('url', '/'.$slug)->value('id_modulo');
            if (! $module) {
                $module = DB::table('acceso.modulo')->insertGetId(['nombre' => $slug, 'url' => '/'.$slug, 'estado' => true], 'id_modulo');
                DB::table('acceso.rol_modulo')->insert(['id_rol' => 1, 'id_modulo' => $module]);
            }
            $permission = DB::table('acceso.permiso')->insertGetId(['id_modulo' => $module, 'nombre' => $key, 'clave' => $key, 'estado' => true], 'id_permiso');
            DB::table('acceso.rol_modulo_permiso')->insert(['id_rol' => 1, 'id_modulo' => $module, 'id_permiso' => $permission]);
        }
    }

    private function cookieFor(User $user): void
    {
        Auth::forgetGuards();
        $this->withCredentials()->withUnencryptedCookie(JwtService::COOKIE, app(JwtService::class)->createToken($user));
    }

    public function test_all_user_operations_require_authentication_without_redirecting(): void
    {
        foreach (self::REQUESTS as [$method, $uri]) {
            $this->call($method, $uri)->assertUnauthorized()->assertHeaderMissing('Location')->assertExactJson(['message' => 'No autenticado']);
        }
    }

    public function test_authentication_does_not_grant_user_administration(): void
    {
        $this->cookieFor($this->actor());
        foreach (self::REQUESTS as [$method, $uri]) {
            $this->json($method, $uri)->assertForbidden();
        }
    }

    public function test_user_read_permission_does_not_grant_write_permission(): void
    {
        $user = $this->actor();
        $this->grant(['usuarios.ver']);
        $this->cookieFor($user);
        $this->getJson('/api/usuarios')->assertOk()->assertJsonMissingPath('data.0.password_hash');
        $this->getJson('/api/usuarios/1')->assertOk()->assertJsonPath('data.nombre', 'Usuario Prueba');
        foreach (array_slice(self::REQUESTS, 2) as [$method, $uri]) {
            $this->json($method, $uri, ['correo' => 'denied@example.test'])->assertForbidden();
        }
        $this->assertDatabaseHas('acceso.usuario', ['id_usuario' => 1, 'correo' => 'actor@example.test']);
    }

    public function test_login_issues_an_institutional_cookie_and_me_resolves_the_shared_persona(): void
    {
        $this->actor();
        $this->grant(['actividades.ver']);
        $response = $this->postJson('/api/auth/login', ['email' => 'actor@example.test', 'password' => 'fixture-password'])->assertOk()->assertJsonPath('user.nombre', 'Usuario Prueba')->assertJsonMissingPath('user.password_hash');
        $cookie = $response->baseResponse->headers->getCookies()[0];
        $this->assertSame('access_token', $cookie->getName());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('strict', $cookie->getSameSite());
        $this->assertSame(1, (int) app(JwtService::class)->decode($cookie->getValue())->sub);
        Auth::forgetGuards();
        $this->withCredentials()->withUnencryptedCookie('access_token', $cookie->getValue())->getJson('/api/auth/me')->assertOk()->assertJsonPath('user.id', 1)->assertJsonPath('permisos.0', 'actividades.ver');
    }

    public function test_angular_login_preflight_allows_credentials_without_duplicate_cors_headers(): void
    {
        $response = $this->options('/api/auth/login', [], [
            'Origin' => 'http://localhost:4200',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'content-type',
        ]);

        $response->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:4200')
            ->assertHeader('Access-Control-Allow-Credentials', 'true')
            ->assertHeader('Access-Control-Allow-Methods', 'POST')
            ->assertHeader('Access-Control-Allow-Headers', 'content-type');
        $this->assertSame(['http://localhost:4200'], $response->baseResponse->headers->all('access-control-allow-origin'));
        $this->assertSame(['true'], $response->baseResponse->headers->all('access-control-allow-credentials'));
    }

    public function test_preflight_does_not_reflect_an_unconfigured_origin_or_a_wildcard(): void
    {
        $this->options('/api/auth/login', [], [
            'Origin' => 'http://untrusted.example',
            'Access-Control-Request-Method' => 'POST',
        ])->assertNoContent()->assertHeader('Access-Control-Allow-Origin', 'http://localhost:4200');
    }

    public function test_me_without_a_cookie_returns_401_with_angular_cors_headers(): void
    {
        $this->withHeader('Origin', 'http://localhost:4200')->getJson('/api/auth/me')
            ->assertUnauthorized()->assertHeaderMissing('Location')
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:4200')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
    }

    public function test_angular_can_login_over_local_http_and_send_the_httponly_cookie_to_me(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        $this->actor();
        $response = $this->withHeader('Origin', 'http://localhost:4200')
            ->postJson('/api/auth/login', ['email' => 'actor@example.test', 'password' => 'fixture-password'])
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:4200')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
        $cookie = $response->baseResponse->headers->getCookies()[0];
        $this->assertSame('access_token', $cookie->getName());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertFalse($cookie->isSecure());
        $this->assertSame('strict', $cookie->getSameSite());
        $this->assertSame('/', $cookie->getPath());
        $this->assertNull($cookie->getDomain());

        Auth::forgetGuards();
        $me = $this->withCredentials()->withUnencryptedCookie('access_token', $cookie->getValue())
            ->getJson('/api/auth/me')->assertOk()->assertJsonPath('user.id', 1)
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:4200')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
        $this->assertSame(['http://localhost:4200'], $me->baseResponse->headers->all('access-control-allow-origin'));
    }

    public function test_jwt_user_is_shared_by_request_auth_and_module_gates(): void
    {
        $user = $this->actor();
        DB::table('gestion.area')->insert(['id_area' => 1, 'nombre' => 'Área de prueba']);
        DB::table('gestion.persona_trabajador')->insert(['id_persona' => 1, 'id_area' => 1, 'estado' => true, 'fecha_ingreso' => today()->toDateString()]);
        $this->grant(['actividades.ver']);
        $this->cookieFor($user);
        $this->getJson('/api/activity')->assertOk();
        $this->assertSame(1, Auth::user()->id_usuario);
        $this->postJson('/api/activity', [])->assertForbidden();
        $this->getJson('/api/areas/list')->assertForbidden();
        $this->grant(['actividades.crear']);
        $this->postJson('/api/activity', [])->assertForbidden();
    }

    public function test_inactive_roles_permissions_modules_and_removed_assignments_deny_access(): void
    {
        $user = $this->actor();
        $this->grant(['actividades.ver']);
        foreach (['acceso.rol', 'acceso.permiso', 'acceso.modulo'] as $table) {
            DB::table($table)->update(['estado' => false]);
            $this->cookieFor($user);
            $this->getJson('/api/activity')->assertForbidden();
            DB::table($table)->update(['estado' => true]);
        }
        DB::table('acceso.rol_modulo')->delete();
        $this->cookieFor($user);
        $this->getJson('/api/activity')->assertForbidden();
    }

    public function test_a_permission_assigned_under_the_wrong_module_does_not_grant_access(): void
    {
        $user = $this->actor();
        $this->grant(['actividades.ver']);
        $otherModule = DB::table('acceso.modulo')->insertGetId(['nombre' => 'Otro', 'url' => '/otro', 'estado' => true], 'id_modulo');
        DB::table('acceso.permiso')->update(['id_modulo' => $otherModule]);
        $this->cookieFor($user);
        $this->getJson('/api/activity')->assertForbidden();
    }

    public function test_institutional_role_module_and_permission_management(): void
    {
        $this->cookieFor($this->actor('Administrador'));
        $module = $this->postJson('/api/modulos', ['nombre' => 'Fixture', 'url' => '/fixture'])->assertCreated()->json('data.id');
        $this->assertNotNull($module);
        $permission = $this->postJson('/api/modulos/'.$module.'/permisos', ['nombre' => 'Ver', 'clave' => 'fixture.ver'])->assertCreated()->json('data.id');
        $role = $this->postJson('/api/roles', ['nombre' => 'Destino'])->assertCreated()->json('data.id');
        $this->assertNotNull($permission);
        $this->assertNotNull($role);
        $this->putJson('/api/roles/'.$role.'/modulos', ['modulos' => [$module]])->assertOk();
        $this->putJson('/api/roles/'.$role.'/permisos', ['permisos' => [['idModulo' => $module, 'idPermiso' => $permission]]])->assertOk();
        $this->getJson('/api/roles/'.$role.'/modulos')->assertOk();
        $this->getJson('/api/roles/'.$role.'/permisos')->assertOk();
        $this->getJson('/api/modulos')->assertOk();
        $this->putJson('/api/roles/'.$role, ['nombre' => 'Actualizado'])->assertOk();
        $this->putJson('/api/modulos/'.$module, ['nombre' => 'Actualizado', 'url' => '/fixture'])->assertOk();
        $this->putJson('/api/permisos/'.$permission, ['nombre' => 'Consultar', 'clave' => 'fixture.ver'])->assertOk();
        $this->postJson('/api/modulos/'.$module.'/permisos', ['nombre' => 'Incorrecto', 'clave' => 'otro.ver'])->assertUnprocessable();
        $this->putJson('/api/modulos/'.$module, ['nombre' => 'Cambio', 'url' => '/otro'])->assertConflict();
        $this->patchJson('/api/roles/'.$role.'/estado', ['estado' => false])->assertOk();
        $this->patchJson('/api/modulos/'.$module.'/estado', ['estado' => false])->assertOk();
        $this->patchJson('/api/permisos/'.$permission.'/estado', ['estado' => false])->assertOk();
        $this->assertDatabaseHas('acceso.rol_modulo_permiso', ['id_rol' => $role, 'id_modulo' => $module, 'id_permiso' => $permission]);
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_invalid_role_permission_assignment_rolls_back_without_losing_permissions(): void
    {
        $this->cookieFor($this->actor('Administrador'));
        $this->grant(['actividades.ver']);
        $module = DB::table('acceso.modulo')->value('id_modulo');
        $this->putJson('/api/roles/1/permisos', ['permisos' => [['idModulo' => $module, 'idPermiso' => 999999]]])->assertUnprocessable();
        $this->assertDatabaseCount('acceso.rol_modulo_permiso', 1);
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_permission_resolution_uses_one_query_and_observes_role_revocation(): void
    {
        $user = $this->actor('Administrador');
        $this->grant(['actividades.ver']);
        DB::enableQueryLog();
        DB::flushQueryLog();

        $permissions = $user->permissionKeys();

        $this->assertContains('actividades.ver', $permissions);
        $this->assertContains('personas.crear', $permissions);
        $this->assertCount(1, DB::getQueryLog());
        DB::table('acceso.rol')->update(['estado' => false]);
        $this->assertSame([], $user->permissionKeys());
        DB::disableQueryLog();
    }

    public function test_repeated_gates_share_permissions_and_immediately_observe_role_revocation(): void
    {
        $user = $this->actor('Administrador');
        $this->grant(['actividades.ver']);
        Route::get('/permission-read-probe', function () use ($user): array {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $allowed = Gate::forUser($user)->allows('viewAny', Activity::class);
            $administrator = $user->isSystemAdministrator();
            Gate::forUser($user)->allows('viewAny', Activity::class);
            $queries = count(DB::getQueryLog());
            DB::table('acceso.rol')->update(['estado' => false]);

            return ['allowed' => $allowed, 'administrator' => $administrator, 'queries' => $queries,
                'after' => Gate::forUser($user)->allows('viewAny', Activity::class),
                'administrator_after' => $user->isSystemAdministrator()];
        });

        $this->getJson('/permission-read-probe')->assertOk()->assertExactJson([
            'allowed' => true, 'administrator' => true, 'queries' => 1, 'after' => false, 'administrator_after' => false,
        ]);
    }

    public function test_permission_reads_are_not_shared_between_users_in_the_same_request(): void
    {
        $administrator = $this->actor('Administrador');
        DB::table('acceso.usuario')->insert(['id_usuario' => 2, 'id_persona' => 1, 'correo' => 'reader@example.test', 'estado' => true]);
        $reader = User::findOrFail(2);
        Route::get('/permission-read-probe', fn (): array => [
            'administrator' => Gate::forUser($administrator)->allows('viewAny', Activity::class),
            'reader' => Gate::forUser($reader)->allows('viewAny', Activity::class),
            'reader_is_administrator' => $reader->isSystemAdministrator(),
        ]);

        $this->getJson('/permission-read-probe')->assertOk()->assertExactJson([
            'administrator' => true, 'reader' => false, 'reader_is_administrator' => false,
        ]);
    }

    public function test_inactive_administrator_role_does_not_authorize_role_administration(): void
    {
        $user = $this->actor('Administrador');
        DB::table('acceso.rol')->update(['estado' => false]);
        $this->cookieFor($user);
        $this->getJson('/api/roles')->assertForbidden();
        DB::table('acceso.rol')->update(['estado' => true]);
        $this->cookieFor($user);
        $this->getJson('/api/roles')->assertOk();
        $this->getJson('/api/activity')->assertOk();
    }

    public function test_administrator_can_manage_person_documents_without_catalogued_crud_permissions(): void
    {
        $this->cookieFor($this->actor('Administrador'));
        Schema::create('certificado.certificado', function (Blueprint $table): void {
            $table->increments('id_certificado');
            $table->integer('id_persona');
        });

        $this->getJson('/api/person-documents/list')->assertOk();
        $this->getJson('/api/person-documents/detail/1')->assertOk();
        $this->putJson('/api/person-documents/update/1', [
            'id_persona' => 1, 'id_tipo_documento' => 1, 'numero_documento' => '87654321', 'estado' => 'Activo',
        ])->assertOk();
        $this->assertDatabaseHas('gestion.persona_documento', ['id_persona_documento' => 1, 'numero_documento' => '87654321']);
        $this->deleteJson('/api/person-documents/delete/1')->assertOk();
        $this->assertDatabaseCount('gestion.persona_documento', 0);
        $this->postJson('/api/person-documents/create', [
            'id_persona' => 1, 'id_tipo_documento' => 1, 'numero_documento' => '11223344', 'estado' => 'Activo',
        ])->assertCreated();
        $this->assertDatabaseHas('gestion.persona_documento', ['id_persona' => 1, 'numero_documento' => '11223344']);
        $this->getJson('/api/auth/me')->assertOk();
        $permissions = User::findOrFail(1)->permissionKeys();
        foreach (['personas.ver', 'personas.verpersonas', 'personas.crear', 'personas.editar', 'personas.eliminar'] as $permission) {
            $this->assertContains($permission, $permissions);
        }
        $this->assertNotContains('firmas.firmar', $permissions);
    }

    public function test_invalid_expired_and_inactive_user_cookies_are_rejected(): void
    {
        $user = $this->actor();
        $this->withCredentials()->withUnencryptedCookie('access_token', 'invalid')->getJson('/api/auth/me')->assertUnauthorized();
        Auth::forgetGuards();
        $expired = JWT::encode(['sub' => 1, 'exp' => time() - 60], config('jwt.secret'), 'HS256');
        $this->withUnencryptedCookie('access_token', $expired)->getJson('/api/auth/me')->assertUnauthorized();
        DB::table('acceso.usuario')->update(['estado' => false]);
        $this->cookieFor($user);
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_login_requires_a_valid_turnstile_token_and_rejects_reuse(): void
    {
        $this->actor();
        config([
            'services.turnstile.enabled' => true,
            'services.turnstile.secret' => 'test-secret',
            'services.turnstile.hostname' => 'login.example.test',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://challenges.cloudflare.com/turnstile/v0/siteverify' => Http::sequence()
                ->push(['success' => false, 'error-codes' => ['timeout-or-duplicate']])
                ->push(['success' => true, 'hostname' => 'other.example.test', 'action' => 'login'])
                ->push(['success' => true, 'hostname' => 'login.example.test', 'action' => 'other'])
                ->push(['success' => true, 'hostname' => 'login.example.test', 'action' => 'login'])
                ->push(['success' => true, 'hostname' => 'login.example.test', 'action' => 'login']),
        ]);

        $credentials = ['email' => 'actor@example.test', 'password' => 'fixture-password'];

        $this->postJson('/api/auth/login', $credentials)->assertUnprocessable()->assertJsonValidationErrors('turnstileToken');
        Http::assertNothingSent();

        foreach (['expired', 'wrong-host', 'wrong-action'] as $token) {
            $this->postJson('/api/auth/login', $credentials + ['turnstileToken' => $token])
                ->assertUnprocessable()->assertJsonValidationErrors('turnstileToken');
        }

        $this->postJson('/api/auth/login', $credentials + ['turnstileToken' => 'valid-token'])->assertOk();
        $this->postJson('/api/auth/login', $credentials + ['turnstileToken' => 'valid-token'])
            ->assertUnprocessable()->assertJsonValidationErrors('turnstileToken');
    }

    public function test_login_fails_closed_when_turnstile_cannot_be_reached(): void
    {
        $this->actor();
        config([
            'services.turnstile.enabled' => true,
            'services.turnstile.secret' => 'test-secret',
            'services.turnstile.hostname' => 'login.example.test',
        ]);

        Http::preventStrayRequests();
        Http::fake(['https://challenges.cloudflare.com/turnstile/v0/siteverify' => Http::failedConnection()]);

        $this->postJson('/api/auth/login', [
            'email' => 'actor@example.test',
            'password' => 'wrong',
            'turnstileToken' => 'test-token',
        ])->assertServiceUnavailable();

        $this->assertDatabaseHas('acceso.usuario', ['id_usuario' => 1, 'intentos_fallidos' => 0]);
    }

    public function test_login_rejects_bad_credentials_and_locks_after_three_attempts(): void
    {
        $this->actor();
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $this->postJson('/api/auth/login', ['email' => 'actor@example.test', 'password' => 'wrong'])->assertUnauthorized();
        }
        $this->postJson('/api/auth/login', ['email' => 'actor@example.test', 'password' => 'wrong'])->assertStatus(423);
        $this->postJson('/api/auth/login', ['email' => 'actor@example.test', 'password' => 'fixture-password'])->assertStatus(423);
        $this->assertDatabaseHas('acceso.usuario', ['id_usuario' => 1, 'intentos_fallidos' => 3]);
    }

    public function test_administrator_can_unlock_a_user_before_the_lock_expires(): void
    {
        $this->freezeSecond();
        $admin = $this->actor('Administrador');
        $this->grant(['usuarios.ver', 'usuarios.editar']);
        DB::table('acceso.usuario')->insert([
            'id_usuario' => 2,
            'id_persona' => $admin->id_persona,
            'correo' => 'locked@example.test',
            'password_hash' => Hash::make('fixture-password'),
            'estado' => true,
            'intentos_fallidos' => 3,
            'bloqueado_hasta' => now()->addMinutes(30),
        ]);
        $credentials = ['email' => 'locked@example.test', 'password' => 'fixture-password'];

        $this->postJson('/api/auth/login', $credentials)->assertStatus(423);

        $this->cookieFor($admin);
        $this->getJson('/api/usuarios/2')->assertOk()
            ->assertJsonPath('data.bloqueado', true)
            ->assertJsonPath('data.intentosFallidos', 3)
            ->assertJsonPath('data.limiteIntentos', 3);
        $this->patchJson('/api/usuarios/2/desbloquear')->assertOk()
            ->assertJsonPath('data.bloqueado', false)
            ->assertJsonPath('data.intentosFallidos', 0)
            ->assertJsonPath('data.estado', true);
        $this->assertDatabaseHas('acceso.usuario', [
            'id_usuario' => 2,
            'bloqueado_hasta' => null,
            'intentos_fallidos' => 0,
        ]);

        $this->postJson('/api/auth/login', $credentials)->assertOk();
        $this->getJson('/api/usuarios/2')->assertOk()->assertJsonPath('data.ultimoAcceso', now()->toIso8601String());
    }

    public function test_password_recovery_resets_the_institutional_password_and_consumes_the_token(): void
    {
        Notification::fake();
        $user = $this->actor();
        $this->postJson('/api/auth/forgot-password', ['email' => $user->correo])->assertOk();
        $notification = Notification::sent($user, ResetPassword::class)->sole();
        $payload = ['email' => $user->correo, 'token' => $notification->token, 'password' => 'new-fixture-password', 'password_confirmation' => 'new-fixture-password'];
        $this->postJson('/api/auth/reset-password', $payload)->assertOk();
        $this->assertTrue(Hash::check('new-fixture-password', User::findOrFail(1)->password_hash));
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->postJson('/api/auth/reset-password', $payload)->assertStatus(400);
        $this->postJson('/api/auth/login', ['email' => $user->correo, 'password' => 'new-fixture-password'])->assertOk();
        $this->postJson('/api/auth/login', ['email' => $user->correo, 'password' => 'fixture-password'])->assertUnauthorized();
    }

    public function test_password_recovery_does_not_disclose_whether_the_email_exists(): void
    {
        Notification::fake();
        $this->actor();
        $known = $this->postJson('/api/auth/forgot-password', ['email' => 'actor@example.test'])->assertOk()->json();
        $unknown = $this->postJson('/api/auth/forgot-password', ['email' => 'unknown@example.test'])->assertOk()->json();
        $this->assertSame($known, $unknown);
    }

    public function test_institutional_user_creation_update_status_and_password_link(): void
    {
        Notification::fake();
        $user = $this->actor();
        $this->grant(['usuarios.ver', 'usuarios.crear', 'usuarios.editar']);
        DB::table('acceso.rol')->insert(['id_rol' => 2, 'nombre' => 'Destino', 'estado' => true]);
        $this->cookieFor($user);
        $payload = ['dni' => '87654321', 'nombres' => 'Nueva', 'apellidoPaterno' => 'Persona', 'correo' => 'target@example.test', 'idRol' => 2];
        $id = $this->postJson('/api/usuarios', $payload)->assertCreated()->json('data.id');
        $this->assertNotNull($id);
        Notification::assertSentTo(User::findOrFail($id), ResetPassword::class);
        $hash = User::findOrFail($id)->password_hash;
        $this->putJson('/api/usuarios/'.$id, ['correo' => 'updated@example.test', 'idRol' => 2])->assertOk();
        $this->assertSame($hash, User::findOrFail($id)->password_hash);
        $this->putJson('/api/usuarios/'.$id, [
            'correo' => 'updated@example.test',
            'password' => 'new-fixture-password',
            'password_confirmation' => 'new-fixture-password',
        ])->assertForbidden();
        $this->putJson('/api/usuarios/'.$id, ['correo' => 'updated@example.test'])
            ->assertOk()->assertJsonPath('data.rol.id', 2);
        $this->patchJson('/api/usuarios/'.$id.'/estado', ['estado' => false])->assertOk();
        $this->assertFalse(User::findOrFail($id)->estado);
        $this->getJson('/api/usuarios/999')->assertNotFound();
        $this->putJson('/api/usuarios/'.$id, ['correo' => 'updated@example.test', 'idRol' => null])
            ->assertUnprocessable()->assertJsonValidationErrors('idRol');
    }

    public function test_administrator_can_change_credentials_without_reassigning_the_role(): void
    {
        $admin = $this->actor('Administrador');
        $this->grant(['usuarios.editar']);
        $this->cookieFor($admin);
        $target = User::findOrFail(1);
        $token = Password::createToken($target);
        $payload = [
            'correo' => 'new-address@example.test',
            'password' => 'new-fixture-password',
        ];

        $this->putJson('/api/usuarios/1', $payload + ['password_confirmation' => 'incorrect'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertSame('actor@example.test', $target->refresh()->correo);

        $this->putJson('/api/usuarios/1', $payload + ['password_confirmation' => $payload['password']])
            ->assertOk()->assertJsonPath('data.correo', $payload['correo'])
            ->assertJsonPath('data.rol.id', 1)
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.password_hash');
        $this->assertFalse(Password::tokenExists($target, $token));

        $this->postJson('/api/auth/login', ['email' => 'actor@example.test', 'password' => 'fixture-password'])
            ->assertUnauthorized();
        $this->postJson('/api/auth/login', ['email' => $payload['correo'], 'password' => 'fixture-password'])
            ->assertUnauthorized();
        $this->postJson('/api/auth/login', ['email' => $payload['correo'], 'password' => $payload['password']])
            ->assertOk();
    }

    public function test_logout_expires_the_cookie_and_legacy_bearer_is_not_accepted(): void
    {
        $user = $this->actor();
        $this->cookieFor($user);
        $response = $this->postJson('/api/auth/logout')->assertOk();
        $this->assertLessThan(time(), $response->baseResponse->headers->getCookies()[0]->getExpiresTime());
        Auth::forgetGuards();
        $this->withUnencryptedCookie('access_token', '')->withToken('legacy-sanctum-token')->getJson('/api/activity')->assertUnauthorized();
    }

    public function test_all_registered_protected_routes_reject_guests(): void
    {
        $public = ['api/auth/login', 'api/auth/forgot-password', 'api/auth/reset-password', 'api/certificados/verificar/{code}', 'api/certificados/consulta-dni', 'api/portal-certificados/consultar-dni'];
        $routes = array_values(array_filter(app('router')->getRoutes()->getRoutes(), fn ($route) => str_starts_with($route->uri(), 'api/')));
        $protected = array_values(array_filter($routes, fn ($route) => ! in_array($route->uri(), $public, true)));
        $this->assertNotEmpty($protected);
        foreach ($protected as $route) {
            $uri = $this->registeredRouteUri($route);
            $this->json($route->methods()[0], $uri)->assertUnauthorized()->assertHeaderMissing('Location');
        }
    }

    public function test_an_institutional_cookie_without_permissions_does_not_authorize_any_crud(): void
    {
        $this->cookieFor($this->actor());
        $allowed = ['api/auth/login', 'api/auth/forgot-password', 'api/auth/reset-password', 'api/auth/me', 'api/auth/logout', 'api/certificados/verificar/{code}', 'api/certificados/consulta-dni', 'api/portal-certificados/consultar-dni',
            'api/mis-certificados', 'api/mis-certificados/{id}/pdf'];
        $routes = array_filter(app('router')->getRoutes()->getRoutes(), fn ($route) => str_starts_with($route->uri(), 'api/') && ! in_array($route->uri(), $allowed, true));
        $this->assertNotEmpty($routes);
        foreach ($routes as $route) {
            $uri = $this->registeredRouteUri($route);
            $response = $this->json($route->methods()[0], $uri);
            $this->assertSame(403, $response->status(), $route->methods()[0].' '.$uri);
        }
    }

    private function registeredRouteUri(RegisteredRoute $route): string
    {
        return '/'.preg_replace_callback('/\{([^}]+)\}/', function (array $parameter) use ($route): string {
            $firstChoice = explode('|', $route->wheres[$parameter[1]] ?? '')[0];

            return preg_match('/^[a-z_]+$/D', $firstChoice) === 1 ? $firstChoice : '1';
        }, $route->uri());
    }

    public function test_tampering_with_the_subject_of_a_valid_token_is_rejected(): void
    {
        $token = app(JwtService::class)->createToken($this->actor());
        $parts = explode('.', $token);
        $parts[1] = JWT::urlsafeB64Encode(json_encode(['sub' => 999, 'rol' => 'Administrador', 'iat' => time(), 'exp' => time() + 60]));
        $this->withCredentials()->withUnencryptedCookie('access_token', implode('.', $parts))->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_a_signed_administrator_claim_does_not_override_database_roles(): void
    {
        $this->actor();
        $token = JWT::encode(['sub' => 1, 'rol' => 'Administrador', 'iat' => time(), 'exp' => time() + 60], config('jwt.secret'), 'HS256');
        $this->withCredentials()->withUnencryptedCookie('access_token', $token)->getJson('/api/roles')->assertForbidden();
    }

    public static function invalidInstitutionalClaims(): array
    {
        return [
            'missing expiry' => [['exp' => null]],
            'missing issuance' => [['iat' => null]],
            'invalid subject shape' => [['sub' => ['id' => 1]]],
            'zero subject' => [['sub' => 0]],
            'non-integer expiry' => [['exp' => 'not-a-timestamp']],
            'future issuance despite valid nbf' => [['future_issuance' => true]],
        ];
    }

    #[DataProvider('invalidInstitutionalClaims')]
    public function test_signed_tokens_must_have_valid_institutional_claims(array $overrides): void
    {
        $this->actor();
        if ($overrides['future_issuance'] ?? false) {
            unset($overrides['future_issuance']);
            $overrides['iat'] = time() + 120;
            $overrides['nbf'] = time() - 10;
        }
        $payload = array_replace(['sub' => 1, 'iat' => time() - 10, 'exp' => time() + 300], $overrides);
        $header = JWT::urlsafeB64Encode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $body = JWT::urlsafeB64Encode(json_encode($payload));
        $signature = JWT::urlsafeB64Encode(hash_hmac('sha256', $header.'.'.$body, config('jwt.secret'), true));
        $token = $header.'.'.$body.'.'.$signature;
        $this->withCredentials()->withUnencryptedCookie('access_token', $token)->getJson('/api/auth/me')->assertUnauthorized();
    }
}
