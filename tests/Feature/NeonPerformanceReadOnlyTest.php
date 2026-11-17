<?php

namespace Tests\Feature;

use App\Models\User\User;
use App\Services\CertificateDocumentGenerator;
use App\Services\CertificateOrigins;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('postgresql')]
class NeonPerformanceReadOnlyTest extends TestCase
{
    public function test_real_routes_without_changing_institutional_data_or_files(): void
    {
        if (getenv('NEON_PERFORMANCE_READ_ONLY') !== '1') {
            $this->markTestSkipped('Requires explicit read-only Neon diagnostics.');
        }
        config(['database.default' => 'pgsql', 'database.connections.pgsql.database' => 'certificados_morales',
            'database.connections.pgsql.url' => null]);
        $connections = [];
        for ($run = 0; $run < 3; $run++) {
            DB::purge('pgsql');
            $start = hrtime(true);
            DB::connection()->getPdo();
            $connections[] = (hrtime(true) - $start) / 1e6;
        }
        $pdo = DB::connection()->getPdo();
        $pdo->beginTransaction();
        $pdo->exec('SET TRANSACTION READ ONLY');
        $pdo->exec("SET LOCAL statement_timeout = '15s'");
        $pdo->exec("SET LOCAL lock_timeout = '2s'");
        try {
            $this->assertSame('certificados_morales', DB::selectOne('select current_database() as name')->name);
            $this->assertSame('on', DB::selectOne('show transaction_read_only')->transaction_read_only);
            $protocol = [];
            foreach ([false, true] as $disablePrepares) {
                $pdo->setAttribute(\PDO::PGSQL_ATTR_DISABLE_PREPARES, $disablePrepares);
                for ($run = 0; $run < 3; $run++) {
                    $start = hrtime(true);
                    $row = DB::selectOne('select ?::integer as value, ?::text as text', [17, "test'parameter"]);
                    $this->assertSame(17, $row->value);
                    $this->assertSame("test'parameter", $row->text);
                    $protocol[] = ['disable_prepares' => $disablePrepares, 'ms' => (hrtime(true) - $start) / 1e6];
                }
            }
            $pdo->setAttribute(\PDO::PGSQL_ATTR_DISABLE_PREPARES, config('database.connections.pgsql.options.'.\PDO::PGSQL_ATTR_DISABLE_PREPARES, false));
            $plan = DB::select('explain (analyze, buffers, format json) select ap.id_actividad_ponente
                from certificado.actividad_ponente ap join gestion.persona_ponente pp on pp.id_persona_ponente=ap.id_persona_ponente
                join gestion.persona p on p.id_persona=pp.id_persona where ap.id_actividad_ponente=?', [17]);
            $waits = DB::select('select state, wait_event_type, wait_event, count(*) as sessions,
                max(extract(epoch from clock_timestamp()-xact_start)) as oldest_transaction_seconds,
                bool_or(cardinality(pg_blocking_pids(pid)) > 0) as blocked
                from pg_stat_activity where datname=current_database() and pid<>pg_backend_pid()
                group by state, wait_event_type, wait_event');
            $this->actingAs(User::findOrFail((int) (getenv('NEON_PERFORMANCE_USER') ?: 1)));
            $realDisk = Storage::disk('local');
            $fake = Storage::fake('neon-performance');
            $fileStart = hrtime(true);
            $readMs = 0;
            $writeMs = 0;
            $fileCount = 0;
            foreach ($realDisk->allFiles('certificados') as $path) {
                $start = hrtime(true);
                $contents = $realDisk->get($path);
                $readMs += (hrtime(true) - $start) / 1e6;
                $start = hrtime(true);
                $fake->put($path, $contents);
                $writeMs += (hrtime(true) - $start) / 1e6;
                $fileCount++;
            }
            $copyMs = (hrtime(true) - $fileStart) / 1e6;
            Storage::set('local', $fake);
            $queries = [];
            DB::listen(function (QueryExecuted $query) use (&$queries): void {
                $queries[] = ['sql' => $query->sql, 'ms' => $query->time,
                    'fingerprint' => hash('sha256', $query->sql.serialize($query->bindings))];
            });
            $samples = [];
            $assignment = (int) (getenv('NEON_PERFORMANCE_ASSIGNMENT') ?: 17);
            $session = (int) (getenv('NEON_PERFORMANCE_SESSION') ?: 5);
            foreach (['/api/auth/me', '/api/activity', '/api/activity-session',
                '/api/activity-session/'.$session.'/ponentes-certificacion',
                '/api/certificaciones/ponente_sesion/'.$assignment.'/preparar'] as $uri) {
                for ($run = 0; $run < 3; $run++) {
                    $queries = [];
                    $start = hrtime(true);
                    $response = str_ends_with($uri, '/preparar')
                        ? $this->postJson($uri, ['accion' => 'revisar_documento', 'modalidad_reconocimiento' => 'sesion', 'id_sesion' => $session])
                        : $this->getJson($uri);
                    $samples[] = ['uri' => $uri, 'run' => $run, 'status' => $response->status(),
                        'ms' => (hrtime(true) - $start) / 1e6, 'queries' => count($queries),
                        'sql_ms' => array_sum(array_column($queries, 'ms')),
                        'permission_sql_ms' => array_sum(array_column(array_filter($queries, fn (array $query): bool => str_contains($query['sql'], '"acceso"')), 'ms')),
                        'duplicates' => count($queries) - count(array_unique(array_column($queries, 'fingerprint'))),
                        'duplicate_queries' => collect($queries)->groupBy('fingerprint')->filter(fn ($group): bool => $group->count() > 1)
                            ->map(fn ($group): array => ['sql' => $group->first()['sql'], 'count' => $group->count()])->values()->all(),
                        'schema_queries' => count(array_filter($queries, fn (array $query): bool => str_contains($query['sql'], 'pg_catalog') || str_contains($query['sql'], 'information_schema'))),
                        'recovered' => $response->json('data.recuperado'), 'reused' => $response->json('data.revision_reutilizada')];
                    fwrite(STDOUT, json_encode(end($samples)).PHP_EOL);
                    $response->assertOk();
                    $this->assertSame(0, end($samples)['duplicates'], 'Repeated SQL and bindings on '.$uri);
                }
            }
            $origins = app(CertificateOrigins::class);
            $context = $origins->context('ponente_sesion', $assignment);
            $generator = new class extends CertificateDocumentGenerator
            {
                public float $pdfMs = 0;

                public function previewPdf(string $html): string
                {
                    $start = hrtime(true);
                    try {
                        return parent::previewPdf($html);
                    } finally {
                        $this->pdfMs = (hrtime(true) - $start) / 1e6;
                    }
                }
            };
            $render = [];
            for ($run = 0; $run < (getenv('NEON_PERFORMANCE_SKIP_RENDER') === '1' ? 0 : 3); $run++) {
                $start = hrtime(true);
                $qr = (new Writer(new ImageRenderer(new RendererStyle(200, 4), new SvgImageBackEnd)))
                    ->writeString('https://example.invalid/certificados/verificar/'.str_repeat('a', 30));
                $qrMs = (hrtime(true) - $start) / 1e6;
                $this->assertStringContainsString('<svg', $qr);
                $pdf = $generator->reviewPdf($context, $origins->message($context), str_repeat('a', 30), []);
                $this->assertStringStartsWith('%PDF-', $pdf);
                $render[] = ['run' => $run, 'pdf_ms' => $generator->pdfMs, 'qr_ms' => $qrMs];
            }
            fwrite(STDOUT, json_encode(['connection_ms' => $connections,
                'pooler' => str_contains(config('database.connections.pgsql.host'), '-pooler'),
                'waits' => $waits, 'protocol' => $protocol, 'plan' => $plan,
                'isolated_file_copy_ms' => $copyMs, 'file_read_ms' => $readMs, 'isolated_file_write_ms' => $writeMs,
                'file_count' => $fileCount, 'render' => $render, 'samples' => $samples], JSON_PRETTY_PRINT).PHP_EOL);
        } finally {
            $pdo->rollBack();
        }
    }
}
