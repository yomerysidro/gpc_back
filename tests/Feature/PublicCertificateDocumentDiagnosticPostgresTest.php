<?php

namespace Tests\Feature;

use App\Models\User\User;
use App\Services\CertificateDocumentGenerator;
use App\Services\CertificateDocumentVersions;
use App\Services\CertificateHumanResources;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('postgresql')]
class PublicCertificateDocumentDiagnosticPostgresTest extends TestCase
{
    public function test_real_document_storage_publication_and_original_confirmed_pdf_without_writes(): void
    {
        if (getenv('PUBLIC_CERTIFICATES_REAL_READ_ONLY') !== '1') {
            $this->markTestSkipped('Requires authorized read-only diagnostics against Neon.');
        }
        config(['database.default' => 'pgsql', 'database.connections.pgsql.database' => 'certificados_morales', 'database.connections.pgsql.url' => null, 'cache.default' => 'array']);
        DB::purge('pgsql');
        $this->assertSame('certificados_morales', DB::selectOne('select current_database() as name')->name);
        DB::beginTransaction();
        DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
        try {
            $code = '9cd9ed09841fe0ce22c917fd2ba236';
            $generator = app(CertificateDocumentGenerator::class);
            $document = $generator->documentByCode($code);
            $before = (array) $document;
            $disk = Storage::disk('local');
            $originalPath = $disk->path($document->ruta_archivo);
            Log::info('public_pdf.real_diagnostic', ['document_id' => $document->id_certificado_documento,
                'certificate_id' => $document->id_certificado, 'document_state' => $document->estado,
                'certificate_state' => $document->estado_certificado, 'disk' => 'local', 'driver' => config('filesystems.disks.local.driver'),
                'relative_path' => $document->ruta_archivo, 'exists' => is_file($originalPath), 'readable' => is_readable($originalPath),
                'original_hash' => $document->hash_certificado, 'original_hash_matches' => is_file($originalPath) && hash_equals($document->hash_certificado, hash_file('sha256', $originalPath)),
                'policy_reference_configured' => filled(config('certification.public_lookup.pdf_policy_reference')),
                'code_allowlisted' => in_array($code, config('certification.public_lookup.pdf_codes'), true),
                'config_cached' => app()->configurationIsCached()]);
            $this->assertFileIsReadable($originalPath);
            $generator->assertArtifact($document);
            $approval = app(CertificateDocumentVersions::class)->current($document, readOnly: true);
            $confirmed = collect(iterator_to_array(app(CertificateHumanResources::class)->publicConfirmedDocuments((int) $document->id_persona, $code)))->first();
            Log::info('public_pdf.real_confirmation', ['approved_revision' => $approval !== null, 'ready_for_delivery' => $confirmed !== null,
                'confirmed_relative_path' => $confirmed?->ruta_archivo, 'confirmed_hash' => $confirmed?->hash_certificado]);
            $this->assertNotNull($confirmed);
            $confirmedPath = $disk->path($confirmed->ruta_archivo);
            $this->assertFileIsReadable($confirmedPath);
            $confirmedHash = hash_file('sha256', $confirmedPath);
            $this->assertSame($confirmed->hash_certificado, $confirmedHash);
            $this->assertSame('%PDF-', file_get_contents($confirmedPath, false, null, 0, 5));
            $url = '/api/certificados/verificar/'.$code;
            $metadata = $this->getJson($url)->assertOk()->assertJsonPath('data.puede_ver_documento', false);
            $this->assertStringNotContainsString('ruta_archivo', $metadata->getContent());
            $this->assertStringNotContainsString(storage_path(), $metadata->getContent());
            $this->getJson($url.'?archivo=1')->assertForbidden();
            $this->getJson($url.'?archivo=1&descargar=1')->assertForbidden();
            $dni = DB::table('gestion.persona_documento as pd')->join('gestion.tipo_documento as td', 'td.id_tipo_documento', '=', 'pd.id_tipo_documento')
                ->where('pd.id_persona', $document->id_persona)->whereRaw('UPPER(TRIM(td.nombre)) = ?', ['DNI'])
                ->whereRaw('LOWER(TRIM(pd.estado)) = ?', ['activo'])->value('pd.numero_documento');
            $this->assertNotNull($dni);
            $items = $this->postJson('/api/portal-certificados/consultar-dni', ['dni' => $dni])->assertOk()->json('data.certificados');
            $item = collect($items)->firstWhere('codigo_verificacion', $code);
            $this->assertNotNull($item);
            $this->assertFalse($item['pdf_publicado']);

            config(['certification.public_lookup.pdf_policy_reference' => 'PRUEBA AISLADA EN MEMORIA: NO AUTORIZA PUBLICACION REAL', 'certification.public_lookup.pdf_codes' => [$code]]);
            $this->getJson($url.'?archivo=1')->assertForbidden();
            $this->getJson($url.'?archivo=1&descargar=1')->assertForbidden();
            $actor = User::findOrFail(1);
            $this->assertTrue($actor->isSystemAdministrator());
            $this->actingAs($actor)->getJson($url.'?archivo=1')->assertForbidden();
            $this->assertSame($before, (array) $generator->documentByCode($code));
            $this->assertSame($document->hash_certificado, hash_file('sha256', $originalPath));
            $this->assertSame($confirmedHash, hash_file('sha256', $confirmedPath));
            $this->assertSame('on', DB::selectOne('show transaction_read_only')->transaction_read_only);
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
    }
}
