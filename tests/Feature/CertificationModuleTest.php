<?php

namespace Tests\Feature;

use App\Data\Certificate\CertificateData;
use App\Models\Certificate\Certificate;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CertificationModuleTest extends TestCase
{
    private array $entities = [
        'CertificateType' => ['tipos-certificado', 'tipo_certificado', 'id_tipo_certificado', ['nombre_tipo', 'estado']],
        'Certificate' => ['certificados', 'certificado', 'id_certificado', ['id_persona', 'id_tipo_certificado', 'id_reconocimiento', 'id_participacion', 'id_practica', 'fecha_emision', 'estado', 'notificado', 'fecha_notificacion']],
        'Recognition' => ['reconocimientos', 'reconocimiento', 'id_reconocimiento', ['id_persona_trabajador', 'titulo', 'descripcion', 'motivo', 'fecha_reconocimiento', 'estado']],
        'CertificateDocument' => ['certificados-documentos', 'certificado_documento', 'id_certificado_documento', ['id_certificado', 'codigo_verificacion', 'hash_certificado', 'ruta_archivo', 'motivo_reemision', 'estado', 'fecha_generacion']],
        'Signature' => ['firmas', 'firma', 'id_firma', ['id_persona_trabajador', 'ruta_archivo_pfx', 'passphrase_cifrada', 'fecha_firma']],
        'CertificateSignature' => ['certificados-firmas', 'certificado_firma', 'id_certificado_firma', ['id_certificado_documento', 'id_firma', 'fecha_firma', 'orden_firma']],
        'CertificateInquiry' => ['consultas-certificado', 'consulta_certificado', 'id_consulta_certificado', ['id_certificado_documento', 'dni_consulta', 'codigo_consulta', 'fecha_consulta', 'resultado']],
        'ActivitySignature' => ['actividades-firmas', 'actividad_firma', 'id_actividad_firma', ['id_actividad', 'id_firma', 'orden_firma']],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        // Fixtures exist exclusively in memory. Never use application credentials.
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'database.connections.sqlite.url' => null]);
        DB::purge('sqlite');
        $definitions = array_values($this->entities);
        foreach (['persona' => ['id_persona', []], 'persona_trabajador' => ['id_persona_trabajador', ['id_persona']], 'persona_practicante' => ['id_persona_practicante', ['id_persona']], 'actividad' => ['id_actividad', []], 'participacion' => ['id_participacion', ['id_persona']], 'practica' => ['id_practica', ['id_persona_practicante']]] as $table => [$key, $fields]) {
            $definitions[] = ['', $table, $key, $fields];
        }
        foreach ($definitions as [$prefix, $table, $key, $fields]) {
            Schema::create($table, function (Blueprint $blueprint) use ($key, $fields) {
                $blueprint->increments($key);
                foreach ($fields as $field) {
                    if (str_starts_with($field, 'id_') || $field === 'orden_firma') {
                        $blueprint->integer($field)->nullable();
                    } else {
                        $blueprint->string($field, 500)->nullable();
                    }
                }
            });
        }
        DB::table('persona')->insert([['id_persona' => 1], ['id_persona' => 2]]);
        DB::table('persona_trabajador')->insert(['id_persona_trabajador' => 1, 'id_persona' => 1]);
        DB::table('actividad')->insert(['id_actividad' => 1]);
        DB::table('participacion')->insert(['id_participacion' => 1, 'id_persona' => 1]);
        DB::table('persona_practicante')->insert(['id_persona_practicante' => 1, 'id_persona' => 2]);
        DB::table('practica')->insert(['id_practica' => 1, 'id_persona_practicante' => 1]);
        DB::table('tipo_certificado')->insert(['id_tipo_certificado' => 1, 'nombre_tipo' => 'Asistencia', 'estado' => 'ACTIVO']);
        Storage::fake('local');
    }

    private function administrator(): void
    {
        $this->actingAs(new User(['name' => 'Test administrator']));
        Gate::before(fn () => true); // Test-only authorization, never installed in production.
    }

    private function certificate(): array
    {
        return ['id_persona' => 1, 'id_tipo_certificado' => 1, 'estado' => true, 'notificado' => false];
    }

    private function seedDocument(): void
    {
        DB::table('certificado_documento')->insert(['id_certificado_documento' => 1, 'id_certificado' => 1, 'codigo_verificacion' => 'TEST-1', 'hash_certificado' => str_repeat('a', 64), 'ruta_archivo' => 'certificados/private.pdf', 'estado' => 'GENERADO', 'fecha_generacion' => '2026-01-01']);
    }

    public function test_all_48_routes_require_authentication(): void
    {
        foreach ($this->entities as [$prefix]) {
            $this->getJson("/api/$prefix/list")->assertUnauthorized();
            $this->getJson("/api/$prefix/detail/1")->assertUnauthorized();
            $this->postJson("/api/$prefix/create", [])->assertUnauthorized();
            $this->putJson("/api/$prefix/update/1", [])->assertUnauthorized();
            $this->patchJson("/api/$prefix/update/1", [])->assertUnauthorized();
            $this->deleteJson("/api/$prefix/delete/1")->assertUnauthorized();
        }
    }

    public function test_api_guests_receive_json_without_requesting_json(): void
    {
        foreach ([[], ['HTTP_ACCEPT' => 'text/html'], ['HTTP_ACCEPT' => '*/*']] as $headers) {
            foreach ($this->entities as [$prefix]) {
                foreach ([['GET', 'list'], ['GET', 'detail/1'], ['POST', 'create'], ['PUT', 'update/1'], ['PATCH', 'update/1'], ['DELETE', 'delete/1']] as [$method, $path]) {
                    $this->call($method, "/api/$prefix/$path", [], [], [], $headers)
                        ->assertUnauthorized()
                        ->assertHeader('Content-Type', 'application/json')
                        ->assertHeaderMissing('Location')
                        ->assertExactJson(['success' => false, 'message' => 'No autenticado.', 'data' => []]);
                }
            }
        }
    }

    public function test_missing_policies_deny_authenticated_users(): void
    {
        $this->actingAs(new User(['name' => 'Unauthorized user']));
        foreach ($this->entities as [$prefix]) {
            $this->getJson("/api/$prefix/list")->assertForbidden();
            $this->postJson("/api/$prefix/create", [])->assertForbidden();
            $this->getJson("/api/$prefix/detail/1")->assertForbidden();
            $this->patchJson("/api/$prefix/update/1", [])->assertForbidden();
            $this->deleteJson("/api/$prefix/delete/1")->assertForbidden();
        }
    }

    public function test_each_entity_validates_create_and_returns_404(): void
    {
        $this->administrator();
        foreach ($this->entities as [$prefix]) {
            $this->postJson("/api/$prefix/create", [])->assertUnprocessable();
            $this->getJson("/api/$prefix/detail/999")->assertNotFound();
            $this->patchJson("/api/$prefix/update/999", [])->assertNotFound();
            $this->deleteJson("/api/$prefix/delete/999")->assertNotFound();
            $this->getJson("/api/$prefix/list?per_page=101")->assertUnprocessable();
        }
    }

    public function test_type_crud_pagination_put_patch_and_duplicates(): void
    {
        $this->administrator();
        $payload = ['nombre_tipo' => 'Práctica', 'estado' => 'ACTIVO'];
        $this->postJson('/api/tipos-certificado/create', $payload + ['unexpected' => 'ignored'])->assertCreated()->assertJsonPath('data.id_tipo_certificado', 2);
        $this->postJson('/api/tipos-certificado/create', $payload)->assertConflict();
        $this->getJson('/api/tipos-certificado/list?per_page=1&estado=ACTIVO')->assertOk()->assertJsonPath('data.total', 2)->assertJsonCount(1, 'data.data');
        $this->putJson('/api/tipos-certificado/update/2', ['estado' => 'INACTIVO'])->assertUnprocessable();
        $this->patchJson('/api/tipos-certificado/update/2', ['estado' => 'INACTIVO'])->assertOk()->assertJsonPath('data.nombre_tipo', 'Práctica');
        $this->deleteJson('/api/tipos-certificado/delete/2')->assertOk();
        $this->getJson('/api/tipos-certificado/detail/2')->assertNotFound();
    }

    public function test_certificate_validates_origins_foreign_keys_notifications_and_history(): void
    {
        $this->administrator();
        $this->postJson('/api/certificados/create', array_replace($this->certificate(), ['id_persona' => 99]))->assertUnprocessable();
        $this->postJson('/api/certificados/create', $this->certificate() + ['id_participacion' => 1, 'id_practica' => 1])->assertUnprocessable();
        $this->postJson('/api/certificados/create', $this->certificate() + ['id_practica' => 1])->assertUnprocessable();
        $this->postJson('/api/certificados/create', array_replace($this->certificate(), ['notificado' => true]))->assertUnprocessable();
        $this->postJson('/api/certificados/create', $this->certificate())->assertCreated();
        $this->postJson('/api/certificados/create', $this->certificate())->assertConflict();
        $this->patchJson('/api/certificados/update/1', ['fecha_emision' => '2026-01-01'])->assertOk();
        $this->patchJson('/api/certificados/update/1', ['notificado' => true, 'fecha_notificacion' => '2025-12-31'])->assertUnprocessable();
        $this->deleteJson('/api/tipos-certificado/delete/1')->assertConflict();
        $this->seedDocument();
        $this->deleteJson('/api/certificados/delete/1')->assertConflict();
        $this->patchJson('/api/certificados/update/1', ['fecha_emision' => '2026-02-01'])->assertConflict();
    }

    public function test_recognition_crud_and_worker_validation(): void
    {
        $this->administrator();
        $payload = ['id_persona_trabajador' => 1, 'titulo' => 'Mérito', 'descripcion' => 'Descripción', 'motivo' => 'Motivo', 'fecha_reconocimiento' => '2026-01-01', 'estado' => 'ACTIVO'];
        $this->postJson('/api/reconocimientos/create', array_replace($payload, ['id_persona_trabajador' => 99]))->assertUnprocessable();
        $this->postJson('/api/reconocimientos/create', $payload)->assertCreated();
        $this->patchJson('/api/reconocimientos/update/1', ['titulo' => 'Mérito académico'])->assertOk();
        $this->getJson('/api/reconocimientos/detail/1')->assertOk()->assertJsonPath('data.titulo', 'Mérito académico');
        $this->deleteJson('/api/reconocimientos/delete/1')->assertOk();
    }

    public function test_documents_reject_client_hash_signed_state_and_invalid_files(): void
    {
        $this->administrator();
        CertificateData::store($this->certificate());
        $payload = ['id_certificado' => 1, 'codigo_verificacion' => 'TEST-1', 'ruta_archivo' => 'certificados/missing.pdf', 'estado' => 'GENERADO', 'fecha_generacion' => '2026-01-01'];
        $this->postJson('/api/certificados-documentos/create', $payload + ['hash_certificado' => str_repeat('a', 64)])->assertUnprocessable();
        $this->postJson('/api/certificados-documentos/create', array_replace($payload, ['estado' => 'FIRMADO']))->assertUnprocessable();
        $this->postJson('/api/certificados-documentos/create', $payload)->assertUnprocessable();
        Storage::disk('local')->put('certificados/not-pdf.pdf', 'not a PDF');
        $this->postJson('/api/certificados-documentos/create', array_replace($payload, ['ruta_archivo' => 'certificados/not-pdf.pdf']))->assertUnprocessable();
        $this->assertSame(0, DB::table('certificado_documento')->count());
    }

    public function test_hash_matches_document_bytes_and_evidence_cannot_be_deleted(): void
    {
        $this->administrator();
        CertificateData::store($this->certificate());
        $pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Count 0 /Kids [] >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
        Storage::disk('local')->put('certificados/fixture.pdf', $pdf);
        $payload = ['id_certificado' => 1, 'codigo_verificacion' => 'TEST-1', 'ruta_archivo' => 'certificados/fixture.pdf', 'estado' => 'GENERADO', 'fecha_generacion' => '2026-01-01'];
        $this->postJson('/api/certificados-documentos/create', $payload)->assertCreated()->assertJsonPath('data.hash_certificado', hash('sha256', $pdf))->assertJsonMissingPath('data.ruta_archivo');
        $this->postJson('/api/certificados-documentos/create', $payload)->assertConflict();
        $this->deleteJson('/api/certificados-documentos/delete/1')->assertConflict();
    }

    public function test_signature_secrets_are_hidden_and_unverified_signing_is_blocked(): void
    {
        $this->administrator();
        DB::table('firma')->insert(['id_firma' => 1, 'id_persona_trabajador' => 1, 'ruta_archivo_pfx' => 'firmas/secret.pfx', 'passphrase_cifrada' => 'secret-ciphertext', 'fecha_firma' => '2026-01-01']);
        $this->seedDocument();
        $this->getJson('/api/firmas/detail/1')->assertOk()->assertJsonMissingPath('data.passphrase_cifrada')->assertJsonMissingPath('data.ruta_archivo_pfx');
        $this->getJson('/api/firmas/list')->assertOk()->assertDontSee('secret-ciphertext')->assertDontSee('secret.pfx');
        $this->postJson('/api/certificados-firmas/create', ['id_certificado_documento' => 1, 'id_firma' => 1, 'fecha_firma' => '2026-01-01', 'orden_firma' => 1])->assertConflict();
        $this->assertSame(0, DB::table('certificado_firma')->count());
        $this->postJson('/api/firmas/create', ['id_persona_trabajador' => 1, 'ruta_archivo_pfx' => 'public/secret.pfx', 'passphrase' => 'password', 'fecha_firma' => '2026-01-01'])->assertUnprocessable()->assertDontSee('password');
    }

    public function test_activity_order_duplicates_update_and_delete(): void
    {
        $this->administrator();
        DB::table('firma')->insert(['id_firma' => 1]);
        DB::table('firma')->insert(['id_firma' => 2]);
        $payload = ['id_actividad' => 1, 'id_firma' => 1, 'orden_firma' => 1];
        $this->postJson('/api/actividades-firmas/create', $payload)->assertCreated();
        $this->postJson('/api/actividades-firmas/create', $payload)->assertConflict();
        $this->postJson('/api/actividades-firmas/create', array_replace($payload, ['id_firma' => 2]))->assertConflict();
        $this->postJson('/api/actividades-firmas/create', array_replace($payload, ['orden_firma' => 0]))->assertUnprocessable();
        $this->patchJson('/api/actividades-firmas/update/1', ['orden_firma' => 2])->assertOk();
        $this->deleteJson('/api/firmas/delete/1')->assertConflict();
        $this->deleteJson('/api/actividades-firmas/delete/1')->assertOk();
    }

    public function test_consultations_validate_dni_and_preserve_history(): void
    {
        $this->administrator();
        $this->seedDocument();
        $payload = ['id_certificado_documento' => 1, 'dni_consulta' => '12345678', 'codigo_consulta' => 'TEST-1', 'fecha_consulta' => '2026-01-01 12:00:00', 'resultado' => 'ENCONTRADO'];
        $this->postJson('/api/consultas-certificado/create', array_replace($payload, ['dni_consulta' => '123']))->assertUnprocessable();
        $this->postJson('/api/consultas-certificado/create', $payload)->assertCreated();
        $this->getJson('/api/consultas-certificado/list?dni_consulta=12345678')->assertOk()->assertJsonPath('data.total', 1);
        $this->patchJson('/api/consultas-certificado/update/1', ['resultado' => 'ALTERADO'])->assertConflict();
        $this->deleteJson('/api/consultas-certificado/delete/1')->assertConflict();
    }

    public function test_model_configuration_relations_and_unavailable_schema(): void
    {
        foreach ($this->entities as $entity => [$prefix, $table, $key]) {
            $class = "App\\Models\\$entity\\$entity";
            $model = new $class;
            $this->assertSame($table, $model->getTable());
            $this->assertSame($key, $model->getKeyName());
            $this->assertFalse($model->usesTimestamps());
        }
        $model = new Certificate;
        $this->assertInstanceOf(BelongsTo::class, $model->tipoCertificado());
        $this->assertInstanceOf(HasMany::class, $model->documentos());
        Schema::drop('certificado');
        $this->assertSame(503, CertificateData::list()['status']);
    }

    public function test_real_pfx_is_validated_encrypted_and_updated_without_disclosing_credentials(): void
    {
        $this->administrator();
        Storage::disk('local')->put('firmas/test-openssl.cnf', "[req]\ndistinguished_name=req_dn\n[req_dn]\nCN=Fixture\n");
        $options = ['config' => Storage::disk('local')->path('firmas/test-openssl.cnf'), 'private_key_bits' => 2048, 'digest_alg' => 'sha256'];
        $key = openssl_pkey_new($options);
        $this->assertNotFalse($key);
        $csr = openssl_csr_new(['commonName' => 'Test-only fixture'], $key, $options);
        $this->assertNotFalse($csr);
        $certificate = openssl_csr_sign($csr, null, $key, 1, $options);
        $this->assertNotFalse($certificate);
        $this->assertTrue(openssl_pkcs12_export($certificate, $pfx, $key, 'test-secret'));
        Storage::disk('local')->put('firmas/fixture.pfx', $pfx);
        $payload = ['id_persona_trabajador' => 1, 'ruta_archivo_pfx' => 'firmas/fixture.pfx', 'passphrase' => 'test-secret', 'fecha_firma' => '2026-01-01'];
        $this->postJson('/api/firmas/create', array_replace($payload, ['passphrase' => 'wrong-secret']))->assertUnprocessable()->assertDontSee('wrong-secret');
        $this->postJson('/api/firmas/create', $payload)->assertCreated()->assertJsonMissingPath('data.passphrase_cifrada')->assertJsonMissingPath('data.ruta_archivo_pfx')->assertDontSee('test-secret');
        $encrypted = DB::table('firma')->value('passphrase_cifrada');
        $this->assertNotSame('test-secret', $encrypted);
        $this->assertSame('test-secret', Crypt::decryptString($encrypted));
        $this->postJson('/api/firmas/create', $payload)->assertConflict();
        Schema::table('firma', fn (Blueprint $blueprint) => $blueprint->string('integration_metadata')->nullable());
        DB::table('firma')->update(['integration_metadata' => 'preserve']);
        $this->assertSame(['fecha_firma'], $this->updateColumns(fn () => $this->patchJson('/api/firmas/update/1', ['fecha_firma' => '2026-02-01', 'integration_metadata' => 'tampered'])->assertOk(), 'firma'));
        $this->assertSame($encrypted, DB::table('firma')->value('passphrase_cifrada'));
        $this->assertSame(['fecha_firma'], $this->updateColumns(fn () => $this->putJson('/api/firmas/update/1', $payload + ['integration_metadata' => 'tampered'])->assertOk(), 'firma'));
        $this->assertSame($encrypted, DB::table('firma')->value('passphrase_cifrada'));
        $this->assertSame('preserve', DB::table('firma')->value('integration_metadata'));
        $this->assertSame('test-secret', Crypt::decryptString(DB::table('firma')->value('passphrase_cifrada')));
        $this->assertSame([], $this->updateColumns(fn () => $this->patchJson('/api/firmas/update/1', ['fecha_firma' => '2026-01-01'])->assertOk(), 'firma'));
        $this->assertTrue(openssl_pkcs12_export($certificate, $pfx, $key, 'new-test-secret'));
        Storage::disk('local')->put('firmas/fixture.pfx', $pfx);
        $this->assertSame(['passphrase_cifrada'], $this->updateColumns(fn () => $this->patchJson('/api/firmas/update/1', ['passphrase' => 'new-test-secret'])->assertOk()->assertJsonMissingPath('data.passphrase_cifrada'), 'firma'));
        $this->assertSame('new-test-secret', Crypt::decryptString(DB::table('firma')->value('passphrase_cifrada')));
        $this->deleteJson('/api/firmas/delete/1')->assertOk();
        Storage::disk('local')->assertExists('firmas/fixture.pfx');
    }

    public function test_participation_requires_the_same_beneficiary(): void
    {
        $this->administrator();
        $this->postJson('/api/certificados/create', $this->certificate() + ['id_participacion' => 1])->assertCreated();
        $this->postJson('/api/certificados/create', array_replace($this->certificate(), ['id_persona' => 2, 'id_participacion' => 1]))->assertUnprocessable()->assertJsonValidationErrors('id_participacion', 'data');
        $this->postJson('/api/certificados/create', $this->certificate() + ['id_participacion' => 99])->assertUnprocessable();
    }

    public function test_practice_uses_the_practitioner_beneficiary(): void
    {
        $this->administrator();
        $this->postJson('/api/certificados/create', array_replace($this->certificate(), ['id_persona' => 2, 'id_practica' => 1]))->assertCreated();
        $this->postJson('/api/certificados/create', $this->certificate() + ['id_practica' => 1])->assertUnprocessable()->assertJsonValidationErrors('id_practica', 'data');
        $this->postJson('/api/certificados/create', $this->certificate() + ['id_practica' => 99])->assertUnprocessable();
        DB::table('practica')->insert(['id_practica' => 2, 'id_persona_practicante' => 99]);
        $this->postJson('/api/certificados/create', $this->certificate() + ['id_practica' => 2])->assertUnprocessable()->assertJsonValidationErrors('id_practica', 'data');
    }

    public function test_recognition_uses_the_worker_beneficiary(): void
    {
        $this->administrator();
        DB::table('reconocimiento')->insert(['id_reconocimiento' => 1, 'id_persona_trabajador' => 1]);
        $this->postJson('/api/certificados/create', $this->certificate() + ['id_reconocimiento' => 1])->assertCreated();
        $this->postJson('/api/certificados/create', array_replace($this->certificate(), ['id_persona' => 2, 'id_reconocimiento' => 1]))->assertUnprocessable()->assertJsonValidationErrors('id_reconocimiento', 'data');
        DB::table('reconocimiento')->insert(['id_reconocimiento' => 2, 'id_persona_trabajador' => 99]);
        $this->postJson('/api/certificados/create', $this->certificate() + ['id_reconocimiento' => 2])->assertUnprocessable();
    }

    public function test_updates_revalidate_origins_and_allow_clearing_an_optional_origin(): void
    {
        $this->administrator();
        $this->postJson('/api/certificados/create', $this->certificate() + ['id_participacion' => 1])->assertCreated();
        $this->patchJson('/api/certificados/update/1', ['id_practica' => 1])->assertUnprocessable();
        $this->patchJson('/api/certificados/update/1', ['id_persona' => 2])->assertUnprocessable();
        $this->putJson('/api/certificados/update/1', array_replace($this->certificate(), ['id_persona' => 2, 'id_participacion' => 1]))->assertUnprocessable();
        $this->assertSame(['id_participacion'], $this->updateColumns(fn () => $this->patchJson('/api/certificados/update/1', ['id_participacion' => null])->assertOk(), 'certificado'));
        $this->assertSame(['id_persona', 'id_practica'], $this->updateColumns(fn () => $this->patchJson('/api/certificados/update/1', ['id_persona' => 2, 'id_practica' => 1])->assertOk(), 'certificado'));
        $this->patchJson('/api/certificados/update/1', ['id_persona' => 1])->assertUnprocessable();
    }

    public function test_document_hash_updates_are_computed_by_the_server(): void
    {
        $this->administrator();
        CertificateData::store($this->certificate());
        $this->seedDocument();
        Storage::disk('local')->put('certificados/private.pdf', '%PDF-1.4 updated fixture');
        $this->patchJson('/api/certificados-documentos/update/1', ['hash_certificado' => str_repeat('b', 64)])->assertUnprocessable();
        $this->assertSame(['hash_certificado'], $this->updateColumns(fn () => $this->patchJson('/api/certificados-documentos/update/1', ['estado' => 'GENERADO'])->assertOk()->assertJsonPath('data.hash_certificado', hash('sha256', '%PDF-1.4 updated fixture')), 'certificado_documento'));
    }

    #[DataProvider('missingExternalTables')]
    public function test_missing_external_tables_are_controlled(string $table, string $prefix, array $extra): void
    {
        $this->administrator();
        DB::table('reconocimiento')->insert(['id_reconocimiento' => 1, 'id_persona_trabajador' => 1]);
        Schema::drop($table);
        $payload = $prefix === 'certificados' ? array_replace($this->certificate(), $extra) : $extra;
        $this->postJson("/api/$prefix/create", $payload)->assertStatus(503);
        $this->assertSame(0, DB::table('certificado')->count());
    }

    public static function missingExternalTables(): array
    {
        return [
            'person' => ['persona', 'certificados', []],
            'participation' => ['participacion', 'certificados', ['id_participacion' => 1]],
            'practice' => ['practica', 'certificados', ['id_practica' => 1]],
            'practitioner' => ['persona_practicante', 'certificados', ['id_practica' => 1]],
            'worker' => ['persona_trabajador', 'certificados', ['id_reconocimiento' => 1]],
            'activity' => ['actividad', 'actividades-firmas', ['id_actividad' => 1, 'id_firma' => 1, 'orden_firma' => 1]],
        ];
    }

    private function updateColumns(callable $operation, string $table): array
    {
        DB::connection()->flushQueryLog();
        DB::connection()->enableQueryLog();
        try {
            $operation();
            $updates = array_values(array_filter(DB::connection()->getQueryLog(), fn (array $query) => str_starts_with($query['query'], 'update "'.$table.'" set ')));
            if ($updates === []) {
                return [];
            }
            $this->assertCount(1, $updates);
            $set = explode(' where ', explode(' set ', $updates[0]['query'], 2)[1], 2)[0];
            preg_match_all('/"([a-z_]+)" = /', $set, $matches);

            return $matches[1];
        } finally {
            DB::connection()->disableQueryLog();
            DB::connection()->flushQueryLog();
        }
    }

    public function test_put_and_patch_only_write_changed_authorized_columns(): void
    {
        $this->administrator();
        CertificateData::store($this->certificate());
        DB::table('reconocimiento')->insert(['id_reconocimiento' => 1, 'id_persona_trabajador' => 1, 'titulo' => 'Título', 'descripcion' => 'Descripción', 'motivo' => 'Motivo', 'fecha_reconocimiento' => '2026-01-01', 'estado' => 'ACTIVO']);
        DB::table('firma')->insert(['id_firma' => 1]);
        DB::table('actividad_firma')->insert(['id_actividad_firma' => 1, 'id_actividad' => 1, 'id_firma' => 1, 'orden_firma' => 1]);
        $this->seedDocument();
        Storage::disk('local')->put('certificados/private.pdf', '%PDF-1.4 fixture');
        $cases = [
            ['tipos-certificado', 'tipo_certificado', 'estado', 'INACTIVO', 'ACTIVO', ['nombre_tipo' => 'Asistencia', 'estado' => 'ACTIVO']],
            ['certificados', 'certificado', 'estado', false, true, $this->certificate()],
            ['reconocimientos', 'reconocimiento', 'titulo', 'Otro título', 'Título', ['id_persona_trabajador' => 1, 'titulo' => 'Título', 'descripcion' => 'Descripción', 'motivo' => 'Motivo', 'fecha_reconocimiento' => '2026-01-01', 'estado' => 'ACTIVO']],
            ['actividades-firmas', 'actividad_firma', 'orden_firma', 2, 1, ['id_actividad' => 1, 'id_firma' => 1, 'orden_firma' => 1]],
            ['certificados-documentos', 'certificado_documento', 'estado', 'ANULADO', 'GENERADO', ['id_certificado' => 1, 'codigo_verificacion' => 'TEST-1', 'ruta_archivo' => 'certificados/private.pdf', 'estado' => 'GENERADO', 'fecha_generacion' => '2026-01-01']],
        ];
        DB::table('certificado_documento')->where('id_certificado_documento', 1)->update(['hash_certificado' => hash('sha256', '%PDF-1.4 fixture')]);
        foreach ($cases as [$prefix, $table, $field, $changed, $original, $put]) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->string('integration_metadata')->nullable());
            DB::table($table)->update(['integration_metadata' => 'preserve']);
            $this->assertSame([$field], $this->updateColumns(fn () => $this->patchJson("/api/$prefix/update/1", [$field => $changed, 'integration_metadata' => 'tampered'])->assertOk(), $table));
            $this->assertSame([$field], $this->updateColumns(fn () => $this->putJson("/api/$prefix/update/1", $put + ['integration_metadata' => 'tampered'])->assertOk(), $table));
            $this->assertSame([], $this->updateColumns(fn () => $this->patchJson("/api/$prefix/update/1", [$field => $original])->assertOk(), $table));
            $this->assertSame('preserve', DB::table($table)->value('integration_metadata'));
        }
    }

    public function test_historical_updates_emit_no_update_sql(): void
    {
        $this->administrator();
        CertificateData::store($this->certificate());
        $this->seedDocument();
        DB::table('firma')->insert(['id_firma' => 1]);
        DB::table('actividad_firma')->insert(['id_actividad_firma' => 1, 'id_actividad' => 1, 'id_firma' => 1, 'orden_firma' => 1]);
        DB::table('reconocimiento')->insert(['id_reconocimiento' => 1, 'id_persona_trabajador' => 1, 'titulo' => 'Título', 'descripcion' => 'Descripción', 'motivo' => 'Motivo', 'fecha_reconocimiento' => '2026-01-01', 'estado' => 'ACTIVO']);
        DB::table('certificado')->update(['id_reconocimiento' => 1]);
        DB::table('certificado_firma')->insert(['id_certificado_firma' => 1, 'id_certificado_documento' => 1, 'id_firma' => 1, 'fecha_firma' => '2026-01-01', 'orden_firma' => 1]);
        DB::table('consulta_certificado')->insert(['id_consulta_certificado' => 1, 'id_certificado_documento' => 1, 'dni_consulta' => '12345678', 'codigo_consulta' => 'TEST', 'fecha_consulta' => '2026-01-01', 'resultado' => 'ENCONTRADO']);
        foreach (['PUT', 'PATCH'] as $method) {
            $cases = [
                ['tipos-certificado', 'tipo_certificado', ['nombre_tipo' => 'Otro nombre', 'estado' => 'ACTIVO']],
                ['certificados', 'certificado', $this->certificate() + ['fecha_emision' => '2026-02-01']],
                ['reconocimientos', 'reconocimiento', ['id_persona_trabajador' => 1, 'titulo' => 'Otro título', 'descripcion' => 'Descripción', 'motivo' => 'Motivo', 'fecha_reconocimiento' => '2026-01-01', 'estado' => 'ACTIVO']],
                ['firmas', 'firma', ['id_persona_trabajador' => 1, 'ruta_archivo_pfx' => 'firmas/fixture.pfx', 'passphrase' => 'test-only', 'fecha_firma' => '2026-01-01']],
                ['actividades-firmas', 'actividad_firma', ['id_actividad' => 1, 'id_firma' => 1, 'orden_firma' => 2]],
                ['certificados-documentos', 'certificado_documento', ['id_certificado' => 1, 'codigo_verificacion' => 'TEST-1', 'ruta_archivo' => 'certificados/private.pdf', 'estado' => 'ANULADO', 'fecha_generacion' => '2026-01-01']],
                ['certificados-firmas', 'certificado_firma', ['id_certificado_documento' => 1, 'id_firma' => 1, 'fecha_firma' => '2026-01-02', 'orden_firma' => 1]],
                ['consultas-certificado', 'consulta_certificado', ['id_certificado_documento' => 1, 'dni_consulta' => '12345678', 'codigo_consulta' => 'TEST', 'fecha_consulta' => '2026-01-01', 'resultado' => 'ALTERADO']],
            ];
            foreach ($cases as [$prefix, $table, $payload]) {
                $this->assertSame([], $this->updateColumns(fn () => $this->json($method, "/api/$prefix/update/1", $payload)->assertConflict(), $table));
            }
        }
    }

    public function test_user_crud_contract_remains_available(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });
        $this->postJson('/api/users/create', ['name' => 'Regression', 'email' => 'regression@example.test', 'password' => 'test-password', 'password_confirmation' => 'test-password'])->assertCreated()->assertJsonMissingPath('data.password');
        $this->getJson('/api/users/list')->assertOk();
        $this->getJson('/api/users/detail/1')->assertOk();
        $this->putJson('/api/users/update/1', ['name' => 'Updated', 'email' => 'regression@example.test'])->assertOk();
        $this->deleteJson('/api/users/delete/1')->assertOk();
    }
}
