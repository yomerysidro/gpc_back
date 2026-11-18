<?php

namespace Tests\Feature;

use App\Models\Role\Role;
use App\Models\User\User;
use App\Services\CertificationReadiness;
use BaconQrCode\Writer;
use Dompdf\Dompdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class CertificateDocumentGenerationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null, 'app.url' => 'http://localhost',
            'app.frontend_url' => 'https://municipalidad.test', 'certification.documents.verification_base_url' => null]);
        DB::purge('sqlite');
        DB::statement("ATTACH DATABASE ':memory:' AS certificado");
        DB::statement("ATTACH DATABASE ':memory:' AS gestion");
        foreach ([
            'gestion.persona' => 'id_persona INTEGER PRIMARY KEY, nombres TEXT, apellido_paterno TEXT, apellido_materno TEXT',
            'certificado.actividad' => 'id_actividad INTEGER PRIMARY KEY, id_area INTEGER, nombre TEXT',
            'certificado.participacion' => 'id_participacion INTEGER PRIMARY KEY, id_persona INTEGER, id_actividad INTEGER',
            'certificado.certificado' => 'id_certificado INTEGER PRIMARY KEY, id_persona INTEGER, id_tipo_certificado INTEGER, id_participacion INTEGER, id_practica INTEGER, id_reconocimiento INTEGER, fecha_emision TEXT, estado TEXT',
            'certificado.tipo_certificado' => 'id_tipo_certificado INTEGER PRIMARY KEY, nombre_tipo TEXT, estado BOOLEAN',
            'certificado.certificado_documento' => 'id_certificado_documento INTEGER PRIMARY KEY, id_certificado INTEGER, codigo_verificacion TEXT UNIQUE, hash_certificado TEXT UNIQUE, ruta_archivo TEXT, fecha_generacion TEXT, estado TEXT',
        ] as $table => $columns) {
            DB::statement("CREATE TABLE {$table} ({$columns})");
        }
        DB::table('gestion.persona')->insert(['id_persona' => 1, 'nombres' => 'Persona', 'apellido_paterno' => 'Prueba']);
        DB::table('certificado.actividad')->insert(['id_actividad' => 1, 'id_area' => 1, 'nombre' => 'Actividad aislada']);
        DB::table('certificado.participacion')->insert(['id_participacion' => 1, 'id_persona' => 1, 'id_actividad' => 1]);
        DB::table('certificado.tipo_certificado')->insert(['id_tipo_certificado' => 1, 'nombre_tipo' => 'Capacitación', 'estado' => true]);
        DB::table('certificado.certificado')->insert(['id_certificado' => 1, 'id_persona' => 1, 'id_tipo_certificado' => 1, 'id_participacion' => 1, 'fecha_emision' => '2026-01-01', 'estado' => 'Registro de prueba']);
        Storage::fake('local');
    }

    private function authorize(bool $canCreate = true): void
    {
        $user = new class extends User
        {
            public bool $canCreate = true;

            public function isSystemAdministrator(): bool
            {
                return true;
            }

            public function permissionKeys(): array
            {
                return $this->canCreate ? ['emision.ver', 'emision.crear', 'emision.editar'] : ['emision.ver'];
            }
        };
        $user->canCreate = $canCreate;
        $user->forceFill(['id_usuario' => 1, 'id_persona' => 1, 'estado' => true]);
        $user->setRelation('roles', (new Role)->newCollection());
        $this->actingAs($user);
    }

    private function isolateDocumentBehavior(): void
    {
        config(['certification.development_session_type_ids' => [1]]);
        $this->mock(CertificationReadiness::class)->shouldReceive('assertDevelopmentClosureEnvironment')->andReturnNull();
    }

    private function existingDocument(string $state = 'Vigente'): string
    {
        $code = str_repeat('a', 30);
        $path = 'certificados/desarrollo/'.$code.'.pdf';
        $fixture = '%PDF-1.4 isolated pre-existing file fixture';
        Storage::disk('local')->put($path, $fixture);
        DB::table('certificado.certificado_documento')->insert([
            'id_certificado_documento' => 1, 'id_certificado' => 1, 'codigo_verificacion' => $code,
            'hash_certificado' => hash('sha256', $fixture), 'ruta_archivo' => $path,
            'fecha_generacion' => '2026-01-01 08:00:00', 'estado' => $state,
        ]);

        return $code;
    }

    public function test_generation_and_download_require_authentication(): void
    {
        $this->postJson('/api/certificados/1/generar-documento')->assertUnauthorized();
        $this->getJson('/api/certificados-documentos/1/archivo')->assertUnauthorized();
    }

    public function test_generation_requires_effective_create_permission(): void
    {
        $this->authorize(false);
        $this->postJson('/api/certificados/1/generar-documento')->assertForbidden();
        $this->assertDatabaseCount('certificado.certificado_documento', 0);
    }

    public function test_persistent_generation_rejects_overflow_before_saving_an_artifact(): void
    {
        $this->authorize();
        $this->isolateDocumentBehavior();
        DB::table('certificado.actividad')->where('id_actividad', 1)->update(['nombre' => str_repeat('Actividad extensa ', 500)]);

        $this->postJson('/api/certificados/1/generar-documento')->assertUnprocessable();

        $this->assertDatabaseCount('certificado.certificado_documento', 0);
        self::assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_generation_does_not_write_when_isolation_is_not_verified(): void
    {
        $this->authorize();
        $this->postJson('/api/certificados/1/generar-documento')->assertConflict();
        $this->assertDatabaseCount('certificado.certificado_documento', 0);
    }

    public function test_client_cannot_supply_code_hash_path_or_actor(): void
    {
        $this->authorize();
        $this->isolateDocumentBehavior();
        $this->postJson('/api/certificados/1/generar-documento', ['codigo_verificacion' => 'forged', 'id_usuario' => 8])->assertUnprocessable();
        $this->assertDatabaseCount('certificado.certificado_documento', 0);
    }

    public function test_retries_retrieve_the_same_existing_document_without_creating_another(): void
    {
        $this->authorize();
        $this->isolateDocumentBehavior();
        $code = $this->existingDocument();
        foreach ([1, 2] as $attempt) {
            $this->postJson('/api/certificados/1/generar-documento')->assertOk()
                ->assertJsonPath('data.codigo_verificacion', $code)->assertJsonPath('data.creado', false)
                ->assertJsonPath('data.oficial', false)->assertJsonPath('data.puede_emitir', false);
        }
        $this->assertDatabaseCount('certificado.certificado_documento', 1);
        $this->assertDatabaseCount('certificado.certificado', 1);
    }

    public function test_modified_file_and_annulled_document_cannot_be_reused_or_downloaded(): void
    {
        $this->authorize();
        $this->isolateDocumentBehavior();
        $code = $this->existingDocument();
        Storage::disk('local')->put('certificados/desarrollo/'.$code.'.pdf', 'altered');
        $this->postJson('/api/certificados/1/generar-documento')->assertConflict();
        $this->getJson('/api/certificados-documentos/1/archivo')->assertConflict();
        DB::table('certificado.certificado_documento')->where('id_certificado_documento', 1)->update(['estado' => 'Anulado']);
        $this->getJson('/api/certificados-documentos/1/archivo')->assertConflict();
        $this->assertDatabaseCount('certificado.certificado_documento', 1);
    }

    public function test_verification_returns_only_document_metadata_and_does_not_assert_official_validity(): void
    {
        $this->isolateDocumentBehavior();
        $code = $this->existingDocument();
        $this->getJson('/api/certificados/verificar/'.$code)->assertOk()
            ->assertJsonPath('data.registro_encontrado', true)
            ->assertJsonPath('data.integridad_verificada', true)
            ->assertJsonPath('data.puede_ver_documento', false)
            ->assertJsonPath('data.emision_oficial_verificada', false)
            ->assertJsonPath('data.firma_verificada', false)
            ->assertJsonMissingPath('data.ruta_archivo')->assertJsonMissingPath('data.id_persona');
        $this->getJson('/api/certificados/verificar/'.str_repeat('b', 30))->assertNotFound();
    }

    #[TestWith(['missing'])]
    #[TestWith(['corrupt'])]
    public function test_public_verification_detects_unavailable_or_corrupt_files_and_never_serves_them(string $condition): void
    {
        $code = $this->existingDocument();
        DB::table('certificado.certificado')->where('id_certificado', 1)->update(['estado' => 'Vigente']);
        if ($condition === 'missing') {
            Storage::disk('local')->delete('certificados/desarrollo/'.$code.'.pdf');
        } else {
            Storage::disk('local')->put('certificados/desarrollo/'.$code.'.pdf', '%PDF-1.4 modified');
        }
        $this->getJson('/api/certificados/verificar/'.$code)->assertOk()
            ->assertJsonPath('data.registro_encontrado', true)->assertJsonPath('data.integridad_verificada', false)
            ->assertJsonPath('data.vigente', false)->assertJsonPath('data.estado_certificado', 'Vigente')
            ->assertJsonPath('data.puede_ver_documento', false)->assertJsonPath('data.url_documento', null)
            ->assertJsonPath('data.emision_oficial_verificada', false)->assertJsonPath('data.firma_verificada', false);
        $this->getJson('/api/certificados/verificar/'.$code.'?archivo=1')->assertForbidden();
    }

    public function test_public_verification_returns_the_stored_pdf_without_login_or_development_mode(): void
    {
        $code = $this->existingDocument();
        $path = 'certificados/archivo/'.$code.'.pdf';
        Storage::disk('local')->move('certificados/desarrollo/'.$code.'.pdf', $path);
        DB::table('certificado.certificado_documento')->where('id_certificado_documento', 1)->update(['ruta_archivo' => $path]);
        DB::table('certificado.certificado')->where('id_certificado', 1)->update(['estado' => 'Vigente']);
        $url = '/api/certificados/verificar/'.$code;
        $this->getJson($url)->assertOk()->assertJsonPath('data.puede_ver_documento', false);
        $this->getJson($url.'?archivo=1')->assertForbidden();
        config(['certification.public_lookup.pdf_policy_reference' => 'Autorización de prueba',
            'certification.public_lookup.pdf_codes' => [$code]]);
        $this->getJson('/api/certificados/verificar/'.$code)->assertOk()
            ->assertJsonPath('data.integridad_verificada', true)->assertJsonPath('data.puede_ver_documento', true)
            ->assertJsonPath('data.url_documento', '/api/certificados/verificar/'.$code.'?archivo=1')
            ->assertJsonPath('data.firma_verificada', false)
            ->assertJsonMissingPath('data.ruta_archivo')->assertJsonMissingPath('data.id_persona')
            ->assertHeader('Cache-Control', 'no-store, private');
        $response = $this->get('/api/certificados/verificar/'.$code.'?archivo=1')->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Disposition', 'inline; filename=certificado-'.$code.'.pdf');
        self::assertSame(Storage::disk('local')->get($path), file_get_contents($response->baseResponse->getFile()->getPathname()));
        Storage::disk('local')->delete($path);
        $this->getJson($url)->assertOk()->assertJsonPath('data.vigente', false)
            ->assertJsonPath('data.motivos.0', 'Documento no disponible.');
        $this->getJson($url.'?archivo=1')->assertNotFound()->assertJsonPath('message', 'Documento no disponible.');
    }

    #[TestWith(['Vigente', 'Anulado'])]
    #[TestWith(['Anulado', 'Vigente'])]
    #[TestWith(['Revocado', 'Vigente'])]
    public function test_public_verification_preserves_revoked_states_and_rejects_the_pdf(string $certificateState, string $documentState): void
    {
        $code = $this->existingDocument($documentState);
        DB::table('certificado.certificado')->where('id_certificado', 1)->update(['estado' => $certificateState]);
        $this->getJson('/api/certificados/verificar/'.$code)->assertOk()
            ->assertJsonPath('data.estado_certificado', $certificateState)->assertJsonPath('data.estado_documento', $documentState)
            ->assertJsonPath('data.vigente', false)->assertJsonPath('data.puede_ver_documento', false);
        $this->getJson('/api/certificados/verificar/'.$code.'?archivo=1')->assertForbidden();
    }

    public function test_an_orphan_document_code_cannot_verify_without_its_certificate(): void
    {
        $code = $this->existingDocument();
        DB::table('certificado.certificado')->where('id_certificado', 1)->delete();
        $this->getJson('/api/certificados/verificar/'.$code)->assertNotFound()->assertJsonPath('message', 'Certificado no encontrado.');
    }

    #[TestWith(['PREPARADO'])]
    #[TestWith(['PENDIENTE_FIRMA'])]
    #[TestWith(['FIRMADO'])]
    public function test_public_qr_rejects_unissued_certificate_for_any_storage_path(string $state): void
    {
        $code = $this->existingDocument();
        DB::table('certificado.certificado_documento')->update(['ruta_archivo' => 'certificados/archivo.pdf']);
        Storage::disk('local')->put('certificados/archivo.pdf', Storage::disk('local')->get('certificados/desarrollo/'.$code.'.pdf'));
        DB::table('certificado.certificado')->where('id_certificado', 1)->update(['estado' => $state, 'fecha_emision' => null]);
        config(['certification.public_lookup.pdf_policy_reference' => 'Autorización de prueba',
            'certification.public_lookup.pdf_codes' => [$code]]);

        $this->getJson('/api/certificados/verificar/'.$code)->assertOk()
            ->assertJsonPath('data.estado_certificado', $state)->assertJsonPath('data.integridad_verificada', true)
            ->assertJsonPath('data.emision_oficial_verificada', false)->assertJsonPath('data.firma_verificada', false)
            ->assertJsonPath('data.puede_ver_documento', false);
        $this->getJson('/api/certificados/verificar/'.$code.'?archivo=1')->assertForbidden();
    }

    #[TestWith(['http://localhost:4200', 'staging'])]
    #[TestWith(['https://localhost', 'staging'])]
    #[TestWith(['https://127.0.0.1', 'staging'])]
    #[TestWith(['https://user:password@municipalidad.test/verificar', 'testing'])]
    #[TestWith(['https://municipalidad.test/verificar?code=forged', 'testing'])]
    public function test_generation_rejects_invalid_deployment_urls_without_registering_a_file(string $url, string $environment): void
    {
        $this->authorize();
        $this->isolateDocumentBehavior();
        $this->app['env'] = $environment;
        config([$environment === 'staging' ? 'app.frontend_url' : 'certification.documents.verification_base_url' => $url]);
        $this->postJson('/api/certificados/1/generar-documento')->assertServiceUnavailable();
        $this->assertDatabaseCount('certificado.certificado_documento', 0);
        self::assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_generated_document_code_cannot_be_changed_through_generic_crud(): void
    {
        $this->authorize();
        $this->existingDocument();
        $this->patchJson('/api/certificados-documentos/update/1', ['codigo_verificacion' => str_repeat('b', 30)])->assertConflict();
        $this->assertDatabaseHas('certificado.certificado_documento', ['id_certificado_documento' => 1, 'codigo_verificacion' => str_repeat('a', 30)]);
    }

    #[TestWith(['inactive'])]
    #[TestWith(['incompatible'])]
    public function test_generation_requires_a_current_type_compatible_with_participation(string $condition): void
    {
        $this->authorize();
        $this->isolateDocumentBehavior();
        if ($condition === 'inactive') {
            DB::table('certificado.tipo_certificado')->where('id_tipo_certificado', 1)->update(['estado' => false]);
        } else {
            config(['certification.development_session_type_ids' => []]);
        }
        $this->postJson('/api/certificados/1/generar-documento')->assertConflict();
        $this->assertDatabaseCount('certificado.certificado_documento', 0);
        self::assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_failed_document_registration_rolls_back_and_removes_the_real_generated_pdf(): void
    {
        $this->authorize();
        $this->isolateDocumentBehavior();
        DB::statement("CREATE TRIGGER certificado.reject_document BEFORE INSERT ON certificado_documento
            BEGIN SELECT RAISE(ABORT, 'Isolated persistence failure'); END");
        $this->postJson('/api/certificados/1/generar-documento')->assertConflict();
        $this->assertDatabaseCount('certificado.certificado_documento', 0);
        $this->assertDatabaseCount('certificado.certificado', 1);
        $this->assertDatabaseHas('certificado.certificado', ['id_certificado' => 1, 'estado' => 'Registro de prueba']);
        self::assertSame([], Storage::disk('local')->allFiles());
    }

    #[TestWith(['http://localhost:4200'])]
    #[TestWith(['https://municipalidad.test'])]
    #[TestWith(['http://localhost:4200', 'production'])]
    public function test_rendering_real_pdf_with_qr_and_persistence_when_dependencies_are_available(string $frontendUrl, string $environment = 'testing'): void
    {
        if (! class_exists(Dompdf::class) || ! class_exists(Writer::class)) {
            $this->markTestSkipped('Falta autorizar e instalar las dependencias PDF/QR; no se simula el generador.');
        }
        $this->authorize();
        $this->isolateDocumentBehavior();
        $this->app['env'] = $environment;
        config(['app.frontend_url' => $frontendUrl]);
        if ($environment === 'production') {
            config(['certification.documents.verification_base_url' => 'https://api.example.org/verificar']);
        }
        $data = $this->postJson('/api/certificados/1/generar-documento')->assertCreated()
            ->assertJsonPath('data.creado', true)->assertJsonPath('data.oficial', false)->json('data');
        self::assertMatchesRegularExpression('/^[a-f0-9]{30}$/D', $data['codigo_verificacion']);
        $publicFrontend = $environment === 'production' ? 'https://certificados-morales.aiyond.org' : $frontendUrl;
        self::assertSame($publicFrontend.'/verificar/'.$data['codigo_verificacion'], $data['url_verificacion']);
        $document = DB::table('certificado.certificado_documento')->first();
        self::assertSame('certificados/desarrollo/'.$document->codigo_verificacion.'.pdf', $document->ruta_archivo);
        $content = Storage::disk('local')->get($document->ruta_archivo);
        self::assertStringStartsWith('%PDF-', $content);
        self::assertSame(hash('sha256', $content), $data['hash_certificado']);
        self::assertSame($data['hash_certificado'], $document->hash_certificado);
        $this->postJson('/api/certificados/1/generar-documento')->assertOk()
            ->assertJsonPath('data.codigo_verificacion', $data['codigo_verificacion']);
        // Reopen the persistent directory through a fresh adapter.
        config(['filesystems.disks.local.root' => Storage::disk('local')->path('')]);
        Storage::forgetDisk('local');
        $url = '/api/certificados-documentos/'.$document->id_certificado_documento.'/archivo';
        $filename = 'certificado-'.$document->codigo_verificacion.'.pdf';
        $response = $this->get($url)->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'inline; filename='.$filename)
            ->assertHeader('X-Document-SHA256', $document->hash_certificado);
        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        ob_start();
        try {
            $response->baseResponse->sendContent();
            self::assertSame($content, ob_get_contents());
        } finally {
            ob_end_clean();
        }
        $this->get($url.'?descargar=1')->assertOk()->assertDownload($filename);
        $this->assertDatabaseCount('certificado.certificado_documento', 1);
        $this->assertDatabaseHas('certificado.certificado', ['id_certificado' => 1, 'estado' => 'Registro de prueba']);
    }
}
