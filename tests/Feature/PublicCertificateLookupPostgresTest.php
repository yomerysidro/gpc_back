<?php

namespace Tests\Feature;

use App\Services\CertificateDocumentVersions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

#[Group('postgresql')]
class PublicCertificateLookupPostgresTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('PUBLIC_CERTIFICATES_REAL_READ_ONLY') !== '1') {
            $this->markTestSkipped('Requiere autorización explícita de lectura real. No crea registros.');
        }
        config(['database.default' => 'pgsql', 'database.connections.pgsql.database' => 'certificados_morales',
            'database.connections.pgsql.url' => null, 'cache.default' => 'array',
            'certification.public_lookup.pdf_policy_reference' => null, 'certification.public_lookup.pdf_codes' => []]);
        DB::purge('pgsql');
        $this->assertSame('certificados_morales', DB::selectOne('SELECT current_database() AS name')->name);
        DB::beginTransaction();
        DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
        $before = $this->fingerprint();
        $this->beforeApplicationDestroyed(function () use ($before): void {
            try {
                $this->assertSame($before, $this->fingerprint());
            } finally {
                while (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }
            }
        });
    }

    private function fingerprint(): string
    {
        $snapshot = [];
        foreach (['certificado.certificado', 'certificado.certificado_documento', 'certificado.certificado_firma', 'gestion.persona', 'gestion.persona_documento'] as $table) {
            $snapshot[$table] = DB::select('SELECT * FROM '.$table.' ORDER BY 1');
        }
        foreach (Storage::disk('local')->allFiles('certificados') as $path) {
            $snapshot['files'][$path] = hash_file('sha256', Storage::disk('local')->path($path));
        }

        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
    }

    private function confirmedIdentity(): object
    {
        $documents = DB::table('certificado.certificado_documento as d')
            ->join('certificado.certificado as c', 'c.id_certificado', '=', 'd.id_certificado')
            ->join('gestion.persona_documento as pd', 'pd.id_persona', '=', 'c.id_persona')
            ->join('gestion.tipo_documento as td', 'td.id_tipo_documento', '=', 'pd.id_tipo_documento')
            ->where('td.nombre', 'DNI')->where('c.estado', 'PENDIENTE_FIRMA')->where('d.estado', 'Vigente')
            ->orderBy('d.id_certificado_documento')->get(['d.*', 'c.id_persona', 'pd.numero_documento']);
        foreach ($documents as $document) {
            if (app(CertificateDocumentVersions::class)->current($document, readOnly: true) !== null) {
                return $document;
            }
        }
        $this->fail('Se necesita un certificado real confirmado con DNI, sin inventarlo.');
    }

    public function test_real_confirmed_documents_have_minimal_metadata_without_private_pdf_access(): void
    {
        $document = $this->confirmedIdentity();
        $response = $this->postJson('/api/certificados/consulta-dni', ['dni' => $document->numero_documento])->assertOk();
        $items = $response->json('data.certificados');
        $this->assertNotEmpty($items);
        $this->assertContains(hash_hmac('sha256', $document->codigo_verificacion, config('app.key')), array_column($items, 'referencia_publica'));
        foreach ($items as $item) {
            $this->assertSame(['referencia_publica', 'codigo_verificacion', 'origen', 'tipo_certificado', 'actividad', 'sesion', 'fecha_sesion',
                'estado_disponibilidad', 'disponible_rrhh', 'pdf_publicado', 'url_documento', 'url_descarga'], array_keys($item));
            $this->assertSame('Listo para entrega', $item['estado_disponibilidad']);
            $this->assertTrue($item['disponible_rrhh']);
            $this->assertFalse($item['pdf_publicado']);
            $this->assertNull($item['url_documento']);
        }
        $this->assertStringNotContainsString($document->numero_documento, $response->getContent());
        $this->getJson('/api/certificados/verificar/'.$document->codigo_verificacion.'?archivo=1')->assertForbidden();
    }

    public function test_real_prepared_documents_are_excluded(): void
    {
        $documents = DB::table('certificado.certificado_documento as d')
            ->join('certificado.certificado as c', 'c.id_certificado', '=', 'd.id_certificado')
            ->join('gestion.persona_documento as pd', 'pd.id_persona', '=', 'c.id_persona')
            ->join('gestion.tipo_documento as td', 'td.id_tipo_documento', '=', 'pd.id_tipo_documento')
            ->where('td.nombre', 'DNI')->where('c.estado', 'PREPARADO')->get(['d.codigo_verificacion', 'pd.numero_documento']);
        $this->assertNotEmpty($documents);
        foreach ($documents as $document) {
            $response = $this->postJson('/api/certificados/consulta-dni', ['dni' => $document->numero_documento])->assertOk();
            $this->assertNotContains(hash_hmac('sha256', $document->codigo_verificacion, config('app.key')),
                array_column($response->json('data.certificados'), 'referencia_publica'));
        }
    }

    public function test_portal_route_reuses_the_same_result_and_rate_limit(): void
    {
        $document = $this->confirmedIdentity();
        $payload = ['dni' => $document->numero_documento];
        $existing = $this->postJson('/api/certificados/consulta-dni', $payload)->assertOk()->json();
        $this->postJson('/api/portal-certificados/consultar-dni', $payload)->assertOk()->assertExactJson($existing);
        foreach ($existing['data']['certificados'] as $item) {
            $this->getJson('/api/certificados/verificar/'.$item['codigo_verificacion'])->assertOk()
                ->assertJsonPath('data.puede_ver_documento', false);
        }
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->postJson('/api/portal-certificados/consultar-dni', ['dni' => 'invalid'])->assertUnprocessable();
        }
        $this->postJson('/api/certificados/consulta-dni', ['dni' => 'invalid'])->assertStatus(429);
    }

    #[TestWith([['dni' => '123']])]
    #[TestWith([['dni' => 12345678]])]
    #[TestWith([['dni' => "' OR 1=1 --"]])]
    #[TestWith([['dni' => '12345678', 'id_persona' => 1]])]
    public function test_invalid_input_does_not_echo_identity(array $payload): void
    {
        $this->postJson('/api/certificados/consulta-dni', $payload)->assertUnprocessable()->assertExactJson([
            'success' => false, 'message' => 'Ingrese un DNI válido de ocho dígitos.', 'data' => ['certificados' => []],
        ]);
    }

    public function test_unknown_dni_returns_a_prudent_empty_response(): void
    {
        $this->assertFalse(DB::table('gestion.persona_documento')->where('numero_documento', '00000000')->exists());
        $this->postJson('/api/certificados/consulta-dni', ['dni' => '00000000'])->assertOk()->assertExactJson([
            'success' => true, 'message' => 'Consulta de disponibilidad documental.', 'data' => ['certificados' => []],
        ]);
        $this->postJson('/api/certificados/consulta-dni?dni=00000000', [])->assertUnprocessable();
    }

    public function test_rate_limit_applies_to_anonymous_requests(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/certificados/consulta-dni', ['dni' => 'invalid'])->assertUnprocessable();
        }
        $this->postJson('/api/certificados/consulta-dni', ['dni' => 'invalid'])->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_dni_limit_applies_even_when_the_source_ip_changes(): void
    {
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.$attempt])
                ->postJson('/api/certificados/consulta-dni', ['dni' => '00000000'])->assertOk();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.11'])
            ->postJson('/api/certificados/consulta-dni', ['dni' => '00000000'])->assertStatus(429);
    }

    public function test_publication_requires_policy_and_only_serves_the_approved_pdf(): void
    {
        $document = $this->confirmedIdentity();
        $code = $document->codigo_verificacion;
        config(['certification.public_lookup.pdf_policy_reference' => 'POLITICA_SOLO_EN_PROCESO_DE_PRUEBA',
            'certification.public_lookup.pdf_codes' => [$code]]);
        $approval = app(CertificateDocumentVersions::class)->current($document, readOnly: true);
        $this->getJson('/api/certificados/verificar/'.$code)->assertOk()->assertJsonPath('data.puede_ver_documento', true)
            ->assertJsonPath('data.hash_sha256', $approval['hash'])->assertJsonPath('data.firma_verificada', false)
            ->assertJsonPath('data.emision_oficial_verificada', false);
        $response = $this->get('/api/certificados/verificar/'.$code.'?archivo=1')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame($approval['hash'], hash_file('sha256', $response->baseResponse->getFile()->getPathname()));
        $download = $this->get('/api/certificados/verificar/'.$code.'?archivo=1&descargar=1')
            ->assertOk()->assertDownload('certificado-'.$code.'.pdf')
            ->assertHeader('X-Document-SHA256', $approval['hash']);
        $this->assertSame($approval['hash'], hash_file('sha256', $download->baseResponse->getFile()->getPathname()));
        $this->get('/api/certificados/verificar/'.$code, ['Accept' => 'text/html'])->assertOk();
        config(['certification.public_lookup.pdf_policy_reference' => null]);
        $this->getJson('/api/certificados/verificar/'.$code.'?archivo=1')->assertForbidden();
    }
}
