<?php

namespace Tests\Feature;

use App\Models\Person_Worker\Person_Worker;
use App\Models\Role\Role;
use App\Models\User\User;
use App\Services\CertificateDocumentGenerator;
use App\Services\CertificateDocumentVersions;
use App\Services\CertificateDocumentWorkflow;
use App\Services\CertificateHumanResources;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class CertificateSigningStageBTest extends TestCase
{
    private const BASE = '/api/certificados-documentos/1/firma';

    public function test_academic_configuration_enables_portal_links_and_removal_blocks_previously_shared_urls_without_writes(): void
    {
        $this->assignForPdfRevision();
        $sourceCertificate = DB::table('certificado.certificado')->first();
        $manifestPath = 'certificados/revisiones/aprobado.manifest';
        $manifest = json_decode(Crypt::decryptString(Storage::disk('local')->get($manifestPath)), true, 512, JSON_THROW_ON_ERROR);
        $manifest['context'] += ['id_persona' => $sourceCertificate->id_persona, 'id_tipo_certificado' => $sourceCertificate->id_tipo_certificado];
        Storage::disk('local')->put($manifestPath, Crypt::encryptString(json_encode($manifest, JSON_THROW_ON_ERROR)));
        $review = $this->postJson(self::BASE.'/revision-pdf')->assertOk()->json('data');
        $this->postJson(self::BASE.'/confirmar-pdf', ['token_revision' => $review['token_revision'], 'confirmar' => true])->assertOk();
        $document = DB::table('certificado.certificado_documento')->first();
        $certificate = DB::table('certificado.certificado')->first();
        DB::statement('CREATE TABLE gestion.tipo_documento (id_tipo_documento INTEGER PRIMARY KEY, nombre TEXT, estado BOOLEAN)');
        DB::statement('CREATE TABLE gestion.persona_documento (id_persona_documento INTEGER PRIMARY KEY, id_persona INTEGER, id_tipo_documento INTEGER, numero_documento TEXT, estado TEXT)');
        $dniType = DB::table('gestion.tipo_documento')->insertGetId(['nombre' => 'DNI', 'estado' => true], 'id_tipo_documento');
        $dni = '87654321';
        DB::table('gestion.persona_documento')->insert(['id_persona' => $certificate->id_persona,
            'id_tipo_documento' => $dniType, 'numero_documento' => $dni, 'estado' => 'Activo']);
        $files = collect(Storage::disk('local')->allFiles())->mapWithKeys(fn (string $path): array => [$path => hash_file('sha256', Storage::disk('local')->path($path))])->all();
        $this->partialMock(CertificateDocumentGenerator::class)->shouldNotReceive('previewPdf', 'reviewPdf', 'generate');
        app('auth')->forgetGuards();
        config(['certification.public_lookup.pdf_policy_reference' => 'CONSENTIMIENTO ACADEMICO EXCLUSIVO DE DATOS FICTICIOS DE PRUEBA',
            'certification.public_lookup.pdf_codes' => []]);
        $portal = '/api/portal-certificados/consultar-dni';
        $private = $this->postJson($portal, ['dni' => $dni])->assertOk()->assertJsonCount(1, 'data.certificados')->json('data.certificados.0');
        self::assertFalse($private['pdf_publicado']);
        self::assertNull($private['url_documento']);
        self::assertNull($private['url_descarga']);
        config(['certification.public_lookup.pdf_codes' => [$document->codigo_verificacion]]);
        $public = $this->postJson($portal, ['dni' => $dni])->assertOk()->json('data.certificados.0');
        self::assertTrue($public['pdf_publicado']);
        $codeUrl = '/api/certificados/verificar/'.$public['codigo_verificacion'];
        $this->getJson($codeUrl)->assertOk()->assertJsonPath('data.puede_ver_documento', true);
        $this->get($codeUrl, ['Accept' => 'text/html'])->assertOk()->assertSee('Abrir PDF almacenado');
        foreach (['url_documento', 'url_descarga'] as $key) {
            $response = $this->get($public[$key])->assertOk()->assertHeader('Content-Type', 'application/pdf')
                ->assertHeader('X-Document-SHA256', $review['hash_pdf']);
            self::assertSame($review['hash_pdf'], hash_file('sha256', $response->baseResponse->getFile()->getPathname()));
        }
        config(['certification.public_lookup.pdf_codes' => []]);
        $this->postJson($portal, ['dni' => $dni])->assertOk()->assertJsonPath('data.certificados.0.pdf_publicado', false)
            ->assertJsonPath('data.certificados.0.url_documento', null)->assertJsonPath('data.certificados.0.url_descarga', null);
        $this->getJson($codeUrl)->assertOk()->assertJsonPath('data.url_documento', null)->assertJsonPath('data.url_descarga', null);
        $this->get($public['url_documento'])->assertForbidden();
        $this->get($public['url_descarga'])->assertForbidden();
        self::assertSame((array) $document, (array) DB::table('certificado.certificado_documento')->first());
        self::assertSame((array) $certificate, (array) DB::table('certificado.certificado')->first());
        self::assertSame($files, collect(Storage::disk('local')->allFiles())->mapWithKeys(fn (string $path): array => [$path => hash_file('sha256', Storage::disk('local')->path($path))])->all());
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_explicit_publication_serves_identical_confirmed_bytes_for_view_and_download(bool $authenticated): void
    {
        $review = $this->prepareHumanResources();
        $document = DB::table('certificado.certificado_documento')->first();
        config(['certification.public_lookup.pdf_policy_reference' => 'AUTORIZACION DE PRUEBA',
            'certification.public_lookup.pdf_codes' => [$document->codigo_verificacion]]);
        if (! $authenticated) {
            app('auth')->forgetGuards();
        }
        $url = '/api/certificados/verificar/'.$document->codigo_verificacion;
        $this->getJson($url)->assertOk()->assertJsonPath('data.puede_ver_documento', true)
            ->assertJsonPath('data.estado_certificado', 'PENDIENTE_FIRMA');
        foreach (['?archivo=1', '?archivo=1&descargar=1'] as $query) {
            $response = $this->get($url.$query)->assertOk()->assertHeader('Content-Type', 'application/pdf')
                ->assertHeader('X-Document-SHA256', $review['hash_pdf']);
            self::assertSame($review['hash_pdf'], hash_file('sha256', $response->baseResponse->getFile()->getPathname()));
            if (str_contains($query, 'descargar')) {
                $response->assertDownload('certificado-'.$document->codigo_verificacion.'.pdf');
            } else {
                self::assertStringStartsWith('inline;', $response->headers->get('Content-Disposition'));
            }
        }
        config(['certification.public_lookup.pdf_policy_reference' => null]);
        $this->getJson($url.'?archivo=1')->assertForbidden();
    }

    #[TestWith(['private'])]
    #[TestWith(['prepared'])]
    #[TestWith(['cancelled'])]
    #[TestWith(['hash'])]
    #[TestWith(['missing'])]
    #[TestWith(['plan'])]
    #[TestWith(['unknown'])]
    public function test_public_pdf_rejects_unauthorized_or_invalid_documents(string $condition): void
    {
        $this->prepareHumanResources();
        $document = DB::table('certificado.certificado_documento')->first();
        config(['certification.public_lookup.pdf_policy_reference' => 'AUTORIZACION DE PRUEBA',
            'certification.public_lookup.pdf_codes' => $condition === 'private' ? [] : [$document->codigo_verificacion]]);
        if ($condition === 'prepared') {
            DB::table('certificado.certificado')->update(['estado' => 'PREPARADO']);
        } elseif ($condition === 'cancelled') {
            DB::table('certificado.certificado_documento')->update(['estado' => 'Anulado']);
        } elseif ($condition === 'hash') {
            DB::table('certificado.certificado_documento')->update(['hash_certificado' => str_repeat('0', 64)]);
        } elseif ($condition === 'missing') {
            $confirmed = app(CertificateDocumentVersions::class)->resolve($document, readOnly: true);
            Storage::disk('local')->delete($confirmed->ruta_archivo);
        } elseif ($condition === 'plan') {
            DB::table('certificado.certificado_firma')->delete();
        }
        app('auth')->forgetGuards();
        $code = $condition === 'unknown' ? str_repeat('f', 30) : $document->codigo_verificacion;
        foreach (['?archivo=1', '?archivo=1&descargar=1'] as $query) {
            $response = $this->getJson('/api/certificados/verificar/'.$code.$query);
            $condition === 'unknown' ? $response->assertNotFound() : $response->assertForbidden();
        }
    }

    private function prepareUnassignedPreview(): array
    {
        $this->assignForPdfRevision();
        DB::table('certificado.certificado_firma')->delete();
        DB::table('certificado.certificado_documento')->update(['modalidad_firma' => null]);

        return ['modalidad' => 'SECUENCIAL', 'firmantes' => [
            ['id_persona_trabajador' => 10, 'orden_firma' => 1],
            ['id_persona_trabajador' => 11, 'orden_firma' => 2],
        ]];
    }

    public function test_dynamic_preview_does_not_write_and_can_be_changed_and_cancelled(): void
    {
        $input = $this->prepareUnassignedPreview();
        $original = (array) DB::table('certificado.certificado_documento')->first();
        DB::enableQueryLog();
        $first = $this->postJson(self::BASE.'/revision-pdf', $input)->assertOk()->assertJsonPath('data.persistido', false)->json('data');
        $input['modalidad'] = 'PARALELA';
        $input['firmantes'][0]['orden_firma'] = 2;
        $input['firmantes'][1]['orden_firma'] = 1;
        $second = $this->postJson(self::BASE.'/revision-pdf', $input)->assertOk()
            ->assertJsonPath('data.firmantes.0.id_persona_trabajador', 11)->assertJsonPath('data.modalidad', 'PARALELA')->json('data');
        $input['firmantes'] = [['id_persona_trabajador' => 11, 'orden_firma' => 1]];
        $this->postJson(self::BASE.'/revision-pdf', $input)->assertOk()
            ->assertJsonCount(1, 'data.firmantes')->assertJsonPath('data.firmantes.0.id_persona_trabajador', 11);
        foreach (DB::getQueryLog() as $query) {
            self::assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace)\b/i', $query['query']);
        }
        DB::disableQueryLog();
        self::assertNotSame($first['hash_pdf'], $second['hash_pdf']);
        self::assertSame($original, (array) DB::table('certificado.certificado_documento')->first());
        $this->assertDatabaseCount('certificado.certificado_firma', 0);
        $this->deleteJson(self::BASE.'/revision-pdf', ['token_revision' => $first['token_revision']])->assertOk()->assertJsonPath('data.descartado', true);
        Storage::disk('local')->assertMissing('certificados/revisiones-firmantes/'.hash('sha256', $first['token_revision']).'.pdf');
        $this->postJson(self::BASE.'/confirmar-pdf', ['token_revision' => $first['token_revision'], 'confirmar' => true])->assertConflict();
        $this->assertDatabaseCount('certificado.certificado_firma', 0);
    }

    public function test_dynamic_preview_is_confirmed_only_after_the_exact_assignment_and_can_recover(): void
    {
        $input = $this->prepareUnassignedPreview();
        $review = $this->postJson(self::BASE.'/revision-pdf', $input)->assertOk()->json('data');
        $confirm = ['token_revision' => $review['token_revision'], 'confirmar' => true];
        $this->postJson(self::BASE.'/confirmar-pdf', $confirm)->assertConflict();
        $this->postJson(self::BASE.'/asignar', $input + ['confirmar' => true])->assertOk();
        $this->postJson(self::BASE.'/confirmar-pdf', $confirm)->assertOk()->assertJsonPath('data.pdf_revisado_disponible', true);
        $download = $this->getJson(self::BASE.'/pdf-autorizado')->assertOk()->assertDownload();
        self::assertSame(base64_decode($review['vista_previa_pdf']['contenido_base64'], true), file_get_contents($download->baseResponse->getFile()->getPathname()));
        $this->assertDatabaseCount('certificado.certificado_documento', 1);
        $this->assertDatabaseHas('certificado.certificado', ['estado' => 'PENDIENTE_FIRMA', 'fecha_emision' => null]);
    }

    public function test_dynamic_preview_rejects_concurrent_different_assignment(): void
    {
        $input = $this->prepareUnassignedPreview();
        $review = $this->postJson(self::BASE.'/revision-pdf', $input)->assertOk()->json('data');
        $input['modalidad'] = 'PARALELA';
        $this->postJson(self::BASE.'/asignar', $input + ['confirmar' => true])->assertOk();
        $this->postJson(self::BASE.'/confirmar-pdf', ['token_revision' => $review['token_revision'], 'confirmar' => true])->assertConflict();
        Storage::disk('local')->assertMissing('certificados/versiones/1/current');
    }

    public function test_dynamic_preview_requires_jwt_edit_permission_and_document_scope(): void
    {
        $input = $this->prepareUnassignedPreview();
        auth('jwt')->forgetUser();
        $this->postJson(self::BASE.'/revision-pdf', $input)->assertUnauthorized();
        $this->actor(['emision.ver']);
        $this->postJson(self::BASE.'/revision-pdf', $input)->assertForbidden();
        $this->actor(['emision.ver', 'emision.editar'], administrator: false);
        $this->postJson(self::BASE.'/revision-pdf', $input)->assertNotFound();
        $this->assertDatabaseCount('certificado.certificado_firma', 0);
    }

    #[TestWith(['estado', false])]
    #[TestWith(['habilitado_firma_certificados', false])]
    #[TestWith(['cargo', ''])]
    public function test_dynamic_preview_rejects_ineligible_workers(string $field, mixed $value): void
    {
        $input = $this->prepareUnassignedPreview();
        DB::table('gestion.persona_trabajador')->where('id_persona_trabajador', 11)->update([$field => $value]);
        $this->postJson(self::BASE.'/revision-pdf', $input)->assertUnprocessable();
        $this->assertDatabaseCount('certificado.certificado_firma', 0);
    }

    #[TestWith(['modalidad', 'OTRA'])]
    #[TestWith(['firmantes', [['id_persona_trabajador' => 999, 'orden_firma' => 1]]])]
    #[TestWith(['firmantes', [['id_persona_trabajador' => 10, 'orden_firma' => 2]]])]
    #[TestWith(['firmantes', [['id_persona_trabajador' => 10, 'orden_firma' => 1], ['id_persona_trabajador' => 10, 'orden_firma' => 2]]])]
    #[TestWith(['firmantes', [['id_persona_trabajador' => 10, 'orden_firma' => 1, 'nombre' => 'Inventado']]])]
    public function test_dynamic_preview_validates_selection_without_assigning(string $field, mixed $value): void
    {
        $input = $this->prepareUnassignedPreview();
        $input[$field] = $value;
        $this->postJson(self::BASE.'/revision-pdf', $input)->assertUnprocessable();
        $this->assertDatabaseCount('certificado.certificado_firma', 0);
    }

    private function assignForPdfRevision(): void
    {
        $this->enableRealAssignment();
        DB::statement('ALTER TABLE certificado.certificado_firma ADD COLUMN id_persona_trabajador INTEGER');
        DB::statement('ALTER TABLE certificado.certificado_documento ADD COLUMN motivo_reemision TEXT');
        DB::table('certificado.actividad_sesion')->update(['fecha' => '2026-11-01']);
        DB::table('certificado.firma')->delete();
        $document = DB::table('certificado.certificado_documento')->first();
        Storage::disk('local')->put('certificados/revisiones/aprobado.manifest', Crypt::encryptString(json_encode([
            'code' => $document->codigo_verificacion, 'hash' => $document->hash_certificado, 'message' => 'Mensaje aprobado.',
            'context' => ['tipo_certificado' => 'Capacitación', 'nombres' => 'Antón', 'apellido_paterno' => 'Talián',
                'apellido_materno' => null, 'actividad' => 'Capacitación', 'sesion' => 'Sesión 001', 'fecha_sesion' => '2026-11-01'],
        ], JSON_THROW_ON_ERROR)));
        DB::table('gestion.persona')->insert(['id_persona' => 11, 'nombres' => 'Segunda Responsable', 'apellido_paterno' => 'Real']);
        DB::table('gestion.persona_trabajador')->insert(['id_persona_trabajador' => 11, 'id_persona' => 11,
            'id_area' => 1, 'cargo' => 'Directora institucional', 'estado' => true, 'habilitado_firma_certificados' => true, 'fecha_ingreso' => '2020-01-01']);
        $this->actor(['emision.ver', 'emision.editar']);
        $this->postJson(self::BASE.'/asignar', ['modalidad' => 'SECUENCIAL', 'confirmar' => true,
            'firmantes' => [['id_persona_trabajador' => 10, 'orden_firma' => 1], ['id_persona_trabajador' => 11, 'orden_firma' => 2]]])
            ->assertOk()->assertJsonCount(2, 'data.revision_pdf.firmantes')
            ->assertJsonPath('data.revision_pdf.requiere_confirmacion', true);
    }

    public function test_approved_revision_persists_a_new_pdf_with_pending_workers_and_preserves_original_bytes(): void
    {
        $this->assignForPdfRevision();
        $original = DB::table('certificado.certificado_documento')->first();
        $bytes = Storage::disk('local')->get($original->ruta_archivo);
        $review = $this->postJson(self::BASE.'/revision-pdf')
            ->assertOk()->assertJsonPath('data.persistido', false)->assertJsonPath('data.firmantes.0.cargo', 'Cargo de prueba')->json('data');
        $pdf = base64_decode($review['vista_previa_pdf']['contenido_base64'], true);
        $text = $this->pdfText($pdf);
        self::assertStringContainsString('Responsable de prueba Apellido Materno', $text);
        self::assertStringContainsString('Segunda Responsable Real', $text);
        self::assertStringContainsString('Cargo de prueba', $text);
        self::assertStringContainsString('Directora institucional', $text);
        self::assertStringContainsString('Mensaje aprobado.', $text);
        self::assertStringContainsString($original->codigo_verificacion, $text);
        self::assertStringNotContainsString('Documento administrativo', $text);
        self::assertLessThan(strpos($text, 'Segunda Responsable Real'), strpos($text, 'Responsable de prueba Apellido Materno'));
        self::assertSame(1, preg_match_all('~/Type\s*/Page\b~', $pdf));
        self::assertMatchesRegularExpression('~/MediaBox\s*\[\s*0(?:\.0+)?\s+0(?:\.0+)?\s+841\.\d+\s+595\.\d+\s*\]~', $pdf);
        $this->assertDatabaseCount('certificado.certificado_documento', 1);
        $this->postJson(self::BASE.'/confirmar-pdf', ['token_revision' => $review['token_revision'], 'confirmar' => true])
            ->assertOk()->assertJsonPath('data.persistido', true)->assertJsonPath('data.pdf_revisado_disponible', true)
            ->assertJsonPath('data.requiere_nueva_revision_pdf', false)->assertJsonPath('data.id_certificado_documento', 1)
            ->assertJsonPath('data.codigo_verificacion', $original->codigo_verificacion);
        $new = app(CertificateDocumentWorkflow::class)->document(1, auth('jwt')->user());
        self::assertSame($bytes, Storage::disk('local')->get($original->ruta_archivo));
        self::assertSame($original->hash_certificado, DB::table('certificado.certificado_documento')->where('id_certificado_documento', 1)->value('hash_certificado'));
        self::assertSame(base64_decode($review['vista_previa_pdf']['contenido_base64'], true), Storage::disk('local')->get($new->ruta_archivo));
        $this->assertDatabaseHas('certificado.certificado_documento', ['id_certificado_documento' => 1, 'estado' => 'Vigente']);
        $this->assertDatabaseHas('certificado.certificado_firma', ['id_certificado_documento' => 1,
            'id_persona_trabajador' => 10, 'orden_firma' => 1, 'id_firma' => null, 'fecha_firma' => null]);
        $this->assertDatabaseHas('certificado.certificado', ['estado' => 'PENDIENTE_FIRMA', 'fecha_emision' => null]);
        $this->assertDatabaseCount('certificado.firma', 0);
        $download = $this->getJson(self::BASE.'/pdf-autorizado')->assertOk()->assertDownload();
        self::assertSame($pdf, file_get_contents($download->baseResponse->getFile()->getPathname()));
        $this->getJson('/api/certificados-documentos/1/archivo?flujo=firmas')->assertOk()->assertDownload();
        $this->postJson(self::BASE.'/confirmar-pdf', ['token_revision' => $review['token_revision'], 'confirmar' => true])
            ->assertOk()->assertJsonPath('data.reintento', true);
        $this->assertDatabaseCount('certificado.certificado_documento', 1);
        $this->assertDatabaseCount('certificado.certificado', 1);
        $this->assertDatabaseCount('certificado.certificado_firma', 2);
    }

    /** @return array<string, mixed> */
    private function prepareHumanResources(): array
    {
        $this->assignForPdfRevision();
        DB::table('gestion.area')->insert(['id_area' => 2, 'nombre' => 'Recursos Humanos']);
        DB::table('gestion.persona')->insert(['id_persona' => 20, 'nombres' => 'Responsable RRHH']);
        DB::table('gestion.persona_trabajador')->insert(['id_persona_trabajador' => 20, 'id_persona' => 20,
            'id_area' => 1, 'cargo' => 'Personal RRHH', 'estado' => true, 'fecha_ingreso' => '2020-01-01']);
        config(['certification.human_resources.area_ids' => []]);
        $review = $this->postJson(self::BASE.'/revision-pdf')->assertOk()->json('data');
        $this->postJson(self::BASE.'/confirmar-pdf', ['token_revision' => $review['token_revision'], 'hash_pdf' => $review['hash_pdf'],
            'confirmar' => true])->assertOk()->assertJsonPath('data.estado_administrativo', 'PREPARADO');

        return $review;
    }

    public function test_human_resources_reads_the_existing_certificate_by_activity_session_and_participant_without_writes(): void
    {
        $review = $this->prepareHumanResources();
        $management = $this->getJson(self::BASE.'/pdf-autorizado')->assertOk();
        $expected = file_get_contents($management->baseResponse->getFile()->getPathname());
        $original = (array) DB::table('certificado.certificado_documento')->first();
        $files = Storage::disk('local')->allFiles('certificados');
        $this->actor(['emision.ver'], administrator: false, id: 20);
        DB::enableQueryLog();
        $this->getJson('/api/rrhh/actividades')->assertOk()->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.id_actividad', 1)->assertJsonPath('data.data.0.total_sesiones', 1);
        $this->getJson('/api/rrhh/actividades/1/sesiones')->assertOk()->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.id_sesion', 2)->assertJsonPath('data.data.0.total_certificados', 1);
        $this->getJson('/api/rrhh/sesiones/2/certificados?id_persona=1')->assertOk()->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.nombre_completo', 'Antón Talián')->assertJsonPath('data.data.0.id_certificado', 1);
        $detail = $this->getJson('/api/rrhh/certificados/1')->assertOk()->assertJsonPath('data.pdf_confirmado', true)
            ->assertJsonPath('data.estado_certificado', 'PENDIENTE_FIRMA')->assertJsonPath('data.fecha_emision', null)
            ->assertJsonPath('data.firma_verificada', false)->assertJsonPath('data.emision_oficial_verificada', false)
            ->assertJsonPath('data.registro_entrega_disponible', false)->json('data');
        self::assertSame($original['codigo_verificacion'], $detail['codigo_verificacion']);
        self::assertSame($original['hash_certificado'], $detail['hash_pdf_original']);
        self::assertSame($review['hash_pdf'], $detail['hash_pdf']);
        self::assertSame(hash('sha256', $review['token_revision']), $detail['revision_pdf']);
        self::assertArrayNotHasKey('gestion_entrega', $detail);
        self::assertArrayNotHasKey('ruta_archivo', $detail);
        $this->getJson('/api/rrhh/certificados?id_actividad=1&id_sesion=2&id_persona=1')->assertOk()
            ->assertJsonPath('data.total', 1)->assertJsonPath('data.actividades.0.sesiones.0.certificados.0.id_certificado_documento', 1);
        foreach (['', '?descargar=1'] as $suffix) {
            $response = $this->getJson('/api/rrhh/certificados/1/pdf'.$suffix)->assertOk()
                ->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Document-SHA256', $review['hash_pdf']);
            self::assertSame($expected, file_get_contents($response->baseResponse->getFile()->getPathname()));
        }
        foreach (DB::getQueryLog() as $query) {
            self::assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace|alter|create|drop)\b/i', $query['query']);
        }
        DB::disableQueryLog();
        self::assertSame($original, (array) DB::table('certificado.certificado_documento')->first());
        self::assertSame($files, Storage::disk('local')->allFiles('certificados'));
        self::assertSame(base64_decode($review['vista_previa_pdf']['contenido_base64'], true), $expected);
        $this->assertDatabaseCount('certificado.certificado', 1);
        $this->assertDatabaseCount('certificado.certificado_documento', 1);
        $this->assertDatabaseHas('certificado.certificado', ['estado' => 'PENDIENTE_FIRMA', 'fecha_emision' => null, 'notificado' => false]);
        self::assertSame(0, DB::table('certificado.certificado_firma')->whereNotNull('fecha_firma')->count());
        self::assertSame("%PDF-1.4\nOriginal aprobado.\n%%EOF", Storage::disk('local')->get('certificados/preparados/original.pdf'));
        self::assertFalse(Schema::hasColumn('certificado.certificado_documento', 'gestion_entrega'));
    }

    public function test_human_resources_read_permissions_area_scope_and_private_pdf_integrity(): void
    {
        $this->prepareHumanResources();
        $routes = ['/api/rrhh/actividades', '/api/rrhh/actividades/1/sesiones', '/api/rrhh/sesiones/2/certificados',
            '/api/rrhh/certificados', '/api/rrhh/certificados/1', '/api/rrhh/certificados/1/pdf'];
        auth('jwt')->forgetUser();
        foreach ($routes as $route) {
            $this->getJson($route)->assertUnauthorized();
        }
        $this->actor([], administrator: false, id: 20);
        foreach ($routes as $route) {
            $this->getJson($route)->assertForbidden();
        }
        $this->actor(['emision.ver'], administrator: false, id: 20);
        $this->getJson('/api/rrhh/certificados?id_persona=999')->assertOk()->assertJsonPath('data.total', 0);
        $this->getJson('/api/rrhh/sesiones/2/certificados?id_persona=999')->assertOk()->assertJsonPath('data.total', 0);
        $this->getJson('/api/rrhh/actividades/999/sesiones')->assertNotFound();
        $this->getJson('/api/rrhh/sesiones/999/certificados')->assertNotFound();
        $this->getJson('/api/rrhh/certificados/999')->assertNotFound();
        $this->getJson('/api/rrhh/certificados/999/pdf')->assertNotFound();
        $this->getJson('/api/rrhh/actividades?page=0')->assertUnprocessable();
        $this->getJson('/api/rrhh/certificados?id_persona=1 OR 1=1')->assertUnprocessable();
        $this->getJson('/api/rrhh/sesiones/2/certificados?estado=EMITIDO')->assertUnprocessable();
        DB::table('gestion.persona_trabajador')->where('id_persona', 20)->update(['id_area' => 2]);
        $this->getJson('/api/rrhh/actividades')->assertOk()->assertJsonPath('data.total', 0);
        $this->getJson('/api/rrhh/certificados')->assertOk()->assertJsonPath('data.total', 0);
        foreach (array_diff($routes, ['/api/rrhh/actividades', '/api/rrhh/certificados']) as $route) {
            $this->getJson($route)->assertNotFound();
        }
        DB::table('gestion.persona_trabajador')->where('id_persona', 20)->update(['id_area' => 1, 'fecha_fin' => '2020-12-31']);
        $this->getJson('/api/rrhh/actividades')->assertForbidden();
        DB::table('gestion.persona_trabajador')->where('id_persona', 20)->update(['fecha_fin' => null]);
        $document = app(CertificateHumanResources::class)->artifact(1, auth('jwt')->user());
        Storage::disk('local')->put($document->ruta_archivo, '%PDF-alterado');
        $this->getJson('/api/rrhh/certificados/1/pdf')->assertConflict();
        $this->getJson('/api/certificados-documentos/list')->assertConflict();
        $this->actor([], administrator: false, id: 1);
        $this->getJson('/api/mis-certificados/1/pdf')->assertNotFound();
        $this->getJson('/api/mis-certificados')->assertOk()->assertJsonPath('data.total', 0);
        $this->getJson('/api/mis-certificados?id_persona=20')->assertUnprocessable();
    }

    public function test_read_only_human_resources_does_not_simulate_transfers_receipts_or_delivery(): void
    {
        $this->prepareHumanResources();
        $this->actor(['emision.ver', 'emision.editar'], administrator: false, id: 20);
        $original = (array) DB::table('certificado.certificado_documento')->first();
        DB::enableQueryLog();
        $this->postJson('/api/certificados-documentos/1/enviar-rrhh', ['confirmar' => true,
            'id_area_destino' => 2, 'revision_pdf' => str_repeat('a', 64)])->assertConflict();
        foreach (['recibir', 'disponible', 'entregar'] as $action) {
            $this->postJson('/api/rrhh/certificados/1/'.$action, ['confirmar' => true])->assertConflict();
        }
        $this->getJson('/api/rrhh/certificados/1/historial')->assertOk()->assertJsonPath('data.disponible', false)
            ->assertJsonCount(0, 'data.historial');
        foreach (DB::getQueryLog() as $query) {
            self::assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|alter|create)\b/i', $query['query']);
        }
        DB::disableQueryLog();
        self::assertSame($original, (array) DB::table('certificado.certificado_documento')->first());
        $this->actor(['emision.ver', 'emision.editar']);
        $this->postJson(self::BASE.'/revision-pdf')->assertOk();
        $this->getJson(self::BASE.'/pdf-autorizado')->assertOk();
    }

    public function test_unconfirmed_or_mislinked_certificates_are_not_exposed_in_human_resources(): void
    {
        $this->assignForPdfRevision();
        DB::table('gestion.persona_trabajador')->where('id_persona', 10)->update(['id_area' => 1]);
        $this->actor(['emision.ver'], administrator: false, id: 10);
        $files = Storage::disk('local')->allFiles('certificados');
        $this->getJson('/api/rrhh/actividades')->assertOk()->assertJsonPath('data.total', 0);
        $this->getJson('/api/rrhh/certificados/1')->assertConflict();
        $this->getJson('/api/rrhh/certificados/1/pdf')->assertConflict();
        self::assertSame($files, Storage::disk('local')->allFiles('certificados'));
        DB::table('certificado.participacion')->update(['id_persona' => 11]);
        $this->getJson('/api/rrhh/certificados/1')->assertNotFound();
    }

    public function test_preparation_requires_exact_hash_and_preserves_confirmation_without_delivery_schema(): void
    {
        $this->assignForPdfRevision();
        $review = $this->postJson(self::BASE.'/revision-pdf')->assertOk()->json('data');
        $confirm = ['confirmar' => true, 'token_revision' => $review['token_revision'], 'hash_pdf' => str_repeat('0', 64)];
        $this->postJson(self::BASE.'/confirmar-pdf', $confirm)->assertConflict();
        $confirm['hash_pdf'] = $review['hash_pdf'];
        $this->postJson(self::BASE.'/confirmar-pdf', $confirm)->assertOk()->assertJsonPath('data.reintento', false);
        $confirmed = $this->getJson(self::BASE.'/firmantes-asignados')->assertOk()->json('data');
        self::assertSame(1, $confirmed['documento']['id_certificado_documento']);
        self::assertSame($review['hash_pdf'], $confirmed['documento']['hash_pdf']);
        self::assertTrue($confirmed['pdf_revisado_disponible']);
        $this->travel(31)->minutes();
        $retry = $this->postJson(self::BASE.'/confirmar-pdf', $confirm)->assertOk()->assertJsonPath('data.reintento', true)
            ->assertHeader('Content-Type', 'application/json')->json('data');
        self::assertSame($retry['id_certificado'], $retry['documento']['id_certificado']);
        self::assertSame($retry['id_certificado_documento'], $retry['documento']['id_certificado_documento']);
        self::assertSame($retry['codigo_verificacion'], $retry['documento']['codigo_verificacion']);
        self::assertSame('archivo_privado_versionado', $retry['persistencia']);
        $this->getJson(self::BASE.'/pdf-autorizado')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        self::assertFalse(Schema::hasColumn('certificado.certificado_documento', 'gestion_entrega'));
    }

    public function test_existing_emitted_documents_catalog_exposes_prepared_certificate_without_issuing_or_writing(): void
    {
        $review = $this->prepareHumanResources();
        $original = (array) DB::table('certificado.certificado_documento')->first();
        $certificate = (array) DB::table('certificado.certificado')->first();
        $files = Storage::disk('local')->allFiles('certificados');
        $this->getJson(self::BASE.'/progreso')->assertOk()->assertJsonPath('data.disponible_rrhh', true)
            ->assertJsonPath('data.preparado_para_entrega', true)->assertJsonPath('data.etiqueta_estado', 'Listo para entrega');
        $this->actor(['emision.ver'], administrator: false, id: 20);
        DB::enableQueryLog();
        $this->getJson('/api/certificados-documentos/list')->assertOk()->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.id_certificado_documento', 1)->assertJsonPath('data.data.0.disponible_rrhh', true)
            ->assertJsonPath('data.data.0.estado', 'Vigente')->assertJsonPath('data.data.0.estado_certificado', 'PENDIENTE_FIRMA')
            ->assertJsonPath('data.data.0.estado_preparacion', 'PREPARADO_ENTREGA')
            ->assertJsonPath('data.data.0.etiqueta_estado', 'Listo para entrega')
            ->assertJsonPath('data.data.0.hash_pdf', $review['hash_pdf'])->assertJsonPath('data.data.0.fecha_emision', null)
            ->assertJsonPath('data.data.0.emision_oficial_verificada', false)->assertJsonCount(2, 'data.data.0.responsables_administrativos');
        $this->getJson('/api/certificados/list')->assertOk()->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.estado', 'PENDIENTE_FIRMA')->assertJsonPath('data.data.0.id_certificado', 1);
        $this->getJson('/api/certificados/detail/1')->assertOk()->assertJsonPath('data.estado', 'PENDIENTE_FIRMA')
            ->assertJsonPath('data.id_persona', 1)->assertJsonPath('data.id_participacion', 2)
            ->assertJsonPath('data.id_actividad_sesion', 2)->assertJsonPath('data.id_certificado_documento', 1)
            ->assertJsonPath('data.codigo_verificacion', $original['codigo_verificacion'])
            ->assertJsonPath('data.hash_pdf_original', $original['hash_certificado'])
            ->assertJsonPath('data.hash_pdf', $review['hash_pdf']);
        $download = $this->getJson('/api/certificados-documentos/1/archivo')->assertOk()->assertDownload();
        self::assertSame(base64_decode($review['vista_previa_pdf']['contenido_base64'], true), file_get_contents($download->baseResponse->getFile()->getPathname()));
        foreach (['id_actividad=1', 'id_sesion=2', 'id_persona=1', 'estado=PREPARADO_ENTREGA',
            'estado_preparacion=PREPARADO_ENTREGA', 'estado_certificado=PENDIENTE_FIRMA'] as $filter) {
            $this->getJson('/api/certificados-documentos/list?'.$filter)->assertOk()->assertJsonPath('data.total', 1);
        }
        foreach (['id_actividad=999', 'id_sesion=999', 'id_persona=999', 'estado_certificado=EMITIDO'] as $filter) {
            $this->getJson('/api/certificados-documentos/list?'.$filter)->assertOk()->assertJsonPath('data.total', 0);
        }
        $this->getJson('/api/certificados/list?estado=EMITIDO')->assertOk()->assertJsonPath('data.total', 0);
        $this->getJson('/api/certificados-documentos/list?page=2&per_page=1')->assertOk()->assertJsonPath('data.total', 1)
            ->assertJsonCount(0, 'data.data');
        foreach (DB::getQueryLog() as $query) {
            self::assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|alter|create|drop)\b/i', $query['query']);
        }
        DB::disableQueryLog();
        self::assertSame($original, (array) DB::table('certificado.certificado_documento')->first());
        self::assertSame($certificate, (array) DB::table('certificado.certificado')->first());
        self::assertSame($files, Storage::disk('local')->allFiles('certificados'));
    }

    public function test_existing_catalog_includes_unconfirmed_documents_but_rrhh_requires_saved_signers(): void
    {
        $this->prepareHumanResources();
        $this->actor(['emision.ver'], administrator: false, id: 20);
        $pointer = Storage::disk('local')->get('certificados/versiones/1/current');
        Storage::disk('local')->delete('certificados/versiones/1/current');
        $this->getJson('/api/certificados-documentos/list')->assertOk()->assertJsonPath('data.total', 1);
        $this->getJson('/api/certificados/list')->assertOk()->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.etiqueta_estado', 'Pendiente de firma')->assertJsonPath('data.data.0.disponible_rrhh', false);
        $this->getJson('/api/certificados/detail/1')->assertOk()->assertJsonPath('data.etiqueta_estado', 'Pendiente de firma');
        Storage::disk('local')->put('certificados/versiones/1/current', $pointer);
        DB::table('certificado.certificado_firma')->delete();
        $this->getJson('/api/certificados-documentos/list')->assertOk()->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.disponible_rrhh', false);
        $this->getJson('/api/rrhh/certificados')->assertOk()->assertJsonPath('data.total', 0);
        $this->getJson('/api/rrhh/certificados/1/pdf')->assertConflict();
        $this->assertDatabaseHas('certificado.certificado', ['estado' => 'PENDIENTE_FIRMA', 'fecha_emision' => null]);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_existing_catalog_enforces_jwt_permissions_scope_and_filter_validation(bool $pending): void
    {
        $this->prepareHumanResources();
        if ($pending) {
            Storage::disk('local')->delete('certificados/versiones/1/current');
            DB::table('certificado.certificado_firma')->delete();
        }
        $routes = ['/api/certificados-documentos/list', '/api/certificados/list', '/api/certificados/detail/1'];
        auth('jwt')->forgetUser();
        foreach ($routes as $route) {
            $this->getJson($route)->assertUnauthorized();
        }
        $this->actor([], administrator: false, id: 20);
        foreach ($routes as $route) {
            $this->getJson($route)->assertForbidden();
        }
        $this->actor(['emision.ver'], administrator: false, id: 20);
        $this->getJson('/api/certificados-documentos/list?estado=FIRMADO')->assertUnprocessable();
        $this->getJson('/api/certificados-documentos/list?id_sesion=1 OR 1=1')->assertUnprocessable();
        $this->getJson('/api/certificados/list?estado_preparacion=ENTREGADO')->assertUnprocessable();
        DB::table('gestion.persona_trabajador')->where('id_persona', 20)->update(['id_area' => 2]);
        $this->getJson('/api/certificados-documentos/list')->assertOk()->assertJsonPath('data.total', 0);
        $this->getJson('/api/certificados/list')->assertOk()->assertJsonPath('data.total', 0);
        $this->getJson('/api/certificados/detail/1')->assertNotFound();
        $this->getJson('/api/certificados-documentos/1/archivo')->assertNotFound();
    }

    public function test_certificate_catalog_preserves_legacy_records_without_creating_a_document(): void
    {
        $this->prepareHumanResources();
        DB::table('certificado.certificado_documento')->delete();
        DB::table('certificado.certificado')->update(['estado' => 'EMITIDO', 'fecha_emision' => '2026-11-01']);
        $this->actor(['emision.ver'], administrator: false, id: 20);
        $this->getJson('/api/certificados/list')->assertOk()->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.id_certificado', 1)->assertJsonPath('data.data.0.id_certificado_documento', null)
            ->assertJsonPath('data.data.0.preparado_para_entrega', false)->assertJsonPath('data.data.0.disponible_rrhh', false)
            ->assertJsonPath('data.data.0.emision_oficial_verificada', false);
        $this->getJson('/api/certificados-documentos/list')->assertOk()->assertJsonPath('data.total', 0);
        $this->assertDatabaseCount('certificado.certificado_documento', 0);
        $this->assertDatabaseCount('certificado.certificado', 1);
    }

    public function test_confirmed_prepared_state_downloads_the_same_pdf_through_the_existing_route(): void
    {
        $review = $this->prepareHumanResources();
        DB::table('certificado.certificado')->update(['estado' => 'PREPARADO']);
        $this->actor(['emision.ver'], administrator: false, id: 20);
        $this->getJson('/api/certificados-documentos/list?estado_preparacion=PREPARADO_ENTREGA')->assertOk()
            ->assertJsonPath('data.total', 0);
        $download = $this->getJson('/api/certificados-documentos/1/archivo')->assertOk()->assertDownload();
        self::assertSame(base64_decode($review['vista_previa_pdf']['contenido_base64'], true), file_get_contents($download->baseResponse->getFile()->getPathname()));
        $this->assertDatabaseHas('certificado.certificado', ['estado' => 'PREPARADO', 'fecha_emision' => null]);
    }

    private function pdfText(string $pdf): string
    {
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $streams);
        $text = [];
        foreach ($streams[1] as $stream) {
            $content = @gzuncompress($stream);
            if ($content === false) {
                $content = $stream;
            }
            preg_match_all('/BT\b(.*?)\bET/s', $content, $blocks);
            foreach ($blocks[1] as $block) {
                preg_match_all('/\(((?:\\\\.|[^\\\\)])*)\)/s', $block, $strings);
                foreach ($strings[1] as $string) {
                    $text[] = mb_convert_encoding(strtr($string, ['\\(' => '(', '\\)' => ')', '\\\\' => '\\']), 'UTF-8', 'UTF-16BE');
                }
            }
        }

        return preg_replace('/\s+/u', ' ', implode(' ', $text));
    }

    public function test_revision_preserves_approved_text_and_fails_without_a_bound_source(): void
    {
        $this->assignForPdfRevision();
        $this->postJson(self::BASE.'/revision-pdf', ['mensaje' => 'Texto diferente.'])->assertUnprocessable();
        Storage::disk('local')->delete('certificados/revisiones/aprobado.manifest');
        $this->postJson(self::BASE.'/revision-pdf')->assertConflict();
        $this->assertDatabaseCount('certificado.certificado_documento', 1);
    }

    public function test_competing_revisions_cannot_replace_a_newer_approval_and_corruption_fails_closed(): void
    {
        $this->assignForPdfRevision();
        $first = $this->postJson(self::BASE.'/revision-pdf')->assertOk()->json('data');
        $second = $this->postJson(self::BASE.'/revision-pdf')->assertOk()->json('data');
        $this->postJson(self::BASE.'/confirmar-pdf', ['token_revision' => $first['token_revision'], 'confirmar' => true])->assertOk();
        $this->postJson(self::BASE.'/confirmar-pdf', ['token_revision' => $second['token_revision'], 'confirmar' => true])->assertConflict();
        $response = $this->getJson(self::BASE.'/pdf-autorizado')->assertOk();
        self::assertSame(base64_decode($first['vista_previa_pdf']['contenido_base64'], true), file_get_contents($response->baseResponse->getFile()->getPathname()));
        $third = $this->postJson(self::BASE.'/revision-pdf')->assertOk()->json('data');
        $this->postJson(self::BASE.'/confirmar-pdf', ['token_revision' => $third['token_revision'], 'confirmar' => true])->assertOk();
        $response = $this->getJson(self::BASE.'/pdf-autorizado')->assertOk();
        self::assertSame(base64_decode($third['vista_previa_pdf']['contenido_base64'], true), file_get_contents($response->baseResponse->getFile()->getPathname()));
        Storage::disk('local')->put('certificados/versiones/1/current', 'aprobacion alterada');
        $this->getJson(self::BASE.'/pdf-autorizado')->assertConflict();
        $this->assertDatabaseCount('certificado.certificado_documento', 1);
        self::assertSame("%PDF-1.4\nOriginal aprobado.\n%%EOF", Storage::disk('local')->get('certificados/preparados/original.pdf'));
    }

    public function test_persisted_assignment_can_be_reviewed_and_confirmed_after_administrative_disabling(): void
    {
        $this->assignForPdfRevision();
        DB::table('gestion.persona_trabajador')->where('id_persona_trabajador', 10)->update(['habilitado_firma_certificados' => false]);
        $this->getJson(self::BASE.'/progreso')->assertOk()->assertJsonPath('data.pdf_confirmado', false)
            ->assertJsonPath('data.disponible_rrhh', false);
        $plan = ['modalidad' => 'SECUENCIAL', 'firmantes' => [
            ['id_persona_trabajador' => 10, 'orden_firma' => 1], ['id_persona_trabajador' => 11, 'orden_firma' => 2],
        ]];
        $review = $this->postJson(self::BASE.'/revision-pdf', $plan)->assertOk()->json('data');
        $this->postJson(self::BASE.'/revision-pdf', ['modalidad' => 'PARALELA',
            'firmantes' => [['id_persona_trabajador' => 10, 'orden_firma' => 1]]])->assertUnprocessable();
        $this->postJson(self::BASE.'/confirmar-pdf', ['token_revision' => $review['token_revision'],
            'hash_pdf' => $review['hash_pdf'], 'confirmar' => true])->assertOk()->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('success', true)->assertJsonPath('data.pdf_confirmado', true)
            ->assertJsonPath('data.disponible_rrhh', true)->assertJsonPath('data.etiqueta_estado', 'Listo para entrega');
        $this->getJson('/api/certificados/list')->assertOk()->assertJsonPath('data.data.0.pdf_confirmado', true)
            ->assertJsonPath('data.data.0.disponible_rrhh', true);
        $this->assertDatabaseHas('certificado.certificado', ['estado' => 'PENDIENTE_FIRMA', 'fecha_emision' => null]);
        $this->assertDatabaseCount('certificado.certificado_documento', 1);
        $this->assertDatabaseCount('certificado.certificado_firma', 2);
    }

    public function test_timeout_after_approval_preserves_pdf_and_recovers_the_same_token_without_pending_files(): void
    {
        $this->assignForPdfRevision();
        $review = $this->postJson(self::BASE.'/revision-pdf')->assertOk()->json('data');
        $confirm = ['token_revision' => $review['token_revision'], 'hash_pdf' => $review['hash_pdf'], 'confirmar' => true];
        $this->partialMock(CertificateDocumentVersions::class, function (MockInterface $mock): void {
            $mock->shouldReceive('approve')->once()->andReturnUsing(function (object $document, array $record, callable $validate): void {
                (new CertificateDocumentVersions)->approve($document, $record, $validate);
                throw new \RuntimeException('Timeout después de persistir la aprobación.');
            });
        });
        $this->postJson(self::BASE.'/confirmar-pdf', $confirm)->assertStatus(500);
        $this->getJson(self::BASE.'/progreso')->assertOk()->assertJsonPath('data.pdf_confirmado', true)
            ->assertJsonPath('data.disponible_rrhh', true);
        $revision = hash('sha256', $review['token_revision']);
        $path = 'certificados/versiones/1/'.$revision.'.pdf';
        $bytes = Storage::disk('local')->get($path);
        $pointer = Storage::disk('local')->get('certificados/versiones/1/current');
        Storage::disk('local')->delete(['certificados/revisiones-firmantes/'.$revision.'.manifest',
            'certificados/revisiones-firmantes/'.$revision.'.pdf']);
        $this->travel(31)->minutes();
        foreach ([1, 2] as $attempt) {
            $this->postJson(self::BASE.'/confirmar-pdf', $confirm)->assertOk()
                ->assertJsonPath('data.reintento', true)->assertJsonPath('data.pdf_confirmado', true)
                ->assertJsonPath('data.hash_pdf', $review['hash_pdf'])->assertJsonPath('data.disponible_rrhh', true);
        }
        self::assertSame($pointer, Storage::disk('local')->get('certificados/versiones/1/current'));
        self::assertSame($bytes, Storage::disk('local')->get($path));
        self::assertSame($review['hash_pdf'], hash('sha256', $bytes));
        $this->postJson(self::BASE.'/confirmar-pdf', array_replace($confirm, ['hash_pdf' => str_repeat('0', 64)]))->assertConflict();
        $this->actor(['emision.ver', 'emision.editar'], id: 9);
        $this->postJson(self::BASE.'/confirmar-pdf', $confirm)->assertForbidden();
        $this->assertDatabaseCount('certificado.certificado', 1);
        $this->assertDatabaseCount('certificado.certificado_documento', 1);
        $this->assertDatabaseCount('certificado.certificado_firma', 2);
        self::assertSame("%PDF-1.4\nOriginal aprobado.\n%%EOF", Storage::disk('local')->get('certificados/preparados/original.pdf'));
    }

    public function test_expired_pending_review_can_be_replaced_on_the_same_persisted_document(): void
    {
        $this->assignForPdfRevision();
        $first = $this->postJson(self::BASE.'/revision-pdf')->assertOk()->json('data');
        $this->travel(31)->minutes();
        $this->postJson(self::BASE.'/confirmar-pdf', ['token_revision' => $first['token_revision'],
            'hash_pdf' => $first['hash_pdf'], 'confirmar' => true])->assertConflict();
        $this->getJson(self::BASE.'/progreso')->assertOk()->assertJsonPath('data.pdf_confirmado', false);
        $next = $this->postJson(self::BASE.'/revision-pdf')->assertOk()->json('data');
        $this->postJson(self::BASE.'/confirmar-pdf', ['token_revision' => $next['token_revision'],
            'hash_pdf' => $next['hash_pdf'], 'confirmar' => true])->assertOk()->assertJsonPath('data.pdf_confirmado', true);
        $this->postJson(self::BASE.'/confirmar-pdf', ['token_revision' => $first['token_revision'], 'confirmar' => true])->assertConflict();
        $this->assertDatabaseCount('certificado.certificado', 1);
        $this->assertDatabaseCount('certificado.certificado_documento', 1);
    }

    #[TestWith(['cargo', 409])]
    #[TestWith(['pdf', 409])]
    #[TestWith(['actor', 403])]
    #[TestWith(['expired', 409])]
    #[TestWith(['permission', 403])]
    public function test_pdf_revision_rejects_changed_approval_without_creating_a_version(string $change, int $status): void
    {
        $this->assignForPdfRevision();
        $review = $this->postJson(self::BASE.'/revision-pdf', ['mensaje' => 'Mensaje aprobado.'])->assertOk()->json('data');
        match ($change) {
            'cargo' => DB::table('gestion.persona_trabajador')->update(['cargo' => 'Otro cargo']),
            'pdf' => Storage::disk('local')->put('certificados/revisiones-firmantes/'.hash('sha256', $review['token_revision']).'.pdf', '%PDF-alterado'),
            'actor' => $this->actor(id: 9),
            'expired' => $this->travel(31)->minutes(),
            'permission' => $this->actor(['emision.ver']),
        };
        $this->postJson(self::BASE.'/confirmar-pdf', ['token_revision' => $review['token_revision'], 'confirmar' => true])->assertStatus($status);
        $this->assertDatabaseCount('certificado.certificado_documento', 1);
        $this->assertDatabaseHas('certificado.certificado_documento', ['estado' => 'Vigente']);
        self::assertSame("%PDF-1.4\nOriginal aprobado.\n%%EOF", Storage::disk('local')->get('certificados/preparados/original.pdf'));
    }

    public function test_administrative_catalog_uses_workers_and_grants_without_pfx_or_password(): void
    {
        $this->enableRealAssignment();
        DB::table('certificado.firma')->delete();
        DB::statement('ALTER TABLE certificado.certificado_firma ADD COLUMN id_persona_trabajador INTEGER');
        $this->getJson('/api/funcionarios-firmantes/trabajadores')->assertOk()
            ->assertJsonCount(1, 'data.funcionarios')->assertJsonPath('data.requiere_pfx', false)
            ->assertJsonPath('data.funcionarios.0.nombre', 'Responsable de prueba Apellido Materno')
            ->assertJsonPath('data.funcionarios.0.cargo', 'Cargo de prueba')
            ->assertJsonPath('data.funcionarios.0.credencial_valida', null);
        DB::table('acceso.usuario_rol')->insert(['id_usuario' => 10, 'id_rol' => 10]);
        $this->getJson('/api/funcionarios-firmantes')->assertOk()->assertJsonCount(1, 'data.funcionarios');
        $this->actor(['emision.ver', 'emision.editar']);
        $this->postJson('/api/funcionarios-firmantes', ['id_persona_trabajador' => 10, 'id_certificado_documento' => 1,
            'modalidad' => 'PARALELA', 'orden_firma' => 1, 'confirmar' => true])->assertOk()->assertJsonPath('data.persistido', true);
        $this->getJson('/api/funcionarios-firmantes')->assertOk()->assertJsonCount(1, 'data.funcionarios')
            ->assertJsonPath('data.funcionarios.0.habilitado', true);
        DB::table('acceso.permiso')->update(['estado' => false]);
        $this->getJson('/api/funcionarios-firmantes')->assertOk()->assertJsonCount(1, 'data.funcionarios');
        $this->getJson('/api/funcionarios-firmantes/trabajadores')->assertOk()
            ->assertJsonPath('data.funcionarios.0.habilitado', true);
        $this->assertDatabaseCount('certificado.firma', 0);
        $this->assertDatabaseCount('certificado.certificado_firma', 1);
    }

    public function test_workers_keep_distinct_employment_ids_without_duplicates_from_credentials(): void
    {
        $this->enableRealAssignment();
        DB::table('gestion.persona_trabajador')->insert(['id_persona_trabajador' => 12, 'id_persona' => 10,
            'id_area' => 1, 'cargo' => 'Otro cargo', 'estado' => true, 'fecha_ingreso' => '2020-01-01']);
        DB::table('certificado.firma')->insert(['id_firma' => 12, 'id_persona_trabajador' => 10,
            'ruta_archivo_pfx' => 'privado.pfx', 'passphrase_cifrada' => 'secreto']);
        $response = $this->getJson('/api/funcionarios-firmantes/trabajadores')->assertOk()
            ->assertJsonCount(2, 'data.funcionarios')->assertJsonPath('data.catalogo_independiente', false)
            ->assertJsonPath('data.funcionarios.0.habilitacion_global', true)
            ->assertJsonPath('data.funcionarios.1.habilitacion_global', false)
            ->assertJsonPath('data.funcionarios.0.estado_laboral', 'Vigente');
        self::assertSame([10, 12], array_column($response->json('data.funcionarios'), 'id_persona_trabajador'));
        self::assertSame($response->json('data.funcionarios.0.nombre'), $response->json('data.funcionarios.1.nombre'));
        $response->assertDontSee('privado.pfx')->assertDontSee('secreto');
    }

    public function test_fourth_session_document_enters_catalog_after_remission_and_becomes_ready_after_confirmation(): void
    {
        $this->prepareUnassignedPreview();
        DB::table('gestion.persona')->insert(['id_persona' => 20, 'nombres' => 'Responsable RRHH']);
        DB::table('gestion.persona_trabajador')->insert(['id_persona_trabajador' => 20, 'id_persona' => 20,
            'id_area' => 1, 'cargo' => 'Personal RRHH', 'estado' => true, 'fecha_ingreso' => '2020-01-01']);
        config(['certification.preparation.session_type_ids' => [1]]);
        DB::table('certificado.actividad_sesion')->insert(['id_actividad_sesion' => 3, 'id_actividad' => 1,
            'nombre' => 'Sesion 002', 'fecha' => '2026-11-01', 'estado' => 'Finalizada']);
        foreach ([2, 3] as $person) {
            DB::table('gestion.persona')->insert(['id_persona' => $person, 'nombres' => 'Beneficiario '.$person, 'apellido_paterno' => 'Real']);
            DB::table('certificado.participacion')->insert(['id_participacion' => $person + 1, 'id_actividad' => 1,
                'id_persona' => $person, 'estado' => 'Activo']);
        }
        $source = (array) DB::table('certificado.certificado_documento')->first();
        $certificate = (array) DB::table('certificado.certificado')->first();
        foreach ([[2, 2, 3, 2], [3, 3, 4, 3], [6, 1, 2, 3]] as [$id, $person, $participation, $session]) {
            DB::table('certificado.certificado')->insert(array_replace($certificate, ['id_certificado' => $id,
                'id_persona' => $person, 'id_participacion' => $participation, 'id_actividad_sesion' => $session,
                'estado' => $id === 6 ? 'PREPARADO' : 'PENDIENTE_FIRMA']));
            $pdf = "%PDF-1.4\nDocumento aislado {$id}.\n%%EOF";
            $path = 'certificados/preparados/aislado-'.$id.'.pdf';
            Storage::disk('local')->put($path, $pdf);
            $code = str_pad((string) $id, 30, '0');
            DB::table('certificado.certificado_documento')->insert(array_replace($source, ['id_certificado_documento' => $id,
                'id_certificado' => $id, 'codigo_verificacion' => $code, 'ruta_archivo' => $path, 'hash_certificado' => hash('sha256', $pdf)]));
            $context = ['tipo_certificado' => 'Capacitacion', 'nombres' => $person === 1 ? 'Antón' : 'Beneficiario '.$person,
                'apellido_paterno' => $person === 1 ? 'Talián' : 'Real', 'apellido_materno' => null,
                'actividad' => 'Capacitacion', 'sesion' => $session === 3 ? 'Sesion 002' : 'Sesion 001', 'fecha_sesion' => '2026-11-01'];
            Storage::disk('local')->put('certificados/revisiones/aislado-'.$id.'.manifest', Crypt::encryptString(json_encode([
                'code' => $code, 'hash' => hash('sha256', $pdf), 'message' => 'Mensaje aprobado.', 'context' => $context,
            ], JSON_THROW_ON_ERROR)));
            DB::table('certificado.asistencia')->insert(['id_participacion' => $participation, 'id_actividad_sesion' => $session, 'estado' => 'PRESENTE']);
        }
        $input = ['modalidad' => 'PARALELA', 'firmantes' => [['id_persona_trabajador' => 10, 'orden_firma' => 1]]];
        foreach ([1, 2, 3] as $id) {
            $base = '/api/certificados-documentos/'.$id.'/firma';
            $review = $this->postJson($base.'/revision-pdf', $input)->assertOk()->json('data');
            $this->postJson($base.'/asignar', $input + ['confirmar' => true])->assertOk();
            $this->postJson($base.'/confirmar-pdf', ['token_revision' => $review['token_revision'], 'confirmar' => true])->assertOk();
        }
        $this->getJson('/api/certificados/list')->assertOk()->assertJsonPath('data.total', 3);
        $before = DB::table('certificado.certificado_documento')->whereIn('id_certificado_documento', [1, 2, 3])->get()->toArray();
        $base = '/api/certificados-documentos/6';
        $this->getJson('/api/certificados-documentos/detail/6?flujo=firmas')->assertOk()
            ->assertJsonPath('data.estado_certificado', 'PREPARADO')->assertJsonPath('data.facultades.remitir', true);
        $review = $this->postJson($base.'/revisar-envio', [])->assertOk()->json('data');
        $send = ['token_revision' => $review['token_revision'], 'confirmar' => true];
        $this->postJson($base.'/enviar-gerencia', $send)->assertOk()->assertJsonPath('data.reintento', false);
        $this->postJson($base.'/enviar-gerencia', $send)->assertOk()->assertJsonPath('data.reintento', true);
        $this->getJson($base.'/firma/progreso')->assertOk()->assertJsonPath('data.disponible_rrhh', false);
        $pending = $this->getJson('/api/certificados/list?id_sesion=3')->assertOk()->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.data.0.etiqueta_estado', 'Pendiente de firma')
            ->assertJsonPath('data.data.0.pdf_confirmado', false)->assertJsonPath('data.data.0.disponible_rrhh', false)->json('data.data.0');
        $originalPdf = Storage::disk('local')->get('certificados/preparados/aislado-6.pdf');
        $download = $this->getJson($base.'/archivo')->assertOk()->assertDownload();
        self::assertSame($originalPdf, file_get_contents($download->baseResponse->getFile()->getPathname()));
        $this->actor(['emision.ver'], administrator: false, id: 20);
        $this->getJson('/api/rrhh/certificados')->assertOk()->assertJsonPath('data.total', 3);
        $this->getJson('/api/rrhh/certificados/6/pdf')->assertConflict();
        $this->actor();
        $revision = $this->postJson($base.'/firma/revision-pdf', $input)->assertOk()->json('data');
        $download = $this->getJson($base.'/archivo')->assertOk()->assertDownload();
        self::assertSame($originalPdf, file_get_contents($download->baseResponse->getFile()->getPathname()));
        $this->postJson($base.'/firma/asignar', $input + ['confirmar' => true])->assertOk();
        $this->postJson($base.'/firma/asignar', $input + ['confirmar' => true])->assertOk()->assertJsonPath('data.reintento', true);
        $this->getJson('/api/certificados/list')->assertOk()->assertJsonPath('data.total', 4)
            ->assertJsonPath('data.data.0.id_certificado', 6)->assertJsonPath('data.data.0.id_certificado_documento', 6)
            ->assertJsonPath('data.data.0.etiqueta_estado', 'Pendiente de firma')->assertJsonPath('data.data.0.disponible_rrhh', false);
        $download = $this->getJson($base.'/archivo')->assertOk()->assertDownload()
            ->assertHeader('X-Document-SHA256', $pending['hash_pdf']);
        self::assertSame($originalPdf, file_get_contents($download->baseResponse->getFile()->getPathname()));
        $confirm = ['token_revision' => $revision['token_revision'], 'confirmar' => true];
        $this->postJson($base.'/firma/confirmar-pdf', $confirm)->assertOk();
        $this->postJson($base.'/firma/confirmar-pdf', $confirm)->assertOk();
        $this->getJson($base.'/firma/progreso')->assertOk()->assertJsonPath('data.estado_preparacion', 'PREPARADO_ENTREGA')
            ->assertJsonPath('data.disponible_rrhh', true);
        $ready = $this->getJson('/api/certificados/list')->assertOk()->assertJsonPath('data.total', 4)
            ->assertJsonPath('data.data.0.etiqueta_estado', 'Listo para entrega')
            ->assertJsonPath('data.data.0.pdf_confirmado', true)->assertJsonPath('data.data.0.disponible_rrhh', true)->json('data.data.0');
        foreach (['id_certificado', 'id_certificado_documento', 'id_participacion', 'id_sesion', 'codigo_verificacion'] as $field) {
            self::assertSame($pending[$field], $ready[$field]);
        }
        $download = $this->getJson($base.'/archivo')->assertOk()->assertDownload()
            ->assertHeader('X-Document-SHA256', $ready['hash_pdf']);
        self::assertSame(base64_decode($revision['vista_previa_pdf']['contenido_base64'], true), file_get_contents($download->baseResponse->getFile()->getPathname()));
        $this->actor(['emision.ver'], administrator: false, id: 20);
        $this->getJson('/api/rrhh/certificados')->assertOk()->assertJsonPath('data.total', 4);
        $this->getJson('/api/certificados/list?id_sesion=2')->assertOk()->assertJsonPath('data.total', 2);
        $this->getJson('/api/certificados/list?id_sesion=3')->assertOk()->assertJsonPath('data.total', 2);
        self::assertSame(array_map(fn (object $row): array => (array) $row, $before),
            DB::table('certificado.certificado_documento')->whereIn('id_certificado_documento', [1, 2, 3])->get()->map(fn (object $row): array => (array) $row)->all());
        $this->assertDatabaseCount('certificado.certificado', 4);
        $this->assertDatabaseCount('certificado.certificado_documento', 4);
        $this->assertDatabaseCount('certificado.certificado_firma', 4);
        $this->assertDatabaseCount('certificado.firma', 0);
    }

    public function test_inactive_worker_is_excluded_from_future_selection_without_deleting_historical_assignments(): void
    {
        $this->prepareHumanResources();
        $assignments = DB::table('certificado.certificado_firma')->get()->map(fn (object $row): array => (array) $row)->all();
        DB::table('gestion.persona_trabajador')->where('id_persona_trabajador', 10)->update(['estado' => false]);

        $workers = $this->getJson('/api/funcionarios-firmantes/trabajadores')->assertOk()->json('data.funcionarios');

        self::assertFalse(collect($workers)->firstWhere('id_persona_trabajador', 10)['disponible_asignacion']);
        self::assertSame($assignments, DB::table('certificado.certificado_firma')->get()->map(fn (object $row): array => (array) $row)->all());
        $this->getJson('/api/certificados-documentos/1/firma/progreso')->assertOk()
            ->assertJsonCount(2, 'data.firmantes_asignados')->assertJsonPath('data.disponible_rrhh', true);
    }

    public function test_admin_enable_persists_without_a_document_or_credentials(): void
    {
        $this->enableRealAssignment();
        DB::table('certificado.firma')->delete();
        DB::table('gestion.persona_trabajador')->update(['habilitado_firma_certificados' => false]);
        $this->actor(['ponentes-firmantes.editar']);
        $this->postJson('/api/funcionarios-firmantes', ['id_persona_trabajador' => 10])->assertOk()
            ->assertJsonPath('data.persistido', true)->assertJsonPath('data.funcionario.habilitado_firma_certificados', true);
        $this->assertDatabaseHas('gestion.persona_trabajador', ['id_persona_trabajador' => 10, 'habilitado_firma_certificados' => true]);
        $this->assertDatabaseCount('certificado.certificado_firma', 0);
        $this->assertDatabaseCount('certificado.firma', 0);
    }

    public function test_worker_detail_shows_inactive_state_without_changing_history(): void
    {
        $this->prepareHumanResources();
        $before = DB::table('certificado.certificado_firma')->get()->map(fn (object $row): array => (array) $row)->all();
        DB::table('gestion.persona_trabajador')->where('id_persona_trabajador', 10)->update(['estado' => false]);
        $this->getJson('/api/funcionarios-firmantes/10')->assertOk()
            ->assertJsonPath('data.estado_laboral', 'No vigente')->assertJsonPath('data.disponible_asignacion', false);
        $this->getJson('/api/funcionarios-firmantes/999')->assertNotFound();
        $this->postJson(self::BASE.'/asignar', ['modalidad' => 'SECUENCIAL', 'confirmar' => true,
            'firmantes' => [['id_persona_trabajador' => 10, 'orden_firma' => 1], ['id_persona_trabajador' => 11, 'orden_firma' => 2]]])
            ->assertOk()->assertJsonPath('data.reintento', true);
        self::assertSame($before, DB::table('certificado.certificado_firma')->get()->map(fn (object $row): array => (array) $row)->all());
    }

    public function test_worker_detail_requires_authentication_permission_and_labor_scope(): void
    {
        $this->getJson('/api/funcionarios-firmantes/10')->assertUnauthorized();
        $this->enableRealAssignment();
        $this->actor([]);
        $this->getJson('/api/funcionarios-firmantes/10')->assertForbidden();
        $this->actor(['ponentes-firmantes.editar'], false, 8);
        $this->getJson('/api/funcionarios-firmantes/10')->assertNotFound();
        $this->actor(['ponentes-firmantes.editar'], false, 10);
        $this->getJson('/api/funcionarios-firmantes/10')->assertOk()
            ->assertJsonPath('data.id_persona_trabajador', 10)->assertJsonPath('data.habilitacion_global', true);
        $this->postJson('/api/funcionarios-firmantes', ['id_persona_trabajador' => 10,
            'id_certificado_documento' => 1, 'orden_firma' => 1, 'modalidad' => 'PARALELA', 'confirmar' => true])->assertForbidden();
        $this->assertDatabaseCount('certificado.certificado_firma', 0);
    }

    public function test_administrative_catalog_enforces_authentication_permissions_and_area_scope(): void
    {
        $this->getJson('/api/funcionarios-firmantes')->assertUnauthorized();
        $this->enableRealAssignment();
        $this->actor([]);
        $this->getJson('/api/funcionarios-firmantes')->assertForbidden();
        $this->actor(['emision.ver']);
        $this->postJson('/api/funcionarios-firmantes/habilitar', ['id_persona_trabajador' => 10, 'id_certificado_documento' => 1,
            'orden_firma' => 1, 'modalidad' => 'SECUENCIAL', 'confirmar' => true])->assertForbidden();
        $this->actor(['emision.ver'], false);
        $this->getJson('/api/funcionarios-firmantes/trabajadores')->assertOk()->assertJsonCount(0, 'data.funcionarios');
        $this->getJson('/api/funcionarios-firmantes?id_area=1')->assertNotFound();
        $this->actor(['emision.ver'], false, 10);
        $this->getJson('/api/funcionarios-firmantes/trabajadores?id_area=1')->assertOk()->assertJsonCount(1, 'data.funcionarios');
        $this->getJson('/api/funcionarios-firmantes?id_area=99')->assertNotFound();
    }

    #[TestWith(['estado', false])]
    #[TestWith(['fecha_ingreso', '2099-01-01'])]
    #[TestWith(['fecha_fin', '2000-01-01'])]
    #[TestWith(['cargo', '   '])]
    public function test_administrative_catalog_excludes_ineligible_workers(string $field, mixed $value): void
    {
        $this->freezeTime();
        $this->enableRealAssignment();
        DB::table('gestion.persona_trabajador')->update([$field => $value]);
        $this->getJson('/api/funcionarios-firmantes/trabajadores')->assertOk()->assertJsonCount(1, 'data.funcionarios')
            ->assertJsonPath('data.funcionarios.0.disponible_asignacion', false);
    }

    #[TestWith([1, 'PARALELA'])]
    #[TestWith([2, 'SECUENCIAL'])]
    #[TestWith([3, 'PARALELA'])]
    #[TestWith([4, 'SECUENCIAL'])]
    public function test_workers_without_any_credential_can_be_assigned_without_altering_the_pdf(int $count, string $mode): void
    {
        $this->enableRealAssignment();
        DB::statement('ALTER TABLE certificado.certificado_firma ADD COLUMN id_persona_trabajador INTEGER');
        DB::table('certificado.firma')->delete();
        $signers = [['id_persona_trabajador' => 10, 'orden_firma' => 1]];
        for ($index = 1; $index < $count; $index++) {
            $id = 10 + $index;
            DB::table('gestion.persona')->insert(['id_persona' => $id, 'nombres' => 'Trabajador '.$id, 'apellido_paterno' => 'Prueba']);
            DB::table('gestion.persona_trabajador')->insert(['id_persona_trabajador' => $id, 'id_persona' => $id,
                'id_area' => 1, 'cargo' => 'Cargo '.$id, 'estado' => true, 'habilitado_firma_certificados' => true, 'fecha_ingreso' => '2020-01-01']);
            DB::table('acceso.usuario')->insert(['id_usuario' => $id, 'id_persona' => $id, 'estado' => true]);
            DB::table('acceso.usuario_rol')->insert(['id_usuario' => $id, 'id_rol' => 10]);
            $signers[] = ['id_persona_trabajador' => $id, 'orden_firma' => $index + 1];
        }
        $hash = DB::table('certificado.certificado_documento')->value('hash_certificado');
        $this->getJson(self::BASE.'/firmantes-disponibles')->assertOk()->assertJsonCount($count, 'data')
            ->assertJsonPath('data.0.id_firma', null)->assertJsonPath('data.0.credencial_registrada', false);
        $input = ['modalidad' => $mode, 'firmantes' => $signers, 'confirmar' => true];
        $response = $this->postJson(self::BASE.'/asignar', $input)->assertOk()->assertJsonPath('data.modalidad', $mode)
            ->assertJsonCount($count, 'data.firmantes_asignados')->assertJsonPath('data.pdf_revisado_disponible', false)
            ->assertJsonPath('data.requiere_nueva_revision_pdf', true)->assertJsonPath('data.documento.firmas.registradas', 0)
            ->assertJsonPath('data.documento.firmas.pendientes', $count)
            ->assertJsonPath('data.firmantes_asignados.0.nombre', 'Responsable de prueba Apellido Materno')
            ->assertJsonPath('data.firmantes_asignados.0.cargo', 'Cargo de prueba');
        foreach ($response->json('data.firmantes_asignados') as $signer) {
            self::assertSame('Pendiente', $signer['estado']);
            self::assertNull($signer['fecha_firma']);
            self::assertNull($signer['id_firma']);
            self::assertNotEmpty($signer['nombre']);
            self::assertNotEmpty($signer['cargo']);
        }
        $this->postJson(self::BASE.'/asignar', $input)->assertOk()->assertJsonPath('data.reintento', true);
        $this->assertDatabaseCount('certificado.firma', 0);
        $this->assertDatabaseCount('certificado.certificado_documento', 1);
        $this->assertDatabaseHas('certificado.certificado', ['estado' => 'PENDIENTE_FIRMA', 'fecha_emision' => null, 'notificado' => false]);
        self::assertSame($hash, hash('sha256', Storage::disk('local')->get('certificados/preparados/original.pdf')));
        self::assertSame($hash, DB::table('certificado.certificado_documento')->value('hash_certificado'));
        $this->postJson('/api/certificados-documentos/1/emitir', ['confirmar' => true])->assertConflict();
        $this->getJson('/api/certificados-documentos/1/archivo-final')->assertConflict();
        $input['firmantes'][] = $signers[0];
        $this->postJson(self::BASE.'/asignar', $input)->assertUnprocessable();
        $this->assertDatabaseCount('certificado.certificado_firma', $count);
    }

    public function test_enabled_mayor_is_available_and_assignable_across_areas_without_pfx(): void
    {
        $this->enableRealAssignment();
        DB::statement('ALTER TABLE certificado.certificado_firma ADD COLUMN id_persona_trabajador INTEGER');
        DB::table('certificado.firma')->delete();
        DB::table('gestion.persona_trabajador')->update(['habilitado_firma_certificados' => false]);
        DB::table('gestion.area')->insert(['id_area' => 2, 'nombre' => 'Alcaldia']);
        DB::table('gestion.persona')->insert(['id_persona' => 3, 'nombres' => 'Juan', 'apellido_paterno' => 'Alcalde']);
        DB::table('gestion.persona_trabajador')->insert(['id_persona_trabajador' => 3, 'id_persona' => 3,
            'id_area' => 2, 'cargo' => 'Alcalde', 'estado' => true, 'habilitado_firma_certificados' => true,
            'fecha_ingreso' => '2026-10-27']);
        $this->actor(['emision.ver', 'emision.editar'], false, 10);
        $original = (array) DB::table('certificado.certificado_documento')->first();
        $bytes = Storage::disk('local')->get($original['ruta_archivo']);
        $this->getJson(self::BASE.'/firmantes-disponibles')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id_persona_trabajador', 3)->assertJsonPath('data.0.nombre', 'Juan Alcalde')
            ->assertJsonPath('data.0.cargo', 'Alcalde')->assertJsonPath('data.0.area', 'Alcaldia');
        $input = ['modalidad' => 'PARALELA', 'firmantes' => [['id_persona_trabajador' => 3, 'orden_firma' => 1]], 'confirmar' => true];
        DB::table('gestion.persona_trabajador')->where('id_persona_trabajador', 3)->update(['habilitado_firma_certificados' => false]);
        $this->getJson(self::BASE.'/firmantes-disponibles')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson(self::BASE.'/asignar', $input)->assertUnprocessable();
        DB::table('gestion.persona_trabajador')->where('id_persona_trabajador', 3)
            ->update(['habilitado_firma_certificados' => true, 'estado' => false]);
        $this->getJson(self::BASE.'/firmantes-disponibles')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson(self::BASE.'/asignar', $input)->assertUnprocessable();
        DB::table('gestion.persona_trabajador')->where('id_persona_trabajador', 3)->update(['estado' => true]);
        $this->postJson(self::BASE.'/asignar', $input)->assertOk()->assertJsonPath('data.persistido', true);
        $this->assertDatabaseHas('certificado.certificado_firma', ['id_persona_trabajador' => 3, 'id_firma' => null, 'fecha_firma' => null]);
        $this->assertDatabaseCount('certificado.firma', 0);
        $this->assertDatabaseCount('certificado.actividad_firma', 0);
        self::assertSame($bytes, Storage::disk('local')->get($original['ruta_archivo']));
        self::assertSame($original['hash_certificado'], DB::table('certificado.certificado_documento')->value('hash_certificado'));
        $this->actor(['emision.ver']);
        $this->getJson(self::BASE.'/firmantes-disponibles')->assertForbidden();
        $this->actor(['emision.ver', 'emision.editar'], false, 3);
        $this->getJson(self::BASE.'/firmantes-disponibles')->assertNotFound();
    }

    public function test_worker_assignment_is_blocked_without_direct_worker_schema(): void
    {
        $this->enableRealAssignment();
        $this->postJson(self::BASE.'/asignar', ['modalidad' => 'PARALELA',
            'firmantes' => [['id_persona_trabajador' => 10, 'orden_firma' => 1]], 'confirmar' => true])->assertConflict()
            ->assertJsonPath('data.codigo_bloqueo', 'ASIGNACION_DOCUMENTAL_NO_DISPONIBLE');
        $this->assertDatabaseCount('certificado.certificado_firma', 0);
    }

    public function test_assignment_rejects_ambiguous_identity_and_workers_without_a_position(): void
    {
        $this->enableRealAssignment();
        DB::statement('ALTER TABLE certificado.certificado_firma ADD COLUMN id_persona_trabajador INTEGER');
        $input = ['modalidad' => 'PARALELA', 'firmantes' => [
            ['id_persona_trabajador' => 10, 'id_firma' => 10, 'orden_firma' => 1],
        ], 'confirmar' => true];
        $this->postJson(self::BASE.'/asignar', $input)->assertUnprocessable();
        unset($input['firmantes'][0]['id_firma']);
        DB::table('gestion.persona_trabajador')->update(['cargo' => '   ']);
        $this->getJson(self::BASE.'/firmantes-disponibles')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson(self::BASE.'/asignar', $input)->assertUnprocessable();
        $this->assertDatabaseCount('certificado.certificado_firma', 0);
    }

    #[TestWith([0])]
    #[TestWith([1])]
    #[TestWith([2])]
    #[TestWith([3])]
    #[TestWith([4])]
    #[TestWith([8])]
    public function test_new_pdf_has_empty_signature_spaces_and_fits_one_landscape_page(int $count): void
    {
        config(['certification.documents.verification_base_url' => 'https://municipalidad.test/verificar']);
        $context = (object) ['tipo_certificado' => 'Capacitación', 'id_actividad' => 1, 'nombres' => 'Beneficiario',
            'apellido_paterno' => 'Prueba', 'apellido_materno' => null, 'actividad' => 'Actividad', 'sesion' => 'Sesión',
            'fecha_sesion' => '2026-11-10'];
        $signers = [];
        for ($index = 1; $index <= $count; $index++) {
            $signers[] = ['nombre' => 'Funcionario de prueba '.$index, 'cargo' => 'Cargo institucional de prueba '.$index];
        }
        $generator = app(CertificateDocumentGenerator::class);
        $html = $generator->preview($context, 'Mensaje aprobado.', $signers);
        self::assertStringNotContainsString('Documento administrativo', $html);
        self::assertStringContainsString('<div class="signature-space"></div>', $html);
        self::assertSame(max(1, $count), substr_count($html, '<div class="signature-space"></div>'));
        foreach ($signers as $signer) {
            self::assertStringContainsString($signer['nombre'], $html);
            self::assertStringContainsString($signer['cargo'], $html);
        }
        $pdf = $generator->reviewPdf($context, 'Mensaje aprobado.', str_repeat('a', 30), $signers);
        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertSame(1, preg_match_all('~/Type\s*/Page\b~', $pdf));
        self::assertMatchesRegularExpression('~/MediaBox\s*\[\s*0(?:\.0+)?\s+0(?:\.0+)?\s+841\.\d+\s+595\.\d+\s*\]~', $pdf);
        $this->assertDatabaseCount('certificado.certificado_documento', 1);
    }

    private function enableRealAssignment(): void
    {
        DB::statement('DROP TABLE certificado.certificado_firma');
        DB::statement('CREATE TABLE certificado.certificado_firma (id_certificado_firma INTEGER PRIMARY KEY, id_certificado_documento INTEGER, id_firma INTEGER, orden_firma INTEGER, fecha_firma TEXT, UNIQUE(id_certificado_documento, id_firma))');
        DB::statement('ALTER TABLE certificado.certificado_documento ADD COLUMN modalidad_firma TEXT');
        DB::table('gestion.persona')->insert(['id_persona' => 10, 'nombres' => 'Responsable de prueba',
            'apellido_paterno' => 'Apellido', 'apellido_materno' => 'Materno']);
        DB::table('gestion.persona_trabajador')->insert(['id_persona_trabajador' => 10, 'id_persona' => 10,
            'id_area' => 1, 'cargo' => 'Cargo de prueba', 'estado' => true, 'habilitado_firma_certificados' => true, 'fecha_ingreso' => '2020-01-01']);
        DB::table('certificado.firma')->insert(['id_firma' => 10, 'id_persona_trabajador' => 10]);
        DB::table('acceso.usuario')->insert(['id_usuario' => 10, 'id_persona' => 10, 'estado' => true]);
        DB::table('acceso.rol')->insert(['id_rol' => 10, 'estado' => true]);
        DB::table('acceso.modulo')->insert(['id_modulo' => 10, 'estado' => true]);
        DB::table('acceso.permiso')->insert(['id_permiso' => 10, 'id_modulo' => 10, 'clave' => 'firmas.firmar', 'estado' => true]);
        DB::table('acceso.usuario_rol')->insert(['id_usuario' => 10, 'id_rol' => 10]);
        DB::table('acceso.rol_modulo')->insert(['id_modulo' => 10, 'id_rol' => 10]);
        DB::table('acceso.rol_modulo_permiso')->insert(['id_modulo' => 10, 'id_rol' => 10, 'id_permiso' => 10]);
        $this->actor();
    }

    public function test_global_authorization_does_not_follow_employment_credentials_or_signing_permissions(): void
    {
        $this->enableRealAssignment();
        DB::table('gestion.persona_trabajador')->update(['habilitado_firma_certificados' => false]);
        $this->getJson('/api/funcionarios-firmantes/10')->assertOk()
            ->assertJsonPath('data.vigente', true)->assertJsonPath('data.elegible_laboralmente', true)
            ->assertJsonPath('data.habilitado_firma_certificados', false)->assertJsonPath('data.disponible_asignacion', false);
        $this->getJson(self::BASE.'/firmantes-disponibles')->assertOk()->assertJsonCount(0, 'data');
        DB::table('certificado.firma')->delete();
        DB::statement('ALTER TABLE certificado.certificado_firma ADD COLUMN id_persona_trabajador INTEGER');
        $this->postJson(self::BASE.'/asignar', ['modalidad' => 'SECUENCIAL', 'confirmar' => true,
            'firmantes' => [['id_persona_trabajador' => 10, 'orden_firma' => 1]]])->assertUnprocessable();
        $this->assertDatabaseCount('certificado.certificado_firma', 0);
        $this->assertDatabaseHas('gestion.persona_trabajador', ['id_persona_trabajador' => 10, 'estado' => true]);
    }

    public function test_global_authorization_persists_enable_disable_reenable_and_idempotency_without_credentials(): void
    {
        $this->enableRealAssignment();
        DB::table('certificado.firma')->delete();
        DB::table('gestion.persona_trabajador')->update(['habilitado_firma_certificados' => false]);
        $before = (array) DB::table('gestion.persona_trabajador')->where('id_persona_trabajador', 10)->first();
        $this->actor(['ponentes-firmantes.editar']);
        $input = ['id_persona_trabajador' => 10];
        $this->postJson('/api/funcionarios-firmantes/habilitar', $input)->assertOk()
            ->assertJsonPath('data.persistido', true)->assertJsonPath('data.reintento', false)
            ->assertJsonPath('data.funcionario.habilitado_firma_certificados', true);
        self::assertTrue(Person_Worker::findOrFail(10)->habilitado_firma_certificados);
        $this->postJson('/api/funcionarios-firmantes/habilitar', $input)->assertOk()->assertJsonPath('data.reintento', true);
        $this->actor(['ponentes-firmantes.editar']);
        $this->getJson('/api/funcionarios-firmantes/10')->assertOk()->assertJsonPath('data.habilitado_firma_certificados', true);
        $this->deleteJson('/api/funcionarios-firmantes/10/habilitacion')->assertOk()
            ->assertJsonPath('data.persistido', true)->assertJsonPath('data.funcionario.habilitado_firma_certificados', false);
        self::assertFalse(Person_Worker::findOrFail(10)->habilitado_firma_certificados);
        $this->deleteJson('/api/funcionarios-firmantes/10/habilitacion')->assertOk()->assertJsonPath('data.reintento', true);
        $this->postJson('/api/funcionarios-firmantes/habilitar', $input)->assertOk()->assertJsonPath('data.reintento', false);
        $after = (array) DB::table('gestion.persona_trabajador')->where('id_persona_trabajador', 10)->first();
        unset($before['habilitado_firma_certificados'], $after['habilitado_firma_certificados']);
        self::assertSame($before, $after);
        $this->assertDatabaseCount('gestion.persona_trabajador', 1);
        $this->assertDatabaseCount('certificado.firma', 0);
        $this->assertDatabaseCount('certificado.certificado_firma', 0);
    }

    public function test_global_authorization_changes_require_authentication_admin_permission_and_scope(): void
    {
        $input = ['id_persona_trabajador' => 10];
        $this->postJson('/api/funcionarios-firmantes/habilitar', $input)->assertUnauthorized();
        $this->deleteJson('/api/funcionarios-firmantes/10/habilitacion')->assertUnauthorized();
        $this->enableRealAssignment();
        $this->actor(['emision.ver', 'firmas.firmar']);
        $this->postJson('/api/funcionarios-firmantes/habilitar', $input)->assertForbidden();
        $this->deleteJson('/api/funcionarios-firmantes/10/habilitacion')->assertForbidden();
        $this->actor(['ponentes-firmantes.editar'], false, 8);
        $this->postJson('/api/funcionarios-firmantes/habilitar', $input)->assertNotFound();
        $this->deleteJson('/api/funcionarios-firmantes/10/habilitacion')->assertNotFound();
        $this->actor(['ponentes-firmantes.editar']);
        $this->postJson('/api/funcionarios-firmantes/habilitar', ['id_persona_trabajador' => 999])->assertNotFound();
        $this->postJson('/api/funcionarios-firmantes/habilitar', $input + ['password' => 'prohibido'])->assertUnprocessable();
        $this->postJson('/api/funcionarios-firmantes/habilitar', ['id_persona_trabajador' => 'x'])->assertUnprocessable();
        DB::table('gestion.persona_trabajador')->update(['estado' => false]);
        $this->postJson('/api/funcionarios-firmantes/habilitar', $input)->assertConflict();
        $this->deleteJson('/api/funcionarios-firmantes/10/habilitacion')->assertOk();
        $this->assertDatabaseHas('gestion.persona_trabajador', ['id_persona_trabajador' => 10, 'estado' => false, 'habilitado_firma_certificados' => false]);
    }

    public function test_global_authorization_distinguishes_two_maria_perez_employments_and_filters_gerencia(): void
    {
        $this->enableRealAssignment();
        DB::table('gestion.persona')->where('id_persona', 10)->update(['nombres' => 'Maria', 'apellido_paterno' => 'Perez']);
        DB::table('gestion.persona_trabajador')->insert(['id_persona_trabajador' => 12, 'id_persona' => 10,
            'id_area' => 1, 'cargo' => 'Supervisora', 'estado' => true, 'fecha_ingreso' => '2020-01-01']);
        $this->getJson('/api/funcionarios-firmantes')->assertOk()->assertJsonCount(2, 'data.funcionarios')
            ->assertJsonPath('data.funcionarios.0.id_persona_trabajador', 10)
            ->assertJsonPath('data.funcionarios.1.id_persona_trabajador', 12)
            ->assertJsonPath('data.funcionarios.1.habilitado_firma_certificados', false);
        $this->getJson(self::BASE.'/firmantes-disponibles')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id_persona_trabajador', 10);
        $this->actor(['ponentes-firmantes.editar']);
        $this->postJson('/api/funcionarios-firmantes/habilitar', ['id_persona_trabajador' => 12])->assertOk();
        $this->deleteJson('/api/funcionarios-firmantes/10/habilitacion')->assertOk();
        $this->actor(['emision.ver', 'emision.editar']);
        $this->getJson(self::BASE.'/firmantes-disponibles')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id_persona_trabajador', 12);
        $this->assertDatabaseCount('gestion.persona_trabajador', 2);
    }

    public function test_global_authorization_disable_preserves_historical_assignments_and_original_pdf(): void
    {
        $this->enableRealAssignment();
        DB::statement('ALTER TABLE certificado.certificado_firma ADD COLUMN id_persona_trabajador INTEGER');
        DB::table('certificado.firma')->delete();
        DB::table('certificado.certificado_documento')->update(['modalidad_firma' => 'SECUENCIAL']);
        DB::table('certificado.certificado_firma')->insert(['id_certificado_documento' => 1,
            'id_persona_trabajador' => 10, 'id_firma' => null, 'fecha_firma' => null, 'orden_firma' => 1]);
        $original = DB::table('certificado.certificado_documento')->first();
        $bytes = Storage::disk('local')->get($original->ruta_archivo);
        $this->actor(['ponentes-firmantes.editar']);
        $this->deleteJson('/api/funcionarios-firmantes/10/habilitacion')->assertOk();
        $this->actor(['emision.ver', 'emision.editar']);
        $this->getJson(self::BASE.'/firmantes-disponibles')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(self::BASE.'/progreso')->assertOk()->assertJsonCount(1, 'data.firmantes_asignados')
            ->assertJsonPath('data.firmantes_asignados.0.id_persona_trabajador', 10)
            ->assertJsonPath('data.firmantes_asignados.0.id_firma', null);
        $this->postJson(self::BASE.'/asignar', ['modalidad' => 'PARALELA', 'confirmar' => true,
            'firmantes' => [['id_persona_trabajador' => 10, 'orden_firma' => 1]]])->assertConflict();
        $this->postJson(self::BASE.'/asignar', ['modalidad' => 'SECUENCIAL', 'confirmar' => true,
            'firmantes' => [['id_persona_trabajador' => 10, 'orden_firma' => 1]]])->assertOk()->assertJsonPath('data.reintento', true);
        $this->assertDatabaseCount('certificado.certificado_firma', 1);
        self::assertSame((array) $original, (array) DB::table('certificado.certificado_documento')->first());
        self::assertSame($bytes, Storage::disk('local')->get($original->ruta_archivo));
        $this->assertDatabaseCount('certificado.firma', 0);
    }

    #[TestWith(['PARALELA'])]
    #[TestWith(['SECUENCIAL'])]
    public function test_real_assignment_persists_pending_plan_without_signing_and_retries_safely(string $mode): void
    {
        $this->enableRealAssignment();
        $input = ['modalidad' => $mode, 'firmantes' => [['id_firma' => 10, 'orden_firma' => 1]], 'confirmar' => true];
        $this->postJson(self::BASE.'/asignar', $input)->assertOk()->assertJsonPath('data.completada', true)
            ->assertJsonPath('data.modalidad', $mode)->assertJsonPath('data.reintento', false)
            ->assertJsonPath('data.firmantes_asignados.0.fecha_firma', null);
        $this->postJson(self::BASE.'/asignar', $input)->assertOk()->assertJsonPath('data.reintento', true);
        $this->assertDatabaseCount('certificado.certificado_firma', 1);
        $this->assertDatabaseHas('certificado.certificado_firma', ['id_firma' => 10, 'fecha_firma' => null]);
        $this->assertDatabaseHas('certificado.certificado', ['estado' => 'PENDIENTE_FIRMA', 'fecha_emision' => null]);
        $this->getJson(self::BASE.'/progreso')->assertOk()->assertJsonPath('data.origen_firmantes', 'documento')
            ->assertJsonPath('data.facultades.firmar', false)->assertJsonPath('data.facultades.emitir', false);
        $input['modalidad'] = $mode === 'PARALELA' ? 'SECUENCIAL' : 'PARALELA';
        $this->postJson(self::BASE.'/asignar', $input)->assertConflict();
        self::assertSame("%PDF-1.4\nOriginal aprobado.\n%%EOF", Storage::disk('local')->get('certificados/preparados/original.pdf'));
    }

    public function test_real_assignment_rejects_ineligible_signer_and_nonconsecutive_order(): void
    {
        $this->enableRealAssignment();
        $input = ['modalidad' => 'SECUENCIAL', 'firmantes' => [['id_firma' => 10, 'orden_firma' => 2]], 'confirmar' => true];
        $this->postJson(self::BASE.'/asignar', $input)->assertUnprocessable();
        $input['firmantes'][0]['orden_firma'] = 1;
        DB::table('gestion.persona_trabajador')->update(['estado' => false]);
        $this->postJson(self::BASE.'/asignar', $input)->assertUnprocessable();
        $this->assertDatabaseCount('certificado.certificado_firma', 0);
        $this->assertDatabaseHas('certificado.certificado_documento', ['modalidad_firma' => null]);
    }

    public function test_real_assignment_requires_management_permission_and_integrity(): void
    {
        $this->enableRealAssignment();
        $input = ['modalidad' => 'PARALELA', 'firmantes' => [['id_firma' => 10, 'orden_firma' => 1]], 'confirmar' => true];
        $this->actor(['emision.ver']);
        $this->postJson(self::BASE.'/asignar', $input)->assertForbidden();
        $this->actor();
        Storage::disk('local')->put('certificados/preparados/original.pdf', 'alterado');
        $this->postJson(self::BASE.'/asignar', $input)->assertConflict();
        $this->assertDatabaseCount('certificado.certificado_firma', 0);
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null, 'app.key' => 'base64:'.base64_encode(str_repeat('b', 32)),
            'certification.stage_b.demo_enabled' => false]);
        DB::purge('sqlite');
        foreach (['certificado', 'gestion', 'acceso'] as $schema) {
            DB::statement("ATTACH DATABASE ':memory:' AS {$schema}");
        }
        foreach ([
            'gestion.area' => 'id_area INTEGER PRIMARY KEY, nombre TEXT',
            'gestion.persona' => 'id_persona INTEGER PRIMARY KEY, nombres TEXT, apellido_paterno TEXT, apellido_materno TEXT',
            'gestion.persona_trabajador' => 'id_persona_trabajador INTEGER PRIMARY KEY, id_persona INTEGER, id_area INTEGER, cargo TEXT, estado BOOLEAN, habilitado_firma_certificados BOOLEAN NOT NULL DEFAULT FALSE, fecha_ingreso DATE, fecha_fin DATE',
            'gestion.persona_practicante' => 'id_persona_practicante INTEGER PRIMARY KEY, id_persona INTEGER',
            'gestion.practica' => 'id_practica INTEGER PRIMARY KEY, id_persona_practicante INTEGER, id_area INTEGER',
            'certificado.reconocimiento' => 'id_reconocimiento INTEGER PRIMARY KEY, id_persona_trabajador INTEGER',
            'certificado.actividad' => 'id_actividad INTEGER PRIMARY KEY, id_area INTEGER, nombre TEXT',
            'certificado.actividad_sesion' => 'id_actividad_sesion INTEGER PRIMARY KEY, id_actividad INTEGER, nombre TEXT, fecha DATE, estado TEXT',
            'certificado.participacion' => 'id_participacion INTEGER PRIMARY KEY, id_actividad INTEGER, id_persona INTEGER, estado TEXT',
            'certificado.asistencia' => 'id_asistencia INTEGER PRIMARY KEY, id_participacion INTEGER, id_actividad_sesion INTEGER, estado TEXT',
            'certificado.tipo_certificado' => 'id_tipo_certificado INTEGER PRIMARY KEY, nombre_tipo TEXT, estado BOOLEAN',
            'certificado.certificado' => 'id_certificado INTEGER PRIMARY KEY, id_persona INTEGER, id_participacion INTEGER, id_actividad_sesion INTEGER,
                id_tipo_certificado INTEGER, id_practica INTEGER, id_reconocimiento INTEGER, estado TEXT, fecha_emision DATE, notificado BOOLEAN, fecha_notificacion TEXT',
            'certificado.certificado_documento' => 'id_certificado_documento INTEGER PRIMARY KEY, id_certificado INTEGER,
                codigo_verificacion TEXT UNIQUE, hash_certificado TEXT UNIQUE, ruta_archivo TEXT, estado TEXT, fecha_generacion TEXT',
            'certificado.firma' => 'id_firma INTEGER PRIMARY KEY, id_persona_trabajador INTEGER, ruta_archivo_pfx TEXT, passphrase_cifrada TEXT',
            'certificado.actividad_firma' => 'id_actividad_firma INTEGER PRIMARY KEY, id_actividad INTEGER, id_firma INTEGER, orden_firma INTEGER',
            'certificado.certificado_firma' => 'id_certificado_firma INTEGER PRIMARY KEY, id_certificado_documento INTEGER,
                id_firma INTEGER, orden_firma INTEGER, fecha_firma TEXT NOT NULL',
            'acceso.usuario' => 'id_usuario INTEGER PRIMARY KEY, id_persona INTEGER, estado BOOLEAN',
            'acceso.usuario_rol' => 'id_usuario INTEGER, id_rol INTEGER',
            'acceso.rol' => 'id_rol INTEGER PRIMARY KEY, nombre TEXT, estado BOOLEAN',
            'acceso.rol_modulo_permiso' => 'id_rol INTEGER, id_modulo INTEGER, id_permiso INTEGER',
            'acceso.rol_modulo' => 'id_rol INTEGER, id_modulo INTEGER',
            'acceso.modulo' => 'id_modulo INTEGER PRIMARY KEY, estado BOOLEAN',
            'acceso.permiso' => 'id_permiso INTEGER PRIMARY KEY, id_modulo INTEGER, clave TEXT, estado BOOLEAN',
        ] as $table => $columns) {
            DB::statement("CREATE TABLE {$table} ({$columns})");
        }
        Storage::fake('local');
        DB::table('gestion.persona')->insert(['id_persona' => 1, 'nombres' => 'Antón', 'apellido_paterno' => 'Talián']);
        DB::table('gestion.area')->insert(['id_area' => 1, 'nombre' => 'Área real de prueba']);
        DB::table('certificado.actividad')->insert(['id_actividad' => 1, 'id_area' => 1, 'nombre' => 'Capacitación']);
        DB::table('certificado.actividad_sesion')->insert(['id_actividad_sesion' => 2, 'id_actividad' => 1, 'nombre' => 'Sesión 001', 'estado' => 'Finalizada']);
        DB::table('certificado.participacion')->insert(['id_participacion' => 2, 'id_actividad' => 1, 'id_persona' => 1, 'estado' => 'Activo']);
        DB::table('certificado.asistencia')->insert(['id_participacion' => 2, 'id_actividad_sesion' => 2, 'estado' => 'PRESENTE']);
        DB::table('certificado.tipo_certificado')->insert(['id_tipo_certificado' => 1, 'nombre_tipo' => 'Capacitación', 'estado' => true]);
        DB::table('certificado.certificado')->insert(['id_certificado' => 1, 'id_persona' => 1, 'id_participacion' => 2,
            'id_actividad_sesion' => 2, 'id_tipo_certificado' => 1, 'estado' => 'PENDIENTE_FIRMA', 'notificado' => false]);
        $pdf = "%PDF-1.4\nOriginal aprobado.\n%%EOF";
        Storage::disk('local')->put('certificados/preparados/original.pdf', $pdf);
        DB::table('certificado.certificado_documento')->insert(['id_certificado_documento' => 1, 'id_certificado' => 1,
            'codigo_verificacion' => str_repeat('a', 30), 'hash_certificado' => hash('sha256', $pdf),
            'ruta_archivo' => 'certificados/preparados/original.pdf', 'estado' => 'Vigente', 'fecha_generacion' => '2026-11-10']);
    }

    /** @param list<string> $permissions */
    private function actor(array $permissions = ['emision.ver', 'emision.editar', 'firmas.firmar'], bool $administrator = true, int $id = 8): void
    {
        $actor = new class extends User
        {
            public array $permissions = [];

            public bool $administrator = true;

            public function permissionKeys(): array
            {
                return $this->permissions;
            }

            public function isSystemAdministrator(): bool
            {
                return $this->administrator;
            }
        };
        $actor->forceFill(['id_usuario' => $id, 'id_persona' => $id, 'estado' => true]);
        $actor->permissions = $permissions;
        $actor->administrator = $administrator;
        $actor->setRelation('roles', (new Role)->newCollection());
        $this->actingAs($actor, 'jwt');
    }

    private function enableDemo(): void
    {
        $this->app['env'] = 'local';
        config(['certification.stage_b.demo_enabled' => true]);
        $this->actor();
    }

    /** @return array<string, mixed> */
    private function assignment(string $mode = 'SECUENCIAL'): array
    {
        return ['modalidad' => $mode, 'firmantes' => [
            ['id_demo' => 'DEMO-1', 'orden_firma' => 1], ['id_demo' => 'DEMO-2', 'orden_firma' => 2],
        ], 'clave_operacion' => (string) Str::uuid(), 'confirmar' => true];
    }

    private function assertRealDocumentUnchanged(): void
    {
        $this->assertDatabaseHas('certificado.certificado', ['id_certificado' => 1, 'estado' => 'PENDIENTE_FIRMA',
            'fecha_emision' => null, 'notificado' => false]);
        $this->assertDatabaseCount('certificado.certificado_firma', 0);
        $this->assertDatabaseCount('certificado.firma', 0);
        self::assertSame("%PDF-1.4\nOriginal aprobado.\n%%EOF", Storage::disk('local')->get('certificados/preparados/original.pdf'));
    }

    public function test_progress_reports_real_schema_limits_and_zero_signers(): void
    {
        $this->actor();
        $this->getJson(self::BASE.'/progreso')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.documento.beneficiario.nombre', 'Antón Talián')
            ->assertJsonPath('data.documento.id_sesion', 2)->assertJsonPath('data.documento.estado_certificado', 'PENDIENTE_FIRMA')
            ->assertJsonPath('data.documento.asignacion_firmantes_pendiente', true)
            ->assertJsonPath('data.requisitos.firmantes_pendientes_individuales', false)
            ->assertJsonPath('data.requisitos.modalidad_documental', false)
            ->assertJsonPath('data.requisitos.vinculo_versiones', false)
            ->assertJsonPath('data.requisitos.motor_pdf_disponible', false)
            ->assertJsonPath('data.modalidad_persistida', false)->assertJsonPath('data.facultades.firmar', false);
        $this->getJson(self::BASE.'/firmantes-disponibles')->assertOk()->assertJsonCount(0, 'data');
        $this->assertRealDocumentUnchanged();
    }

    public function test_real_assignment_signing_and_emission_are_blocked_without_infrastructure(): void
    {
        $this->actor();
        $this->postJson(self::BASE.'/asignar', ['modalidad' => 'PARALELA',
            'firmantes' => [['id_firma' => 10, 'orden_firma' => 1]], 'confirmar' => true])->assertConflict()
            ->assertJsonPath('data.codigo_bloqueo', 'ASIGNACION_DOCUMENTAL_NO_DISPONIBLE');
        $this->postJson(self::BASE.'/aplicar', ['id_firma' => 10, 'confirmar' => true])->assertConflict()
            ->assertJsonPath('data.codigo_bloqueo', 'MOTOR_FIRMA_NO_DISPONIBLE');
        $this->postJson('/api/certificados-documentos/1/emitir', ['confirmar' => true])->assertConflict();
        $this->getJson('/api/certificados-documentos/1/archivo-final')->assertConflict();
        $this->assertRealDocumentUnchanged();
    }

    public function test_demo_assignment_order_idempotency_and_completion_preserve_original(): void
    {
        $this->enableDemo();
        $assignment = $this->assignment();
        $this->postJson(self::BASE.'/demo/asignar', $assignment)->assertOk()
            ->assertJsonPath('data.advertencia', 'Simulación de firma — No válida oficialmente')
            ->assertJsonPath('data.persistencia', 'archivo_privado_local')->assertJsonPath('data.modalidad', 'SECUENCIAL');
        $this->postJson(self::BASE.'/demo/asignar', $assignment)->assertOk()->assertJsonPath('data.reintento', true);
        $this->postJson(self::BASE.'/demo/simular', ['id_demo' => 'DEMO-2',
            'clave_operacion' => (string) Str::uuid(), 'confirmar' => true])->assertConflict();
        foreach (['DEMO-1', 'DEMO-2'] as $signer) {
            $payload = ['id_demo' => $signer, 'clave_operacion' => (string) Str::uuid(), 'confirmar' => true];
            $this->postJson(self::BASE.'/demo/simular', $payload)->assertOk()->assertJsonPath('data.firma_verificada', false);
            $this->postJson(self::BASE.'/demo/simular', $payload)->assertOk()->assertJsonPath('data.reintento', true);
        }
        $this->getJson(self::BASE.'/demo/progreso')->assertOk()->assertJsonPath('data.simulacion_completa', true)
            ->assertJsonPath('data.estado_real', 'PENDIENTE_FIRMA')->assertJsonPath('data.emitido', false)
            ->assertJsonPath('data.entregado', false)->assertJsonPath('data.fecha_emision', null);
        $this->getJson('/api/certificados/verificar/'.str_repeat('a', 30))->assertOk()
            ->assertJsonPath('data.puede_ver_documento', false)->assertJsonPath('data.firma_verificada', false);
        $this->assertRealDocumentUnchanged();
    }

    public function test_parallel_demo_allows_a_different_order_and_rejects_a_second_simulation(): void
    {
        $this->enableDemo();
        $this->postJson(self::BASE.'/demo/asignar', $this->assignment('PARALELA'))->assertOk();
        $payload = ['id_demo' => 'DEMO-2', 'clave_operacion' => (string) Str::uuid(), 'confirmar' => true];
        $this->postJson(self::BASE.'/demo/simular', $payload)->assertOk()->assertJsonPath('data.firmantes_demo.1.simulada', true);
        $payload['clave_operacion'] = (string) Str::uuid();
        $this->postJson(self::BASE.'/demo/simular', $payload)->assertConflict();
        $this->assertRealDocumentUnchanged();
    }

    #[TestWith(['production'])]
    #[TestWith(['testing'])]
    public function test_demo_cannot_be_enabled_outside_local_even_with_configuration(string $environment): void
    {
        $this->enableDemo();
        $this->app['env'] = $environment;
        $this->postJson(self::BASE.'/demo/asignar', $this->assignment())->assertNotFound();
        $this->assertRealDocumentUnchanged();
    }

    public function test_demo_requires_opt_in_and_rejects_duplicates_invalid_mode_and_reused_key(): void
    {
        $this->actor();
        $this->getJson(self::BASE.'/demo/progreso')->assertNotFound();
        $this->enableDemo();
        $assignment = $this->assignment();
        $duplicate = $assignment;
        $duplicate['firmantes'][1]['id_demo'] = 'DEMO-1';
        $this->postJson(self::BASE.'/demo/asignar', $duplicate)->assertUnprocessable();
        $this->postJson(self::BASE.'/demo/asignar', array_replace($assignment, ['modalidad' => 'DESCONOCIDA']))->assertUnprocessable();
        $this->postJson(self::BASE.'/demo/asignar', $assignment)->assertOk();
        $this->postJson(self::BASE.'/demo/asignar', array_replace($assignment, ['modalidad' => 'PARALELA']))->assertConflict();
        $this->assertRealDocumentUnchanged();
    }

    public function test_verification_reports_corrupt_pdf_without_claiming_a_valid_signature(): void
    {
        $this->actor();
        $this->getJson(self::BASE.'/verificacion')->assertOk()->assertJsonPath('data.integridad_verificada', true)
            ->assertJsonPath('data.firma_verificada', false);
        Storage::disk('local')->put('certificados/preparados/original.pdf', '%PDF-1.4 altered');
        $this->getJson(self::BASE.'/verificacion')->assertOk()->assertJsonPath('data.integridad_verificada', false)
            ->assertJsonPath('data.codigo_bloqueo', 'INTEGRIDAD_PDF_INVALIDA');
        $this->getJson(self::BASE.'/progreso')->assertConflict();
    }

    public function test_batch_preserves_individual_document_results_and_performs_no_transition(): void
    {
        $this->actor();
        DB::table('gestion.persona')->insert(['id_persona' => 2, 'nombres' => 'María', 'apellido_paterno' => 'Perez']);
        DB::table('certificado.actividad_sesion')->insert(['id_actividad_sesion' => 3, 'id_actividad' => 1,
            'nombre' => 'Otra sesión', 'estado' => 'Finalizada']);
        DB::table('certificado.participacion')->insert(['id_participacion' => 3, 'id_actividad' => 1, 'id_persona' => 2, 'estado' => 'Activo']);
        DB::table('certificado.asistencia')->insert(['id_participacion' => 3, 'id_actividad_sesion' => 3, 'estado' => 'PRESENTE']);
        DB::table('certificado.certificado')->insert(['id_certificado' => 2, 'id_persona' => 2, 'id_participacion' => 3,
            'id_actividad_sesion' => 3, 'id_tipo_certificado' => 1, 'estado' => 'PENDIENTE_FIRMA', 'notificado' => false]);
        Storage::disk('local')->put('certificados/preparados/segundo.pdf', '%PDF-1.4 segundo');
        DB::table('certificado.certificado_documento')->insert(['id_certificado_documento' => 2, 'id_certificado' => 2,
            'codigo_verificacion' => str_repeat('b', 30), 'hash_certificado' => hash('sha256', '%PDF-1.4 segundo'),
            'ruta_archivo' => 'certificados/preparados/segundo.pdf', 'estado' => 'Vigente', 'fecha_generacion' => '2026-11-10']);

        $this->postJson('/api/certificados-documentos/firmas/lote/progreso', ['ids_documentos' => [1, 2, 999]])->assertOk()
            ->assertJsonPath('data.lote_persistido', false)->assertJsonPath('data.resultados.0.status', 200)
            ->assertJsonPath('data.resultados.0.data.documento.id_sesion', 2)
            ->assertJsonPath('data.resultados.1.data.documento.id_sesion', 3)
            ->assertJsonPath('data.resultados.1.data.documento.beneficiario.nombre', 'María Perez')
            ->assertJsonPath('data.resultados.1.data.documento.codigo_verificacion', str_repeat('b', 30))
            ->assertJsonPath('data.resultados.2.status', 404);
        $this->assertDatabaseHas('certificado.certificado', ['id_certificado' => 2, 'estado' => 'PENDIENTE_FIRMA', 'fecha_emision' => null]);
        $this->assertRealDocumentUnchanged();
    }

    public function test_signer_candidates_require_current_worker_without_cryptographic_permission_and_hide_secrets(): void
    {
        $this->actor();
        DB::table('gestion.persona')->insert(['id_persona' => 10, 'nombres' => 'Responsable', 'apellido_paterno' => 'Autorizado']);
        DB::table('gestion.persona_trabajador')->insert(['id_persona_trabajador' => 10, 'id_persona' => 10,
            'id_area' => 1, 'cargo' => 'Cargo institucional', 'estado' => true, 'habilitado_firma_certificados' => true, 'fecha_ingreso' => '2020-01-01']);
        DB::table('certificado.firma')->insert(['id_firma' => 10, 'id_persona_trabajador' => 10,
            'ruta_archivo_pfx' => 'firmas/secreto.pfx', 'passphrase_cifrada' => 'secreto-cifrado']);
        DB::table('acceso.usuario')->insert(['id_usuario' => 10, 'id_persona' => 10, 'estado' => true]);
        DB::table('acceso.usuario_rol')->insert(['id_usuario' => 10, 'id_rol' => 10]);
        DB::table('acceso.rol')->insert(['id_rol' => 10, 'estado' => true]);
        DB::table('acceso.modulo')->insert(['id_modulo' => 1, 'estado' => true]);
        DB::table('acceso.permiso')->insert(['id_permiso' => 1, 'id_modulo' => 1, 'clave' => 'firmas.firmar', 'estado' => true]);
        DB::table('acceso.rol_modulo')->insert(['id_rol' => 10, 'id_modulo' => 1]);
        DB::table('acceso.rol_modulo_permiso')->insert(['id_rol' => 10, 'id_modulo' => 1, 'id_permiso' => 1]);

        $response = $this->getJson(self::BASE.'/firmantes-disponibles')->assertOk()
            ->assertJsonPath('data.0.id_firma', 10)->assertJsonPath('data.0.cargo', 'Cargo institucional')
            ->assertJsonPath('data.0.credencial_verificada', false);
        self::assertStringNotContainsString('secreto', $response->getContent());
        DB::table('acceso.permiso')->where('id_permiso', 1)->update(['estado' => false]);
        $this->getJson(self::BASE.'/firmantes-disponibles')->assertOk()->assertJsonCount(1, 'data');
        DB::table('gestion.persona_trabajador')->update(['estado' => false]);
        $this->getJson(self::BASE.'/firmantes-disponibles')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_authentication_permissions_area_and_formal_signing_permission_are_enforced(): void
    {
        $this->getJson(self::BASE.'/progreso')->assertUnauthorized();
        $this->actor(['emision.ver']);
        $this->getJson(self::BASE.'/firmantes-disponibles')->assertForbidden();
        $this->actor(['emision.ver', 'emision.editar']);
        $this->postJson(self::BASE.'/aplicar', ['id_firma' => 10, 'confirmar' => true])->assertForbidden();
        $this->actor(['emision.ver'], administrator: false);
        $this->getJson(self::BASE.'/progreso')->assertNotFound();
        $this->assertRealDocumentUnchanged();
    }

    public function test_demo_state_is_private_per_actor_and_tampering_is_rejected(): void
    {
        $this->enableDemo();
        $this->postJson(self::BASE.'/demo/asignar', $this->assignment())->assertOk();
        $path = 'certificados/simulaciones/8/1.json.enc';
        self::assertStringNotContainsString('Responsable DEMO', Storage::disk('local')->get($path));
        $this->actor(id: 9);
        $this->getJson(self::BASE.'/demo/progreso')->assertOk()->assertJsonCount(0, 'data.firmantes_demo');
        $this->actor();
        Storage::disk('local')->put($path, 'tampered');
        $this->getJson(self::BASE.'/demo/progreso')->assertConflict();
        $this->assertRealDocumentUnchanged();
    }

    public function test_busy_demo_returns_503_without_overwriting_its_state(): void
    {
        $this->enableDemo();
        $this->getJson(self::BASE.'/demo/progreso')->assertOk();
        $path = Storage::disk('local')->path('certificados/simulaciones/8/1.json.enc.lock');
        $lock = fopen($path, 'c+b');
        self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        try {
            $this->postJson(self::BASE.'/demo/asignar', $this->assignment())->assertStatus(503);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        Storage::disk('local')->assertMissing('certificados/simulaciones/8/1.json.enc');
        $this->assertRealDocumentUnchanged();
    }

    public function test_unverified_emitted_state_does_not_enable_public_qr_or_delivery(): void
    {
        $this->actor();
        DB::table('certificado.certificado')->where('id_certificado', 1)->update(['estado' => 'EMITIDO', 'fecha_emision' => '2026-11-10']);
        Storage::disk('local')->move('certificados/preparados/original.pdf', 'certificados/final/sin-verificar.pdf');
        DB::table('certificado.certificado_documento')->where('id_certificado_documento', 1)
            ->update(['ruta_archivo' => 'certificados/final/sin-verificar.pdf']);
        $this->getJson('/api/certificados/verificar/'.str_repeat('a', 30))->assertOk()
            ->assertJsonPath('data.estado_certificado', 'EMITIDO')->assertJsonPath('data.emision_oficial_verificada', false)
            ->assertJsonPath('data.puede_ver_documento', false);
        $this->getJson('/api/certificados-documentos/1/archivo-final')->assertConflict();
    }
}
