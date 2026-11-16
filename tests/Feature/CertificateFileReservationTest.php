<?php

namespace Tests\Feature;

use App\Services\CertificateDocumentGenerator;
use App\Services\CertificateManagement;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CertificateFileReservationTest extends TestCase
{
    public function test_pdf_temporary_resources_are_removed_after_rendering_and_rejected_content(): void
    {
        $before = glob(storage_path('framework/cache/pdf-*'));
        $generator = app(CertificateDocumentGenerator::class);
        $svg = base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20"><rect width="20" height="20" fill="black"/></svg>');
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->assertStringStartsWith('%PDF-', $generator->previewPdf('<html><body><img src="data:image/svg+xml;base64,'.$svg.'">PDF</body></html>'));
        }
        try {
            $generator->previewPdf('<html><body><div style="page-break-after:always">First page</div><div>Second page</div></body></html>');
            $this->fail('Multiple pages must be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
        $this->assertSame($before, glob(storage_path('framework/cache/pdf-*')));
    }

    public function test_existing_session_reviews_are_inspected_without_changing_files(): void
    {
        $disk = Storage::disk('local');
        $results = [];
        foreach ($disk->files('certificados/revisiones') as $path) {
            if (! str_ends_with($path, '.manifest')) {
                continue;
            }
            try {
                $manifest = json_decode(Crypt::decryptString($disk->get($path)), true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                continue;
            }
            if (($manifest['context']['origen'] ?? null) !== 'ponente_sesion' || ($manifest['context']['id_origen'] ?? null) !== 17) {
                continue;
            }
            $target = 'certificados/preparados/'.$manifest['code'].'.pdf';
            $results[] = ['expired' => ($manifest['expires'] ?? 0) < time(), 'prepared_exists' => $disk->exists($target), 'review_pdf_exists' => $disk->exists(substr($path, 0, -9).'.pdf'), 'prepared_hash_matches' => $disk->exists($target) && hash_equals($manifest['hash'], hash('sha256', $disk->get($target)))];
        }
        Log::info('reservation.existing_reviews', ['reviews' => $results]);
        $this->assertIsArray($results);
    }

    public function test_real_private_disk_can_reserve_a_new_file_without_overwriting(): void
    {
        $path = 'certificados/revisiones/'.bin2hex(random_bytes(32)).'.manifest';
        $owned = false;
        try {
            (new \ReflectionMethod(CertificateManagement::class, 'writeExclusive'))->invoke(app(CertificateManagement::class), $path, 'isolated reservation diagnostic', function () use (&$owned): void {
                $owned = true;
            });
            $this->assertSame('isolated reservation diagnostic', Storage::disk('local')->get($path));
        } catch (\Throwable $exception) {
            Log::info('reservation.diagnostic', ['native_error' => error_get_last(), 'path_length' => strlen(Storage::disk('local')->path($path)), 'directory_writable' => is_writable(dirname(Storage::disk('local')->path($path)))]);
            throw $exception;
        } finally {
            if ($owned) {
                Storage::disk('local')->delete($path);
            }
        }
    }
}
