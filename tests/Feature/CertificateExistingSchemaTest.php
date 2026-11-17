<?php

namespace Tests\Feature;

use App\Data\ActivitySignature\ActivitySignatureData;
use App\Models\Role\Role;
use App\Models\User\User;
use App\Services\Auth\JwtService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class CertificateExistingSchemaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null, 'app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);
        DB::purge('sqlite');
        foreach (['gestion', 'certificado'] as $schema) {
            DB::statement("ATTACH DATABASE ':memory:' AS {$schema}");
        }
        foreach ([
            'gestion.area' => 'id_area INTEGER PRIMARY KEY, nombre TEXT',
            'gestion.persona' => 'id_persona INTEGER PRIMARY KEY, nombres TEXT, apellido_paterno TEXT, apellido_materno TEXT',
            'gestion.persona_trabajador' => 'id_persona_trabajador INTEGER PRIMARY KEY, id_persona INTEGER, id_area INTEGER, cargo TEXT, estado BOOLEAN, fecha_ingreso DATE, fecha_fin DATE',
            'gestion.persona_practicante' => 'id_persona_practicante INTEGER PRIMARY KEY, id_persona INTEGER',
            'gestion.practica' => 'id_practica INTEGER PRIMARY KEY, id_persona_practicante INTEGER, id_area INTEGER',
            'certificado.reconocimiento' => 'id_reconocimiento INTEGER PRIMARY KEY, id_persona_trabajador INTEGER',
            'certificado.actividad' => 'id_actividad INTEGER PRIMARY KEY, id_area INTEGER, nombre TEXT',
            'certificado.participacion' => 'id_participacion INTEGER PRIMARY KEY, id_actividad INTEGER, id_persona INTEGER',
            'certificado.tipo_certificado' => 'id_tipo_certificado INTEGER PRIMARY KEY, nombre_tipo TEXT, estado BOOLEAN',
            'certificado.firma' => 'id_firma INTEGER PRIMARY KEY, id_persona_trabajador INTEGER, ruta_archivo_pfx TEXT, passphrase_cifrada TEXT, fecha_firma DATE',
            'certificado.actividad_firma' => 'id_actividad_firma INTEGER PRIMARY KEY, id_actividad INTEGER, id_firma INTEGER, orden_firma INTEGER',
            'certificado.certificado' => 'id_certificado INTEGER PRIMARY KEY, id_persona INTEGER, id_tipo_certificado INTEGER, id_participacion INTEGER, id_practica INTEGER,
                id_reconocimiento INTEGER, fecha_emision DATE NOT NULL, estado TEXT, notificado BOOLEAN, fecha_notificacion TEXT',
            'certificado.certificado_documento' => 'id_certificado_documento INTEGER PRIMARY KEY, id_certificado INTEGER, codigo_verificacion TEXT UNIQUE,
                hash_certificado TEXT UNIQUE, ruta_archivo TEXT, fecha_generacion TEXT NOT NULL, estado TEXT',
            'certificado.certificado_firma' => 'id_certificado_firma INTEGER PRIMARY KEY, id_certificado_documento INTEGER, id_firma INTEGER, orden_firma INTEGER,
                fecha_firma TEXT NOT NULL, UNIQUE(id_certificado_documento,id_firma)',
        ] as $table => $columns) {
            DB::statement("CREATE TABLE {$table} ({$columns})");
        }
    }

    private function actor(int $id = 50, bool $administrator = true, array $permissions = ['emision.ver']): void
    {
        $user = new class extends User
        {
            public bool $administrator;

            public array $permissions;

            public function isSystemAdministrator(): bool
            {
                return $this->administrator;
            }

            public function permissionKeys(): array
            {
                return $this->permissions;
            }
        };
        $user->forceFill(['id_usuario' => $id, 'id_persona' => $id, 'estado' => true]);
        $user->administrator = $administrator;
        $user->permissions = $permissions;
        $user->setRelation('roles', (new Role)->newCollection());
        $this->actingAs($user, 'jwt');
    }

    private function context(int $signers = 1, string $origin = 'actividad'): string
    {
        Storage::fake('local');
        DB::table('gestion.area')->insert([['id_area' => 1, 'nombre' => 'Pruebas A'], ['id_area' => 2, 'nombre' => 'Pruebas B']]);
        DB::table('gestion.persona')->insert(['id_persona' => 1, 'nombres' => 'Beneficiario', 'apellido_paterno' => 'Prueba']);
        DB::table('certificado.tipo_certificado')->insert(['id_tipo_certificado' => 1, 'nombre_tipo' => 'Tipo real de prueba', 'estado' => true]);
        DB::table('certificado.actividad')->insert(['id_actividad' => 1, 'id_area' => 1, 'nombre' => 'Actividad real de prueba']);
        DB::table('certificado.participacion')->insert(['id_participacion' => 1, 'id_persona' => 1, 'id_actividad' => 1]);
        DB::table('gestion.persona_practicante')->insert(['id_persona_practicante' => 1, 'id_persona' => 1]);
        DB::table('gestion.practica')->insert(['id_practica' => 1, 'id_persona_practicante' => 1, 'id_area' => 1]);
        DB::table('gestion.persona_trabajador')->insert(['id_persona_trabajador' => 1, 'id_persona' => 1, 'id_area' => 1, 'cargo' => 'Beneficiario',
            'estado' => true, 'fecha_ingreso' => '2020-01-01']);
        DB::table('certificado.reconocimiento')->insert(['id_reconocimiento' => 1, 'id_persona_trabajador' => 1]);
        foreach ($signers === 0 ? [] : range(10, 9 + $signers) as $id) {
            DB::table('gestion.persona')->insert(['id_persona' => $id, 'nombres' => 'Responsable '.$id]);
            DB::table('gestion.persona_trabajador')->insert(['id_persona_trabajador' => $id, 'id_persona' => $id, 'id_area' => 1,
                'cargo' => 'Cargo real '.$id, 'estado' => true, 'fecha_ingreso' => '2020-01-01']);
            DB::table('certificado.firma')->insert(['id_firma' => $id, 'id_persona_trabajador' => $id, 'ruta_archivo_pfx' => 'secret.pfx',
                'passphrase_cifrada' => 'secret', 'fecha_firma' => '2020-01-01']);
            DB::table('certificado.actividad_firma')->insert(['id_actividad_firma' => $id, 'id_actividad' => 1, 'id_firma' => $id, 'orden_firma' => $id - 9]);
        }
        DB::table('certificado.certificado')->insert(['id_certificado' => 1, 'id_persona' => 1, 'id_tipo_certificado' => 1,
            'id_participacion' => $origin === 'actividad' ? 1 : null, 'id_practica' => $origin === 'practica' ? 1 : null,
            'id_reconocimiento' => $origin === 'reconocimiento' ? 1 : null,
            'fecha_emision' => '2026-01-01', 'estado' => 'PENDIENTE_FIRMA', 'notificado' => false]);
        $bytes = "%PDF-1.4\nExisting private document\n%%EOF";
        Storage::disk('local')->put('certificados/existente.pdf', $bytes);
        DB::table('certificado.certificado_documento')->insert(['id_certificado_documento' => 1, 'id_certificado' => 1,
            'codigo_verificacion' => str_repeat('a', 30), 'hash_certificado' => hash('sha256', $bytes), 'ruta_archivo' => 'certificados/existente.pdf',
            'fecha_generacion' => '2026-01-01 10:00:00', 'estado' => 'Vigente']);

        return $bytes;
    }

    #[TestWith([1])]
    #[TestWith([2])]
    #[TestWith([3])]
    #[TestWith([4])]
    #[TestWith([0])]
    public function test_current_schema_resolves_signers_and_downloads_identical_pdf(int $count): void
    {
        $bytes = $this->context($count);
        $this->actor();

        $response = $this->getJson('/api/certificados-documentos/list?bandeja=por_firmar')->assertOk()
            ->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.firmas.requeridas_configuradas', $count)
            ->assertJsonPath('data.data.0.firmas.pendientes', $count)->assertJsonPath('data.data.0.id_sesion', null)
            ->assertJsonPath('data.data.0.beneficiario.nombre', 'Beneficiario Prueba')
            ->assertJsonPath('data.data.0.asignacion_firmantes_pendiente', $count === 0)
            ->assertJsonPath('data.data.0.facultades.firmar', false)
            ->assertJsonPath('data.data.0.facultades.emitir', false)
            ->assertJsonPath('data.data.0.facultades.entregar', false);
        foreach ($response->json('data.data.0.firmantes') as $signer) {
            self::assertSame('Cargo real '.$signer['id_persona'], $signer['cargo']);
            self::assertSame('Pendiente', $signer['estado']);
            self::assertNull($signer['fecha_firma']);
            self::assertFalse($signer['firma_verificada']);
        }
        self::assertStringNotContainsString('passphrase', $response->getContent());
        self::assertStringNotContainsString('secret.pfx', $response->getContent());
        self::assertStringNotContainsString('ruta_archivo', $response->getContent());
        $this->getJson('/api/certificados-documentos/detail/1')->assertOk()->assertJsonPath('data.fecha_emision', '2026-01-01');
        $download = $this->get('/api/certificados-documentos/1/archivo')->assertOk();
        self::assertSame($bytes, file_get_contents($download->baseResponse->getFile()->getPathname()));
        $this->getJson('/api/certificados-documentos/1/historial')->assertOk()->assertJsonPath('data.disponible', false);
        $this->assertDatabaseCount('certificado.certificado_firma', 0);
        $this->assertDatabaseCount('certificado.certificado_documento', 1);
    }

    public function test_registered_signature_is_not_treated_as_cryptographically_verified(): void
    {
        $this->context(2);
        $this->actor();
        DB::table('certificado.certificado_firma')->insert(['id_certificado_firma' => 1, 'id_certificado_documento' => 1, 'id_firma' => 10,
            'orden_firma' => 1, 'fecha_firma' => '2026-01-03 12:30:00']);

        $this->getJson('/api/certificados-documentos/detail/1')->assertOk()
            ->assertJsonPath('data.firmantes.0.estado', 'Registrada')->assertJsonPath('data.firmantes.0.fecha_firma', '2026-01-03 12:30:00')
            ->assertJsonPath('data.firmantes.0.firma_verificada', false)
            ->assertJsonPath('data.firmantes.1.estado', 'Pendiente')->assertJsonPath('data.firmas.registradas', 1)
            ->assertJsonPath('data.firmas.pendientes', 1)->assertJsonPath('data.firmas.verificadas', null);
    }

    #[TestWith(['practica'])]
    #[TestWith(['reconocimiento'])]
    public function test_other_origins_use_real_relationships_without_inventing_signer_assignments(string $origin): void
    {
        $this->context(1, $origin);
        $this->actor();

        $this->getJson('/api/certificados-documentos/detail/1')->assertOk()->assertJsonPath('data.origen', $origin)
            ->assertJsonPath('data.beneficiario.id_persona', 1)->assertJsonCount(0, 'data.firmantes')
            ->assertJsonPath('data.id_actividad', null)->assertJsonPath('data.id_sesion', null);
    }

    public function test_private_reads_require_authentication_permissions_and_area(): void
    {
        $this->context();
        $this->getJson('/api/certificados-documentos/list?bandeja=por_firmar')->assertUnauthorized();
        $this->actor(50, true, []);
        $this->getJson('/api/certificados-documentos/list?bandeja=por_firmar')->assertForbidden();
        $this->getJson('/api/certificados-documentos/detail/1')->assertForbidden();
        $this->actor(50, false);
        DB::table('gestion.persona_trabajador')->insert(['id_persona_trabajador' => 50, 'id_persona' => 50,
            'id_area' => 2, 'estado' => true, 'fecha_ingreso' => '2020-01-01']);

        $this->getJson('/api/certificados-documentos/list?bandeja=por_firmar')->assertOk()->assertJsonPath('data.total', 0);
        $this->getJson('/api/certificados-documentos/detail/1')->assertNotFound();
        $this->getJson('/api/certificados-documentos/1/archivo')->assertNotFound();
        $this->getJson('/api/certificados-documentos/1/historial')->assertNotFound();
        $this->getJson('/api/certificados/list')->assertOk()->assertJsonPath('data.total', 0);
        $this->getJson('/api/certificados-documentos/list')->assertOk()->assertJsonPath('data.total', 0);
    }

    public function test_altered_pdf_and_invalid_filters_are_rejected_without_writes(): void
    {
        $this->context();
        $this->actor();
        Storage::disk('local')->put('certificados/existente.pdf', '%PDF-altered');

        $this->getJson('/api/certificados-documentos/1/archivo')->assertConflict();
        $this->getJson('/api/certificados-documentos/list?bandeja=por_firmar&per_page=0')->assertUnprocessable();

        $this->assertDatabaseCount('certificado.certificado_firma', 0);
        $this->assertDatabaseCount('certificado.certificado', 1);
    }

    public function test_review_and_remission_use_existing_columns_keep_pdf_and_are_idempotent(): void
    {
        $bytes = $this->context(3);
        $this->actor(50, true, ['emision.ver', 'emision.editar']);
        DB::table('certificado.certificado')->where('id_certificado', 1)->update(['estado' => 'PREPARADO']);
        Log::spy();
        $review = $this->postJson('/api/certificados-documentos/1/revisar-envio')->assertOk()->json('data');
        self::assertSame($bytes, base64_decode($review['vista_previa_pdf']['contenido_base64'], true));
        self::assertTrue($review['documento']['facultades']['remitir']);
        $payload = ['token_revision' => $review['token_revision'], 'confirmar' => true];

        $this->postJson('/api/certificados-documentos/1/enviar-gerencia', $payload)->assertOk()
            ->assertJsonPath('data.enviado', true)->assertJsonPath('data.reintento', false)
            ->assertJsonPath('data.estado_flujo', 'PENDIENTE_FIRMA')->assertJsonPath('data.historial_en_bd', false)
            ->assertJsonPath('data.documento.facultades.remitir', false);
        $this->postJson('/api/certificados-documentos/1/enviar-gerencia', $payload)->assertOk()->assertJsonPath('data.reintento', true);

        Log::shouldHaveReceived('info')->once()->with('certificado_documento.remitido_gerencia', \Mockery::on(fn (array $data): bool => $data['id_usuario'] === 50 && $data['id_certificado_documento'] === 1 && $data['hash_pdf'] === hash('sha256', $bytes)));
        $this->assertDatabaseHas('certificado.certificado', ['id_certificado' => 1, 'estado' => 'PENDIENTE_FIRMA', 'fecha_emision' => '2026-01-01', 'notificado' => false]);
        $this->assertDatabaseCount('certificado.certificado_firma', 0);
        $this->assertDatabaseCount('certificado.certificado_documento', 1);
        self::assertSame($bytes, Storage::disk('local')->get('certificados/existente.pdf'));
        self::assertSame(409, ActivitySignatureData::destroy(10)['status']);
        self::assertSame(409, ActivitySignatureData::update(10, ['orden_firma' => 8], true)['status']);
        $this->getJson('/api/certificados-documentos/list?bandeja=por_firmar')->assertOk()->assertJsonPath('data.total', 1);
    }

    public function test_confirmation_cannot_be_used_by_another_user_or_with_another_pdf(): void
    {
        $this->context(2);
        DB::table('certificado.certificado')->where('id_certificado', 1)->update(['estado' => 'PREPARADO']);
        $this->actor(50, true, ['emision.ver', 'emision.editar']);
        $review = $this->postJson('/api/certificados-documentos/1/revisar-envio')->assertOk()->json('data');
        $payload = ['token_revision' => $review['token_revision'], 'confirmar' => true];
        $this->actor(51, true, ['emision.ver', 'emision.editar']);

        $this->postJson('/api/certificados-documentos/1/enviar-gerencia', $payload)->assertForbidden();
        $this->actor(50, true, ['emision.ver', 'emision.editar']);
        $this->postJson('/api/certificados-documentos/1/enviar-gerencia', $payload + ['hash_pdf' => 'forged'])->assertUnprocessable();
        Storage::disk('local')->put('certificados/existente.pdf', '%PDF-changed');
        $this->postJson('/api/certificados-documentos/1/enviar-gerencia', $payload)->assertConflict();

        $this->assertDatabaseHas('certificado.certificado', ['id_certificado' => 1, 'estado' => 'PREPARADO']);
        $this->assertDatabaseCount('certificado.certificado_firma', 0);
    }

    public function test_changed_authorities_expired_tokens_and_missing_edit_permission_prevent_remission(): void
    {
        $this->context(2);
        DB::table('certificado.certificado')->where('id_certificado', 1)->update(['estado' => 'PREPARADO']);
        $this->actor();
        $this->postJson('/api/certificados-documentos/1/revisar-envio')->assertForbidden();
        $this->actor(50, true, ['emision.ver', 'emision.editar']);
        $review = $this->postJson('/api/certificados-documentos/1/revisar-envio')->assertOk()->json('data');
        $payload = ['token_revision' => $review['token_revision'], 'confirmar' => true];
        DB::table('gestion.persona_trabajador')->where('id_persona_trabajador', 11)->update(['cargo' => 'Cambio de cargo']);

        $this->postJson('/api/certificados-documentos/1/enviar-gerencia', $payload)->assertConflict();
        $this->travel(31)->minutes();
        $this->postJson('/api/certificados-documentos/1/enviar-gerencia', $payload)->assertConflict();

        $this->assertDatabaseHas('certificado.certificado', ['id_certificado' => 1, 'estado' => 'PREPARADO']);
    }

    public function test_duplicate_authority_or_existing_signature_prevents_a_new_remission(): void
    {
        $this->context(2);
        DB::table('certificado.certificado')->where('id_certificado', 1)->update(['estado' => 'PREPARADO']);
        $this->actor(50, true, ['emision.ver', 'emision.editar']);
        DB::table('gestion.persona_trabajador')->where('id_persona_trabajador', 11)->update(['id_persona' => 10]);

        $this->postJson('/api/certificados-documentos/1/revisar-envio')->assertConflict();
        DB::table('gestion.persona_trabajador')->where('id_persona_trabajador', 11)->update(['id_persona' => 11]);
        DB::table('certificado.certificado_firma')->insert(['id_certificado_documento' => 1, 'id_firma' => 10, 'orden_firma' => 1, 'fecha_firma' => '2026-01-03']);
        $this->postJson('/api/certificados-documentos/1/revisar-envio')->assertConflict();

        $this->assertDatabaseHas('certificado.certificado', ['id_certificado' => 1, 'estado' => 'PREPARADO']);
    }

    public function test_generic_crud_cannot_forge_pending_state_or_move_a_remitted_document(): void
    {
        $this->context();
        $this->actor(50, true, ['emision.ver', 'emision.editar']);
        DB::table('certificado.certificado')->where('id_certificado', 1)->update(['estado' => 'PREPARADO']);

        $this->patchJson('/api/certificados/update/1', ['estado' => 'PENDIENTE_FIRMA'])->assertConflict();
        DB::table('certificado.certificado')->insert(['id_certificado' => 2, 'id_persona' => 1, 'id_tipo_certificado' => 1,
            'id_participacion' => 1, 'fecha_emision' => '2026-01-01', 'estado' => 'PREPARADO', 'notificado' => false]);
        DB::table('certificado.certificado')->where('id_certificado', 1)->update(['estado' => 'PENDIENTE_FIRMA']);
        $this->patchJson('/api/certificados-documentos/update/1', ['id_certificado' => 2])->assertConflict();

        $this->assertDatabaseHas('certificado.certificado_documento', ['id_certificado_documento' => 1, 'id_certificado' => 1]);
    }

    public function test_log_failure_reports_its_limit_and_does_not_repeat_the_database_transition(): void
    {
        $this->context();
        $this->actor(50, true, ['emision.ver', 'emision.editar']);
        DB::table('certificado.certificado')->where('id_certificado', 1)->update(['estado' => 'PREPARADO']);
        $review = $this->postJson('/api/certificados-documentos/1/revisar-envio')->assertOk()->json('data');
        $payload = ['token_revision' => $review['token_revision'], 'confirmar' => true];
        Log::shouldReceive('info')->once()->andThrow(new \RuntimeException('Isolated log failure'));

        $this->postJson('/api/certificados-documentos/1/enviar-gerencia', $payload)->assertOk()
            ->assertJsonPath('data.enviado', true)->assertJsonPath('data.auditoria_log', false)
            ->assertJsonPath('data.historial_en_bd', false);
        $this->postJson('/api/certificados-documentos/1/enviar-gerencia', $payload)->assertOk()->assertJsonPath('data.reintento', true);

        $this->assertDatabaseHas('certificado.certificado', ['id_certificado' => 1, 'estado' => 'PENDIENTE_FIRMA']);
        $this->assertDatabaseCount('certificado.certificado_firma', 0);
    }

    public function test_current_schema_remission_uses_cookie_jwt_and_live_permissions(): void
    {
        $this->context();
        DB::table('certificado.certificado')->where('id_certificado', 1)->update(['estado' => 'PREPARADO']);
        DB::statement("ATTACH DATABASE ':memory:' AS acceso");
        foreach ([
            'acceso.usuario' => 'id_usuario INTEGER PRIMARY KEY, id_persona INTEGER, correo TEXT, estado BOOLEAN, bloqueado_hasta TEXT',
            'acceso.rol' => 'id_rol INTEGER PRIMARY KEY, nombre TEXT, estado BOOLEAN',
            'acceso.usuario_rol' => 'id_usuario_rol INTEGER PRIMARY KEY, id_usuario INTEGER, id_rol INTEGER',
            'acceso.modulo' => 'id_modulo INTEGER PRIMARY KEY, nombre TEXT, url TEXT, estado BOOLEAN',
            'acceso.permiso' => 'id_permiso INTEGER PRIMARY KEY, id_modulo INTEGER, clave TEXT, estado BOOLEAN',
            'acceso.rol_modulo' => 'id_rol INTEGER, id_modulo INTEGER',
            'acceso.rol_modulo_permiso' => 'id_rol INTEGER, id_modulo INTEGER, id_permiso INTEGER',
        ] as $table => $columns) {
            DB::statement("CREATE TABLE {$table} ({$columns})");
        }
        DB::table('acceso.usuario')->insert(['id_usuario' => 10, 'id_persona' => 10, 'correo' => 'actual-schema@example.test', 'estado' => true]);
        DB::table('acceso.rol')->insert(['id_rol' => 2, 'nombre' => 'Rol de prueba', 'estado' => true]);
        DB::table('acceso.usuario_rol')->insert(['id_usuario_rol' => 1, 'id_usuario' => 10, 'id_rol' => 2]);
        DB::table('acceso.modulo')->insert(['id_modulo' => 1, 'nombre' => 'Emisión', 'url' => '/emision', 'estado' => true]);
        DB::table('acceso.rol_modulo')->insert(['id_modulo' => 1, 'id_rol' => 2]);
        foreach (['emision.ver', 'emision.editar'] as $index => $permission) {
            DB::table('acceso.permiso')->insert(['id_permiso' => $index + 1, 'id_modulo' => 1, 'clave' => $permission, 'estado' => true]);
            DB::table('acceso.rol_modulo_permiso')->insert(['id_permiso' => $index + 1, 'id_modulo' => 1, 'id_rol' => 2]);
        }
        config(['jwt.secret' => str_repeat('real-cookie-current-schema-', 3), 'app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);
        Auth::forgetGuards();
        $this->withCredentials()->withUnencryptedCookie(JwtService::COOKIE, app(JwtService::class)->createToken(User::findOrFail(10)));
        $review = $this->postJson('/api/certificados-documentos/1/revisar-envio')->assertOk()->json('data');

        $this->postJson('/api/certificados-documentos/1/enviar-gerencia', ['token_revision' => $review['token_revision'], 'confirmar' => true])->assertOk();
        Auth::forgetGuards();
        $this->get('/api/certificados-documentos/1/archivo')->assertOk();
        DB::table('acceso.permiso')->where('clave', 'emision.ver')->update(['estado' => false]);
        Auth::forgetGuards();
        $this->getJson('/api/certificados-documentos/1/archivo')->assertForbidden();

        $this->assertDatabaseCount('certificado.certificado_firma', 0);
    }
}
