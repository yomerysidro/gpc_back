<?php

namespace Tests\Feature;

use App\Models\Role\Role;
use App\Models\User\User;
use App\Services\Auth\JwtService;
use App\Services\CertificateDocumentGenerator;
use App\Services\CertificateDocumentWorkflow;
use App\Services\CertificateOrigins;
use App\Services\SessionSpeakers;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class CertificateOriginsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'database.connections.sqlite.url' => null,
            'app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);
        DB::purge('sqlite');
        DB::statement("ATTACH DATABASE ':memory:' AS gestion");
        DB::statement("ATTACH DATABASE ':memory:' AS certificado");
        foreach ([
            'gestion.area' => 'id_area INTEGER PRIMARY KEY, nombre TEXT',
            'gestion.persona' => 'id_persona INTEGER PRIMARY KEY, nombres TEXT, apellido_paterno TEXT, apellido_materno TEXT',
            'gestion.persona_trabajador' => 'id_persona_trabajador INTEGER PRIMARY KEY, id_persona INTEGER, id_area INTEGER, estado BOOLEAN, fecha_ingreso DATE, fecha_fin DATE, cargo TEXT, habilitado_firma_certificados BOOLEAN',
            'gestion.institucion' => 'id_institucion INTEGER PRIMARY KEY, nombre_institucion TEXT',
            'gestion.persona_ponente' => 'id_persona_ponente INTEGER PRIMARY KEY, id_persona INTEGER, id_institucion INTEGER, cargo TEXT, especialidad TEXT',
            'gestion.persona_practicante' => 'id_persona_practicante INTEGER PRIMARY KEY, id_persona INTEGER, estado TEXT',
            'gestion.practica' => 'id_practica INTEGER PRIMARY KEY, id_persona_practicante INTEGER, id_institucion INTEGER, id_area INTEGER, carrera TEXT, fecha_inicio DATE, fecha_fin DATE, estado TEXT',
            'gestion.practica_supervisor' => 'id_practica_supervisor INTEGER PRIMARY KEY, id_practica INTEGER, id_persona_trabajador INTEGER, tipo_supervision TEXT, fecha_inicio DATE, fecha_fin DATE, estado TEXT',
            'certificado.tipo_actividad' => 'id_tipo_actividad INTEGER PRIMARY KEY, nombre TEXT, descripcion TEXT, estado BOOLEAN',
            'certificado.tipo_certificado' => 'id_tipo_certificado INTEGER PRIMARY KEY, nombre_tipo TEXT, estado BOOLEAN',
            'certificado.actividad' => 'id_actividad INTEGER PRIMARY KEY, id_area INTEGER, nombre TEXT, estado TEXT, fecha_inicio DATE, fecha_fin DATE',
            'certificado.solicitud_actividad' => 'id_solicitud_actividad INTEGER PRIMARY KEY, id_actividad INTEGER, estado TEXT',
            'certificado.actividad_sesion' => 'id_actividad_sesion INTEGER PRIMARY KEY, id_actividad INTEGER, nombre TEXT, fecha DATE, estado TEXT',
            'certificado.actividad_ponente' => 'id_actividad_ponente INTEGER PRIMARY KEY, id_actividad INTEGER, id_persona_ponente INTEGER',
            'certificado.participacion' => 'id_participacion INTEGER PRIMARY KEY, id_actividad INTEGER, id_persona INTEGER, estado TEXT',
            'certificado.asistencia' => 'id_asistencia INTEGER PRIMARY KEY, id_participacion INTEGER, id_actividad_sesion INTEGER, estado TEXT',
            'certificado.certificado' => 'id_certificado INTEGER PRIMARY KEY, id_persona INTEGER, id_participacion INTEGER, id_practica INTEGER, id_reconocimiento INTEGER, id_actividad_sesion INTEGER, id_tipo_certificado INTEGER, estado TEXT, fecha_emision DATE, notificado BOOLEAN, fecha_notificacion TEXT',
            'certificado.reconocimiento' => 'id_reconocimiento INTEGER PRIMARY KEY, id_persona_trabajador INTEGER, titulo TEXT, descripcion TEXT, motivo TEXT, fecha_reconocimiento DATE, estado TEXT',
            'certificado.certificado_documento' => 'id_certificado_documento INTEGER PRIMARY KEY, id_certificado INTEGER, codigo_verificacion TEXT UNIQUE, hash_certificado TEXT UNIQUE, ruta_archivo TEXT, motivo_reemision TEXT, modalidad_firma TEXT, estado TEXT, fecha_generacion TEXT',
            'certificado.certificado_firma' => 'id_certificado_firma INTEGER PRIMARY KEY, id_certificado_documento INTEGER, id_firma INTEGER, id_persona_trabajador INTEGER, fecha_firma TEXT, orden_firma INTEGER',
            'certificado.firma' => 'id_firma INTEGER PRIMARY KEY, id_persona_trabajador INTEGER, ruta_archivo_pfx TEXT, passphrase_cifrada TEXT',
            'certificado.actividad_firma' => 'id_actividad_firma INTEGER PRIMARY KEY, id_actividad INTEGER, id_firma INTEGER, orden_firma INTEGER',
        ] as $table => $columns) {
            DB::statement("CREATE TABLE {$table} ({$columns})");
        }
    }

    private function authorize(bool $administrator = true, ?array $permissions = null): void
    {
        $user = new class extends User
        {
            public bool $administrator = true;

            public array $permissions = [];

            public function isSystemAdministrator(): bool
            {
                return $this->administrator;
            }

            public function permissionKeys(): array
            {
                return $this->permissions;
            }
        };
        $user->forceFill(['id_usuario' => 8, 'id_persona' => 1, 'estado' => true]);
        $user->administrator = $administrator;
        $user->permissions = $permissions ?? ($administrator
            ? ['emision.ver', 'actividades.ver', 'ponentes-firmantes.ver', 'practicantes.ver', 'reconocimientos.ver'] : []);
        $user->setRelation('roles', (new Role)->newCollection());
        $this->actingAs($user);
    }

    private function seedOrigins(): void
    {
        DB::table('gestion.area')->insert(['id_area' => 1, 'nombre' => 'Área']);
        DB::table('gestion.institucion')->insert(['id_institucion' => 1, 'nombre_institucion' => 'Institución']);
        DB::table('gestion.persona')->insert([['id_persona' => 1, 'nombres' => 'Ana'], ['id_persona' => 2, 'nombres' => 'Luis']]);
        DB::table('certificado.actividad')->insert(['id_actividad' => 1, 'id_area' => 1, 'nombre' => 'Actividad',
            'estado' => 'Activa', 'fecha_inicio' => '2025-11-01', 'fecha_fin' => '2025-11-01']);
        DB::table('certificado.solicitud_actividad')->insert(['id_solicitud_actividad' => 1, 'id_actividad' => 1, 'estado' => 'Aprobada']);
        DB::table('certificado.actividad_sesion')->insert([
            ['id_actividad_sesion' => 1, 'id_actividad' => 1, 'nombre' => 'Primera'],
            ['id_actividad_sesion' => 2, 'id_actividad' => 1, 'nombre' => 'Segunda'],
        ]);
        DB::table('gestion.persona_ponente')->insert([
            ['id_persona_ponente' => 1, 'id_persona' => 1, 'id_institucion' => 1],
            ['id_persona_ponente' => 2, 'id_persona' => 2, 'id_institucion' => null],
        ]);
        DB::table('certificado.actividad_ponente')->insert([
            ['id_actividad_ponente' => 1, 'id_actividad' => 1, 'id_persona_ponente' => 1],
            ['id_actividad_ponente' => 2, 'id_actividad' => 1, 'id_persona_ponente' => 2],
        ]);
        DB::table('gestion.persona_practicante')->insert(['id_persona_practicante' => 1, 'id_persona' => 1, 'estado' => 'Activo']);
        DB::table('gestion.practica')->insert(['id_practica' => 1, 'id_persona_practicante' => 1,
            'id_institucion' => 1, 'id_area' => 1, 'carrera' => 'Sistemas', 'fecha_inicio' => '2025-01-01',
            'fecha_fin' => '2025-06-30', 'estado' => 'Finalizada']);
    }

    public function test_practice_finalization_requires_authentication_and_edit_permission(): void
    {
        $this->postJson('/api/practices/1/finalizar')->assertUnauthorized();
        $this->seedAccreditedOrigin('practica');
        $this->postJson('/api/practices/1/finalizar')->assertForbidden();
        $this->assertDatabaseHas('gestion.practica', ['id_practica' => 1, 'estado' => 'APROBADO_PRUEBA']);
    }

    #[TestWith(['future'])]
    #[TestWith(['reversed'])]
    #[TestWith(['supervision'])]
    #[TestWith(['invalid_state'])]
    public function test_practice_finalization_returns_409_without_writes_for_missing_evidence(string $condition): void
    {
        $this->seedAccreditedOrigin('practica');
        $this->authorize(permissions: ['practicantes.ver', 'practicantes.editar']);
        config(['certification.origins.practica.completion_state' => 'APROBADO_PRUEBA',
            'certification.origins.practica.supervision_required' => true]);
        DB::table('gestion.practica')->update(['estado' => 'activo']);
        match ($condition) {
            'future' => DB::table('gestion.practica')->update(['fecha_fin' => '2027-01-01']),
            'reversed' => DB::table('gestion.practica')->update(['fecha_inicio' => '2025-07-01']),
            'supervision' => DB::table('gestion.practica_supervisor')->update(['fecha_fin' => '2025-06-29']),
            'invalid_state' => DB::table('gestion.practica')->update(['estado' => 'cancelado']),
        };
        $before = DB::table('gestion.practica')->first();

        $response = $this->postJson('/api/practices/1/finalizar')->assertConflict()
            ->assertJsonPath('data.practica_finalizada', false);

        $this->assertNotEmpty($response->json('data.motivos_bloqueo'));
        $this->assertSame((array) $before, (array) DB::table('gestion.practica')->first());
        $this->assertDatabaseCount('certificado.certificado', 0);
    }

    public function test_practice_finalization_persists_only_state_and_query_recovers_eligibility(): void
    {
        $this->seedAccreditedOrigin('practica');
        $this->authorize(permissions: ['practicantes.ver', 'practicantes.editar', 'emision.ver', 'emision.crear']);
        config(['certification.origins.practica.completion_state' => 'APROBADO_PRUEBA']);
        DB::table('gestion.practica')->update(['estado' => 'activo']);
        $before = (array) DB::table('gestion.practica')->first();
        $this->getJson('/api/practices/evaluacion-certificacion')->assertOk()
            ->assertJsonPath('data.practicas.0.situacion_constancia', 'activa')
            ->assertJsonPath('data.practicas.0.puede_preparar', false);
        $this->postJson('/api/practices/1/finalizar', ['estado' => 'APROBADO_PRUEBA'])->assertUnprocessable();

        $this->postJson('/api/practices/1/finalizar')->assertOk()->assertJsonPath('data.practica_finalizada', true)
            ->assertJsonPath('data.practica.estado', 'APROBADO_PRUEBA');

        $this->assertSame([...$before, 'estado' => 'APROBADO_PRUEBA'], (array) DB::table('gestion.practica')->first());
        $this->assertDatabaseCount('certificado.certificado', 0);
        $this->getJson('/api/practices/evaluacion-certificacion')->assertOk()
            ->assertJsonPath('data.practicas.0.situacion_constancia', 'finalizada_elegible')
            ->assertJsonPath('data.practicas.0.puede_preparar', true);
        $this->postJson('/api/practices/1/finalizar')->assertOk()->assertJsonPath('data.reintento', true);
        $this->postJson('/api/practices/999/finalizar')->assertNotFound();
    }

    #[TestWith(['partial', null])]
    #[TestWith(['none', ''])]
    #[TestWith(['complete', 'finalizado'])]
    public function test_default_finalization_and_preparation_allow_optional_supervision(string $supervision, ?string $configuredState): void
    {
        Storage::fake('local');
        $this->seedAccreditedOrigin('practica');
        $this->authorize(permissions: ['practicantes.ver', 'practicantes.editar', 'emision.ver', 'emision.crear', 'emision.editar']);
        config(['certification.origins.practica.reference' => null,
            'certification.origins.practica.completion_state' => $configuredState,
            'certification.origins.practica.eligible_states' => [],
            'certification.origins.practica.supervisor_states' => [],
            'certification.origins.practica.supervision_required' => false]);
        DB::table('gestion.practica')->update(['estado' => 'activo']);
        if ($supervision === 'none') {
            DB::table('gestion.practica_supervisor')->delete();
        } elseif ($supervision === 'partial') {
            DB::table('gestion.practica_supervisor')->update(['fecha_inicio' => '2025-02-01', 'fecha_fin' => '2025-06-29']);
        }
        $supervisors = DB::table('gestion.practica_supervisor')->get()->map(fn (object $row): array => (array) $row)->all();
        $before = (array) DB::table('gestion.practica')->first();
        $this->postJson('/api/certificaciones/practica/1/preparar', ['accion' => 'revisar_documento'])->assertConflict();
        $filesBeforeFinalization = Storage::disk('local')->allFiles();

        $this->postJson('/api/practices/1/finalizar')->assertOk()->assertJsonPath('data.practica.estado', 'finalizado')
            ->assertJsonPath('data.practica_finalizada', true)->assertJsonPath('data.finalizacion_registrada', true)
            ->assertJsonPath('data.supervision_acreditada', false)->assertJsonPath('data.motivos_bloqueo', [])
            ->assertJsonPath('data.puede_preparar', true)->assertJsonPath('data.puede_generar_pdf', true);

        $this->assertSame([...$before, 'estado' => 'finalizado'], (array) DB::table('gestion.practica')->first());
        $this->assertSame($supervisors, DB::table('gestion.practica_supervisor')->get()->map(fn (object $row): array => (array) $row)->all());
        $this->assertDatabaseCount('certificado.certificado', 0);
        $this->assertSame($filesBeforeFinalization, Storage::disk('local')->allFiles());
        $this->getJson('/api/practices/evaluacion-certificacion')->assertOk()
            ->assertJsonPath('data.practicas.0.finalizacion_registrada', true)->assertJsonPath('data.practicas.0.puede_preparar', true);
        $this->getJson('/api/practices/1/evaluacion-certificacion')->assertOk()
            ->assertJsonPath('data.finalizacion_registrada', true)->assertJsonPath('data.puede_generar_pdf', true);
        $url = '/api/certificaciones/practica/1/preparar';
        $this->getJson($url.'?accion=consultar_preparacion')->assertOk()->assertJsonPath('data.puede_preparar', true);
        $review = $this->postJson($url, ['accion' => 'revisar_documento'])->assertOk()->json('data');
        $prepared = $this->postJson($url, ['accion' => 'confirmar_preparacion', 'token_revision' => $review['token_revision'],
            'confirmar' => true])->assertCreated()->assertJsonPath('data.estado_flujo', 'PREPARADO')->json('data');
        $files = Storage::disk('local')->allFiles();
        $this->postJson('/api/practices/1/finalizar')->assertOk()->assertJsonPath('data.reintento', true);
        $this->getJson($url.'?accion=consultar_preparacion')->assertOk()->assertJsonPath('data.recuperado', true)
            ->assertJsonPath('data.id_certificado_documento', $prepared['id_certificado_documento']);
        $this->assertDatabaseCount('certificado.certificado', 1);
        $this->assertDatabaseCount('certificado.certificado_documento', 1);
        $this->assertSame($files, Storage::disk('local')->allFiles());
    }

    public function test_practice_finalization_rejects_an_area_outside_actor_scope_with_403(): void
    {
        $this->seedAccreditedOrigin('practica');
        $this->authorize(false, ['practicantes.ver', 'practicantes.editar', 'emision.ver']);
        DB::table('gestion.practica')->update(['id_area' => 2]);

        $this->postJson('/api/practices/1/finalizar')->assertForbidden();
        $this->getJson('/api/practices/evaluacion-certificacion')->assertOk()->assertJsonCount(0, 'data.practicas');
    }

    public function test_practice_light_recovery_requires_read_permission_and_reuses_the_exact_preparation(): void
    {
        $url = '/api/certificaciones/practica/1/preparar';
        $this->getJson($url.'?accion=consultar_preparacion')->assertUnauthorized();
        Storage::fake('local');
        $this->seedAccreditedOrigin('practica');
        $this->getJson($url.'?accion=consultar_preparacion')->assertOk()->assertJsonPath('data.persistido', false);
        $review = $this->postJson($url, ['accion' => 'revisar_documento'])->assertOk()->json('data');
        $prepared = $this->postJson($url, ['accion' => 'confirmar_preparacion', 'token_revision' => $review['token_revision'],
            'confirmar' => true])->assertCreated()->json('data');
        $files = Storage::disk('local')->allFiles();
        $this->authorize(permissions: ['practicantes.ver', 'emision.ver']);

        $this->getJson($url.'?accion=consultar_preparacion')->assertOk()
            ->assertJsonPath('data.recuperado', true)->assertJsonPath('data.id_certificado_documento', $prepared['id_certificado_documento'])
            ->assertJsonPath('data.estado_flujo', 'PREPARADO')->assertJsonPath('data.hash_pdf', $review['hash_pdf'])
            ->assertJsonPath('data.texto_editable', false)->assertJsonPath('data.puede_enviar_gerencia', false)
            ->assertJsonMissingPath('data.vista_previa_pdf');

        $this->assertSame($files, Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('certificado.certificado', 1);
        $this->assertDatabaseCount('certificado.certificado_documento', 1);
        $this->getJson($url.'?accion=revisar_documento')->assertUnprocessable();
        $this->authorize(permissions: ['emision.ver']);
        $this->getJson($url.'?accion=consultar_preparacion')->assertForbidden();
    }

    public function test_practice_default_message_queries_are_light_and_keep_read_permissions(): void
    {
        Storage::fake('local');
        $this->seedAccreditedOrigin('practica');
        $expected = 'Por haber realizado sus prácticas en Área, del 2025-01-01 al 2025-06-30.';
        foreach (['/api/practices/1/evaluacion-certificacion',
            '/api/certificaciones/practica/1/preparar?accion=consultar_preparacion'] as $url) {
            $this->getJson($url)->assertOk()->assertJsonPath('data.mensaje', $expected)
                ->assertJsonPath('data.mensaje_predeterminado', $expected)->assertJsonPath('data.texto_editable', true)
                ->assertJsonMissingPath('data.vista_previa_pdf')->assertJsonMissingPath('data.token_revision');
        }
        $this->getJson('/api/practices/evaluacion-certificacion')->assertOk()
            ->assertJsonPath('data.practicas.0.mensaje', $expected);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('certificado.certificado', 0);
        $this->authorize(permissions: ['practicantes.ver', 'emision.ver']);
        $this->getJson('/api/certificaciones/practica/1/preparar?accion=consultar_preparacion')->assertOk()
            ->assertJsonPath('data.mensaje', $expected)->assertJsonPath('data.texto_editable', false);
    }

    #[TestWith(['default'])]
    #[TestWith(['custom'])]
    #[TestWith(['maximum'])]
    public function test_practice_message_is_reviewed_persisted_and_recovered_without_duplicates(string $kind): void
    {
        Storage::fake('local');
        $this->seedAccreditedOrigin('practica');
        $url = '/api/certificaciones/practica/1/preparar';
        $input = ['accion' => 'revisar_documento'];
        if ($kind !== 'default') {
            $input['mensaje'] = $kind === 'maximum' ? str_repeat('á', 1200) : "Por su dedicación & servicio institucional.\nGracias por su aporte.";
        }
        $review = $this->postJson($url, $input)->assertOk()->assertJsonPath('data.texto_editable', true)->json('data');
        $expected = $input['mensaje'] ?? 'Por haber realizado sus prácticas en Área, del 2025-01-01 al 2025-06-30.';
        $this->assertSame($expected, $review['mensaje']);
        $manifest = json_decode(Crypt::decryptString(Storage::disk('local')->get('certificados/revisiones/'.hash('sha256', $review['token_revision']).'.manifest')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($expected, $manifest['message']);
        $this->assertSame($expected, $manifest['context']['texto_practica']['mensaje']);
        $this->assertSame(1, $manifest['context']['id_persona']);
        $html = view('certificates.origins', ['context' => (object) $manifest['context'], 'customMessage' => $manifest['message'],
            'certificate' => null, 'signers' => [], 'logoDataUri' => 'data:image/png;base64,',
            'code' => $manifest['code'], 'qrDataUri' => 'data:image/svg+xml;base64,', 'administrative' => true])->render();
        foreach ([e($expected), 'Ana', 'CERTIFICADO DE PRÁCTICAS PREPROFESIONALES', 'Otorgado a:', 'Morales, 13 de noviembre de 2026', $manifest['code']] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertSame(1, substr_count($html, e($expected)));
        $this->assertStringNotContainsString('Institución', $html);
        $this->assertStringNotContainsString('Sistemas', $html);
        $this->assertStringNotContainsString('Área:', $html);
        $this->assertStringStartsWith('%PDF-', base64_decode($review['vista_previa_pdf']['contenido_base64'], true));
        $this->postJson($url, [...$input, 'mensaje' => $expected])->assertOk()
            ->assertJsonPath('data.token_revision', $review['token_revision'])->assertJsonPath('data.revision_reutilizada', true);
        $confirm = [...$input, 'accion' => 'confirmar_preparacion', 'token_revision' => $review['token_revision'], 'confirmar' => true];
        $prepared = $this->postJson($url, $confirm)->assertCreated()->assertJsonPath('data.mensaje', $expected)
            ->assertJsonPath('data.texto_editable', false)->assertJsonPath('data.estado_flujo', 'PREPARADO')->json('data');
        $this->postJson($url, $confirm)->assertOk()->assertJsonPath('data.creado', false);
        $recovered = $this->getJson($url.'?accion=consultar_preparacion')->assertOk()
            ->assertJsonPath('data.mensaje_aprobado', $expected)->assertJsonPath('data.mensaje', $expected)
            ->assertJsonPath('data.texto_editable', false)->assertJsonPath('data.hash_pdf', $review['hash_pdf'])
            ->assertJsonPath('data.id_certificado_documento', $prepared['id_certificado_documento'])
            ->assertJsonMissingPath('data.vista_previa_pdf')->json('data');
        $this->assertNotEmpty($recovered['url_documento']);
        $document = DB::table('certificado.certificado_documento')->first();
        $this->assertSame(base64_decode($review['vista_previa_pdf']['contenido_base64'], true), Storage::disk('local')->get($document->ruta_archivo));
        $this->assertTrue(Storage::disk('local')->exists('certificados/preparados/'.$document->codigo_verificacion.'.manifest'));
        $this->postJson($url, ['accion' => 'revisar_documento', 'mensaje' => 'Cambio después de guardar.'])->assertConflict();
        if ($kind === 'custom') {
            $base = '/api/certificados-documentos/'.$prepared['id_certificado_documento'];
            $send = $this->postJson($base.'/revisar-envio')->assertOk()->json('data');
            $this->postJson($base.'/enviar-gerencia', ['token_revision' => $send['token_revision'], 'confirmar' => true])->assertOk();
            $plan = ['modalidad' => 'SECUENCIAL', 'firmantes' => [['id_persona_trabajador' => 2, 'orden_firma' => 1]]];
            $revision = $this->postJson($base.'/firma/revision-pdf', $plan)->assertOk()->assertJsonPath('data.mensaje', $expected)->json('data');
            $this->postJson($base.'/firma/asignar', $plan + ['confirmar' => true])->assertOk();
            $this->postJson($base.'/firma/confirmar-pdf', ['token_revision' => $revision['token_revision'], 'confirmar' => true])->assertOk();
            $this->getJson($url.'?accion=consultar_preparacion')->assertOk()->assertJsonPath('data.mensaje_aprobado', $expected)
                ->assertJsonPath('data.estado_flujo', 'PENDIENTE_FIRMA')->assertJsonPath('data.texto_editable', false);
        }
        $this->assertDatabaseCount('certificado.certificado', 1);
        $this->assertDatabaseCount('certificado.certificado_documento', 1);
    }

    public function test_practice_message_change_invalidates_previous_review_and_confirmation_text(): void
    {
        Storage::fake('local');
        $this->seedAccreditedOrigin('practica');
        $url = '/api/certificaciones/practica/1/preparar';
        $first = $this->postJson($url, ['accion' => 'revisar_documento', 'mensaje' => 'Primer mensaje.'])->assertOk()->json('data');
        $second = $this->postJson($url, ['accion' => 'revisar_documento', 'mensaje' => 'Segundo mensaje.'])->assertOk()->json('data');
        $this->postJson($url, ['accion' => 'confirmar_preparacion', 'token_revision' => $first['token_revision'],
            'confirmar' => true, 'mensaje' => 'Primer mensaje.'])->assertConflict();
        $confirm = ['accion' => 'confirmar_preparacion', 'token_revision' => $second['token_revision'], 'confirmar' => true];
        $this->postJson($url, $confirm)->assertUnprocessable();
        $this->postJson($url, [...$confirm, 'mensaje' => 'Texto no revisado.'])->assertConflict();
        $this->assertDatabaseCount('certificado.certificado', 0);
        $this->postJson($url, [...$confirm, 'mensaje' => 'Segundo mensaje.'])->assertCreated()
            ->assertJsonPath('data.mensaje', 'Segundo mensaje.');
    }

    public function test_practice_recovery_reads_original_message_without_an_indexed_manifest_or_regeneration(): void
    {
        Storage::fake('local');
        $this->seedAccreditedOrigin('practica');
        $url = '/api/certificaciones/practica/1/preparar';
        $review = $this->postJson($url, ['accion' => 'revisar_documento'])->assertOk()->json('data');
        $prepared = $this->postJson($url, ['accion' => 'confirmar_preparacion', 'token_revision' => $review['token_revision'],
            'confirmar' => true])->assertCreated()->json('data');
        $disk = Storage::disk('local');
        $disk->delete('certificados/preparados/'.$prepared['codigo_verificacion'].'.manifest');
        DB::table('gestion.area')->where('id_area', 1)->update(['nombre' => 'Área actualizada']);
        $files = collect($disk->allFiles())->mapWithKeys(fn (string $path): array => [$path => hash_file('sha256', $disk->path($path))])->all();

        $this->getJson($url.'?accion=consultar_preparacion')->assertOk()->assertJsonPath('data.mensaje', $review['mensaje'])
            ->assertJsonPath('data.mensaje_aprobado', $review['mensaje'])->assertJsonPath('data.texto_editable', false)
            ->assertJsonPath('data.hash_pdf', $review['hash_pdf']);

        $this->assertSame($files, collect($disk->allFiles())->mapWithKeys(fn (string $path): array => [$path => hash_file('sha256', $disk->path($path))])->all());
        $this->assertDatabaseCount('certificado.certificado', 1);
    }

    #[TestWith([''])]
    #[TestWith(['   '])]
    #[TestWith([null])]
    #[TestWith([123])]
    #[TestWith(['<script>alert(1)</script>'])]
    #[TestWith(["Texto\x00oculto"])]
    #[TestWith(["Texto\u{202E}oculto"])]
    public function test_practice_message_rejects_invalid_content_with_422(mixed $message): void
    {
        Storage::fake('local');
        $this->seedAccreditedOrigin('practica');
        $url = '/api/certificaciones/practica/1/preparar';
        $this->postJson($url, ['accion' => 'revisar_documento', 'mensaje' => $message])->assertUnprocessable();
        $this->assertDatabaseCount('certificado.certificado', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_practice_message_rejects_overlong_unknown_and_consultation_fields(): void
    {
        Storage::fake('local');
        $this->seedAccreditedOrigin('practica');
        $url = '/api/certificaciones/practica/1/preparar';
        $this->postJson($url, ['accion' => 'revisar_documento', 'mensaje' => str_repeat('á', 1201)])->assertUnprocessable();
        $this->postJson($url, ['accion' => 'revisar_documento', 'id_persona' => 99])->assertUnprocessable();
        $this->postJson($url, ['accion' => 'revisar_documento', 'motivo' => 'No admitido'])->assertUnprocessable();
        $this->getJson($url.'?accion=consultar_preparacion&mensaje=Otro')->assertUnprocessable();
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_an_active_practice_remains_blocked_even_if_misconfigured_as_an_approved_state(): void
    {
        $this->seedAccreditedOrigin('practica');
        DB::table('gestion.practica')->update(['estado' => 'activo']);
        config(['certification.origins.practica.eligible_states' => ['activo']]);

        $this->getJson('/api/practices/1/evaluacion-certificacion')->assertOk()
            ->assertJsonPath('data.puede_preparar', false)->assertJsonPath('data.situacion_constancia', 'activa');
        $this->postJson('/api/certificaciones/practica/1/preparar', ['accion' => 'revisar_documento'])->assertConflict();
    }

    #[TestWith(['/api/certificaciones/catalogos-origenes'])]
    #[TestWith(['/api/activity-session/1/ponentes-certificacion'])]
    #[TestWith(['/api/practices/1/evaluacion-certificacion'])]
    #[TestWith(['/api/practices/evaluacion-certificacion'])]
    #[TestWith(['/api/reconocimientos/1/evaluacion-certificacion'])]
    public function test_origin_queries_require_authentication(string $url): void
    {
        $this->getJson($url)->assertUnauthorized();
    }

    #[TestWith(['/api/certificaciones/catalogos-origenes'])]
    #[TestWith(['/api/activity-session/1/ponentes-certificacion'])]
    #[TestWith(['/api/practices/1/evaluacion-certificacion'])]
    #[TestWith(['/api/practices/evaluacion-certificacion'])]
    #[TestWith(['/api/reconocimientos/1/evaluacion-certificacion'])]
    public function test_origin_queries_require_existing_module_permissions(string $url): void
    {
        $this->authorize(false);

        $this->getJson($url)->assertForbidden();
    }

    public function test_catalogs_keep_independent_keys_and_exclude_inactive_values(): void
    {
        $this->authorize();
        DB::table('certificado.tipo_actividad')->insert(['id_tipo_actividad' => 12, 'nombre' => 'Capacitación', 'estado' => true]);
        DB::table('certificado.tipo_certificado')->insert([
            ['id_tipo_certificado' => 32, 'nombre_tipo' => 'Prácticas', 'estado' => true],
            ['id_tipo_certificado' => 35, 'nombre_tipo' => 'Inactivo', 'estado' => false],
        ]);

        $this->getJson('/api/certificaciones/catalogos-origenes')->assertOk()
            ->assertJsonCount(1, 'data.tipos_actividad')->assertJsonCount(1, 'data.tipos_certificado')
            ->assertJsonPath('data.tipos_actividad.0.id_tipo_actividad', 12)
            ->assertJsonPath('data.tipos_certificado.0.id_tipo_certificado', 32)
            ->assertJsonPath('data.relacion_tipo_actividad_certificado', null);
    }

    public function test_activity_speakers_are_not_attributed_to_sessions_or_replaced_by_attendance(): void
    {
        $this->authorize();
        $this->seedOrigins();
        DB::table('certificado.participacion')->insert(['id_participacion' => 1, 'id_actividad' => 1, 'id_persona' => 1, 'estado' => 'Activo']);

        foreach ([1, 2] as $sessionId) {
            $this->getJson("/api/activity-session/{$sessionId}/ponentes-certificacion")->assertOk()
                ->assertJsonPath('data.id_sesion', $sessionId)->assertJsonCount(2, 'data.ponentes_actividad')
                ->assertJsonPath('data.ponentes_actividad.0.id_persona', 1)
                ->assertJsonPath('data.ponentes_actividad.1.id_persona', 2)
                ->assertJsonPath('data.ponentes_sesion', null)->assertJsonPath('data.puede_preparar', false);
        }
        $this->assertDatabaseCount('certificado.certificado', 0);
        $this->assertDatabaseCount('certificado.participacion', 1);
    }

    #[TestWith(['2025-06-30', true])]
    #[TestWith([null, false])]
    #[TestWith(['2024-06-30', false])]
    #[TestWith(['2027-06-30', false])]
    public function test_practice_period_does_not_substitute_requirements_or_approval(?string $end, bool $valid): void
    {
        $this->travelTo('2026-11-13');
        $this->authorize();
        $this->seedOrigins();
        DB::table('gestion.practica')->where('id_practica', 1)->update(['fecha_fin' => $end]);
        DB::table('gestion.persona_trabajador')->insert(['id_persona_trabajador' => 1, 'id_persona' => 2]);
        DB::table('gestion.practica_supervisor')->insert(['id_practica_supervisor' => 1, 'id_practica' => 1,
            'id_persona_trabajador' => 1, 'estado' => 'Finalizada', 'fecha_inicio' => '2025-01-01']);

        $this->getJson('/api/practices/1/evaluacion-certificacion')->assertOk()
            ->assertJsonPath('data.practica.id_persona', 1)->assertJsonPath('data.practica.institucion', 'Institución')
            ->assertJsonPath('data.supervisores.0.id_persona', 2)
            ->assertJsonPath('data.periodo_transcurrido_valido', $valid)
            ->assertJsonPath('data.cumplimiento_requisitos_acreditado', null)->assertJsonPath('data.aprobacion_acreditada', null)
            ->assertJsonPath('data.id_sesion', null)->assertJsonPath('data.requiere_asistencia_capacitacion', false)
            ->assertJsonPath('data.puede_generar_pdf', false);
        $this->assertDatabaseCount('certificado.certificado', 0);
        $this->assertDatabaseCount('certificado.participacion', 0);
    }

    #[TestWith(['/api/activity-session/999/ponentes-certificacion'])]
    #[TestWith(['/api/practices/999/evaluacion-certificacion'])]
    public function test_missing_origin_returns_404(string $url): void
    {
        $this->authorize();

        $this->getJson($url)->assertNotFound();
    }

    public function test_inconsistent_practice_identity_returns_409(): void
    {
        $this->authorize();
        $this->seedOrigins();
        DB::table('gestion.persona_practicante')->delete();

        $this->getJson('/api/practices/1/evaluacion-certificacion')->assertConflict();
        $this->assertDatabaseCount('certificado.certificado', 0);
    }

    #[TestWith(['/api/activity-session/1/ponentes-certificacion'])]
    #[TestWith(['/api/practices/1/evaluacion-certificacion'])]
    public function test_origins_outside_authorized_area_return_403(string $url): void
    {
        $this->seedOrigins();
        $this->authorize(false, ['emision.ver', 'actividades.ver', 'ponentes-firmantes.ver', 'practicantes.ver']);
        DB::table('gestion.area')->insert(['id_area' => 2, 'nombre' => 'Otra área']);
        DB::table('gestion.persona_trabajador')->insert(['id_persona_trabajador' => 1, 'id_persona' => 1,
            'id_area' => 2, 'estado' => true, 'fecha_ingreso' => '2020-01-01']);

        $this->getJson($url)->assertForbidden();
    }

    private function enableAcademicRecognition(): void
    {
        $this->app->instance('env', 'local');
        config(['certification.origins.reconocimiento.reference' => null,
            'certification.origins.reconocimiento.eligible_states' => [],
            'certification.origins.reconocimiento.academic_enabled' => true,
            'certification.origins.reconocimiento.academic_ids' => [1],
            'certification.origins.reconocimiento.academic_states' => ['APROBADO_PRUEBA']]);
    }

    public function test_individual_28_lifecycle_over_real_http_with_jwt_cookie_and_isolated_storage(): void
    {
        if (getenv('INDIVIDUAL_RECOGNITIONS_HTTP_ISOLATED') !== '1') {
            $this->markTestSkipped('Requiere servidor TCP local explícito; usa únicamente SQLite y archivos aislados.');
        }
        $this->seedAccreditedOrigin('reconocimiento');
        DB::table('certificado.reconocimiento')->where('id_reconocimiento', 1)->update([
            'id_reconocimiento' => 28, 'titulo' => 'DESTACADO', 'motivo' => 'E', 'estado' => 'PREPARADO']);
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
        DB::table('acceso.usuario')->insert([
            ['id_usuario' => 1, 'id_persona' => 1, 'correo' => 'http-allowed@example.test', 'estado' => true],
            ['id_usuario' => 2, 'id_persona' => 2, 'correo' => 'http-denied@example.test', 'estado' => true],
        ]);
        DB::table('acceso.rol')->insert([
            ['id_rol' => 2, 'nombre' => 'Operador', 'estado' => true], ['id_rol' => 3, 'nombre' => 'Sin permisos', 'estado' => true],
        ]);
        DB::table('acceso.usuario_rol')->insert([
            ['id_usuario_rol' => 1, 'id_usuario' => 1, 'id_rol' => 2], ['id_usuario_rol' => 2, 'id_usuario' => 2, 'id_rol' => 3],
        ]);
        DB::table('acceso.modulo')->insert(['id_modulo' => 1, 'nombre' => 'Emisión', 'url' => '/emision', 'estado' => true]);
        DB::table('acceso.rol_modulo')->insert(['id_modulo' => 1, 'id_rol' => 2]);
        foreach (['emision.ver', 'emision.crear', 'reconocimientos.ver'] as $index => $permission) {
            DB::table('acceso.permiso')->insert(['id_permiso' => $index + 1, 'id_modulo' => 1, 'clave' => $permission, 'estado' => true]);
            DB::table('acceso.rol_modulo_permiso')->insert(['id_permiso' => $index + 1, 'id_modulo' => 1, 'id_rol' => 2]);
        }
        $directory = storage_path('framework/testing/recognition-http-'.bin2hex(random_bytes(6)));
        File::ensureDirectoryExists($directory.'/private');
        $schemas = [];
        foreach (['main', 'gestion', 'certificado', 'acceso'] as $schema) {
            $path = str_replace('\\', '/', $directory.'/'.$schema.'.sqlite');
            DB::statement('VACUUM '.$schema.' INTO ?', [$path]);
            if ($schema !== 'main') {
                $schemas[$schema] = $path;
            }
        }
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($socket);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $base = 'http://'.$address;
        $testConfig = ['database.default' => 'sqlite', 'database.connections.sqlite.database' => $directory.'/main.sqlite',
            'database.connections.sqlite.url' => null, 'filesystems.disks.local.root' => $directory.'/private',
            'cache.default' => 'array', 'session.driver' => 'array',
            'app.url' => $base, 'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'jwt.secret' => str_repeat('isolated-http-recognition-', 3),
            'certification.origins.reconocimiento.academic_enabled' => true,
            'certification.origins.reconocimiento.academic_ids' => [28],
            'certification.origins.reconocimiento.academic_states' => ['PREPARADO']];
        file_put_contents($directory.'/fixture.json', json_encode(['config' => $testConfig, 'schemas' => $schemas], JSON_THROW_ON_ERROR));
        config(['jwt.secret' => $testConfig['jwt.secret']]);
        $token = app(JwtService::class)->createToken(User::findOrFail(1));
        $deniedToken = app(JwtService::class)->createToken(User::findOrFail(2));
        $server = new Process([PHP_BINARY, '-d', 'extension=pdo_sqlite', '-S', $address,
            base_path('tests/Fixtures/recognition-http-router.php')], base_path(), [
                'RECOGNITION_HTTP_FIXTURE' => $directory.'/fixture.json', 'APP_ENV' => 'local',
            ]);
        $server->start();
        try {
            $ready = false;
            for ($attempt = 0; $attempt < 50; $attempt++) {
                $probe = @fsockopen('127.0.0.1', (int) parse_url($base, PHP_URL_PORT), timeout: 0.1);
                if (is_resource($probe)) {
                    fclose($probe);
                    $ready = true;
                    break;
                }
                usleep(100000);
            }
            $this->assertTrue($ready, 'El servidor HTTP aislado debe iniciar: '.$server->getErrorOutput());
            $client = new Client(['base_uri' => $base, 'timeout' => 60, 'http_errors' => false,
                'allow_redirects' => false, 'headers' => ['Accept' => 'application/json']]);
            $send = static function (string $method, string $path, array $payload, ?string $cookie) use ($client): array {
                $options = $cookie === null ? [] : ['cookies' => CookieJar::fromArray([JwtService::COOKIE => $cookie], '127.0.0.1')];
                if ($method === 'POST') {
                    $options['json'] = $payload;
                }
                $response = $client->request($method, $path, $options);
                fwrite(STDOUT, 'HTTP '.$method.' '.$path.': '.$response->getStatusCode().' [SQLite aislado, JWT real]'.PHP_EOL);

                return [$response->getStatusCode(), json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR)];
            };
            $evaluate = '/api/reconocimientos/28/evaluacion-certificacion';
            $url = '/api/certificaciones/reconocimiento/28/preparar';
            $this->assertSame(401, $send('GET', $evaluate, [], null)[0]);
            $this->assertSame(403, $send('GET', $evaluate, [], $deniedToken)[0]);
            [$status, $evaluated] = $send('GET', $evaluate, [], $token);
            $this->assertSame(200, $status, json_encode($evaluated));
            $this->assertTrue($evaluated['data']['texto_editable']);
            [$status, $consult] = $send('GET', $url.'?accion=consultar_preparacion', [], $token);
            $this->assertSame(200, $status);
            $this->assertSame('DESTACADO. E', $consult['data']['mensaje_predeterminado']);
            $this->assertTrue($consult['data']['texto_editable']);
            $message = 'Mensaje municipal aprobado por HTTP.';
            [$status, $review] = $send('POST', $url, ['accion' => 'revisar_documento', 'mensaje' => $message], $token);
            $this->assertSame(200, $status, json_encode(array_diff_key($review, ['data' => true])));
            $this->assertSame($message, $review['data']['mensaje']);
            $pdf = base64_decode($review['data']['vista_previa_pdf']['contenido_base64'], true);
            preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $streams);
            $decoded = implode('', array_map(fn (string $stream): string => @gzuncompress($stream) ?: $stream, $streams[1]));
            $encoded = mb_convert_encoding($message, 'UTF-16BE', 'UTF-8');
            $this->assertTrue(str_contains($decoded, $encoded) || str_contains(strtolower($decoded), bin2hex($encoded)));
            $confirm = ['accion' => 'confirmar_preparacion', 'mensaje' => $message,
                'token_revision' => $review['data']['token_revision'], 'confirmar' => true];
            $this->assertSame(409, $send('POST', $url, array_replace($confirm, ['mensaje' => 'Mensaje distinto.']), $token)[0]);
            [$status, $prepared] = $send('POST', $url, $confirm, $token);
            $this->assertSame(201, $status, json_encode(array_diff_key($prepared, ['data' => true])));
            $this->assertSame($message, $prepared['data']['mensaje']);
            $this->assertSame(200, $send('POST', $url, $confirm, $token)[0]);
            $documentId = $prepared['data']['id_certificado_documento'];
            [$status, $detail] = $send('GET', '/api/certificados-documentos/detail/'.$documentId.'?flujo=firmas', [], $token);
            $this->assertSame(200, $status);
            $this->assertSame('reconocimiento', $detail['data']['origen']);
            $this->assertSame($evaluated['data']['id_persona'], $detail['data']['beneficiario']['id_persona']);
            $this->assertSame($evaluated['data']['id_tipo_certificado'], $detail['data']['id_tipo_certificado']);
            $this->assertSame('PREPARADO', $detail['data']['estado_certificado']);
            $this->assertSame($review['data']['hash_pdf'], $detail['data']['hash_pdf']);
            $this->assertSame($message, $detail['data']['mensaje_aprobado']);
            $download = $client->get('/api/certificados-documentos/'.$documentId.'/archivo?flujo=firmas', [
                'cookies' => CookieJar::fromArray([JwtService::COOKIE => $token], '127.0.0.1'),
            ]);
            $this->assertSame(200, $download->getStatusCode());
            $this->assertSame($pdf, (string) $download->getBody());
            fwrite(STDOUT, 'HTTP GET PDF individual persistido: 200; bytes y SHA-256 idénticos a la revisión [aislado].'.PHP_EOL);
            [$status, $recovered] = $send('GET', $url.'?accion=consultar_preparacion', [], $token);
            $this->assertSame(200, $status);
            $this->assertSame($message, $recovered['data']['mensaje_aprobado']);
            $this->assertTrue($recovered['data']['recuperado']);
            $this->assertFalse($recovered['data']['texto_editable']);
            $this->assertSame(409, $send('POST', $url, ['accion' => 'revisar_documento', 'mensaje' => 'Otro mensaje.'], $token)[0]);
            $saved = new \PDO('sqlite:'.$schemas['certificado']);
            $this->assertSame(1, (int) $saved->query('SELECT count(*) FROM certificado')->fetchColumn());
            $document = $saved->query('SELECT * FROM certificado_documento')->fetch(\PDO::FETCH_OBJ);
            $this->assertSame($pdf, file_get_contents($directory.'/private/'.$document->ruta_archivo));
            $this->assertSame(hash('sha256', $pdf), $document->hash_certificado);
        } finally {
            $server->stop(0);
        }
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_individual_message_without_motive_is_reviewed_confirmed_and_kept_in_the_pdf(bool $custom): void
    {
        Storage::fake('local');
        $this->seedAccreditedOrigin('reconocimiento');
        $this->enableAcademicRecognition();
        $url = '/api/certificaciones/reconocimiento/1/preparar';
        $this->getJson('/api/reconocimientos/1/evaluacion-certificacion')->assertOk()
            ->assertJsonPath('data.texto_editable', true);
        $text = $custom ? ['mensaje' => 'Mensaje municipal aprobado.'] : [];
        $message = $text['mensaje'] ?? 'Reconocimiento municipal. Servicio acreditado';
        $review = $this->postJson($url, ['accion' => 'revisar_documento'] + $text)->assertOk()
            ->assertJsonPath('data.mensaje', $message)->json('data');
        $pdf = base64_decode($review['vista_previa_pdf']['contenido_base64'], true);
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $streams);
        $decoded = implode('', array_map(fn (string $stream): string => @gzuncompress($stream) ?: $stream, $streams[1]));
        $encoded = mb_convert_encoding($message, 'UTF-16BE', 'UTF-8');
        $this->assertTrue(str_contains($decoded, $encoded) || str_contains(strtolower($decoded), bin2hex($encoded)), 'El PDF debe contener el texto exacto aprobado.');
        $confirm = ['accion' => 'confirmar_preparacion', 'token_revision' => $review['token_revision'], 'confirmar' => true] + $text;
        if ($custom) {
            $this->postJson($url, array_replace($confirm, ['mensaje' => 'Mensaje distinto.']))->assertConflict();
        }
        $prepared = $this->postJson($url, $confirm)->assertCreated()->assertJsonPath('data.mensaje', $message)->json('data');
        $this->postJson($url, $confirm)->assertOk();
        $this->getJson($url.'?accion=consultar_preparacion')->assertOk()
            ->assertJsonPath('data.mensaje_aprobado', $message)->assertJsonPath('data.texto_editable', false);
        $this->getJson('/api/reconocimientos/1/evaluacion-certificacion')->assertOk()
            ->assertJsonPath('data.texto_editable', false);
        $document = DB::table('certificado.certificado_documento')->where('id_certificado_documento', $prepared['id_certificado_documento'])->first();
        $this->assertSame($pdf, Storage::disk('local')->get($document->ruta_archivo));
        $this->assertDatabaseCount('certificado.certificado', 1);
        $this->postJson($url, ['accion' => 'revisar_documento', 'mensaje' => 'Otro texto.'])->assertConflict();
        $this->assertDatabaseHas('certificado.reconocimiento', ['id_reconocimiento' => 1, 'motivo' => 'Servicio acreditado']);
    }

    #[TestWith([''])]
    #[TestWith(['<b>Texto</b>'])]
    #[TestWith(["Texto\u{202E}"])]
    public function test_individual_message_rejects_invalid_text_with_422(string $message): void
    {
        $this->seedAccreditedOrigin('reconocimiento');
        $this->enableAcademicRecognition();
        $this->postJson('/api/certificaciones/reconocimiento/1/preparar', ['accion' => 'revisar_documento', 'mensaje' => $message])->assertUnprocessable();
        $this->assertDatabaseCount('certificado.certificado', 0);
    }

    public function test_individual_message_rejects_more_than_1200_characters(): void
    {
        $this->seedAccreditedOrigin('reconocimiento');
        $this->enableAcademicRecognition();
        $this->postJson('/api/certificaciones/reconocimiento/1/preparar', ['accion' => 'revisar_documento', 'mensaje' => str_repeat('á', 1201)])->assertUnprocessable();
        $this->assertDatabaseCount('certificado.certificado', 0);
    }

    public function test_individual_message_accepts_1200_characters_without_motive(): void
    {
        Storage::fake('local');
        $this->seedAccreditedOrigin('reconocimiento');
        $this->enableAcademicRecognition();
        $message = str_repeat('á', 1200);
        $review = $this->postJson('/api/certificaciones/reconocimiento/1/preparar', ['accion' => 'revisar_documento', 'mensaje' => $message])
            ->assertOk()->assertJsonPath('data.mensaje', $message)->json('data');
        $manifest = json_decode(Crypt::decryptString(Storage::disk('local')->get('certificados/revisiones/'.hash('sha256', $review['token_revision']).'.manifest')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($message, $manifest['message']);
        $this->assertDatabaseCount('certificado.certificado', 0);
    }

    public function test_academic_recognition_preserves_authentication_create_permissions_and_area_scope(): void
    {
        $url = '/api/certificaciones/reconocimiento/1/preparar';
        $this->postJson($url, ['accion' => 'revisar_documento'])->assertUnauthorized();
        $this->seedAccreditedOrigin('reconocimiento');
        $this->enableAcademicRecognition();
        $this->authorize(false, ['emision.ver', 'reconocimientos.ver']);
        $this->getJson('/api/reconocimientos/1/evaluacion-certificacion')->assertOk()->assertJsonPath('data.puede_preparar', false);
        $this->postJson($url, ['accion' => 'revisar_documento'])->assertForbidden();
        $this->authorize(false, ['emision.ver', 'emision.crear', 'reconocimientos.ver']);
        DB::table('gestion.area')->insert(['id_area' => 2, 'nombre' => 'Otra área']);
        DB::table('gestion.persona_trabajador')->where('id_persona_trabajador', 2)->update(['id_area' => 2]);
        DB::table('certificado.reconocimiento')->update(['id_persona_trabajador' => 2]);
        $this->getJson('/api/reconocimientos/1/evaluacion-certificacion')->assertForbidden();
        $this->postJson($url, ['accion' => 'revisar_documento'])->assertForbidden();
        $this->assertDatabaseCount('certificado.certificado', 0);
    }

    #[TestWith(['disabled'])]
    #[TestWith(['production'])]
    #[TestWith(['unselected'])]
    #[TestWith(['unknown_state'])]
    #[TestWith(['annulled'])]
    public function test_academic_recognition_remains_blocked_without_explicit_technical_eligibility(string $case): void
    {
        $this->seedAccreditedOrigin('reconocimiento');
        $this->enableAcademicRecognition();
        if ($case === 'disabled') {
            config(['certification.origins.reconocimiento.academic_enabled' => false]);
        } elseif ($case === 'production') {
            $this->app->instance('env', 'production');
        } elseif ($case === 'unselected') {
            config(['certification.origins.reconocimiento.academic_ids' => [28]]);
        } else {
            DB::table('certificado.reconocimiento')->update(['estado' => $case === 'annulled' ? 'ANULADO' : 'cualquier texto']);
            if ($case === 'annulled') {
                config(['certification.origins.reconocimiento.academic_states' => ['ANULADO']]);
            }
        }
        $this->getJson('/api/reconocimientos/1/evaluacion-certificacion')->assertOk()
            ->assertJsonPath('data.puede_preparar', false);
        $this->postJson('/api/certificaciones/reconocimiento/1/preparar', ['accion' => 'revisar_documento'])->assertConflict();
        $this->assertDatabaseCount('certificado.certificado', 0);
    }

    public function test_academic_recognition_without_beneficiary_is_blocked_with_specific_reason(): void
    {
        $this->seedAccreditedOrigin('reconocimiento');
        $this->enableAcademicRecognition();
        DB::table('certificado.reconocimiento')->update(['id_persona_trabajador' => null]);
        $this->getJson('/api/reconocimientos/1/evaluacion-certificacion')->assertConflict()
            ->assertJsonPath('message', 'El reconocimiento no tiene un beneficiario municipal verificable con persona y área.');
        self::assertFalse(app(CertificateOrigins::class)->recognition(1)['puede_preparar']);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_academic_individual_recognition_reuses_document_lifecycle_without_approving_origin(bool $custom): void
    {
        Storage::fake('local');
        $this->seedAccreditedOrigin('reconocimiento');
        $this->enableAcademicRecognition();
        $url = '/api/certificaciones/reconocimiento/1/preparar';
        $this->getJson('/api/reconocimientos/1/evaluacion-certificacion')->assertOk()
            ->assertJsonPath('data.puede_preparar', true)->assertJsonPath('data.modalidad_preparacion', 'academica')
            ->assertJsonPath('data.aprobacion_institucional_acreditada', false);
        $this->getJson($url.'?accion=consultar_preparacion')->assertOk()->assertJsonPath('data.recuperado', false);
        $text = $custom ? ['mensaje' => 'Por su aporte al servicio municipal.', 'motivo' => 'Servicio municipal acreditado'] : [];
        $review = $this->postJson($url, ['accion' => 'revisar_documento'] + $text)->assertOk()->json('data');
        self::assertStringStartsWith('%PDF-', base64_decode($review['vista_previa_pdf']['contenido_base64'], true));
        self::assertSame($custom ? $text['mensaje'] : 'Reconocimiento municipal. Servicio acreditado', $review['mensaje']);
        $confirm = ['accion' => 'confirmar_preparacion', 'token_revision' => $review['token_revision'], 'confirmar' => true] + $text;
        $prepared = $this->postJson($url, $confirm)->assertCreated()->json('data');
        $this->postJson($url, $confirm)->assertOk();
        $this->getJson($url.'?accion=consultar_preparacion')->assertOk()->assertJsonPath('data.recuperado', true)
            ->assertJsonPath('data.texto_editable', false)->assertJsonPath('data.mensaje', $review['mensaje']);
        $this->assertDatabaseCount('certificado.certificado', 1);
        $this->assertDatabaseHas('certificado.certificado', ['id_reconocimiento' => 1, 'estado' => 'PREPARADO', 'fecha_emision' => null]);
        $base = '/api/certificados-documentos/'.$prepared['id_certificado_documento'];
        $send = $this->postJson($base.'/revisar-envio')->assertOk()->json('data');
        $this->postJson($base.'/enviar-gerencia', ['token_revision' => $send['token_revision'], 'confirmar' => true])->assertOk();
        $plan = ['modalidad' => 'SECUENCIAL', 'firmantes' => [['id_persona_trabajador' => 2, 'orden_firma' => 1]]];
        $revision = $this->postJson($base.'/firma/revision-pdf', $plan)->assertOk()->json('data');
        $this->postJson($base.'/firma/asignar', $plan + ['confirmar' => true])->assertOk();
        $this->postJson($base.'/firma/confirmar-pdf', ['token_revision' => $revision['token_revision'], 'confirmar' => true])->assertOk();
        $this->getJson($base.'/firma/progreso')->assertOk()->assertJsonPath('data.disponible_rrhh', true);
        $this->assertDatabaseHas('certificado.reconocimiento', ['id_reconocimiento' => 1, 'estado' => 'APROBADO_PRUEBA', 'motivo' => 'Servicio acreditado']);
    }

    private function seedAccreditedOrigin(string $origin): void
    {
        $this->travelTo('2026-11-13');
        $this->seedOrigins();
        DB::table('gestion.persona')->where('id_persona', 2)->update(['apellido_paterno' => 'Responsable']);
        DB::table('gestion.persona_trabajador')->insert([
            ['id_persona_trabajador' => 1, 'id_persona' => 1, 'id_area' => 1, 'estado' => true, 'fecha_ingreso' => '2020-01-01', 'cargo' => 'Personal', 'habilitado_firma_certificados' => false],
            ['id_persona_trabajador' => 2, 'id_persona' => 2, 'id_area' => 1, 'estado' => true, 'fecha_ingreso' => '2020-01-01', 'cargo' => 'Responsable', 'habilitado_firma_certificados' => true],
        ]);
        DB::table('certificado.tipo_certificado')->insert([
            ['id_tipo_certificado' => 23, 'nombre_tipo' => 'Reconocimiento', 'estado' => true],
            ['id_tipo_certificado' => 38, 'nombre_tipo' => 'Prácticas', 'estado' => true],
        ]);
        DB::table('certificado.reconocimiento')->insert(['id_reconocimiento' => 1, 'id_persona_trabajador' => 1,
            'titulo' => 'Reconocimiento municipal', 'motivo' => 'Servicio acreditado', 'fecha_reconocimiento' => '2025-11-01', 'estado' => 'APROBADO_PRUEBA']);
        DB::table('gestion.practica')->update(['estado' => 'APROBADO_PRUEBA']);
        DB::table('gestion.practica_supervisor')->insert(['id_practica_supervisor' => 1, 'id_practica' => 1,
            'id_persona_trabajador' => 2, 'fecha_inicio' => '2025-01-01', 'fecha_fin' => '2025-06-30', 'estado' => 'SUPERVISION_PRUEBA']);
        config(['certification.origins.'.$origin.'.reference' => 'Política sintética exclusiva de esta prueba',
            'certification.origins.'.$origin.'.eligible_states' => ['APROBADO_PRUEBA'],
            'certification.origins.practica.completion_state' => 'APROBADO_PRUEBA',
            'certification.origins.practica.supervisor_states' => ['SUPERVISION_PRUEBA']]);
        $this->authorize(permissions: ['emision.ver', 'emision.crear', 'emision.editar', 'practicantes.ver', 'reconocimientos.ver']);
    }

    #[TestWith(['future_period'])]
    #[TestWith(['missing_start'])]
    #[TestWith(['missing_end'])]
    #[TestWith(['reversed_period'])]
    #[TestWith(['active_state'])]
    #[TestWith(['missing_supervisor'])]
    #[TestWith(['supervisor_starts_late'])]
    #[TestWith(['supervisor_ends_early'])]
    #[TestWith(['unapproved_supervisor'])]
    #[TestWith(['supervisor_policy_missing'])]
    #[TestWith(['worker_starts_late'])]
    #[TestWith(['worker_ends_early'])]
    #[TestWith(['missing_career'])]
    #[TestWith(['missing_type'])]
    #[TestWith(['ambiguous_type'])]
    public function test_practice_requirements_block_incomplete_evidence_with_409_on_preparation(string $condition): void
    {
        Storage::fake('local');
        $this->seedAccreditedOrigin('practica');
        config(['certification.origins.practica.supervision_required' => true]);
        match ($condition) {
            'future_period' => DB::table('gestion.practica')->update(['fecha_fin' => '2027-01-01']),
            'missing_start' => DB::table('gestion.practica')->update(['fecha_inicio' => null]),
            'missing_end' => DB::table('gestion.practica')->update(['fecha_fin' => null]),
            'reversed_period' => DB::table('gestion.practica')->update(['fecha_inicio' => '2025-07-01']),
            'active_state' => DB::table('gestion.practica')->update(['estado' => 'activo']),
            'missing_supervisor' => DB::table('gestion.practica_supervisor')->delete(),
            'supervisor_starts_late' => DB::table('gestion.practica_supervisor')->update(['fecha_inicio' => '2025-02-01']),
            'supervisor_ends_early' => DB::table('gestion.practica_supervisor')->update(['fecha_fin' => '2025-06-29']),
            'unapproved_supervisor' => DB::table('gestion.practica_supervisor')->update(['estado' => 'No acreditada']),
            'supervisor_policy_missing' => config(['certification.origins.practica.supervisor_states' => []]),
            'worker_starts_late' => DB::table('gestion.persona_trabajador')->where('id_persona_trabajador', 2)->update(['fecha_ingreso' => '2025-03-01']),
            'worker_ends_early' => DB::table('gestion.persona_trabajador')->where('id_persona_trabajador', 2)->update(['fecha_fin' => '2025-06-29']),
            'missing_career' => DB::table('gestion.practica')->update(['carrera' => '  ']),
            'missing_type' => DB::table('certificado.tipo_certificado')->where('id_tipo_certificado', 38)->update(['estado' => false]),
            'ambiguous_type' => DB::table('certificado.tipo_certificado')->insert(['id_tipo_certificado' => 39, 'nombre_tipo' => 'Prácticas', 'estado' => true]),
        };

        $evaluation = $this->getJson('/api/practices/1/evaluacion-certificacion')->assertOk()
            ->assertJsonPath('data.estado_elegibilidad', 'Pendiente de validación')->assertJsonPath('data.puede_preparar', false)
            ->assertJsonPath('data.cumplimiento_requisitos_acreditado', null)->assertJsonPath('data.aprobacion_acreditada', null);
        self::assertNotEmpty($evaluation->json('data.motivos_bloqueo'));
        $this->postJson('/api/certificaciones/practica/1/preparar', ['accion' => 'revisar_documento'])->assertConflict();
        $this->assertDatabaseCount('certificado.certificado', 0);
        $this->assertDatabaseCount('certificado.certificado_documento', 0);
    }

    public function test_practice_employment_evidence_changes_invalidate_the_pending_review_with_409(): void
    {
        Storage::fake('local');
        $this->seedAccreditedOrigin('practica');
        config(['certification.origins.practica.supervision_required' => true]);
        $review = $this->postJson('/api/certificaciones/practica/1/preparar', ['accion' => 'revisar_documento'])->assertOk()->json('data');
        DB::table('gestion.persona_trabajador')->where('id_persona_trabajador', 2)->update(['fecha_fin' => '2025-06-29']);

        $this->postJson('/api/certificaciones/practica/1/preparar', ['accion' => 'confirmar_preparacion',
            'token_revision' => $review['token_revision'], 'confirmar' => true])->assertConflict();
        $this->assertDatabaseCount('certificado.certificado', 0);
    }

    public function test_practice_template_and_approved_context_preserve_the_correct_beneficiary_and_historical_shape(): void
    {
        $this->seedAccreditedOrigin('practica');
        $origins = app(CertificateOrigins::class);
        $context = $origins->context('practica', 1);
        self::assertSame(1, $context->id_persona);
        self::assertSame(38, $context->id_tipo_certificado);
        self::assertSame([[
            'id_practica_supervisor' => 1, 'id_practica' => 1, 'id_persona_trabajador' => 2, 'tipo_supervision' => null,
            'fecha_inicio' => '2025-01-01', 'fecha_fin' => '2025-06-30', 'estado' => 'SUPERVISION_PRUEBA',
            'id_persona' => 2, 'nombres' => 'Luis', 'apellido_paterno' => 'Responsable', 'apellido_materno' => null,
        ]], $context->supervisores);
        $html = view('certificates.origins', ['context' => $context, 'customMessage' => $origins->message($context),
            'signers' => [], 'certificate' => null, 'code' => str_repeat('a', 30), 'qrDataUri' => 'data:image/svg+xml;base64,',
            'logoDataUri' => 'data:image/png;base64,', 'administrative' => true])->render();

        foreach (['CERTIFICADO DE PRÁCTICAS PREPROFESIONALES', 'Ana', 'Área', '2025-01-01', '2025-06-30',
            str_repeat('a', 30), 'QR de verificación documental'] as $text) {
            self::assertStringContainsString($text, $html);
        }
    }

    public function test_practice_institutional_presentation_has_one_approved_message_and_an_isolated_pdf(): void
    {
        Storage::fake('local');
        $this->seedAccreditedOrigin('practica');
        $this->travelTo('2026-11-16 10:00:00');
        DB::table('gestion.persona')->where('id_persona', 1)->update([
            'nombres' => 'Ana María', 'apellido_paterno' => 'Pérez', 'apellido_materno' => 'Ramírez',
        ]);
        $message = 'Por haber realizado satisfactoriamente sus prácticas preprofesionales en la Gerencia de Desarrollo Social de la Municipalidad Distrital de Morales, fortaleciendo sus conocimientos y competencias profesionales, demostrando compromiso, responsabilidad y dedicación durante el periodo de prácticas.';
        $review = $this->postJson('/api/certificaciones/practica/1/preparar', ['accion' => 'revisar_documento', 'mensaje' => $message])
            ->assertOk()->assertJsonPath('data.mensaje', $message)->json('data');
        $manifest = json_decode(Crypt::decryptString(Storage::disk('local')->get('certificados/revisiones/'.hash('sha256', $review['token_revision']).'.manifest')), true, 512, JSON_THROW_ON_ERROR);
        $html = view('certificates.origins', ['context' => (object) $manifest['context'], 'customMessage' => $message,
            'certificate' => null, 'signers' => [], 'logoDataUri' => 'data:image/png;base64,'.base64_encode(file_get_contents(resource_path('images/escudo-morales.png'))),
            'code' => $manifest['code'], 'qrDataUri' => 'data:image/svg+xml;base64,', 'administrative' => true])->render();
        $this->assertSame(1, substr_count($html, e($message)));
        $this->assertStringContainsString('Ana María Pérez Ramírez', $html);
        $this->assertSame(1, substr_count($html, 'Morales, 16 de noviembre de 2026'));
        foreach (['Institución', 'Sistemas', 'Área:', '01/01/2025', '30/06/2025', 'horas de prácticas'] as $text) {
            $this->assertStringNotContainsString($text, $html);
        }
        $pdf = base64_decode($review['vista_previa_pdf']['contenido_base64'], true);
        $this->assertSame($review['hash_pdf'], hash('sha256', $pdf));
        $this->assertStringStartsWith('%PDF-', $pdf);
        file_put_contents(storage_path('framework/testing/practice-presentation.pdf'), $pdf);
        $this->assertDatabaseCount('certificado.certificado', 0);
        $this->assertDatabaseCount('certificado.certificado_documento', 0);
    }

    public function test_practice_institutional_date_uses_the_supplied_emission_date_without_duplicate_metadata(): void
    {
        $this->seedAccreditedOrigin('practica');
        $this->travelTo('2026-11-16');
        $context = app(CertificateOrigins::class)->context('practica', 1);
        $html = view('certificates.origins', ['context' => $context, 'customMessage' => 'Mensaje aprobado sin modificaciones.',
            'certificate' => (object) ['fecha_emision' => '2026-11-12'], 'signers' => [],
            'logoDataUri' => 'data:image/png;base64,', 'code' => null, 'qrDataUri' => null, 'administrative' => true])->render();

        $this->assertSame(1, substr_count($html, 'Mensaje aprobado sin modificaciones.'));
        $this->assertSame(1, substr_count($html, 'Morales, 12 de noviembre de 2026'));
        $this->assertStringNotContainsString('Morales, 16 de noviembre de 2026', $html);
    }

    #[TestWith(['missing'])]
    #[TestWith(['ambiguous'])]
    #[TestWith(['wrong_beneficiary'])]
    public function test_practice_recovery_does_not_choose_a_missing_ambiguous_or_foreign_document(string $condition): void
    {
        Storage::fake('local');
        $this->seedAccreditedOrigin('practica');
        DB::table('certificado.certificado')->insert(['id_certificado' => 1, 'id_practica' => 1,
            'id_persona' => $condition === 'wrong_beneficiary' ? 2 : 1, 'id_tipo_certificado' => 38, 'estado' => 'PREPARADO']);
        $documentCount = match ($condition) {
            'missing' => 0,
            'ambiguous' => 2,
            default => 1,
        };
        for ($index = 1; $index <= $documentCount; $index++) {
            DB::table('certificado.certificado_documento')->insert(['id_certificado_documento' => $index, 'id_certificado' => 1,
                'codigo_verificacion' => str_repeat((string) $index, 30), 'hash_certificado' => hash('sha256', (string) $index),
                'ruta_archivo' => 'certificados/prueba-'.$index.'.pdf', 'estado' => 'Vigente']);
        }

        $this->getJson('/api/practices/1/evaluacion-certificacion')->assertOk()
            ->assertJsonPath('data.puede_preparar', false)
            ->assertJsonPath('data.certificados_existentes.0.id_certificado_documento', null);
        $this->getJson('/api/certificaciones/practica/1/preparar?accion=consultar_preparacion')->assertConflict();
        if ($condition === 'wrong_beneficiary') {
            $this->getJson('/api/certificados-documentos/detail/1?flujo=firmas')->assertNotFound();
        }
    }

    public function test_confirmed_practice_appears_in_the_public_portal_and_pdf_requires_explicit_publication(): void
    {
        Storage::fake('local');
        $this->seedAccreditedOrigin('practica');
        DB::table('gestion.persona_trabajador')->where('id_persona', 1)->delete();
        auth()->user()->id_persona = 2;
        DB::statement('CREATE TABLE gestion.tipo_documento (id_tipo_documento INTEGER PRIMARY KEY, nombre TEXT, estado BOOLEAN)');
        DB::statement('CREATE TABLE gestion.persona_documento (id_persona_documento INTEGER PRIMARY KEY, id_persona INTEGER, id_tipo_documento INTEGER, numero_documento TEXT, estado TEXT)');
        DB::table('gestion.tipo_documento')->insert(['id_tipo_documento' => 1, 'nombre' => 'DNI', 'estado' => true]);
        DB::table('gestion.persona_documento')->insert(['id_persona_documento' => 1, 'id_persona' => 1, 'id_tipo_documento' => 1,
            'numero_documento' => '00000001', 'estado' => 'activo']);
        config(['certification.public_lookup.pdf_policy_reference' => null, 'certification.public_lookup.pdf_codes' => []]);
        $url = '/api/certificaciones/practica/1/preparar';
        $review = $this->postJson($url, ['accion' => 'revisar_documento'])->assertOk()->json('data');
        $prepared = $this->postJson($url, ['accion' => 'confirmar_preparacion', 'token_revision' => $review['token_revision'],
            'confirmar' => true])->assertCreated()->json('data');
        $this->assertDatabaseHas('certificado.certificado', ['id_practica' => 1, 'id_persona' => 1, 'id_tipo_certificado' => 38,
            'id_participacion' => null, 'id_actividad_sesion' => null, 'id_reconocimiento' => null, 'estado' => 'PREPARADO']);
        $evaluation = $this->getJson('/api/practices/1/evaluacion-certificacion')->assertOk()
            ->assertJsonPath('data.puede_preparar', false)->assertJsonPath('data.puede_consultar_certificado_existente', true)
            ->assertJsonCount(1, 'data.certificados_existentes');
        self::assertSame($prepared['id_certificado_documento'], $evaluation->json('data.certificados_existentes.0.id_certificado_documento'));
        $this->getJson('/api/certificados-documentos/detail/'.$evaluation->json('data.certificados_existentes.0.id_certificado_documento').'?flujo=firmas')->assertOk()
            ->assertJsonPath('data.id_certificado_documento', $prepared['id_certificado_documento'])
            ->assertJsonPath('data.beneficiario.id_persona', 1);
        $this->postJson('/api/portal-certificados/consultar-dni', ['dni' => '00000001'])->assertOk()->assertJsonCount(0, 'data.certificados');
        $id = $prepared['id_certificado_documento'];
        $base = '/api/certificados-documentos/'.$id;
        $send = $this->postJson($base.'/revisar-envio', [])->assertOk()->json('data');
        $this->postJson($base.'/enviar-gerencia', ['token_revision' => $send['token_revision'], 'confirmar' => true])->assertOk();
        $plan = ['modalidad' => 'SECUENCIAL', 'firmantes' => [['id_persona_trabajador' => 2, 'orden_firma' => 1]]];
        $revision = $this->postJson($base.'/firma/revision-pdf', $plan)->assertOk()->json('data');
        $this->postJson($base.'/firma/asignar', $plan + ['confirmar' => true])->assertOk();
        $this->postJson($base.'/firma/confirmar-pdf', ['token_revision' => $revision['token_revision'], 'confirmar' => true])->assertOk();
        $this->getJson($base.'/firma/progreso')->assertOk()->assertJsonPath('data.disponible_rrhh', true)
            ->assertJsonPath('data.etiqueta_estado', 'Listo para entrega');
        app('auth')->forgetGuards();
        $this->postJson('/api/portal-certificados/consultar-dni', ['dni' => '00000001'])->assertOk()->assertJsonCount(1, 'data.certificados')
            ->assertJsonPath('data.certificados.0.origen', 'practica')->assertJsonPath('data.certificados.0.pdf_publicado', false)
            ->assertJsonPath('data.certificados.0.url_documento', null)->assertJsonPath('data.certificados.0.url_descarga', null)
            ->assertJsonMissingPath('data.certificados.0.id_persona')->assertJsonMissingPath('data.certificados.0.dni');
        $verification = '/api/certificados/verificar/'.$prepared['codigo_verificacion'];
        $this->getJson($verification.'?archivo=1')->assertForbidden();
        config(['certification.public_lookup.pdf_policy_reference' => 'PUBLICACION EXCLUSIVA DE PRUEBA AISLADA',
            'certification.public_lookup.pdf_codes' => [$prepared['codigo_verificacion']]]);
        $public = $this->postJson('/api/portal-certificados/consultar-dni', ['dni' => '00000001'])->assertOk()
            ->assertJsonPath('data.certificados.0.pdf_publicado', true)->json('data.certificados.0');
        $this->getJson($verification)->assertOk()->assertJsonPath('data.firma_verificada', false)
            ->assertJsonPath('data.emision_oficial_verificada', false);
        foreach (['url_documento', 'url_descarga'] as $field) {
            $response = $this->get($public[$field])->assertOk()->assertHeader('Content-Type', 'application/pdf');
            self::assertSame($revision['hash_pdf'], hash_file('sha256', $response->baseResponse->getFile()->getPathname()));
            if ($field === 'url_descarga') {
                $response->assertDownload('certificado-'.$prepared['codigo_verificacion'].'.pdf');
            }
        }
        config(['certification.public_lookup.pdf_codes' => []]);
        $this->getJson($verification.'?archivo=1&descargar=1')->assertForbidden();
        $original = DB::table('certificado.certificado_documento')->where('id_certificado_documento', $id)->first();
        self::assertSame($review['hash_pdf'], $original->hash_certificado);
        self::assertSame(base64_decode($review['vista_previa_pdf']['contenido_base64'], true), Storage::disk('local')->get($original->ruta_archivo));
        $this->assertDatabaseCount('certificado.certificado', 1);
        $this->assertDatabaseHas('certificado.certificado', ['id_practica' => 1, 'estado' => 'PENDIENTE_FIRMA', 'fecha_emision' => null]);
        $this->assertDatabaseCount('certificado.participacion', 0);
    }

    #[TestWith(['reconocimiento'])]
    #[TestWith(['practica'])]
    public function test_real_origin_uses_existing_document_workflow_and_retries_do_not_duplicate(string $origin): void
    {
        Storage::fake('local');
        $this->seedAccreditedOrigin($origin);
        $url = '/api/certificaciones/'.$origin.'/1/preparar';
        $review = $this->postJson($url, ['accion' => 'revisar_documento'])->assertOk()->json('data');
        self::assertStringStartsWith('%PDF-', base64_decode($review['vista_previa_pdf']['contenido_base64'], true));
        $this->assertDatabaseCount('certificado.certificado', 0);
        $confirm = ['accion' => 'confirmar_preparacion', 'token_revision' => $review['token_revision'], 'confirmar' => true];
        $prepared = $this->postJson($url, $confirm)->assertCreated()->json('data');
        $id = $prepared['id_certificado_documento'];
        self::assertMatchesRegularExpression('/^[a-f0-9]{30}$/D', $prepared['codigo_verificacion']);
        $this->getJson('/api/certificados/verificar/'.$prepared['codigo_verificacion'])->assertOk()
            ->assertJsonPath('data.integridad_verificada', true)->assertJsonPath('data.puede_ver_documento', false);
        $this->postJson($url, $confirm)->assertOk()->assertJsonPath('data.creado', false);
        $this->postJson($url, ['accion' => 'revisar_documento'])->assertConflict();
        $this->assertDatabaseCount('certificado.certificado', 1);
        $this->assertDatabaseCount('certificado.certificado_documento', 1);
        $this->assertDatabaseCount('certificado.participacion', 0);
        $this->assertDatabaseHas('certificado.certificado', ['id_'.$origin => 1, 'id_participacion' => null,
            'id_actividad_sesion' => null, 'estado' => 'PREPARADO', 'fecha_emision' => null]);
        $this->getJson('/api/rrhh/certificados')->assertOk()->assertJsonPath('data.total', 0);
        $send = $this->postJson('/api/certificados-documentos/'.$id.'/revisar-envio')->assertOk()->json('data');
        $this->postJson('/api/certificados-documentos/'.$id.'/enviar-gerencia', ['token_revision' => $send['token_revision'], 'confirmar' => true])->assertOk();
        $base = '/api/certificados-documentos/'.$id.'/firma';
        $this->getJson($base.'/firmantes-disponibles')->assertOk()->assertJsonCount(1, 'data');
        $plan = ['modalidad' => 'SECUENCIAL', 'firmantes' => [['id_persona_trabajador' => 2, 'orden_firma' => 1]]];
        $revision = $this->postJson($base.'/revision-pdf', $plan)->assertOk()->json('data');
        $this->postJson($base.'/asignar', $plan + ['confirmar' => true])->assertOk();
        $this->getJson('/api/rrhh/certificados')->assertOk()->assertJsonPath('data.total', 0);
        $this->postJson($base.'/confirmar-pdf', ['token_revision' => $revision['token_revision'], 'confirmar' => true])->assertOk();
        $this->getJson('/api/rrhh/certificados')->assertOk()->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.otros_origenes.0.origen', $origin);
        $document = DB::table('certificado.certificado_documento')->first();
        self::assertSame($review['hash_pdf'], $document->hash_certificado);
        self::assertSame(base64_decode($review['vista_previa_pdf']['contenido_base64'], true), Storage::disk('local')->get($document->ruta_archivo));
        $this->assertDatabaseCount('certificado.certificado_documento', 1);
        $this->getJson('/api/certificados-documentos/list')->assertOk();
    }

    #[TestWith(['reconocimiento'])]
    #[TestWith(['practica'])]
    public function test_unapproved_origin_cannot_prepare_a_document(string $origin): void
    {
        Storage::fake('local');
        $this->seedAccreditedOrigin($origin);
        $table = $origin === 'reconocimiento' ? 'certificado.reconocimiento' : 'gestion.practica';
        DB::table($table)->update(['estado' => 'PENDIENTE_PRUEBA']);

        $this->postJson('/api/certificaciones/'.$origin.'/1/preparar', ['accion' => 'revisar_documento'])->assertConflict();
        $this->assertDatabaseCount('certificado.certificado', 0);
    }

    #[TestWith(['reconocimiento'])]
    #[TestWith(['practica'])]
    public function test_origin_preparation_checks_authentication_permissions_and_area(string $origin): void
    {
        $url = '/api/certificaciones/'.$origin.'/1/preparar';
        $this->postJson($url, ['accion' => 'revisar_documento'])->assertUnauthorized();
        $this->seedAccreditedOrigin($origin);
        $this->authorize(false);
        $this->postJson($url, ['accion' => 'revisar_documento'])->assertForbidden();
        $this->authorize(false, ['emision.ver', 'emision.crear', 'emision.editar', 'practicantes.ver', 'reconocimientos.ver']);
        DB::table('gestion.area')->insert(['id_area' => 2, 'nombre' => 'Fuera de ámbito']);
        DB::table('gestion.persona_trabajador')->where('id_persona_trabajador', 1)->update(['id_area' => 2]);
        if ($origin === 'reconocimiento') {
            DB::table('gestion.persona_trabajador')->where('id_persona_trabajador', 1)->update(['id_area' => 1]);
            DB::table('gestion.persona_trabajador')->where('id_persona', 1)->update(['estado' => false]);
        }

        $this->postJson($url, ['accion' => 'revisar_documento'])->assertForbidden();
        $this->assertDatabaseCount('certificado.certificado', 0);
    }

    #[TestWith(['reconocimiento'])]
    #[TestWith(['practica'])]
    public function test_unknown_origin_returns_404_without_creating_records(string $origin): void
    {
        $this->seedAccreditedOrigin($origin);

        $this->postJson('/api/certificaciones/'.$origin.'/999/preparar', ['accion' => 'revisar_documento'])->assertNotFound();
        $this->assertDatabaseCount('certificado.certificado', 0);
    }

    #[TestWith(['reconocimiento'])]
    #[TestWith(['practica'])]
    public function test_origin_changes_after_preview_require_another_review(string $origin): void
    {
        Storage::fake('local');
        $this->seedAccreditedOrigin($origin);
        $url = '/api/certificaciones/'.$origin.'/1/preparar';
        $review = $this->postJson($url, ['accion' => 'revisar_documento'])->assertOk()->json('data');
        $table = $origin === 'reconocimiento' ? 'certificado.reconocimiento' : 'gestion.practica';
        DB::table($table)->update($origin === 'reconocimiento' ? ['motivo' => 'Otro motivo'] : ['fecha_fin' => '2025-06-29']);

        $this->postJson($url, ['accion' => 'confirmar_preparacion', 'token_revision' => $review['token_revision'], 'confirmar' => true])->assertConflict();
        $this->assertDatabaseCount('certificado.certificado', 0);
    }

    public function test_free_text_state_needs_an_institutional_policy_before_recognition_is_eligible(): void
    {
        $this->seedAccreditedOrigin('reconocimiento');
        config(['certification.origins.reconocimiento.reference' => null]);

        $this->getJson('/api/reconocimientos/1/evaluacion-certificacion')->assertOk()
            ->assertJsonPath('data.puede_preparar', false)
            ->assertJsonPath('data.id_tipo_certificado', 23)
            ->assertJsonPath('data.ponente_externo_admitido', false)
            ->assertJsonPath('data.id_sesion', null);
    }

    #[TestWith(['reconocimiento'])]
    #[TestWith(['practica'])]
    public function test_origin_template_uses_real_facts_without_claiming_a_training_session(string $origin): void
    {
        $this->seedAccreditedOrigin($origin);
        $service = app(CertificateOrigins::class);
        $context = $service->context($origin, 1);

        $html = view('certificates.origins', ['context' => $context, 'customMessage' => $service->message($context),
            'signers' => [], 'certificate' => null, 'code' => str_repeat('a', 30), 'qrDataUri' => 'data:image/svg+xml;base64,',
            'logoDataUri' => 'data:image/png;base64,', 'administrative' => true])->render();

        self::assertStringContainsString('Municipalidad Distrital de Morales', $html);
        self::assertStringContainsString($origin === 'reconocimiento' ? 'Servicio acreditado' : '2025-06-30', $html);
        self::assertStringNotContainsString('correspondiente a la sesión', $html);
        self::assertStringContainsString(str_repeat('a', 30), $html);
        self::assertStringContainsString('QR de verificación', $html);
    }

    private function seedActivitySpeaker(): void
    {
        $this->seedAccreditedOrigin('reconocimiento');
        config(['certification.origins.reconocimiento.reference' => null, 'certification.origins.reconocimiento.eligible_states' => []]);
        $this->authorize(permissions: ['emision.ver', 'emision.crear', 'emision.editar', 'actividades.ver',
            'ponentes-firmantes.ver', 'reconocimientos.ver', 'reconocimientos.crear']);
    }

    private function seedSessionSpeakers(): void
    {
        $this->seedActivitySpeaker();
        $this->authorize(permissions: ['emision.ver', 'emision.crear', 'emision.editar', 'actividades.ver',
            'ponentes-firmantes.ver', 'ponentes-firmantes.crear', 'ponentes-firmantes.eliminar', 'ponentes-firmantes.editar',
            'reconocimientos.ver', 'reconocimientos.crear']);
        DB::statement('ALTER TABLE certificado.actividad_ponente ADD COLUMN id_actividad_sesion INTEGER NULL');
        DB::statement('ALTER TABLE certificado.reconocimiento ADD COLUMN id_actividad_ponente INTEGER NULL');
        DB::statement('ALTER TABLE certificado.reconocimiento ADD COLUMN id_tipo_certificado INTEGER NULL');
        DB::statement('CREATE UNIQUE INDEX certificado.uq_session_speaker ON actividad_ponente (id_actividad_sesion, id_persona_ponente)');
        DB::statement('CREATE UNIQUE INDEX certificado.uq_recognition_speaker_type ON reconocimiento (id_actividad_ponente, id_tipo_certificado)');
        DB::table('certificado.actividad_sesion')->update(['estado' => 'Finalizada', 'fecha' => '2025-11-01']);
    }

    /** @return array{url: string, input: array<string, mixed>} */
    private function editableSessionRecognition(): array
    {
        Storage::fake('local');
        $this->seedSessionSpeakers();
        $assignment = $this->postJson('/api/activity/1/sessions/1/ponentes', ['id_persona_ponente' => 1])->assertOk()->json('data.vinculo.id_actividad_ponente');

        return ['url' => '/api/certificaciones/ponente_sesion/'.$assignment.'/preparar',
            'input' => ['accion' => 'revisar_documento', 'modalidad_reconocimiento' => 'sesion', 'id_sesion' => 1,
                'mensaje' => "Por compartir innovación & servicio.\nGracias por su contribución.",
                'motivo' => 'Participación como ponente y aporte técnico.']];
    }

    #[TestWith(['actividad'])]
    #[TestWith(['sesion'])]
    public function test_original_recognition_summary_preserves_document_state_and_allows_authorized_download(string $modality): void
    {
        if ($modality === 'sesion') {
            ['url' => $url, 'input' => $input] = $this->editableSessionRecognition();
        } else {
            Storage::fake('local');
            $this->seedActivitySpeaker();
            $url = '/api/certificaciones/ponente_actividad/1/preparar';
            $input = ['accion' => 'revisar_documento', 'modalidad_reconocimiento' => 'actividad'];
        }
        $review = $this->postJson($url, $input)->assertOk()->json('data');
        $prepared = $this->postJson($url, array_replace($input, ['accion' => 'confirmar_preparacion',
            'token_revision' => $review['token_revision'], 'confirmar' => true]))->assertCreated()->json('data');
        $recognitionId = $prepared['id_reconocimiento'];
        $documentId = $prepared['id_certificado_documento'];
        $document = DB::table('certificado.certificado_documento')->where('id_certificado_documento', $documentId)->first();
        $bytes = Storage::disk('local')->get($document->ruta_archivo);
        $files = Storage::disk('local')->allFiles();
        $this->partialMock(CertificateDocumentGenerator::class)->shouldNotReceive('reviewPdf');
        DB::enableQueryLog();
        $evaluate = '/api/reconocimientos/'.$recognitionId.'/evaluacion-certificacion';
        $data = $this->getJson($evaluate)->assertOk()->assertJsonPath('data.puede_preparar', false)
            ->assertJsonPath('data.modalidad_reconocimiento', $modality)
            ->assertJsonPath('data.id_certificado_documento', $documentId)
            ->assertJsonPath('data.estado_certificado', 'PREPARADO')->assertJsonPath('data.estado_documento', 'Vigente')
            ->assertJsonPath('data.puede_visualizar', true)->assertJsonPath('data.puede_descargar', true)
            ->assertJsonPath('data.documento_existente.origen', 'ponente_'.$modality)->json('data');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertEmpty(array_filter($queries, fn (array $query): bool => str_contains($query['query'], 'certificado_firma')));
        $this->get($data['url_documento'])->assertOk()->assertDownload();
        $this->getJson('/api/certificaciones/reconocimiento/'.$recognitionId.'/preparar?accion=consultar_preparacion')->assertOk()
            ->assertJsonPath('data.recuperado', true)->assertJsonPath('data.id_certificado_documento', $documentId)
            ->assertJsonMissingPath('data.vista_previa_pdf')->assertJsonMissingPath('data.token_revision');
        $base = '/api/certificados-documentos/'.$documentId;
        $send = $this->postJson($base.'/revisar-envio')->assertOk()->json('data');
        $this->postJson($base.'/enviar-gerencia', ['token_revision' => $send['token_revision'], 'confirmar' => true])->assertOk();
        $this->getJson($evaluate)->assertOk()->assertJsonPath('data.estado_certificado', 'PENDIENTE_FIRMA')
            ->assertJsonPath('data.documento_existente.puede_preparar_como_individual', false);
        $this->getJson('/api/certificados-documentos/detail/'.$documentId.'?flujo=firmas')->assertOk()
            ->assertJsonPath('data.origen', 'ponente_'.$modality)->assertJsonPath('data.puede_visualizar', true);
        $this->assertSame($bytes, Storage::disk('local')->get($document->ruta_archivo));
        $this->assertSame($files, Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('certificado.certificado', 1);
    }

    #[TestWith(['missing_file'])]
    #[TestWith(['annulled'])]
    #[TestWith(['missing_document'])]
    public function test_original_recognition_summary_never_invents_a_pdf_url(string $case): void
    {
        ['url' => $url, 'input' => $input] = $this->editableSessionRecognition();
        $review = $this->postJson($url, $input)->assertOk()->json('data');
        $prepared = $this->postJson($url, array_replace($input, ['accion' => 'confirmar_preparacion',
            'token_revision' => $review['token_revision'], 'confirmar' => true]))->assertCreated()->json('data');
        $documentId = $prepared['id_certificado_documento'];
        if ($case === 'missing_file') {
            Storage::disk('local')->delete(DB::table('certificado.certificado_documento')->where('id_certificado_documento', $documentId)->value('ruta_archivo'));
        } elseif ($case === 'annulled') {
            DB::table('certificado.certificado_documento')->where('id_certificado_documento', $documentId)->update(['estado' => 'Anulado']);
        } else {
            DB::table('certificado.certificado_documento')->where('id_certificado_documento', $documentId)->delete();
        }
        $data = $this->getJson('/api/reconocimientos/'.$prepared['id_reconocimiento'].'/evaluacion-certificacion')->assertOk()
            ->assertJsonPath('data.puede_preparar', false)->assertJsonPath('data.url_documento', null)
            ->assertJsonPath('data.puede_visualizar', false)->assertJsonPath('data.puede_descargar', false)->json('data');
        $this->assertNotEmpty($data['motivo_documento']);
        $this->assertDatabaseCount('certificado.certificado', 1);
    }

    public function test_original_activity_document_summary_preserves_read_permissions_and_original_area_scope(): void
    {
        Storage::fake('local');
        $this->seedActivitySpeaker();
        $url = '/api/certificaciones/ponente_actividad/1/preparar';
        $review = $this->postJson($url, ['accion' => 'revisar_documento', 'modalidad_reconocimiento' => 'actividad'])->assertOk()->json('data');
        $prepared = $this->postJson($url, ['accion' => 'confirmar_preparacion', 'modalidad_reconocimiento' => 'actividad',
            'token_revision' => $review['token_revision'], 'confirmar' => true])->assertCreated()->json('data');
        $evaluate = '/api/reconocimientos/'.$prepared['id_reconocimiento'].'/evaluacion-certificacion';
        $this->authorize(false, ['reconocimientos.ver']);
        $this->getJson($evaluate)->assertForbidden();
        $this->authorize(false, ['emision.ver', 'reconocimientos.ver']);
        $this->getJson($evaluate)->assertOk()->assertJsonPath('data.puede_visualizar', true)
            ->assertJsonPath('data.puede_preparar', false);
        DB::table('gestion.area')->insert(['id_area' => 2, 'nombre' => 'Otra área']);
        DB::table('certificado.actividad')->where('id_actividad', 1)->update(['id_area' => 2]);
        $this->getJson($evaluate)->assertOk()->assertJsonPath('data.url_documento', null)
            ->assertJsonPath('data.puede_visualizar', false)->assertJsonPath('data.puede_descargar', false)
            ->assertJsonPath('data.motivo_documento', 'El documento original está fuera del ámbito autorizado.');
        $this->assertDatabaseCount('certificado.certificado', 1);
    }

    public function test_session_text_is_previewed_persisted_recovered_and_preserved_through_management(): void
    {
        ['url' => $url, 'input' => $input] = $this->editableSessionRecognition();
        $review = $this->postJson($url, $input)->assertOk()->assertJsonPath('data.mensaje', $input['mensaje'])
            ->assertJsonPath('data.motivo', $input['motivo'])->assertJsonPath('data.texto_editable', true)->json('data');
        $manifest = json_decode(Crypt::decryptString(Storage::disk('local')->get('certificados/revisiones/'.hash('sha256', $review['token_revision']).'.manifest')), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $manifest['context']['id_persona']);
        self::assertSame(1, $manifest['context']['id_sesion']);
        self::assertSame($input['mensaje'], $manifest['message']);
        self::assertSame($input['motivo'], $manifest['context']['texto_reconocimiento']['motivo']);
        $html = view('certificates.origins', ['context' => (object) $manifest['context'], 'customMessage' => $manifest['message'],
            'certificate' => null, 'signers' => [], 'logoDataUri' => 'data:image/png;base64,',
            'code' => $manifest['code'], 'qrDataUri' => 'data:image/svg+xml;base64,', 'administrative' => true])->render();
        foreach (['Motivo:', $input['motivo'], 'innovación &amp; servicio', 'Ana', 'Actividad', 'Primera', '2025-11-01', $manifest['code']] as $text) {
            self::assertStringContainsString($text, $html);
        }
        $confirm = array_replace($input, ['accion' => 'confirmar_preparacion', 'token_revision' => $review['token_revision'], 'confirmar' => true]);
        $prepared = $this->postJson($url, $confirm)->assertCreated()->assertJsonPath('data.mensaje', $input['mensaje'])
            ->assertJsonPath('data.motivo', $input['motivo'])->json('data');
        $this->postJson($url, $confirm)->assertOk()->assertJsonPath('data.creado', false);
        $this->assertDatabaseHas('certificado.reconocimiento', ['id_reconocimiento' => $prepared['id_reconocimiento'], 'motivo' => $input['motivo']]);
        $id = $prepared['id_certificado_documento'];
        $base = '/api/certificados-documentos/'.$id;
        $this->getJson('/api/certificados-documentos/detail/'.$id.'?flujo=firmas')->assertOk()
            ->assertJsonPath('data.mensaje_aprobado', $input['mensaje'])->assertJsonPath('data.motivo', $input['motivo'])
            ->assertJsonPath('data.mensaje_estructurado_disponible', true);
        $recovered = $this->postJson($url, $input)->assertOk()->assertJsonPath('data.recuperado', true)
            ->assertJsonPath('data.texto_editable', false)->assertJsonPath('data.token_revision', null)->json('data');
        self::assertSame($review['hash_pdf'], $recovered['hash_pdf']);
        self::assertSame($prepared['codigo_verificacion'], $recovered['codigo_verificacion']);
        $this->postJson($url, array_replace($input, ['mensaje' => 'No debe reemplazar el PDF guardado.']))->assertConflict();
        $send = $this->postJson($base.'/revisar-envio', [])->assertOk()->json('data');
        $this->postJson($base.'/enviar-gerencia', ['token_revision' => $send['token_revision'], 'confirmar' => true])->assertOk();
        $plan = ['modalidad' => 'SECUENCIAL', 'firmantes' => [['id_persona_trabajador' => 2, 'orden_firma' => 1]]];
        $revision = $this->postJson($base.'/firma/revision-pdf', $plan)->assertOk()->assertJsonPath('data.mensaje', $input['mensaje'])->json('data');
        $this->postJson($base.'/firma/asignar', $plan + ['confirmar' => true])->assertOk();
        $this->postJson($base.'/firma/confirmar-pdf', ['token_revision' => $revision['token_revision'], 'confirmar' => true])->assertOk();
        $this->getJson($base.'/firma/progreso')->assertOk()->assertJsonPath('data.disponible_rrhh', true);
        $this->getJson('/api/rrhh/certificados')->assertOk()->assertJsonPath('data.otros_origenes.0.mensaje', $input['mensaje'])
            ->assertJsonPath('data.otros_origenes.0.motivo', $input['motivo']);
        $this->postJson($url, array_replace($input, ['motivo' => 'Cambio no autorizado tras Gerencia.']))->assertConflict();
        $original = DB::table('certificado.certificado_documento')->where('id_certificado_documento', $id)->first();
        self::assertSame($review['hash_pdf'], $original->hash_certificado);
        self::assertSame(base64_decode($review['vista_previa_pdf']['contenido_base64'], true), Storage::disk('local')->get($original->ruta_archivo));
        $this->assertDatabaseCount('certificado.certificado', 1);
        $this->assertDatabaseCount('certificado.certificado_documento', 1);
        $this->assertDatabaseHas('certificado.certificado', ['estado' => 'PENDIENTE_FIRMA', 'fecha_emision' => null]);
    }

    public function test_session_text_new_preview_and_changed_confirmation_invalidate_old_content_with_409(): void
    {
        ['url' => $url, 'input' => $input] = $this->editableSessionRecognition();
        $first = $this->postJson($url, $input)->assertOk()->json('data');
        $secondInput = array_replace($input, ['mensaje' => 'Segundo mensaje revisado.', 'motivo' => 'Segundo motivo revisado.']);
        $second = $this->postJson($url, $secondInput)->assertOk()->json('data');
        $oldConfirm = array_replace($input, ['accion' => 'confirmar_preparacion', 'token_revision' => $first['token_revision'], 'confirmar' => true]);
        $this->postJson($url, $oldConfirm)->assertConflict();
        $confirm = array_replace($secondInput, ['accion' => 'confirmar_preparacion', 'token_revision' => $second['token_revision'], 'confirmar' => true]);
        $this->postJson($url, array_replace($confirm, ['mensaje' => 'Cambio sin vista previa.']))->assertConflict();
        $this->postJson($url, array_replace($confirm, ['motivo' => 'Motivo distinto.']))->assertConflict();
        $this->postJson($url, array_diff_key($confirm, array_flip(['mensaje', 'motivo'])))->assertUnprocessable();
        $this->assertDatabaseCount('certificado.certificado', 0);
        $this->postJson($url, $confirm)->assertCreated()->assertJsonPath('data.mensaje', $secondInput['mensaje'])
            ->assertJsonPath('data.motivo', $secondInput['motivo']);
    }

    #[TestWith(['mensaje', ''])]
    #[TestWith(['motivo', '  '])]
    #[TestWith(['mensaje', 123])]
    #[TestWith(['motivo', ['no texto']])]
    #[TestWith(['mensaje', '<script>alert(1)</script>'])]
    #[TestWith(['motivo', '<b>Motivo</b>'])]
    #[TestWith(['mensaje', "texto\x00oculto"])]
    #[TestWith(['motivo', "texto\u{202E}oculto"])]
    #[TestWith(['id_persona', 2])]
    #[TestWith(['codigo_verificacion', 'elegido-por-cliente'])]
    #[TestWith(['origen', 'ponente_actividad'])]
    public function test_session_text_rejects_unsafe_types_or_unknown_fields_with_422(string $field, mixed $value): void
    {
        ['url' => $url, 'input' => $input] = $this->editableSessionRecognition();
        $this->postJson($url, array_replace($input, [$field => $value]))->assertUnprocessable();
        $this->assertDatabaseCount('certificado.certificado', 0);
    }

    public function test_session_text_rejects_overflow_missing_pair_and_tampered_pdf(): void
    {
        ['url' => $url, 'input' => $input] = $this->editableSessionRecognition();
        $this->postJson($url, array_replace($input, ['mensaje' => str_repeat('a', 1201)]))->assertUnprocessable();
        $this->postJson($url, array_replace($input, ['motivo' => str_repeat('a', 501)]))->assertUnprocessable();
        $this->postJson($url, array_diff_key($input, ['motivo' => true]))->assertUnprocessable();
        $review = $this->postJson($url, $input)->assertOk()->json('data');
        Storage::disk('local')->put('certificados/revisiones/'.hash('sha256', $review['token_revision']).'.pdf', '%PDF-alterado');
        $this->postJson($url, array_replace($input, ['accion' => 'confirmar_preparacion', 'token_revision' => $review['token_revision'], 'confirmar' => true]))->assertConflict();
        $this->assertDatabaseCount('certificado.certificado', 0);
    }

    public function test_session_text_preserves_authentication_permissions_and_review_ownership(): void
    {
        $this->postJson('/api/certificaciones/ponente_sesion/1/preparar', ['accion' => 'revisar_documento'])->assertUnauthorized();
        ['url' => $url, 'input' => $input] = $this->editableSessionRecognition();
        $review = $this->postJson($url, $input)->assertOk()->json('data');
        auth()->user()->id_usuario = 9;
        $this->postJson($url, array_replace($input, ['accion' => 'confirmar_preparacion', 'token_revision' => $review['token_revision'], 'confirmar' => true]))->assertForbidden();
        $this->authorize(false);
        $this->postJson($url, $input)->assertForbidden();
    }

    public function test_sessions_keep_distinct_speakers_real_identity_and_historical_assignments(): void
    {
        $this->seedSessionSpeakers();
        DB::table('gestion.persona')->where('id_persona', 2)->update(['nombres' => 'Ana']);
        $first = $this->postJson('/api/activity/1/sessions/1/ponentes', ['id_persona_ponente' => 1])->assertOk()->json('data.vinculo');
        $second = $this->postJson('/api/activity/1/sessions/2/ponentes', ['id_persona_ponente' => 2])->assertOk()->json('data.vinculo');
        $this->getJson('/api/activity-session/1/ponentes-certificacion')->assertOk()->assertJsonCount(1, 'data.ponentes_sesion')
            ->assertJsonPath('data.ponentes_sesion.0.id_persona', 1);
        $this->getJson('/api/activity-session/2/ponentes-certificacion')->assertOk()->assertJsonCount(1, 'data.ponentes_sesion')
            ->assertJsonPath('data.ponentes_sesion.0.id_persona', 2);
        $this->postJson('/api/activity/1/sessions/2/ponentes', ['id_persona_ponente' => 1])->assertOk();
        $this->postJson('/api/activity/1/sessions/1/ponentes', ['id_persona_ponente' => 1])->assertConflict();
        DB::table('gestion.persona_ponente')->insert(['id_persona_ponente' => 3, 'id_persona' => 1]);
        $this->postJson('/api/activity/1/sessions/1/ponentes', ['id_persona_ponente' => 3])->assertConflict();
        $this->getJson('/api/activity/1/sessions/1/ponentes')->assertOk()->assertJsonCount(3, 'data.ponentes_disponibles');
        $this->deleteJson('/api/activity/1/sessions/1/ponentes', ['id_actividad_ponente' => $second['id_actividad_ponente']])->assertNotFound();
        $this->deleteJson('/api/activity/1/sessions/1/ponentes', ['id_actividad_ponente' => $first['id_actividad_ponente']])->assertOk();
        $this->getJson('/api/activity-session/1/ponentes-certificacion')->assertOk()->assertJsonCount(0, 'data.ponentes_sesion');
        $this->assertDatabaseHas('certificado.actividad_ponente', ['id_actividad_ponente' => 1, 'id_actividad_sesion' => null]);
        $this->assertDatabaseHas('certificado.actividad_ponente', ['id_actividad_ponente' => 2, 'id_actividad_sesion' => null]);
    }

    public function test_session_speaker_eligibility_is_not_queried_twice_for_the_same_listing(): void
    {
        $this->seedSessionSpeakers();
        $this->postJson('/api/activity/1/sessions/1/ponentes', ['id_persona_ponente' => 1])->assertOk();
        DB::enableQueryLog();
        DB::flushQueryLog();

        $response = $this->getJson('/api/activity-session/1/ponentes-certificacion');

        $response->assertOk()->assertJsonCount(1, 'data.ponentes_sesion');
        $assignmentQueries = array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains($query['query'], '"a"."estado" as "estado_actividad"')
            && str_contains($query['query'], '"ap"."id_actividad_ponente" = ?'));
        $this->assertCount(2, $assignmentQueries);
    }

    public function test_session_recognition_uses_document_workflow_with_unique_codes_and_idempotency(): void
    {
        Storage::fake('local');
        $this->seedSessionSpeakers();
        DB::statement('DROP INDEX certificado.uq_recognition_speaker_type');
        DB::statement('ALTER TABLE certificado.reconocimiento DROP COLUMN id_tipo_certificado');
        DB::statement('CREATE UNIQUE INDEX certificado.uq_recognition_assignment ON reconocimiento (id_actividad_ponente)');
        DB::table('gestion.persona_trabajador')->where('id_persona', 1)->delete();
        auth()->user()->id_persona = 2;
        $assignment = $this->postJson('/api/activity/1/sessions/1/ponentes', ['id_persona_ponente' => 1])->assertOk()->json('data.vinculo.id_actividad_ponente');
        $url = '/api/certificaciones/ponente_sesion/'.$assignment.'/preparar';
        $reviewInput = ['accion' => 'revisar_documento', 'modalidad_reconocimiento' => 'sesion', 'id_sesion' => 1];
        $review = $this->postJson($url, $reviewInput)->assertOk()->assertJsonPath('data.id_sesion', 1)->json('data');
        $inbox = '/api/certificados-documentos/list?bandeja=por_firmar&id_actividad=1';
        $this->getJson($inbox)->assertOk()->assertJsonPath('data.total', 0);
        self::assertStringContainsString('Primera', $review['mensaje']);
        self::assertStringStartsWith('%PDF-', base64_decode($review['vista_previa_pdf']['contenido_base64']));
        $this->assertDatabaseCount('certificado.certificado', 0);
        $confirm = ['accion' => 'confirmar_preparacion', 'modalidad_reconocimiento' => 'sesion', 'id_sesion' => 1,
            'token_revision' => $review['token_revision'], 'confirmar' => true];
        $prepared = $this->postJson($url, $confirm)->assertCreated()->assertJsonPath('data.enviado', false)
            ->assertJsonPath('data.estado_flujo', 'PREPARADO')->assertJsonPath('data.puede_enviar_gerencia', true)
            ->assertJsonPath('data.siguiente_accion_preparacion', 'enviar_gerencia')->json('data');
        $this->getJson($inbox)->assertOk()->assertJsonPath('data.total', 0);
        $this->postJson($url, $confirm)->assertOk()->assertJsonPath('data.creado', false);
        $this->postJson($url, $reviewInput)->assertOk()->assertJsonPath('data.recuperado', true)
            ->assertJsonPath('data.mensaje', $review['mensaje']);
        $this->assertDatabaseHas('certificado.reconocimiento', ['id_actividad_ponente' => $assignment, 'id_persona_trabajador' => null]);
        $this->deleteJson('/api/activity/1/sessions/1/ponentes', ['id_actividad_ponente' => $assignment])->assertConflict();
        $this->patchJson('/api/activity-speaker/'.$assignment, ['id_persona_ponente' => 2])->assertConflict();
        $id = $prepared['id_certificado_documento'];
        $detail = app(CertificateDocumentWorkflow::class)->detail($id, auth()->user());
        self::assertSame('ponente_sesion', $detail['origen']);
        self::assertSame(1, $detail['id_sesion']);
        $this->getJson('/api/rrhh/certificados')->assertOk()->assertJsonPath('data.total', 0);
        $send = $this->postJson('/api/certificados-documentos/'.$id.'/revisar-envio')->assertOk()->json('data');
        self::assertSame([], $send['documento']['firmantes']);
        $this->getJson($inbox)->assertOk()->assertJsonPath('data.total', 0);
        $this->postJson('/api/certificados-documentos/'.$id.'/enviar-gerencia', ['token_revision' => $send['token_revision'], 'confirmar' => true])
            ->assertOk()->assertJsonPath('data.enviado', true)->assertJsonPath('data.estado_flujo', 'PENDIENTE_FIRMA');
        $this->postJson('/api/certificados-documentos/'.$id.'/enviar-gerencia', ['token_revision' => $send['token_revision'], 'confirmar' => true])
            ->assertOk()->assertJsonPath('data.reintento', true);
        $this->getJson($inbox)->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.id_certificado_documento', $id)
            ->assertJsonPath('data.data.0.origen', 'ponente_sesion')->assertJsonPath('data.data.0.id_sesion', 1);
        $actor = auth()->user();
        $this->authorize(false, ['emision.ver']);
        auth()->user()->id_persona = 2;
        $this->getJson($inbox)->assertOk()->assertJsonPath('data.total', 1);
        DB::table('gestion.area')->insert(['id_area' => 2, 'nombre' => 'Otra área']);
        DB::table('gestion.persona')->insert(['id_persona' => 3, 'nombres' => 'Usuario otra área']);
        DB::table('gestion.persona_trabajador')->insert(['id_persona_trabajador' => 3, 'id_persona' => 3, 'id_area' => 2,
            'estado' => true, 'fecha_ingreso' => '2020-01-01']);
        auth()->user()->id_persona = 3;
        $this->getJson($inbox)->assertOk()->assertJsonPath('data.total', 0);
        $this->postJson('/api/certificados-documentos/'.$id.'/revisar-envio')->assertNotFound();
        $this->actingAs($actor);
        $base = '/api/certificados-documentos/'.$id.'/firma';
        $plan = ['modalidad' => 'SECUENCIAL', 'firmantes' => [['id_persona_trabajador' => 2, 'orden_firma' => 1]]];
        $revisionResponse = $this->postJson($base.'/revision-pdf', $plan);
        self::assertSame(200, $revisionResponse->status(), $revisionResponse->getContent());
        $revision = $revisionResponse->json('data');
        $assignedResponse = $this->postJson($base.'/asignar', $plan + ['confirmar' => true]);
        self::assertSame(200, $assignedResponse->status(), $assignedResponse->getContent());
        $this->getJson('/api/rrhh/certificados')->assertOk()->assertJsonPath('data.total', 0);
        $this->postJson($base.'/confirmar-pdf', ['token_revision' => $revision['token_revision'], 'confirmar' => true])->assertOk();
        $this->getJson('/api/rrhh/certificados')->assertOk()->assertJsonPath('data.otros_origenes.0.origen', 'ponente_sesion')
            ->assertJsonPath('data.otros_origenes.0.id_sesion', 1)->assertJsonPath('data.otros_origenes.0.sesion', 'Primera');
        $this->getJson('/api/certificados/list?id_sesion=1')->assertOk()->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.origen', 'ponente_sesion');
        $this->getJson('/api/certificados/list?id_sesion=2')->assertOk()->assertJsonPath('data.total', 0);
        $original = DB::table('certificado.certificado_documento')->where('id_certificado_documento', $id)->first();
        self::assertSame($review['hash_pdf'], $original->hash_certificado);
        self::assertSame(base64_decode($review['vista_previa_pdf']['contenido_base64']), Storage::disk('local')->get($original->ruta_archivo));
        $this->getJson('/api/certificados/verificar/'.$prepared['codigo_verificacion'])->assertOk()->assertJsonPath('data.integridad_verificada', true);
        $other = $this->postJson('/api/activity/1/sessions/2/ponentes', ['id_persona_ponente' => 1])->assertOk()->json('data.vinculo.id_actividad_ponente');
        $url2 = '/api/certificaciones/ponente_sesion/'.$other.'/preparar';
        $review2 = $this->postJson($url2, array_replace($reviewInput, ['id_sesion' => 2]))->assertOk()->json('data');
        $second = $this->postJson($url2, array_replace($confirm, ['id_sesion' => 2, 'token_revision' => $review2['token_revision']]))->assertCreated()->json('data');
        self::assertNotSame($prepared['codigo_verificacion'], $second['codigo_verificacion']);
        $this->assertDatabaseCount('certificado.certificado', 2);
        $this->assertDatabaseCount('certificado.participacion', 0);
        $this->assertDatabaseCount('certificado.asistencia', 0);
    }

    public function test_session_recognition_requires_real_assignment_finished_session_and_institutional_evidence(): void
    {
        Storage::fake('local');
        $this->seedSessionSpeakers();
        $input = ['accion' => 'revisar_documento', 'modalidad_reconocimiento' => 'sesion', 'id_sesion' => 1];
        $this->postJson('/api/certificaciones/ponente_sesion/1/preparar', $input)->assertConflict();
        $this->postJson('/api/activity/999/sessions/1/ponentes', ['id_persona_ponente' => 1])->assertConflict();
        $this->postJson('/api/activity/1/sessions/999/ponentes', ['id_persona_ponente' => 1])->assertNotFound();
        $this->postJson('/api/activity/1/sessions/1/ponentes', ['id_persona_ponente' => 999])->assertNotFound();
        $assignment = $this->postJson('/api/activity/1/sessions/1/ponentes', ['id_persona_ponente' => 1])->assertOk()->json('data.vinculo.id_actividad_ponente');
        $url = '/api/certificaciones/ponente_sesion/'.$assignment.'/preparar';
        $this->postJson($url, array_replace($input, ['id_sesion' => 2]))->assertConflict();
        DB::table('certificado.actividad_sesion')->where('id_actividad_sesion', 1)->update(['estado' => 'Programada']);
        $this->postJson($url, $input)->assertConflict();
        DB::table('certificado.actividad_sesion')->where('id_actividad_sesion', 1)->update(['estado' => 'Finalizada']);
        DB::table('certificado.solicitud_actividad')->update(['estado' => 'Rechazada']);
        $this->postJson($url, $input)->assertConflict();
        DB::table('certificado.solicitud_actividad')->update(['estado' => 'Aprobada']);
        DB::table('gestion.persona_trabajador')->where('id_persona', 1)->delete();
        $this->postJson($url, $input)->assertOk();
        $this->assertDatabaseCount('certificado.certificado', 0);
    }

    public function test_session_speaker_operations_enforce_authentication_permissions_and_validation(): void
    {
        $this->getJson('/api/activity/1/sessions/1/ponentes')->assertUnauthorized();
        $this->seedSessionSpeakers();
        $this->postJson('/api/activity/1/sessions/1/ponentes', [])->assertUnprocessable();
        $this->postJson('/api/activity/1/sessions/1/ponentes', ['id_persona_ponente' => 1, 'id_actividad' => 2])->assertUnprocessable();
        $this->authorize(false, ['actividades.ver', 'ponentes-firmantes.ver']);
        $this->postJson('/api/activity/1/sessions/1/ponentes', ['id_persona_ponente' => 1])->assertForbidden();
        $this->postJson('/api/certificaciones/ponente_sesion/1/preparar', ['accion' => 'revisar_documento',
            'modalidad_reconocimiento' => 'sesion', 'id_sesion' => 1])->assertForbidden();
        $this->assertDatabaseCount('certificado.actividad_ponente', 2);
        $this->assertDatabaseCount('certificado.certificado', 0);
    }

    public function test_session_recognition_template_contains_real_activity_session_date_and_qr(): void
    {
        $this->seedSessionSpeakers();
        $assignment = app(SessionSpeakers::class)->link(1, 1, 1);
        $origins = app(CertificateOrigins::class);
        $context = $origins->context('ponente_sesion', $assignment->id_actividad_ponente);
        $html = view('certificates.origins', ['context' => $context, 'customMessage' => $origins->message($context),
            'certificate' => null, 'signers' => [], 'code' => str_repeat('a', 30),
            'qrDataUri' => 'data:image/svg+xml;base64,', 'logoDataUri' => 'data:image/png;base64,'])->render();
        self::assertStringContainsString('Actividad', $html);
        self::assertStringContainsString('Primera', $html);
        self::assertStringContainsString('2025-11-01', $html);
        self::assertStringContainsString('ponente', $html);
        self::assertStringContainsString('QR de verificación documental', $html);
    }

    public function test_activity_recognition_uses_current_document_workflow_without_attendance(): void
    {
        Storage::fake('local');
        $this->seedActivitySpeaker();
        $this->getJson('/api/activity/1/ponentes-certificacion')->assertOk()
            ->assertJsonPath('data.ponentes.0.reconocimiento.puede_preparar', true)
            ->assertJsonPath('data.ponentes.0.reconocimiento.id_tipo_certificado', 23)
            ->assertJsonPath('data.ponentes.0.reconocimiento.id_persona', 1)
            ->assertJsonPath('data.ponentes.0.reconocimiento.id_sesion', null);
        $url = '/api/certificaciones/ponente_actividad/1/preparar';
        $input = ['accion' => 'revisar_documento', 'modalidad_reconocimiento' => 'actividad'];
        $review = $this->postJson($url, $input)->assertOk()->json('data');
        $draft = app(CertificateOrigins::class)->activitySpeakerContext(1);
        $html = view('certificates.origins', ['context' => $draft,
            'customMessage' => app(CertificateOrigins::class)->message($draft),
            'certificate' => null, 'signers' => [], 'code' => null, 'qrDataUri' => null, 'logoDataUri' => 'data:image/png;base64,'])->render();
        self::assertStringContainsString('como ponente en la actividad Actividad', $html);
        self::assertStringNotContainsString('sesión', $html);
        $confirm = ['accion' => 'confirmar_preparacion', 'modalidad_reconocimiento' => 'actividad',
            'token_revision' => $review['token_revision'], 'confirmar' => true];
        $prepared = $this->postJson($url, $confirm)->assertCreated()->json('data');
        $this->postJson($url, $confirm)->assertOk()->assertJsonPath('data.creado', false);
        $this->postJson($url, $input)->assertConflict();
        $this->assertDatabaseCount('certificado.certificado', 1);
        $this->assertDatabaseCount('certificado.reconocimiento', 2);
        $this->assertDatabaseCount('certificado.participacion', 0);
        $this->assertDatabaseCount('certificado.asistencia', 0);
        $this->assertDatabaseHas('certificado.certificado', ['id_persona' => 1, 'id_tipo_certificado' => 23,
            'id_participacion' => null, 'id_actividad_sesion' => null, 'estado' => 'PREPARADO']);
        $id = $prepared['id_certificado_documento'];
        $send = $this->postJson('/api/certificados-documentos/'.$id.'/revisar-envio')->assertOk()->json('data');
        $this->postJson('/api/certificados-documentos/'.$id.'/enviar-gerencia', ['token_revision' => $send['token_revision'], 'confirmar' => true])->assertOk();
        $base = '/api/certificados-documentos/'.$id.'/firma';
        $plan = ['modalidad' => 'SECUENCIAL', 'firmantes' => [['id_persona_trabajador' => 2, 'orden_firma' => 1]]];
        $revision = $this->postJson($base.'/revision-pdf', $plan)->assertOk()->json('data');
        $this->postJson($base.'/asignar', $plan + ['confirmar' => true])->assertOk();
        $this->getJson('/api/rrhh/certificados')->assertOk()->assertJsonPath('data.total', 0);
        $this->postJson($base.'/confirmar-pdf', ['token_revision' => $revision['token_revision'], 'confirmar' => true])->assertOk();
        $this->getJson('/api/rrhh/certificados')->assertOk()
            ->assertJsonPath('data.otros_origenes.0.origen', 'ponente_actividad')
            ->assertJsonPath('data.otros_origenes.0.id_actividad', 1)
            ->assertJsonPath('data.otros_origenes.0.actividad', 'Actividad')
            ->assertJsonPath('data.otros_origenes.0.id_sesion', null);
        $document = DB::table('certificado.certificado_documento')->first();
        self::assertSame($review['hash_pdf'], $document->hash_certificado);
        self::assertSame(base64_decode($review['vista_previa_pdf']['contenido_base64'], true), Storage::disk('local')->get($document->ruta_archivo));
        $this->getJson('/api/certificados/verificar/'.$prepared['codigo_verificacion'])->assertOk()->assertJsonPath('data.integridad_verificada', true);
        $recognitionId = DB::table('certificado.certificado')->first()->id_reconocimiento;
        $this->getJson('/api/certificados/list?id_reconocimiento='.$recognitionId)->assertOk()
            ->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.id_actividad', 1)
            ->assertJsonPath('data.data.0.modalidad_reconocimiento', 'actividad');
        $this->getJson('/api/certificados/list?id_actividad=1')->assertOk()->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.origen', 'ponente_actividad');
        $this->getJson('/api/certificados/list?id_actividad=999')->assertOk()->assertJsonPath('data.total', 0);
        $this->getJson('/api/rrhh/certificados?id_actividad=1')->assertOk()->assertJsonPath('data.total', 1);
        $this->getJson('/api/rrhh/certificados?id_actividad=999')->assertOk()->assertJsonPath('data.total', 0);
    }

    public function test_session_recognition_stays_blocked_without_a_persistent_intervention(): void
    {
        Storage::fake('local');
        $this->seedActivitySpeaker();

        $this->postJson('/api/certificaciones/ponente_actividad/1/preparar', ['accion' => 'revisar_documento',
            'modalidad_reconocimiento' => 'sesion', 'id_sesion' => 1])->assertConflict();
        $this->getJson('/api/activity-session/1/ponentes-certificacion')->assertOk()
            ->assertJsonPath('data.puede_preparar', false)
            ->assertJsonPath('data.ponentes_actividad.0.reconocimiento_por_actividad.puede_preparar', true);
        $this->assertDatabaseCount('certificado.certificado', 0);
        $this->assertDatabaseCount('certificado.reconocimiento', 1);
    }

    public function test_activity_recognition_requires_explicit_modality_and_real_assignment(): void
    {
        Storage::fake('local');
        $this->seedActivitySpeaker();

        $this->postJson('/api/certificaciones/ponente_actividad/1/preparar', ['accion' => 'revisar_documento'])->assertUnprocessable();
        $this->postJson('/api/certificaciones/ponente_actividad/999/preparar', ['accion' => 'revisar_documento', 'modalidad_reconocimiento' => 'actividad'])->assertNotFound();
        DB::table('certificado.actividad_ponente')->where('id_actividad_ponente', 1)->delete();
        $this->postJson('/api/certificaciones/ponente_actividad/1/preparar', ['accion' => 'revisar_documento', 'modalidad_reconocimiento' => 'actividad'])->assertNotFound();
        $this->assertDatabaseCount('certificado.certificado', 0);
    }

    public function test_activity_recognition_checks_authentication_permissions_and_scope(): void
    {
        $payload = ['accion' => 'revisar_documento', 'modalidad_reconocimiento' => 'actividad'];
        $url = '/api/certificaciones/ponente_actividad/1/preparar';
        $this->postJson($url, $payload)->assertUnauthorized();
        $this->getJson('/api/activity/1/ponentes-certificacion')->assertUnauthorized();
        $this->seedActivitySpeaker();
        $this->authorize(false);
        $this->postJson($url, $payload)->assertForbidden();
        $this->getJson('/api/activity/1/ponentes-certificacion')->assertForbidden();
        $this->authorize(false, ['emision.ver', 'emision.crear', 'actividades.ver', 'ponentes-firmantes.ver', 'reconocimientos.crear']);
        DB::table('gestion.area')->insert(['id_area' => 2, 'nombre' => 'Otra área']);
        DB::table('gestion.persona_trabajador')->where('id_persona', 1)->update(['id_area' => 2]);
        $this->postJson($url, $payload)->assertForbidden();
        $this->getJson('/api/activity/1/ponentes-certificacion')->assertForbidden();
        $this->assertDatabaseCount('certificado.certificado', 0);
    }

    #[TestWith(['assignment'])]
    #[TestWith(['approval'])]
    #[TestWith(['activity'])]
    public function test_changed_activity_evidence_invalidates_preview(string $changed): void
    {
        Storage::fake('local');
        $this->seedActivitySpeaker();
        $url = '/api/certificaciones/ponente_actividad/1/preparar';
        $review = $this->postJson($url, ['accion' => 'revisar_documento', 'modalidad_reconocimiento' => 'actividad'])->assertOk()->json('data');
        match ($changed) {
            'assignment' => DB::table('certificado.actividad_ponente')->where('id_actividad_ponente', 1)->delete(),
            'approval' => DB::table('certificado.solicitud_actividad')->update(['estado' => 'Rechazada']),
            'activity' => DB::table('certificado.actividad')->update(['nombre' => 'Otro nombre']),
        };

        $response = $this->postJson($url, ['accion' => 'confirmar_preparacion', 'modalidad_reconocimiento' => 'actividad',
            'confirmar' => true, 'token_revision' => $review['token_revision']]);
        $response->assertStatus($changed === 'assignment' ? 404 : 409);
        $this->assertDatabaseCount('certificado.certificado', 0);
        $this->assertDatabaseCount('certificado.reconocimiento', 1);
    }

    public function test_activity_recognition_retries_across_assignments_cannot_duplicate_the_same_person(): void
    {
        Storage::fake('local');
        $this->seedActivitySpeaker();
        $input = ['accion' => 'revisar_documento', 'modalidad_reconocimiento' => 'actividad'];
        $url = '/api/certificaciones/ponente_actividad/1/preparar';
        $first = $this->postJson($url, $input)->assertOk()->json('data');
        $concurrent = $this->postJson($url, $input)->assertOk()->json('data');
        $this->postJson($url, ['accion' => 'confirmar_preparacion', 'modalidad_reconocimiento' => 'actividad',
            'confirmar' => true, 'token_revision' => $first['token_revision']])->assertCreated();
        $this->postJson($url, ['accion' => 'confirmar_preparacion', 'modalidad_reconocimiento' => 'actividad',
            'confirmar' => true, 'token_revision' => $concurrent['token_revision']])->assertConflict();
        DB::table('gestion.persona_ponente')->insert(['id_persona_ponente' => 3, 'id_persona' => 1]);
        DB::table('certificado.actividad_ponente')->insert(['id_actividad_ponente' => 3, 'id_actividad' => 1, 'id_persona_ponente' => 3]);
        $this->postJson('/api/certificaciones/ponente_actividad/3/preparar', $input)->assertConflict();
        DB::table('certificado.actividad')->insert(['id_actividad' => 2, 'id_area' => 1, 'nombre' => 'Otra actividad',
            'estado' => 'Cerrada', 'fecha_inicio' => '2025-11-02', 'fecha_fin' => '2025-11-02']);
        DB::table('certificado.solicitud_actividad')->insert(['id_solicitud_actividad' => 2, 'id_actividad' => 2, 'estado' => 'Aprobada']);
        DB::table('certificado.actividad_ponente')->insert(['id_actividad_ponente' => 4, 'id_actividad' => 2, 'id_persona_ponente' => 1]);
        $other = $this->postJson('/api/certificaciones/ponente_actividad/4/preparar', $input)->assertOk()->json('data');
        $this->postJson('/api/certificaciones/ponente_actividad/4/preparar', ['accion' => 'confirmar_preparacion',
            'modalidad_reconocimiento' => 'actividad', 'confirmar' => true, 'token_revision' => $other['token_revision']])->assertCreated()
            ->assertJsonPath('data.id_actividad', 2)->assertJsonPath('data.id_sesion', null);
        $this->assertDatabaseCount('certificado.certificado', 2);
        $this->assertDatabaseCount('certificado.reconocimiento', 3);
        $this->assertDatabaseCount('certificado.participacion', 0);
    }

    public function test_session_default_text_can_be_reviewed_reused_and_confirmed_without_manual_editing(): void
    {
        ['url' => $url, 'input' => $input] = $this->editableSessionRecognition();
        $input = array_diff_key($input, array_flip(['mensaje', 'motivo']));
        $review = $this->postJson($url, $input)->assertOk()->json('data');
        self::assertStringContainsString('Actividad', $review['mensaje']);
        self::assertStringContainsString('Primera', $review['mensaje']);
        self::assertStringContainsString('2025-11-01', $review['mensaje']);
        $files = Storage::disk('local')->allFiles('certificados/revisiones');
        $this->postJson($url, $input)->assertOk()->assertJsonPath('data.token_revision', $review['token_revision'])
            ->assertJsonPath('data.hash_pdf', $review['hash_pdf'])->assertJsonPath('data.revision_reutilizada', true);
        $this->postJson($url, [...$input, 'mensaje' => $review['mensaje'], 'motivo' => $review['motivo']])->assertOk()
            ->assertJsonPath('data.token_revision', $review['token_revision']);
        self::assertSame($files, Storage::disk('local')->allFiles('certificados/revisiones'));
        $this->assertDatabaseCount('certificado.certificado', 0);
        $confirm = [...$input, 'accion' => 'confirmar_preparacion', 'token_revision' => $review['token_revision'], 'confirmar' => true];
        $this->postJson($url, $confirm)->assertCreated()->assertJsonPath('data.mensaje', $review['mensaje']);
        $this->postJson($url, $confirm)->assertOk()->assertJsonPath('data.creado', false);
        $this->postJson($url, $input)->assertOk()->assertJsonPath('data.recuperado', true)->assertJsonPath('data.hash_pdf', $review['hash_pdf']);
        $this->assertDatabaseCount('certificado.certificado', 1);
    }

    public function test_session_consultation_recovers_without_pdf_generation_base64_or_file_writes(): void
    {
        ['url' => $url, 'input' => $input] = $this->editableSessionRecognition();
        $consult = ['accion' => 'consultar_preparacion', 'modalidad_reconocimiento' => 'sesion', 'id_sesion' => $input['id_sesion']];
        $this->postJson($url, $consult)->assertOk()->assertJsonPath('data.recuperado', false);
        self::assertSame([], Storage::disk('local')->allFiles());
        $review = $this->postJson($url, $input)->assertOk()->json('data');
        $prepared = $this->postJson($url, [...$input, 'accion' => 'confirmar_preparacion',
            'confirmar' => true, 'token_revision' => $review['token_revision']])->assertCreated()->json('data');
        $files = collect(Storage::disk('local')->allFiles())->mapWithKeys(fn (string $path): array => [$path => hash_file('sha256', Storage::disk('local')->path($path))])->all();
        $generator = $this->partialMock(CertificateDocumentGenerator::class);
        $generator->shouldNotReceive('reviewPdf');
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->postJson($url, $consult)->assertOk()->assertJsonPath('data.recuperado', true)
                ->assertJsonPath('data.id_certificado_documento', $prepared['id_certificado_documento'])
                ->assertJsonPath('data.hash_pdf', $review['hash_pdf'])->assertJsonPath('data.mensaje', $input['mensaje'])
                ->assertJsonPath('data.texto_editable', false)->assertJsonPath('data.puede_enviar_gerencia', true)
                ->assertJsonMissingPath('data.vista_previa_pdf')->assertJsonMissingPath('data.ruta_archivo');
        }
        self::assertSame([], array_values(array_filter($queries, fn (string $sql): bool => preg_match('/^\s*(insert|update|delete)/i', $sql) === 1)));
        self::assertSame($files, collect(Storage::disk('local')->allFiles())->mapWithKeys(fn (string $path): array => [$path => hash_file('sha256', Storage::disk('local')->path($path))])->all());
        $this->get($prepared['url_documento'])->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->getJson($url.'?'.http_build_query($consult))->assertOk()->assertJsonPath('data.recuperado', true)
            ->assertJsonMissingPath('data.vista_previa_pdf');
        $this->getJson($url.'?'.http_build_query($input))->assertUnprocessable();
        $this->assertDatabaseCount('certificado.certificado', 1);
        $this->assertDatabaseCount('certificado.certificado_documento', 1);
    }

    public function test_session_consultation_rejects_another_session_and_mutation_fields(): void
    {
        ['url' => $url, 'input' => $input] = $this->editableSessionRecognition();
        $consult = ['accion' => 'consultar_preparacion', 'modalidad_reconocimiento' => 'sesion', 'id_sesion' => $input['id_sesion']];
        $this->postJson($url, [...$consult, 'id_sesion' => 2])->assertConflict();
        $this->postJson($url, [...$consult, 'token_revision' => str_repeat('a', 64)])->assertUnprocessable();
        $this->postJson($url, [...$consult, 'mensaje' => 'Cambio', 'motivo' => 'Cambio'])->assertUnprocessable();
        self::assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_session_speaker_listing_batches_document_states_without_rendering_per_speaker(): void
    {
        ['input' => $input] = $this->editableSessionRecognition();
        $this->partialMock(CertificateDocumentGenerator::class)->shouldNotReceive('reviewPdf');
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $url = '/api/activity-session/'.$input['id_sesion'].'/ponentes-certificacion';
        $this->getJson($url)->assertOk()->assertJsonCount(1, 'data.ponentes_sesion');
        $oneSpeakerQueryCount = count($queries);
        $speakerId = DB::table('gestion.persona_ponente')->whereNotIn('id_persona_ponente',
            DB::table('certificado.actividad_ponente')->where('id_actividad_sesion', $input['id_sesion'])->select('id_persona_ponente'))
            ->value('id_persona_ponente');
        $activityId = DB::table('certificado.actividad_sesion')->where('id_actividad_sesion', $input['id_sesion'])->value('id_actividad');
        $this->postJson('/api/activity/'.$activityId.'/sessions/'.$input['id_sesion'].'/ponentes', ['id_persona_ponente' => $speakerId])->assertOk();
        $queries = [];
        $this->getJson($url)->assertOk()->assertJsonCount(2, 'data.ponentes_sesion')
            ->assertJsonPath('data.ponentes_sesion.0.reconocimiento.puede_preparar', true)
            ->assertJsonPath('data.ponentes_sesion.1.reconocimiento.puede_preparar', true);
        self::assertLessThanOrEqual($oneSpeakerQueryCount, count($queries));
        self::assertSame([], Storage::disk('local')->allFiles());
    }

    #[TestWith(['PENDIENTE_FIRMA'])]
    #[TestWith(['FIRMADO'])]
    #[TestWith(['EMITIDO'])]
    #[TestWith(['ANULADO'])]
    public function test_session_consultation_preserves_later_document_states_without_preparing_again(string $state): void
    {
        ['url' => $url, 'input' => $input] = $this->editableSessionRecognition();
        $review = $this->postJson($url, $input)->assertOk()->json('data');
        $prepared = $this->postJson($url, [...$input, 'accion' => 'confirmar_preparacion', 'confirmar' => true,
            'token_revision' => $review['token_revision']])->assertCreated()->json('data');
        DB::table('certificado.certificado')->where('id_certificado', $prepared['id_certificado'])->update(['estado' => $state]);
        $this->partialMock(CertificateDocumentGenerator::class)->shouldNotReceive('reviewPdf');
        $response = $this->getJson($url.'?'.http_build_query(['accion' => 'consultar_preparacion',
            'modalidad_reconocimiento' => 'sesion', 'id_sesion' => $input['id_sesion']]));
        if ($state === 'ANULADO') {
            $response->assertConflict();
        } else {
            $response->assertOk()->assertJsonPath('data.estado_flujo', $state)->assertJsonPath('data.puede_enviar_gerencia', false)
                ->assertJsonPath('data.hash_pdf', $review['hash_pdf'])->assertJsonMissingPath('data.vista_previa_pdf');
            $this->get($prepared['url_documento'])->assertOk()->assertHeader('Content-Type', 'application/pdf');
        }
        $this->assertDatabaseCount('certificado.certificado', 1);
        $this->assertDatabaseCount('certificado.certificado_documento', 1);
        self::assertSame($state, DB::table('certificado.certificado')->where('id_certificado', $prepared['id_certificado'])->value('estado'));
    }

    public function test_session_confirmation_adopts_only_the_exact_reviewed_pdf_without_overwriting(): void
    {
        ['url' => $url, 'input' => $input] = $this->editableSessionRecognition();
        $review = $this->postJson($url, $input)->assertOk()->json('data');
        $manifest = json_decode(Crypt::decryptString(Storage::disk('local')->get('certificados/revisiones/'.hash('sha256', $review['token_revision']).'.manifest')), true);
        $target = 'certificados/preparados/'.$manifest['code'].'.pdf';
        $bytes = base64_decode($review['vista_previa_pdf']['contenido_base64'], true);
        Storage::disk('local')->put($target, 'historical evidence');
        $confirm = [...$input, 'accion' => 'confirmar_preparacion', 'token_revision' => $review['token_revision'], 'confirmar' => true];
        $this->postJson($url, $confirm)->assertConflict();
        self::assertSame('historical evidence', Storage::disk('local')->get($target));
        $this->assertDatabaseCount('certificado.certificado', 0);
        Storage::disk('local')->put($target, $bytes);
        $this->postJson($url, $confirm)->assertCreated()->assertJsonPath('data.hash_pdf', $review['hash_pdf']);
        self::assertSame($bytes, Storage::disk('local')->get($target));
        $this->postJson($url, $confirm)->assertOk()->assertJsonPath('data.creado', false);
        $this->assertDatabaseCount('certificado.certificado_documento', 1);
    }

    public function test_session_concurrent_review_is_rejected_without_creating_another_pdf(): void
    {
        ['url' => $url, 'input' => $input] = $this->editableSessionRecognition();
        $review = $this->postJson($url, $input)->assertOk()->json('data');
        $files = Storage::disk('local')->allFiles('certificados/revisiones');
        $lockPath = collect($files)->first(fn (string $path): bool => str_ends_with($path, '.lock'));
        $lock = fopen(Storage::disk('local')->path($lockPath), 'c+b');
        self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        try {
            $this->postJson($url, $input)->assertConflict()->assertJsonPath('message', 'Ya hay una revisión de este reconocimiento en curso.');
            self::assertSame($files, Storage::disk('local')->allFiles('certificados/revisiones'));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        $this->postJson($url, $input)->assertOk()->assertJsonPath('data.token_revision', $review['token_revision']);
    }

    public function test_session_failed_confirmation_keeps_an_adopted_pdf_and_rolls_back_records(): void
    {
        ['url' => $url, 'input' => $input] = $this->editableSessionRecognition();
        $recognitionsBefore = DB::table('certificado.reconocimiento')->get()->map(fn (object $row): array => (array) $row)->all();
        $review = $this->postJson($url, $input)->assertOk()->json('data');
        $manifest = json_decode(Crypt::decryptString(Storage::disk('local')->get('certificados/revisiones/'.hash('sha256', $review['token_revision']).'.manifest')), true);
        $target = 'certificados/preparados/'.$manifest['code'].'.pdf';
        $bytes = base64_decode($review['vista_previa_pdf']['contenido_base64'], true);
        Storage::disk('local')->put($target, $bytes);
        DB::statement("CREATE TRIGGER certificado.reject_recognition_document BEFORE INSERT ON certificado_documento BEGIN SELECT RAISE(ABORT, 'isolated document failure'); END");
        $this->postJson($url, [...$input, 'accion' => 'confirmar_preparacion', 'token_revision' => $review['token_revision'], 'confirmar' => true])->assertConflict();
        self::assertSame($bytes, Storage::disk('local')->get($target));
        $this->assertDatabaseCount('certificado.certificado', 0);
        self::assertSame($recognitionsBefore, DB::table('certificado.reconocimiento')->get()->map(fn (object $row): array => (array) $row)->all());
        $this->assertDatabaseCount('certificado.certificado_documento', 0);
    }

    public function test_activity_recognition_does_not_create_a_worker_for_an_external_speaker(): void
    {
        Storage::fake('local');
        $this->seedActivitySpeaker();
        DB::table('gestion.persona_trabajador')->where('id_persona', 1)->delete();

        $this->postJson('/api/certificaciones/ponente_actividad/1/preparar', ['accion' => 'revisar_documento',
            'modalidad_reconocimiento' => 'actividad'])->assertConflict();
        $this->assertDatabaseCount('gestion.persona_trabajador', 1);
        $this->assertDatabaseCount('certificado.certificado', 0);
    }
}
