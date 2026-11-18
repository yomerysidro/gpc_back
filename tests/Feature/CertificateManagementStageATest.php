<?php

namespace Tests\Feature;

use App\Models\Role\Role;
use App\Models\User\User;
use App\Services\Auth\JwtService;
use App\Services\CertificateManagement;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class CertificateManagementStageATest extends TestCase
{
    private const URL = '/api/activity-session/1/preparar-certificados';

    private function administrativeWorkers(): void
    {
        $this->minimalSessionSchema();
        $this->seedCertificateContext(2);
        DB::table('certificado.actividad_firma')->delete();
        DB::table('certificado.firma')->delete();
        DB::statement('ALTER TABLE certificado.firma ADD COLUMN ruta_archivo_pfx TEXT');
        DB::statement('ALTER TABLE certificado.firma ADD COLUMN passphrase_cifrada TEXT');
        DB::statement('ALTER TABLE certificado.certificado_documento ADD COLUMN modalidad_firma TEXT');
        DB::statement('DROP TABLE certificado.certificado_firma');
        DB::statement('CREATE TABLE certificado.certificado_firma (id_certificado_firma INTEGER PRIMARY KEY,
            id_certificado_documento INTEGER, id_firma INTEGER, id_persona_trabajador INTEGER, orden_firma INTEGER,
            fecha_firma TEXT, UNIQUE(id_certificado_documento,id_persona_trabajador), UNIQUE(id_certificado_documento,orden_firma))');
        foreach ([
            'acceso.usuario' => 'id_usuario INTEGER PRIMARY KEY, id_persona INTEGER, estado BOOLEAN',
            'acceso.usuario_rol' => 'id_usuario INTEGER, id_rol INTEGER',
            'acceso.rol' => 'id_rol INTEGER PRIMARY KEY, estado BOOLEAN',
            'acceso.modulo' => 'id_modulo INTEGER PRIMARY KEY, estado BOOLEAN',
            'acceso.permiso' => 'id_permiso INTEGER PRIMARY KEY, id_modulo INTEGER, clave TEXT, estado BOOLEAN',
            'acceso.rol_modulo' => 'id_rol INTEGER, id_modulo INTEGER',
            'acceso.rol_modulo_permiso' => 'id_rol INTEGER, id_modulo INTEGER, id_permiso INTEGER',
        ] as $table => $columns) {
            DB::statement("CREATE TABLE {$table} ({$columns})");
        }
        DB::table('acceso.rol')->insert(['id_rol' => 10, 'estado' => true]);
        DB::table('acceso.modulo')->insert(['id_modulo' => 10, 'estado' => true]);
        DB::table('acceso.permiso')->insert(['id_permiso' => 10, 'id_modulo' => 10, 'clave' => 'firmas.firmar', 'estado' => true]);
        DB::table('acceso.rol_modulo')->insert(['id_modulo' => 10, 'id_rol' => 10]);
        DB::table('acceso.rol_modulo_permiso')->insert(['id_modulo' => 10, 'id_rol' => 10, 'id_permiso' => 10]);
        foreach ([10, 11] as $id) {
            DB::table('gestion.persona')->where('id_persona', $id)->update(['apellido_paterno' => 'Institucional']);
            DB::table('acceso.usuario')->insert(['id_usuario' => $id, 'id_persona' => $id, 'estado' => true]);
            DB::table('acceso.usuario_rol')->insert(['id_usuario' => $id, 'id_rol' => 10]);
        }
        $this->actor();
    }

    #[TestWith(['Paralela'])]
    #[TestWith(['Secuencial'])]
    public function test_new_prepared_pdf_and_pending_plan_use_selected_workers_without_credentials(string $mode): void
    {
        $this->administrativeWorkers();
        DB::table('gestion.area')->insert(['id_area' => 2, 'nombre' => 'Alcaldia']);
        DB::table('gestion.persona_trabajador')->where('id_persona_trabajador', 11)->update(['id_area' => 2]);
        $input = ['accion' => 'revisar_documento', 'id_participacion' => 1, 'id_tipo_certificado' => 1,
            'mensaje' => 'Mensaje aprobado.', 'modalidad_firma' => $mode,
            'firmantes' => [['id_persona_trabajador' => 11, 'orden_firma' => 2], ['id_persona_trabajador' => 10, 'orden_firma' => 1]]];

        $review = $this->postJson(self::URL, $input)->assertOk()
            ->assertJsonPath('data.firmantes.0.nombre', 'Responsable 10 Institucional')
            ->assertJsonPath('data.firmantes.0.cargo', 'Cargo real 10')->json('data');
        $pdf = base64_decode($review['vista_previa_pdf']['contenido_base64'], true);
        self::assertSame(1, preg_match_all('~/Type\s*/Page\b~', $pdf));
        $payload = ['accion' => 'confirmar_preparacion', 'token_revision' => $review['token_revision'], 'confirmar' => true];
        $prepared = $this->postJson(self::URL, $payload)->assertCreated()->json('data');
        $this->postJson(self::URL, $payload)->assertOk();
        $this->assertDatabaseCount('certificado.certificado_firma', 2);
        $this->assertDatabaseHas('certificado.certificado_firma', ['id_persona_trabajador' => 11, 'orden_firma' => 2, 'fecha_firma' => null, 'id_firma' => null]);
        $this->assertDatabaseHas('certificado.certificado_documento', ['modalidad_firma' => mb_strtoupper($mode)]);
        $this->assertDatabaseHas('certificado.certificado', ['estado' => 'PREPARADO', 'fecha_emision' => null]);
        $this->assertDatabaseCount('certificado.firma', 0);
        $document = DB::table('certificado.certificado_documento')->where('id_certificado_documento', $prepared['id_certificado_documento'])->first();
        self::assertSame($pdf, Storage::disk('local')->get($document->ruta_archivo));
        $this->getJson('/api/certificados-documentos/'.$prepared['id_certificado_documento'].'/firma/progreso')->assertOk()
            ->assertJsonPath('data.pdf_revisado_disponible', true)->assertJsonPath('data.requiere_nueva_revision_pdf', false);
    }

    public function test_changed_worker_position_invalidates_review_without_persisting_or_signing(): void
    {
        $this->administrativeWorkers();
        $input = ['accion' => 'revisar_documento', 'id_participacion' => 1, 'id_tipo_certificado' => 1,
            'mensaje' => 'Mensaje aprobado.', 'modalidad_firma' => 'Paralela',
            'firmantes' => [['id_persona_trabajador' => 10, 'orden_firma' => 1]]];
        $review = $this->postJson(self::URL, $input)->assertOk()->json('data');
        DB::table('gestion.persona_trabajador')->where('id_persona_trabajador', 10)->update(['cargo' => 'Otro cargo real']);

        $this->postJson(self::URL, ['accion' => 'confirmar_preparacion', 'token_revision' => $review['token_revision'], 'confirmar' => true])->assertConflict();
        $this->assertDatabaseCount('certificado.certificado', 0);
        $this->assertDatabaseCount('certificado.certificado_documento', 0);
        $this->assertDatabaseCount('certificado.certificado_firma', 0);
    }

    #[TestWith(['missing_mode', 422])]
    #[TestWith(['duplicate', 422])]
    #[TestWith(['order_gap', 422])]
    #[TestWith(['inactive_worker', 422])]
    #[TestWith(['disabled_worker', 422])]
    #[TestWith(['missing_schema', 409])]
    public function test_selected_worker_review_rejects_invalid_plans_without_persistence(string $condition, int $status): void
    {
        $this->administrativeWorkers();
        $input = ['accion' => 'revisar_documento', 'id_participacion' => 1, 'id_tipo_certificado' => 1,
            'mensaje' => 'Mensaje aprobado.', 'modalidad_firma' => 'Paralela',
            'firmantes' => [['id_persona_trabajador' => 10, 'orden_firma' => 1]]];
        match ($condition) {
            'missing_mode' => $input = array_diff_key($input, ['modalidad_firma' => true]),
            'duplicate' => $input['firmantes'][] = ['id_persona_trabajador' => 10, 'orden_firma' => 2],
            'order_gap' => $input['firmantes'][0]['orden_firma'] = 2,
            'inactive_worker' => DB::table('gestion.persona_trabajador')->where('id_persona_trabajador', 10)->update(['estado' => false]),
            'disabled_worker' => DB::table('gestion.persona_trabajador')->where('id_persona_trabajador', 10)->update(['habilitado_firma_certificados' => false]),
            'missing_schema' => DB::statement('ALTER TABLE certificado.certificado_documento DROP COLUMN modalidad_firma'),
        };

        $this->postJson(self::URL, $input)->assertStatus($status);
        $this->assertDatabaseCount('certificado.certificado', 0);
        $this->assertDatabaseCount('certificado.certificado_firma', 0);
        $this->assertDatabaseCount('certificado.firma', 0);
        self::assertSame([], Storage::disk('local')->allFiles('certificados/revisiones'));
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null, 'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'app.frontend_url' => 'https://municipalidad.test', 'certification.documents.verification_base_url' => null,
            'certification.preparation.session_type_ids' => [1]]);
        DB::purge('sqlite');
        foreach (['gestion', 'certificado', 'acceso'] as $schema) {
            DB::statement("ATTACH DATABASE ':memory:' AS {$schema}");
        }
        foreach ([
            'gestion.area' => 'id_area INTEGER PRIMARY KEY, nombre TEXT',
            'gestion.persona' => 'id_persona INTEGER PRIMARY KEY, nombres TEXT, apellido_paterno TEXT, apellido_materno TEXT',
            'gestion.persona_trabajador' => 'id_persona_trabajador INTEGER PRIMARY KEY, id_persona INTEGER, id_area INTEGER, cargo TEXT, estado BOOLEAN, habilitado_firma_certificados BOOLEAN NOT NULL DEFAULT FALSE, fecha_ingreso DATE, fecha_fin DATE',
            'certificado.actividad' => 'id_actividad INTEGER PRIMARY KEY, id_area INTEGER, nombre TEXT, estado TEXT',
            'certificado.actividad_sesion' => 'id_actividad_sesion INTEGER PRIMARY KEY, id_actividad INTEGER, nombre TEXT, estado TEXT, fecha DATE, lugar TEXT',
            'certificado.participacion' => 'id_participacion INTEGER PRIMARY KEY, id_actividad INTEGER, id_persona INTEGER, estado TEXT',
            'certificado.asistencia' => 'id_asistencia INTEGER PRIMARY KEY, id_participacion INTEGER, id_actividad_sesion INTEGER, estado TEXT, UNIQUE(id_participacion,id_actividad_sesion)',
            'certificado.tipo_certificado' => 'id_tipo_certificado INTEGER PRIMARY KEY, nombre_tipo TEXT, estado BOOLEAN',
            'certificado.firma' => 'id_firma INTEGER PRIMARY KEY, id_persona_trabajador INTEGER',
            'certificado.actividad_firma' => 'id_actividad_firma INTEGER PRIMARY KEY, id_actividad INTEGER, id_firma INTEGER, orden_firma INTEGER',
            'certificado.certificado' => 'id_certificado INTEGER PRIMARY KEY, id_persona INTEGER NOT NULL, id_tipo_certificado INTEGER NOT NULL, id_participacion INTEGER, id_actividad_sesion INTEGER,
                id_practica INTEGER, id_reconocimiento INTEGER, fecha_emision DATE, estado TEXT NOT NULL
                CHECK (estado IN ("PREPARADO", "PENDIENTE_FIRMA", "FIRMADO", "EMITIDO", "ANULADO")),
                notificado BOOLEAN, fecha_notificacion TEXT, CHECK (estado <> "EMITIDO" OR fecha_emision IS NOT NULL),
                FOREIGN KEY(id_participacion,id_actividad_sesion) REFERENCES asistencia(id_participacion,id_actividad_sesion) ON UPDATE RESTRICT ON DELETE RESTRICT',
            'certificado.certificado_documento' => 'id_certificado_documento INTEGER PRIMARY KEY, id_certificado INTEGER NOT NULL, codigo_verificacion TEXT NOT NULL UNIQUE,
                hash_certificado TEXT NOT NULL UNIQUE, ruta_archivo TEXT NOT NULL, fecha_generacion TEXT, motivo_reemision TEXT, estado TEXT,
                mensaje_aprobado TEXT, estado_flujo TEXT, modalidad_firma TEXT',
            'certificado.certificado_firma' => 'id_certificado_firma INTEGER PRIMARY KEY, id_certificado_documento INTEGER, id_firma INTEGER, orden_firma INTEGER,
                id_persona INTEGER, nombre_aprobado TEXT, cargo_aprobado TEXT, estado_firma TEXT, fecha_firma TEXT,
                UNIQUE(id_certificado_documento,id_firma), UNIQUE(id_certificado_documento,id_persona)',
            'certificado.solicitud_actividad' => 'id_solicitud_actividad INTEGER PRIMARY KEY, id_actividad INTEGER, estado TEXT',
            'certificado.solicitud_decision' => 'id_solicitud_decision INTEGER PRIMARY KEY, id_solicitud_actividad INTEGER, id_certificado_documento INTEGER,
                id_usuario_responsable INTEGER, tipo_decision TEXT, estado_anterior TEXT, estado_nuevo TEXT, observacion TEXT, fecha_decision TEXT,
                clave_operacion TEXT, datos TEXT, UNIQUE(id_certificado_documento,clave_operacion)',
            'certificado.consulta_certificado' => 'id_consulta_certificado INTEGER PRIMARY KEY, id_certificado_documento INTEGER',
        ] as $table => $columns) {
            DB::statement("CREATE TABLE {$table} ({$columns})");
        }
        DB::statement('CREATE UNIQUE INDEX certificado.certificado_participacion_sesion_tipo_unique ON certificado(id_participacion,id_actividad_sesion,id_tipo_certificado) WHERE id_actividad_sesion IS NOT NULL');
    }

    private function actor(int $id = 8, bool $administrator = true, array $permissions = ['actividades.ver', 'participantes.ver', 'emision.ver', 'emision.crear', 'emision.editar']): User
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

        return $user;
    }

    private function seedCertificateContext(int $signerCount = 1): void
    {
        Storage::fake('local');
        DB::table('gestion.area')->insert(['id_area' => 1, 'nombre' => 'Área de pruebas']);
        DB::table('gestion.persona')->insert(['id_persona' => 1, 'nombres' => 'Beneficiario', 'apellido_paterno' => 'Prueba']);
        DB::table('certificado.actividad')->insert(['id_actividad' => 1, 'id_area' => 1, 'nombre' => 'Actividad de prueba', 'estado' => 'Activa']);
        DB::table('certificado.actividad_sesion')->insert(['id_actividad_sesion' => 1, 'id_actividad' => 1, 'nombre' => 'Sesión concreta', 'estado' => 'Finalizada', 'fecha' => '2026-11-01']);
        DB::table('certificado.participacion')->insert(['id_participacion' => 1, 'id_persona' => 1, 'id_actividad' => 1, 'estado' => 'Activo']);
        DB::table('certificado.asistencia')->insert(['id_asistencia' => 1, 'id_participacion' => 1, 'id_actividad_sesion' => 1, 'estado' => 'PRESENTE']);
        DB::table('certificado.tipo_certificado')->insert(['id_tipo_certificado' => 1, 'nombre_tipo' => 'Capacitación', 'estado' => true]);
        DB::table('certificado.solicitud_actividad')->insert(['id_solicitud_actividad' => 1, 'id_actividad' => 1, 'estado' => 'Aprobada']);
        foreach ($signerCount === 0 ? [] : range(10, 9 + $signerCount) as $id) {
            DB::table('gestion.persona')->insert(['id_persona' => $id, 'nombres' => 'Responsable '.$id]);
            DB::table('gestion.persona_trabajador')->insert(['id_persona_trabajador' => $id, 'id_persona' => $id, 'id_area' => 1,
                'cargo' => 'Cargo real '.$id, 'estado' => true, 'habilitado_firma_certificados' => true, 'fecha_ingreso' => '2020-01-01']);
            DB::table('certificado.firma')->insert(['id_firma' => $id, 'id_persona_trabajador' => $id]);
            DB::table('certificado.actividad_firma')->insert(['id_actividad_firma' => $id, 'id_actividad' => 1, 'id_firma' => $id, 'orden_firma' => $id - 9]);
        }
    }

    private function minimalSessionSchema(bool $identity = true, bool $nullableEmission = true): void
    {
        DB::statement('DROP TABLE certificado.certificado_documento');
        DB::statement('CREATE TABLE certificado.certificado_documento (id_certificado_documento INTEGER PRIMARY KEY,
            id_certificado INTEGER NOT NULL, codigo_verificacion TEXT UNIQUE NOT NULL, hash_certificado TEXT UNIQUE NOT NULL,
            ruta_archivo TEXT NOT NULL, fecha_generacion TEXT NOT NULL, estado TEXT NOT NULL, motivo_reemision TEXT)');
        DB::statement('DROP TABLE certificado.certificado_firma');
        DB::statement('CREATE TABLE certificado.certificado_firma (id_certificado_firma INTEGER PRIMARY KEY,
            id_certificado_documento INTEGER, id_firma INTEGER, orden_firma INTEGER, fecha_firma TEXT NOT NULL,
            UNIQUE(id_certificado_documento,id_firma))');
        DB::statement('DROP TABLE certificado.solicitud_decision');
        DB::statement('CREATE TABLE certificado.solicitud_decision (id_solicitud_decision INTEGER PRIMARY KEY,
            id_solicitud_actividad INTEGER NOT NULL, id_usuario_responsable INTEGER, tipo_decision TEXT,
            estado_anterior TEXT, estado_nuevo TEXT, observacion TEXT, fecha_decision TEXT)');
        DB::statement('CREATE TABLE gestion.persona_practicante (id_persona_practicante INTEGER PRIMARY KEY, id_persona INTEGER)');
        DB::statement('CREATE TABLE gestion.practica (id_practica INTEGER PRIMARY KEY, id_persona_practicante INTEGER, id_area INTEGER)');
        DB::statement('CREATE TABLE certificado.reconocimiento (id_reconocimiento INTEGER PRIMARY KEY, id_persona_trabajador INTEGER)');
        if (! $identity || ! $nullableEmission) {
            DB::statement('DROP TABLE certificado.certificado');
            $session = $identity ? 'id_actividad_sesion INTEGER,' : '';
            $required = $nullableEmission ? '' : 'NOT NULL';
            $reference = $identity ? ', FOREIGN KEY(id_participacion,id_actividad_sesion) REFERENCES asistencia(id_participacion,id_actividad_sesion) ON UPDATE RESTRICT ON DELETE RESTRICT' : '';
            DB::statement("CREATE TABLE certificado.certificado (id_certificado INTEGER PRIMARY KEY, id_persona INTEGER,
                id_tipo_certificado INTEGER, id_participacion INTEGER, {$session} id_practica INTEGER, id_reconocimiento INTEGER,
                fecha_emision DATE {$required}, estado TEXT, notificado BOOLEAN, fecha_notificacion TEXT {$reference})");
            if ($identity) {
                DB::statement('CREATE UNIQUE INDEX certificado.certificado_participacion_sesion_tipo_unique ON certificado(id_participacion,id_actividad_sesion,id_tipo_certificado) WHERE id_actividad_sesion IS NOT NULL');
            }
        }
    }

    #[TestWith([1])]
    #[TestWith([2])]
    #[TestWith([3])]
    #[TestWith([4])]
    #[TestWith([0])]
    public function test_minimal_session_schema_confirms_pdf_then_uses_existing_management_routes(int $count): void
    {
        $this->minimalSessionSchema();
        $this->seedCertificateContext($count);
        $this->actor();
        $message = "  Mensaje exacto aprobado.\nAcción & Perú.  ";
        $review = $this->postJson(self::URL, ['accion' => 'revisar_documento', 'id_participacion' => 1,
            'id_tipo_certificado' => 1, 'mensaje' => $message])->assertOk()->json('data');
        $pdf = base64_decode($review['vista_previa_pdf']['contenido_base64'], true);
        $confirmation = ['accion' => 'confirmar_preparacion', 'token_revision' => $review['token_revision'], 'confirmar' => true];

        $prepared = $this->postJson(self::URL, $confirmation)->assertCreated()->assertJsonPath('data.estado_flujo', 'PREPARADO')
            ->assertJsonPath('data.fecha_emision', null)->assertJsonPath('data.id_sesion', 1)
            ->assertJsonPath('data.mensaje_aprobado', $message)->json('data');
        $id = $prepared['id_certificado_documento'];
        $this->getJson('/api/certificados/verificar/'.$prepared['codigo_verificacion'])->assertOk()
            ->assertJsonPath('data.estado_certificado', 'PREPARADO')
            ->assertJsonPath('data.emision_oficial_verificada', false)->assertJsonPath('data.puede_ver_documento', false);
        $this->getJson('/api/certificados/verificar/'.$prepared['codigo_verificacion'].'?archivo=1')->assertForbidden();
        $this->patchJson('/api/certificados/update/'.$prepared['id_certificado'], ['estado' => 'EMITIDO'])->assertConflict();
        $this->patchJson('/api/certificados-documentos/update/'.$id, ['estado' => 'Anulado'])->assertConflict();
        $this->postJson(self::URL, $confirmation)->assertOk()->assertJsonPath('data.id_certificado_documento', $id)
            ->assertJsonPath('data.mensaje_aprobado', $message);
        $disk = Storage::disk('local');
        $disk->delete($disk->files('certificados/revisiones'));
        $this->postJson(self::URL, ['accion' => 'revisar_documento', 'id_participacion' => 1,
            'id_tipo_certificado' => 1, 'mensaje' => 'Texto del cliente'])->assertOk()
            ->assertJsonPath('data.mensaje_aprobado', $message)
            ->assertJsonPath('data.hash_pdf', hash('sha256', $pdf));
        $managementReview = $this->postJson('/api/certificados-documentos/'.$id.'/revisar-envio')->assertOk()->json('data');
        self::assertSame($pdf, base64_decode($managementReview['vista_previa_pdf']['contenido_base64'], true));
        $payload = ['token_revision' => $managementReview['token_revision'], 'confirmar' => true];
        $this->postJson('/api/certificados-documentos/'.$id.'/enviar-gerencia', $payload)->assertOk()
            ->assertJsonPath('data.estado_flujo', 'PENDIENTE_FIRMA')->assertJsonPath('data.reintento', false);
        $this->postJson('/api/certificados-documentos/'.$id.'/enviar-gerencia', $payload)->assertOk()->assertJsonPath('data.reintento', true);
        $this->getJson('/api/certificados-documentos/detail/'.$id)->assertOk()->assertJsonPath('data.id_sesion', 1)
            ->assertJsonPath('data.origen', 'sesion')->assertJsonPath('data.firmas.pendientes', 0)->assertJsonPath('data.fecha_emision', null)
            ->assertJsonPath('data.asignacion_firmantes_pendiente', true)
            ->assertJsonPath('data.facultades.firmar', false)->assertJsonPath('data.facultades.entregar', false);
        $download = $this->get('/api/certificados-documentos/'.$id.'/archivo')->assertOk();
        self::assertSame($pdf, file_get_contents($download->baseResponse->getFile()->getPathname()));
        $this->getJson('/api/certificados-documentos/list?bandeja=por_firmar')->assertOk()->assertJsonPath('data.data.0.id_sesion', 1);
        $this->getJson('/api/certificados/verificar/'.$prepared['codigo_verificacion'])->assertOk()
            ->assertJsonPath('data.puede_ver_documento', false)->assertJsonPath('data.firma_verificada', false);
        $this->assertDatabaseHas('certificado.certificado', ['id_actividad_sesion' => 1, 'fecha_emision' => null, 'estado' => 'PENDIENTE_FIRMA', 'notificado' => false]);
        $this->assertDatabaseCount('certificado.certificado_documento', 1);
        $this->assertDatabaseCount('certificado.certificado_firma', 0);
        $this->assertDatabaseCount('certificado.solicitud_decision', 0);
        self::assertSame(hash('sha256', $pdf), DB::table('certificado.certificado_documento')->value('hash_certificado'));
    }

    #[TestWith(['FIRMADO'])]
    #[TestWith(['EMITIDO'])]
    public function test_generic_crud_cannot_advance_prepared_certificate_without_real_signature(string $state): void
    {
        $this->minimalSessionSchema();
        $this->seedCertificateContext(0);
        $this->actor();
        $review = $this->review();
        $prepared = $this->postJson(self::URL, ['accion' => 'confirmar_preparacion',
            'token_revision' => $review['token_revision'], 'confirmar' => true])->assertCreated()->json('data');

        $this->patchJson('/api/certificados/update/'.$prepared['id_certificado'], [
            'estado' => $state, 'fecha_emision' => '2026-11-10', 'notificado' => true,
            'fecha_notificacion' => '2026-11-10',
        ])->assertConflict();

        $this->assertDatabaseHas('certificado.certificado', ['id_certificado' => $prepared['id_certificado'],
            'estado' => 'PREPARADO', 'fecha_emision' => null, 'notificado' => false]);
        $this->assertDatabaseCount('certificado.certificado_firma', 0);
    }

    #[TestWith([false, false])]
    #[TestWith([true, false])]
    public function test_session_confirmation_stays_blocked_without_identity_or_without_nullable_emission(bool $identity, bool $nullable): void
    {
        $this->minimalSessionSchema($identity, $nullable);
        $this->seedCertificateContext();
        $this->actor();

        $this->postJson(self::URL, ['accion' => 'revisar_documento', 'id_participacion' => 1,
            'id_tipo_certificado' => 1, 'mensaje' => 'Mensaje aprobado'])->assertConflict();
        $this->postJson(self::URL, ['accion' => 'confirmar_preparacion', 'token_revision' => str_repeat('a', 64), 'confirmar' => true])->assertConflict();

        $this->assertDatabaseCount('certificado.certificado', 0);
        $this->assertDatabaseCount('certificado.certificado_documento', 0);
        self::assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_session_identity_is_revalidated_on_the_next_http_request(): void
    {
        $this->minimalSessionSchema();
        $this->seedCertificateContext(0);
        $this->actor();
        $payload = ['accion' => 'revisar_documento', 'id_participacion' => 1,
            'id_tipo_certificado' => 1, 'mensaje' => 'Mensaje de revisión.'];
        $this->postJson(self::URL, $payload)->assertOk();
        DB::statement('DROP INDEX certificado.certificado_participacion_sesion_tipo_unique');

        $this->postJson(self::URL, $payload)->assertConflict();

        $this->assertDatabaseCount('certificado.certificado', 0);
        $this->assertDatabaseCount('certificado.certificado_documento', 0);
    }

    public function test_minimal_schema_rejects_another_actor_altered_pdf_and_duplicate_session(): void
    {
        $this->minimalSessionSchema();
        $this->seedCertificateContext();
        $this->actor();
        $review = $this->review();
        $payload = ['accion' => 'confirmar_preparacion', 'token_revision' => $review['token_revision'], 'confirmar' => true];
        $this->actor(9);

        $this->postJson(self::URL, $payload)->assertForbidden();
        $this->actor();
        $path = 'certificados/revisiones/'.hash('sha256', $review['token_revision']).'.pdf';
        $original = Storage::disk('local')->get($path);
        Storage::disk('local')->put($path, '%PDF-altered');
        $this->postJson(self::URL, $payload)->assertConflict();
        $this->assertDatabaseCount('certificado.certificado', 0);
        Storage::disk('local')->put($path, $original);
        $this->postJson(self::URL, $payload)->assertCreated();
        $second = $this->review();
        $this->postJson(self::URL, ['accion' => 'confirmar_preparacion', 'token_revision' => $second['token_revision'], 'confirmar' => true])->assertOk()->assertJsonPath('data.creado', false);

        $this->assertDatabaseCount('certificado.certificado', 1);
        $this->assertDatabaseCount('certificado.certificado_documento', 1);
        $this->assertDatabaseCount('certificado.certificado_firma', 0);
    }

    public function test_minimal_schema_preserves_distinct_sessions_for_the_same_participation(): void
    {
        $this->minimalSessionSchema();
        $this->seedCertificateContext();
        $this->actor();
        $first = $this->review();
        $this->postJson(self::URL, ['accion' => 'confirmar_preparacion', 'token_revision' => $first['token_revision'], 'confirmar' => true])->assertCreated();
        DB::table('certificado.actividad_sesion')->insert(['id_actividad_sesion' => 2, 'id_actividad' => 1,
            'nombre' => 'Segunda sesión', 'estado' => 'Finalizada', 'fecha' => '2026-11-02']);
        DB::table('certificado.asistencia')->insert(['id_asistencia' => 2, 'id_participacion' => 1, 'id_actividad_sesion' => 2, 'estado' => 'PRESENTE']);
        $url = '/api/activity-session/2/preparar-certificados';
        $second = $this->postJson($url, ['accion' => 'revisar_documento', 'id_participacion' => 1,
            'id_tipo_certificado' => 1, 'mensaje' => 'Mensaje de la segunda sesión'])->assertOk()->json('data');

        $this->postJson($url, ['accion' => 'confirmar_preparacion', 'token_revision' => $second['token_revision'], 'confirmar' => true])
            ->assertCreated()->assertJsonPath('data.id_sesion', 2)->assertJsonPath('data.fecha_emision', null);

        self::assertSame([1, 2], DB::table('certificado.certificado')->orderBy('id_actividad_sesion')->pluck('id_actividad_sesion')->all());
        $this->assertDatabaseCount('certificado.certificado_documento', 2);
    }

    public function test_minimal_confirmation_rolls_back_if_document_registration_fails(): void
    {
        $this->minimalSessionSchema();
        $this->seedCertificateContext();
        $this->actor();
        $review = $this->review();
        DB::statement("CREATE TRIGGER certificado.reject_prepared_document BEFORE INSERT ON certificado_documento BEGIN SELECT RAISE(ABORT, 'isolated registration failure'); END");

        $this->postJson(self::URL, ['accion' => 'confirmar_preparacion', 'token_revision' => $review['token_revision'], 'confirmar' => true])->assertConflict();

        $this->assertDatabaseCount('certificado.certificado', 0);
        $this->assertDatabaseCount('certificado.certificado_documento', 0);
        self::assertSame([], Storage::disk('local')->allFiles('certificados/preparados'));
    }

    /** @return array<string, mixed> */
    private function review(): array
    {
        return $this->postJson(self::URL, [
            'accion' => 'revisar_documento', 'id_participacion' => 1, 'id_tipo_certificado' => 1,
            'mensaje' => "  Mensaje aprobado: acción y Perú.\nTexto exacto & autorizado.  ", 'modalidad_firma' => 'Paralela',
        ])->assertOk()->json('data');
    }

    #[TestWith(['confirmar_preparacion'])]
    #[TestWith(['enviar_gerencia'])]
    public function test_minimal_preparation_recovers_and_sends_the_same_document_without_selecting_signers(string $action): void
    {
        $this->minimalSessionSchema();
        $this->seedCertificateContext();
        DB::table('gestion.persona_trabajador')->update(['estado' => false, 'habilitado_firma_certificados' => false]);
        $this->actor();
        $input = ['accion' => 'revisar_documento', 'id_participacion' => 1, 'id_tipo_certificado' => 1, 'mensaje' => 'Texto aprobado.'];
        $review = $this->postJson(self::URL, $input)->assertOk()->assertJsonCount(0, 'data.firmantes')->json('data');
        $confirm = ['accion' => $action, 'token_revision' => $review['token_revision'], 'confirmar' => true];
        $prepared = $this->postJson(self::URL, $confirm)->assertCreated()->assertJsonPath('data.preparacion_persistida', true)->json('data');
        $id = $prepared['id_certificado_documento'];
        $original = (array) DB::table('certificado.certificado_documento')->where('id_certificado_documento', $id)->first();
        $pdf = Storage::disk('local')->get($original['ruta_archivo']);
        $this->postJson(self::URL, $confirm)->assertOk()->assertJsonPath('data.id_certificado_documento', $id)
            ->assertJsonPath('data.reintento', true);
        $again = $this->postJson(self::URL, $input)->assertOk()->assertJsonPath('data.creado', false)
            ->assertJsonPath('data.id_certificado_documento', $id)->assertJsonPath('data.mensaje_aprobado', 'Texto aprobado.')->json('data');
        self::assertSame($pdf, base64_decode($again['vista_previa_pdf']['contenido_base64'], true));
        $this->postJson(self::URL, ['accion' => 'confirmar_preparacion', 'token_revision' => $again['token_revision'], 'confirmar' => true])
            ->assertOk()->assertJsonPath('data.id_certificado_documento', $id)->assertJsonPath('data.creado', false);
        $send = ['accion' => 'enviar_gerencia', 'id_certificado_documento' => $id, 'confirmar' => true];
        $this->postJson(self::URL, $send)->assertOk()->assertJsonPath('data.enviado', true)->assertJsonPath('data.estado_flujo', 'PENDIENTE_FIRMA');
        $this->postJson(self::URL, $send)->assertOk()->assertJsonPath('data.reintento', true);
        $this->getJson('/api/certificados-documentos/detail/'.$id)->assertOk()->assertJsonPath('data.estado_certificado', 'PENDIENTE_FIRMA');
        self::assertSame($original, (array) DB::table('certificado.certificado_documento')->where('id_certificado_documento', $id)->first());
        self::assertSame($pdf, Storage::disk('local')->get($original['ruta_archivo']));
        $this->assertDatabaseCount('certificado.certificado', 1);
        $this->assertDatabaseCount('certificado.certificado_documento', 1);
        $this->assertDatabaseCount('certificado.certificado_firma', 0);
    }

    public function test_minimal_pending_review_recovers_an_identical_pdf_reserved_before_a_failed_save(): void
    {
        $this->minimalSessionSchema();
        $this->seedCertificateContext(0);
        $this->actor();
        $input = ['accion' => 'revisar_documento', 'id_participacion' => 1, 'id_tipo_certificado' => 1, 'mensaje' => 'Texto recuperable.'];
        $first = $this->postJson(self::URL, $input)->assertOk()->json('data');
        $manifest = json_decode(Crypt::decryptString(Storage::disk('local')->get('certificados/revisiones/'.hash('sha256', $first['token_revision']).'.manifest')), true);
        $pdf = base64_decode($first['vista_previa_pdf']['contenido_base64'], true);
        $path = 'certificados/preparados/'.$manifest['code'].'.pdf';
        Storage::disk('local')->put($path, $pdf);
        $this->travel(31)->minutes();
        $this->postJson(self::URL, ['accion' => 'confirmar_preparacion', 'token_revision' => $first['token_revision'], 'confirmar' => true])->assertConflict();
        $next = $this->postJson(self::URL, $input)->assertOk()->assertJsonPath('data.revision_reutilizada', true)->json('data');
        self::assertSame($first['hash_pdf'], $next['hash_pdf']);
        self::assertSame($pdf, base64_decode($next['vista_previa_pdf']['contenido_base64'], true));
        $saved = $this->postJson(self::URL, ['accion' => 'confirmar_preparacion', 'token_revision' => $next['token_revision'], 'confirmar' => true])
            ->assertCreated()->json('data');
        self::assertSame($manifest['code'], $saved['codigo_verificacion']);
        self::assertSame($path, DB::table('certificado.certificado_documento')->value('ruta_archivo'));
        self::assertSame($pdf, Storage::disk('local')->get($path));
        $this->assertDatabaseCount('certificado.certificado', 1);
        $this->assertDatabaseCount('certificado.certificado_documento', 1);
    }

    #[TestWith(['documento_anulado'])]
    #[TestWith(['certificado_emitido'])]
    public function test_preparation_recovery_rejects_real_incompatible_states_without_creating_another_certificate(string $condition): void
    {
        $this->minimalSessionSchema();
        $this->seedCertificateContext(0);
        $this->actor();
        $input = ['accion' => 'revisar_documento', 'id_participacion' => 1, 'id_tipo_certificado' => 1, 'mensaje' => 'Texto aprobado.'];
        $review = $this->postJson(self::URL, $input)->assertOk()->json('data');
        $confirm = ['accion' => 'confirmar_preparacion', 'token_revision' => $review['token_revision'], 'confirmar' => true];
        $this->postJson(self::URL, $confirm)->assertCreated();
        $document = DB::table('certificado.certificado_documento')->first();
        $pdf = Storage::disk('local')->get($document->ruta_archivo);
        if ($condition === 'documento_anulado') {
            DB::table('certificado.certificado_documento')->update(['estado' => 'Anulado']);
        } else {
            DB::table('certificado.certificado')->update(['estado' => 'EMITIDO', 'fecha_emision' => today()->toDateString()]);
        }
        $this->postJson(self::URL, $confirm)->assertConflict();
        $this->postJson(self::URL, $input)->assertConflict();
        self::assertSame($pdf, Storage::disk('local')->get($document->ruta_archivo));
        $this->assertDatabaseCount('certificado.certificado', 1);
        $this->assertDatabaseCount('certificado.certificado_documento', 1);
    }

    public function test_disabling_an_activity_signer_after_review_prevents_new_documental_persistence(): void
    {
        $this->seedCertificateContext();
        $this->actor();
        $review = $this->review();
        DB::table('gestion.persona_trabajador')->where('id_persona_trabajador', 10)
            ->update(['habilitado_firma_certificados' => false]);

        $this->postJson(self::URL, ['accion' => 'enviar_gerencia', 'token_revision' => $review['token_revision'],
            'confirmar' => true])->assertUnprocessable();
        $this->assertDatabaseCount('certificado.certificado', 0);
        $this->assertDatabaseCount('certificado.certificado_documento', 0);
        $this->assertDatabaseCount('certificado.certificado_firma', 0);
    }

    /** @return array<string, mixed> */
    private function send(string $token): array
    {
        return $this->postJson(self::URL, ['accion' => 'enviar_gerencia', 'token_revision' => $token, 'confirmar' => true])->assertCreated()->json('data');
    }

    #[TestWith([1])]
    #[TestWith([2])]
    #[TestWith([3])]
    #[TestWith([4])]
    public function test_confirmed_pdf_message_identity_and_variable_pending_signers_are_persisted(int $count): void
    {
        $this->seedCertificateContext($count);
        $this->actor();
        $review = $this->review();
        $bytes = base64_decode($review['vista_previa_pdf']['contenido_base64'], true);
        $this->assertDatabaseCount('certificado.certificado', 0);

        $sent = $this->send($review['token_revision']);

        self::assertSame(CertificateManagement::PENDING, $sent['estado_flujo']);
        self::assertSame($review['mensaje'], $sent['mensaje_aprobado']);
        self::assertNull($sent['fecha_emision']);
        self::assertFalse($sent['emitida']);
        self::assertSame(1, $sent['contexto']['id_sesion']);
        self::assertCount($count, $sent['firmantes']);
        foreach ($sent['firmantes'] as $signer) {
            self::assertSame('Pendiente', $signer['estado_firma']);
            self::assertNull($signer['fecha_firma']);
            self::assertSame('Cargo real '.$signer['id_persona'], $signer['cargo_aprobado']);
        }
        $document = DB::table('certificado.certificado_documento')->first();
        self::assertSame($bytes, Storage::disk('local')->get($document->ruta_archivo));
        self::assertSame(hash('sha256', $bytes), $document->hash_certificado);
        self::assertSame($review['hash_pdf'], $document->hash_certificado);
        self::assertSame(8, $sent['remision']['id_usuario_responsable']);
        $this->assertDatabaseHas('certificado.certificado', ['id_actividad_sesion' => 1, 'fecha_emision' => null, 'estado' => 'PREPARADO', 'notificado' => false]);
        $this->assertDatabaseCount('certificado.solicitud_decision', 2);
        $download = $this->get('/api/certificados-documentos/'.$document->id_certificado_documento.'/archivo')->assertOk();
        self::assertSame($bytes, file_get_contents($download->baseResponse->getFile()->getPathname()));
        $this->getJson('/api/certificados-documentos/list?bandeja=por_firmar')->assertOk()
            ->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.estado_flujo', 'PENDIENTE_FIRMA');
        $this->getJson('/api/certificados-documentos/detail/'.$document->id_certificado_documento)->assertOk()
            ->assertJsonPath('data.mensaje_aprobado', $review['mensaje']);
        $this->getJson('/api/certificados-documentos/'.$document->id_certificado_documento.'/historial')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/certificados/verificar/'.$document->codigo_verificacion)->assertOk()
            ->assertJsonPath('data.puede_ver_documento', false)->assertJsonPath('data.firma_verificada', false);
        $this->getJson('/api/certificados/verificar/'.$document->codigo_verificacion.'?archivo=1')->assertForbidden();
        $this->get('/api/certificados/verificar/'.$document->codigo_verificacion, ['Accept' => 'text/html'])->assertOk();
    }

    public function test_preparation_then_repeated_remission_and_token_retries_are_idempotent(): void
    {
        $this->seedCertificateContext();
        $this->actor();
        $review = $this->review();
        $prepared = $this->postJson(self::URL, ['accion' => 'confirmar_preparacion', 'token_revision' => $review['token_revision'], 'confirmar' => true])
            ->assertCreated()->assertJsonPath('data.estado_flujo', 'PREPARADO')->json('data');
        $this->assertDatabaseCount('certificado.solicitud_decision', 1);

        $payload = ['accion' => 'enviar_gerencia', 'id_certificado_documento' => $prepared['id_certificado_documento'], 'confirmar' => true];
        $first = $this->postJson(self::URL, $payload)->assertOk()->assertJsonPath('data.reintento', false)->json('data.remision');
        $again = $this->postJson(self::URL, $payload)->assertOk()->assertJsonPath('data.reintento', true)->json('data.remision');
        self::assertSame($first, $again);
        $this->travel(31)->minutes();
        $this->postJson(self::URL, ['accion' => 'enviar_gerencia', 'token_revision' => $review['token_revision'], 'confirmar' => true])
            ->assertOk()->assertJsonPath('data.reintento', true);
        $this->assertDatabaseCount('certificado.certificado', 1);
        $this->assertDatabaseCount('certificado.certificado_documento', 1);
        $this->assertDatabaseCount('certificado.certificado_firma', 1);
        $this->assertDatabaseCount('certificado.solicitud_decision', 2);
    }

    #[TestWith(['pdf'])]
    #[TestWith(['manifest'])]
    #[TestWith(['expired'])]
    #[TestWith(['context'])]
    #[TestWith(['signer'])]
    public function test_tampering_expiry_or_changed_composition_never_persists(string $change): void
    {
        $this->seedCertificateContext();
        $this->actor();
        $review = $this->review();
        $path = 'certificados/revisiones/'.hash('sha256', $review['token_revision']);
        if (in_array($change, ['pdf', 'manifest'], true)) {
            Storage::disk('local')->put($path.'.'.$change, 'altered');
        } elseif ($change === 'expired') {
            $this->travel(31)->minutes();
        } elseif ($change === 'context') {
            DB::table('certificado.actividad_sesion')->where('id_actividad_sesion', 1)->update(['nombre' => 'Otro nombre']);
        } else {
            DB::table('gestion.persona_trabajador')->where('id_persona_trabajador', 10)->update(['cargo' => 'Otro cargo']);
        }

        $this->postJson(self::URL, ['accion' => 'enviar_gerencia', 'token_revision' => $review['token_revision'], 'confirmar' => true])->assertConflict();

        $this->assertDatabaseCount('certificado.certificado', 0);
        $this->assertDatabaseCount('certificado.certificado_documento', 0);
        $this->assertDatabaseCount('certificado.certificado_firma', 0);
        $this->assertDatabaseCount('certificado.solicitud_decision', 0);
        self::assertSame([], Storage::disk('local')->allFiles('certificados/preparados'));
    }

    public function test_another_review_cannot_create_duplicate_certificate(): void
    {
        $this->seedCertificateContext();
        $this->actor();
        $first = $this->review();
        $second = $this->review();
        $saved = $this->send($first['token_revision']);

        $this->postJson(self::URL, ['accion' => 'enviar_gerencia', 'token_revision' => $second['token_revision'], 'confirmar' => true])
            ->assertOk()->assertJsonPath('data.creado', false)->assertJsonPath('data.reintento', true)
            ->assertJsonPath('data.id_certificado_documento', $saved['id_certificado_documento']);

        $this->assertDatabaseCount('certificado.certificado', 1);
        $this->assertDatabaseCount('certificado.certificado_documento', 1);
    }

    public function test_same_participation_can_have_distinct_certificates_for_distinct_sessions(): void
    {
        $this->seedCertificateContext();
        $this->actor();
        $this->send($this->review()['token_revision']);
        DB::table('certificado.actividad_sesion')->insert(['id_actividad_sesion' => 2, 'id_actividad' => 1, 'nombre' => 'Segunda sesión', 'estado' => 'Finalizada', 'fecha' => '2026-11-02']);
        DB::table('certificado.asistencia')->insert(['id_asistencia' => 2, 'id_participacion' => 1, 'id_actividad_sesion' => 2, 'estado' => 'PRESENTE']);
        $url = '/api/activity-session/2/preparar-certificados';
        $review = $this->postJson($url, ['accion' => 'revisar_documento', 'id_participacion' => 1, 'id_tipo_certificado' => 1, 'mensaje' => 'Segunda sesión', 'modalidad_firma' => 'Secuencial'])->assertOk()->json('data');

        $this->postJson($url, ['accion' => 'enviar_gerencia', 'token_revision' => $review['token_revision'], 'confirmar' => true])
            ->assertCreated()->assertJsonPath('data.contexto.id_sesion', 2)->assertJsonPath('data.modalidad_firma', 'Secuencial');

        $this->assertDatabaseCount('certificado.certificado', 2);
        self::assertSame(2, DB::table('certificado.certificado_documento')->distinct()->count('codigo_verificacion'));
    }

    #[TestWith(['session', 409])]
    #[TestWith(['attendance', 409])]
    #[TestWith(['type', 422])]
    #[TestWith(['duplicate_signer', 409])]
    #[TestWith(['inactive_signer', 422])]
    public function test_invalid_context_or_signers_are_rejected(string $condition, int $status): void
    {
        $this->seedCertificateContext(2);
        $this->actor();
        if ($condition === 'session') {
            DB::table('certificado.actividad_sesion')->update(['estado' => 'Activo']);
        } elseif ($condition === 'attendance') {
            DB::table('certificado.asistencia')->update(['estado' => 'AUSENTE']);
        } elseif ($condition === 'type') {
            config(['certification.preparation.session_type_ids' => []]);
        } elseif ($condition === 'duplicate_signer') {
            DB::table('gestion.persona_trabajador')->where('id_persona_trabajador', 11)->update(['id_persona' => 10]);
        } else {
            DB::table('gestion.persona_trabajador')->where('id_persona_trabajador', 11)->update(['estado' => false]);
        }

        $this->postJson(self::URL, ['accion' => 'revisar_documento', 'id_participacion' => 1, 'id_tipo_certificado' => 1, 'mensaje' => 'Texto aprobado', 'modalidad_firma' => 'Paralela'])->assertStatus($status);

        $this->assertDatabaseCount('certificado.certificado', 0);
        self::assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_guests_permissions_token_owner_and_private_document_scope(): void
    {
        $this->seedCertificateContext();
        $this->postJson(self::URL, ['accion' => 'revisar_documento'])->assertUnauthorized();
        $this->getJson('/api/certificados-documentos/list?bandeja=por_firmar')->assertUnauthorized();
        $this->actor(8, true, ['actividades.ver', 'participantes.ver', 'emision.ver']);
        $this->postJson(self::URL, ['accion' => 'revisar_documento'])->assertForbidden();
        $this->actor();
        $review = $this->review();
        $this->actor(9);
        $this->postJson(self::URL, ['accion' => 'enviar_gerencia', 'token_revision' => $review['token_revision'], 'confirmar' => true])->assertForbidden();
        $this->actor();
        $sent = $this->send($review['token_revision']);
        $this->actor(9, false);
        $id = $sent['id_certificado_documento'];

        $this->getJson('/api/certificados-documentos/list?bandeja=por_firmar')->assertOk()->assertJsonPath('data.total', 0);
        $this->getJson('/api/certificados-documentos/detail/'.$id)->assertNotFound();
        $this->getJson('/api/certificados-documentos/'.$id.'/archivo')->assertNotFound();
        $this->getJson('/api/certificados-documentos/'.$id.'/historial')->assertNotFound();
        $this->postJson(self::URL, ['accion' => 'enviar_gerencia', 'id_certificado_documento' => $id, 'confirmar' => true])->assertForbidden();
    }

    public function test_untrusted_hash_and_extra_fields_are_not_confirmation(): void
    {
        $this->seedCertificateContext();
        $this->actor();
        $review = $this->review();

        $this->postJson(self::URL, ['accion' => 'enviar_gerencia', 'token_revision' => $review['token_revision'], 'confirmar' => true, 'hash_pdf' => $review['hash_pdf']])->assertUnprocessable();
        $this->postJson(self::URL, ['accion' => 'confirmar_preparacion', 'token_revision' => 'not-a-token', 'confirmar' => true])->assertUnprocessable();

        $this->assertDatabaseCount('certificado.certificado', 0);
    }

    #[TestWith(['<script>alert(1)</script>'])]
    #[TestWith([''])]
    public function test_review_rejects_active_or_empty_message(string $message): void
    {
        $this->seedCertificateContext();
        $this->actor();

        $this->postJson(self::URL, ['accion' => 'revisar_documento', 'id_participacion' => 1, 'id_tipo_certificado' => 1,
            'mensaje' => $message, 'modalidad_firma' => 'Paralela'])->assertUnprocessable();

        $this->assertDatabaseCount('certificado.certificado', 0);
        self::assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_prepared_pdf_tampering_and_inactive_authority_prevent_remission(): void
    {
        $this->seedCertificateContext();
        $this->actor();
        $review = $this->review();
        $prepared = $this->postJson(self::URL, ['accion' => 'confirmar_preparacion', 'token_revision' => $review['token_revision'], 'confirmar' => true])->assertCreated()->json('data');
        $payload = ['accion' => 'enviar_gerencia', 'id_certificado_documento' => $prepared['id_certificado_documento'], 'confirmar' => true];
        $document = DB::table('certificado.certificado_documento')->first();
        $original = Storage::disk('local')->get($document->ruta_archivo);
        Storage::disk('local')->put($document->ruta_archivo, 'altered');

        $this->postJson(self::URL, $payload)->assertConflict();
        $this->getJson('/api/certificados-documentos/'.$document->id_certificado_documento.'/archivo')->assertConflict();
        Storage::disk('local')->put($document->ruta_archivo, $original);
        DB::table('gestion.persona_trabajador')->where('id_persona_trabajador', 10)->update(['estado' => false]);
        $this->postJson(self::URL, $payload)->assertConflict();

        $this->assertDatabaseCount('certificado.solicitud_decision', 1);
        $this->assertDatabaseHas('certificado.certificado_documento', ['estado_flujo' => 'PREPARADO']);
    }

    public function test_missing_schema_returns_actionable_conflict_without_writes(): void
    {
        $this->seedCertificateContext();
        $this->actor();
        DB::statement('ALTER TABLE certificado.certificado_documento DROP COLUMN mensaje_aprobado');

        $this->postJson(self::URL, ['accion' => 'revisar_documento', 'id_participacion' => 1, 'id_tipo_certificado' => 1, 'mensaje' => 'Texto aprobado', 'modalidad_firma' => 'Paralela'])
            ->assertConflict()->assertJsonPath('message', 'Faltan columnas necesarias para la Etapa A; no se ha modificado el esquema.');

        $this->assertDatabaseCount('certificado.certificado', 0);
    }

    public function test_failure_after_file_creation_rolls_back_every_row_and_removes_the_new_file(): void
    {
        $this->seedCertificateContext();
        $this->actor();
        $review = $this->review();
        DB::statement("CREATE TRIGGER certificado.reject_pending BEFORE INSERT ON certificado_firma BEGIN SELECT RAISE(ABORT, 'fixture storage transaction failure'); END");

        $this->postJson(self::URL, ['accion' => 'enviar_gerencia', 'token_revision' => $review['token_revision'], 'confirmar' => true])->assertConflict();

        $this->assertDatabaseCount('certificado.certificado', 0);
        $this->assertDatabaseCount('certificado.certificado_documento', 0);
        $this->assertDatabaseCount('certificado.certificado_firma', 0);
        $this->assertDatabaseCount('certificado.solicitud_decision', 0);
        self::assertSame([], Storage::disk('local')->allFiles('certificados/preparados'));
    }

    public function test_existing_file_is_never_overwritten_or_deleted_when_reservation_fails(): void
    {
        $this->seedCertificateContext();
        $this->actor();
        $review = $this->review();
        $files = Storage::disk('local')->allFiles('certificados/revisiones');
        $manifestPath = collect($files)->first(fn (string $path): bool => str_ends_with($path, '.manifest'));
        $manifest = json_decode(Crypt::decryptString(Storage::disk('local')->get($manifestPath)), true);
        $target = 'certificados/preparados/'.$manifest['code'].'.pdf';
        Storage::disk('local')->put($target, 'pre-existing evidence');

        $this->postJson(self::URL, ['accion' => 'enviar_gerencia', 'token_revision' => $review['token_revision'], 'confirmar' => true])->assertConflict();

        self::assertSame('pre-existing evidence', Storage::disk('local')->get($target));
        $this->assertDatabaseCount('certificado.certificado', 0);
    }

    public function test_prepared_records_cannot_be_mutated_through_generic_crud(): void
    {
        $this->seedCertificateContext();
        $this->actor();
        $sent = $this->send($this->review()['token_revision']);

        $this->patchJson('/api/certificados/update/'.$sent['id_certificado'], ['estado' => 'Vigente'])->assertNotFound();
        $this->patchJson('/api/certificados-documentos/update/'.$sent['id_certificado_documento'], ['estado' => 'Anulado'])->assertConflict();
        $this->postJson('/api/certificados-firmas/create', ['id_certificado_documento' => $sent['id_certificado_documento'],
            'id_firma' => 10, 'fecha_firma' => '2026-11-10', 'orden_firma' => 1])->assertForbidden();
        $this->actor(10);
        $this->postJson('/api/certificados-firmas/create', ['id_certificado_documento' => $sent['id_certificado_documento'],
            'id_firma' => 10, 'fecha_firma' => '2026-11-10', 'orden_firma' => 1])->assertConflict();

        $this->assertDatabaseHas('certificado.certificado_documento', ['estado_flujo' => 'PENDIENTE_FIRMA', 'estado' => 'Vigente']);
    }

    public function test_storage_failure_does_not_leave_database_rows(): void
    {
        $this->seedCertificateContext();
        $this->actor();
        $review = $this->review();
        $disk = Storage::disk('local');
        $failingDisk = Mockery::mock(FilesystemAdapter::class.'[makeDirectory]', [$disk->getDriver(), $disk->getAdapter(), $disk->getConfig()]);
        $failingDisk->shouldReceive('makeDirectory')->once()->with('certificados/preparados')->andReturnFalse();
        Storage::set('local', $failingDisk);

        $this->postJson(self::URL, ['accion' => 'enviar_gerencia', 'token_revision' => $review['token_revision'], 'confirmar' => true])->assertStatus(503);

        $this->assertDatabaseCount('certificado.certificado', 0);
        $this->assertDatabaseCount('certificado.certificado_documento', 0);
        $this->assertDatabaseCount('certificado.certificado_firma', 0);
        $this->assertDatabaseCount('certificado.solicitud_decision', 0);
        self::assertSame([], $disk->allFiles('certificados/preparados'));
    }

    public function test_valid_jwt_cookie_uses_real_permissions_and_revocation_of_access(): void
    {
        $this->seedCertificateContext();
        config(['jwt.secret' => str_repeat('stage-a-isolated-secret-', 4)]);
        foreach ([
            'acceso.usuario' => 'id_usuario INTEGER PRIMARY KEY, id_persona INTEGER, correo TEXT, password_hash TEXT, estado BOOLEAN, bloqueado_hasta TEXT',
            'acceso.rol' => 'id_rol INTEGER PRIMARY KEY, nombre TEXT, estado BOOLEAN',
            'acceso.usuario_rol' => 'id_usuario_rol INTEGER PRIMARY KEY, id_usuario INTEGER, id_rol INTEGER',
            'acceso.modulo' => 'id_modulo INTEGER PRIMARY KEY, nombre TEXT, url TEXT, estado BOOLEAN',
            'acceso.permiso' => 'id_permiso INTEGER PRIMARY KEY, id_modulo INTEGER, clave TEXT, estado BOOLEAN',
            'acceso.rol_modulo' => 'id_rol INTEGER, id_modulo INTEGER',
            'acceso.rol_modulo_permiso' => 'id_rol INTEGER, id_modulo INTEGER, id_permiso INTEGER',
        ] as $table => $columns) {
            DB::statement("CREATE TABLE {$table} ({$columns})");
        }
        DB::table('acceso.usuario')->insert(['id_usuario' => 10, 'id_persona' => 10, 'correo' => 'stage-a@example.test', 'estado' => true]);
        DB::table('acceso.rol')->insert(['id_rol' => 2, 'nombre' => 'Responsable de prueba', 'estado' => true]);
        DB::table('acceso.usuario_rol')->insert(['id_usuario_rol' => 1, 'id_usuario' => 10, 'id_rol' => 2]);
        foreach (['actividades', 'participantes', 'emision'] as $index => $module) {
            $moduleId = $index + 1;
            DB::table('acceso.modulo')->insert(['id_modulo' => $moduleId, 'nombre' => $module, 'url' => '/'.$module, 'estado' => true]);
            DB::table('acceso.rol_modulo')->insert(['id_rol' => 2, 'id_modulo' => $moduleId]);
            foreach (['ver', 'crear', 'editar'] as $action) {
                $permission = DB::table('acceso.permiso')->insertGetId(['id_modulo' => $moduleId, 'clave' => $module.'.'.$action, 'estado' => true], 'id_permiso');
                DB::table('acceso.rol_modulo_permiso')->insert(['id_rol' => 2, 'id_modulo' => $moduleId, 'id_permiso' => $permission]);
            }
        }
        Auth::forgetGuards();
        $this->withCredentials()->withUnencryptedCookie(JwtService::COOKIE, app(JwtService::class)->createToken(User::findOrFail(10)));

        $sent = $this->send($this->review()['token_revision']);

        self::assertSame(10, $sent['remision']['id_usuario_responsable']);
        Auth::forgetGuards();
        $this->getJson('/api/certificados-documentos/'.$sent['id_certificado_documento'].'/archivo')->assertOk();
        DB::table('acceso.permiso')->where('clave', 'emision.ver')->update(['estado' => false]);
        Auth::forgetGuards();
        $this->getJson('/api/certificados-documentos/'.$sent['id_certificado_documento'].'/archivo')->assertForbidden();
        $this->assertDatabaseCount('certificado.solicitud_decision', 2);
    }
}
