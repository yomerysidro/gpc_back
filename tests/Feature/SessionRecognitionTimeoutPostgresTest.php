<?php

namespace Tests\Feature;

use App\Models\User\User;
use App\Services\CertificateDocumentGenerator;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

#[Group('postgresql')]
class SessionRecognitionTimeoutPostgresTest extends TestCase
{
    #[TestWith([true])]
    #[TestWith([false])]
    public function test_real_preview(bool $customize): void
    {
        if (getenv('SESSION_RECOGNITION_TIMEOUT_DIAGNOSTIC') !== '1') {
            $this->markTestSkipped('Requires real database authorization.');
        }
        config(['database.default' => 'pgsql', 'database.connections.pgsql.database' => 'certificados_morales', 'database.connections.pgsql.url' => null]);
        DB::purge('pgsql');
        $start = microtime(true);
        $this->assertSame('certificados_morales', DB::selectOne('select current_database() as name')->name);
        Log::info('preview.connection', ['ms' => (microtime(true) - $start) * 1000]);
        DB::listen(function ($query): void {
            Log::info('preview.query', ['sql' => $query->sql, 'ms' => $query->time]);
        });
        Log::info('preview.waits', ['sessions' => DB::select('select pid,state,wait_event_type,wait_event,pg_blocking_pids(pid) as blockers from pg_stat_activity where datname=current_database() and pid<>pg_backend_pid()')]);
        DB::beginTransaction();
        DB::statement("SET LOCAL lock_timeout = '3s'");
        DB::statement("SET LOCAL statement_timeout = '10s'");
        $realDisk = Storage::disk('local');
        foreach ($realDisk->files('certificados/revisiones') as $candidate) {
            if (! str_ends_with($candidate, '.manifest')) {
                continue;
            }
            try {
                $previous = json_decode(Crypt::decryptString($realDisk->get($candidate)), true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                continue;
            }
            if (($previous['context']['origen'] ?? null) !== 'ponente_sesion' || ($previous['context']['id_origen'] ?? null) !== 17) {
                continue;
            }
            $target = 'certificados/preparados/'.$previous['code'].'.pdf';
            if ($realDisk->exists($target)) {
                Log::info('reservation.real_existing_pdf', ['registered' => DB::table('certificado.certificado_documento')->where('codigo_verificacion', $previous['code'])->exists(),
                    'hash_matches' => hash_equals($previous['hash'], hash('sha256', $realDisk->get($target)))]);
            }
        }
        Storage::set('local', Storage::fake('session-preview-timeout'));
        try {
            $before = $this->snapshot();
            $existing = DB::table('certificado.certificado_documento as d')->join('certificado.certificado as c', 'c.id_certificado', '=', 'd.id_certificado')
                ->join('certificado.reconocimiento as r', 'r.id_reconocimiento', '=', 'c.id_reconocimiento')
                ->where('r.id_actividad_ponente', 17)->where('d.estado', 'Vigente')->first(['d.*']);
            if ($existing !== null) {
                $this->assertExistingPreparationIsRecovered($realDisk, $existing, $customize);
                $this->assertSame($before, $this->snapshot());

                return;
            }
            Log::info('preview.indexes', ['indexes' => DB::select("select tablename,indexdef from pg_indexes where schemaname in ('certificado','gestion') and tablename in ('actividad_ponente','actividad_sesion','persona_ponente','reconocimiento','certificado')")]);
            Log::info('preview.constraints', ['constraints' => DB::select("select conname, pg_get_constraintdef(oid) as definition from pg_constraint where conrelid in ('certificado.actividad_ponente'::regclass, 'certificado.reconocimiento'::regclass, 'gestion.persona_ponente'::regclass)")]);
            Log::info('preview.plan', ['plan' => DB::select('explain (analyze, buffers) select ap.id_actividad_ponente from certificado.actividad_ponente ap join gestion.persona_ponente pp on pp.id_persona_ponente=ap.id_persona_ponente join gestion.persona p on p.id_persona=pp.id_persona where ap.id_actividad_ponente=17')]);
            $assignment = DB::table('certificado.actividad_ponente')->where('id_actividad_ponente', 17)->first();
            $this->assertSame(3, $assignment->id_actividad);
            $this->assertSame(5, $assignment->id_actividad_sesion);
            $this->assertSame(3, DB::table('certificado.actividad_sesion')->where('id_actividad_sesion', 5)->value('id_actividad'));
            $this->assertNotNull(DB::table('gestion.persona_ponente as pp')->join('gestion.persona as p', 'p.id_persona', '=', 'pp.id_persona')->where('pp.id_persona_ponente', $assignment->id_persona_ponente)->value('p.id_persona'));
            $this->actingAs(User::findOrFail(1));
            $generator = new class extends CertificateDocumentGenerator
            {
                public string $html = '';

                public function previewPdf(string $html): string
                {
                    $this->html = $html;
                    $started = microtime(true);
                    try {
                        return parent::previewPdf($html);
                    } finally {
                        Log::info('preview.pdf', ['ms' => (microtime(true) - $started) * 1000]);
                    }
                }
            };
            $this->app->instance(CertificateDocumentGenerator::class, $generator);
            $start = microtime(true);
            $input = ['accion' => 'revisar_documento', 'modalidad_reconocimiento' => 'sesion', 'id_sesion' => 5,
                ...($customize ? ['mensaje' => 'Texto editable del reconocimiento', 'motivo' => 'Motivo editable'] : [])];
            $executionLimit = (int) ini_get('max_execution_time');
            set_time_limit(30);
            try {
                $data = $this->postJson('/api/certificaciones/ponente_sesion/17/preparar', $input)->assertOk()->assertJsonPath('data.persistido', false)->json('data');
            } finally {
                set_time_limit($executionLimit);
            }
            Log::info('preview.total', ['ms' => (microtime(true) - $start) * 1000]);
            $this->assertLessThan(30, microtime(true) - $start);
            $expectedMessage = $customize ? 'Texto editable del reconocimiento' : $data['mensaje'];
            $expectedMotive = $customize ? 'Motivo editable' : $data['motivo'];
            $this->assertSame($expectedMessage, $data['mensaje']);
            $this->assertSame($expectedMotive, $data['motivo']);
            $pdf = base64_decode($data['vista_previa_pdf']['contenido_base64'], true);
            $this->assertStringStartsWith('%PDF-', $pdf);
            $this->assertSame($data['hash_pdf'], hash('sha256', $pdf));
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $data['token_revision']);
            $manifest = json_decode(Crypt::decryptString(Storage::disk('local')->get('certificados/revisiones/'.hash('sha256', $data['token_revision']).'.manifest')), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame($data['hash_pdf'], $manifest['hash']);
            $this->assertSame(30, strlen($manifest['code']));
            $this->assertStringContainsString($manifest['code'], $generator->html);
            $this->assertStringContainsString(e($expectedMessage), $generator->html);
            $this->assertStringContainsString(e($expectedMotive), $generator->html);
            $this->assertStringContainsString('data:image/svg+xml;base64,', $generator->html);
            $this->assertSame($expectedMessage, $manifest['message']);
            $this->assertSame($expectedMotive, $manifest['context']['texto_reconocimiento']['motivo']);
            if (! $customize) {
                foreach (['actividad', 'sesion', 'fecha_sesion'] as $field) {
                    $this->assertStringContainsString($manifest['context'][$field], $data['mensaje']);
                }
            }
            $this->assertSame($before, $this->snapshot());
            $this->assertCount(4, Storage::disk('local')->allFiles('certificados/revisiones'));
            $this->assertSame('{}', DB::selectOne('select pg_blocking_pids(pg_backend_pid())::text as blockers')->blockers);
            Log::info('preview.final_waits', ['sessions' => DB::select('select pid,state,wait_event_type,wait_event,extract(epoch from now()-xact_start) as transaction_seconds,pg_blocking_pids(pid) as blockers from pg_stat_activity where datname=current_database() and pid<>pg_backend_pid()')]);
            $start = microtime(true);
            $this->postJson('/api/certificaciones/ponente_sesion/17/preparar', $input)->assertOk()->assertJsonPath('data.token_revision', $data['token_revision'])
                ->assertJsonPath('data.revision_reutilizada', true);
            Log::info('preview.repeat', ['ms' => (microtime(true) - $start) * 1000]);
            $target = 'certificados/preparados/'.$manifest['code'].'.pdf';
            Storage::disk('local')->put($target, $pdf);
            $confirm = [...$input, 'accion' => 'confirmar_preparacion', 'token_revision' => $data['token_revision'], 'confirmar' => true];
            $start = microtime(true);
            set_time_limit(30);
            try {
                $this->postJson('/api/certificaciones/ponente_sesion/17/preparar', $confirm)->assertCreated()->assertJsonPath('data.hash_pdf', $data['hash_pdf']);
            } finally {
                set_time_limit($executionLimit);
            }
            Log::info('preview.confirm', ['ms' => (microtime(true) - $start) * 1000]);
            $this->assertLessThan(30, microtime(true) - $start);
            $this->assertSame($pdf, Storage::disk('local')->get($target));
            $this->postJson('/api/certificaciones/ponente_sesion/17/preparar', $confirm)->assertOk()->assertJsonPath('data.creado', false);
            $this->postJson('/api/certificaciones/ponente_sesion/17/preparar', $input)->assertOk()->assertJsonPath('data.recuperado', true)
                ->assertJsonPath('data.hash_pdf', $data['hash_pdf']);
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
        $this->assertSame($before, $this->snapshot());
    }

    private function assertExistingPreparationIsRecovered(Filesystem $realDisk, object $document, bool $customize): void
    {
        $manifest = null;
        foreach ($realDisk->files('certificados/revisiones') as $path) {
            if (! str_ends_with($path, '.manifest')) {
                continue;
            }
            try {
                $record = json_decode(Crypt::decryptString($realDisk->get($path)), true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                continue;
            }
            if (($record['code'] ?? null) === $document->codigo_verificacion && ($record['hash'] ?? null) === $document->hash_certificado) {
                $manifest = $record;
                break;
            }
        }
        $this->assertNotNull($manifest);
        $pdf = $realDisk->get($document->ruta_archivo);
        $this->assertSame($document->hash_certificado, hash('sha256', $pdf));
        Storage::disk('local')->put($document->ruta_archivo, $pdf);
        $token = bin2hex(random_bytes(32));
        Storage::disk('local')->put('certificados/revisiones/'.hash('sha256', $token).'.manifest', Crypt::encryptString(json_encode($manifest, JSON_THROW_ON_ERROR)));
        $this->actingAs(User::findOrFail($manifest['actor']));
        $text = ['mensaje' => $manifest['message'], 'motivo' => $manifest['context']['texto_reconocimiento']['motivo'] ?? $manifest['message']];
        $input = ['accion' => 'revisar_documento', 'modalidad_reconocimiento' => 'sesion', 'id_sesion' => 5, ...($customize ? $text : [])];
        $started = microtime(true);
        $review = $this->postJson('/api/certificaciones/ponente_sesion/17/preparar', $input)->assertOk()->assertJsonPath('data.recuperado', true)
            ->assertJsonPath('data.hash_pdf', $document->hash_certificado)->json('data');
        Log::info('preview.existing_recovery', ['ms' => (microtime(true) - $started) * 1000]);
        $this->assertSame($pdf, base64_decode($review['vista_previa_pdf']['contenido_base64'], true));
        $this->assertSame($text['mensaje'], $review['mensaje']);
        $this->assertSame($text['motivo'], $review['motivo']);
        $started = microtime(true);
        $executionLimit = (int) ini_get('max_execution_time');
        set_time_limit(30);
        try {
            $this->postJson('/api/certificaciones/ponente_sesion/17/preparar', [...$input, ...$text, 'accion' => 'confirmar_preparacion', 'token_revision' => $token, 'confirmar' => true])
                ->assertOk()->assertJsonPath('data.creado', false)->assertJsonPath('data.hash_pdf', $document->hash_certificado);
        } finally {
            set_time_limit($executionLimit);
        }
        Log::info('preview.existing_confirmation', ['ms' => (microtime(true) - $started) * 1000]);
        $this->assertSame($document->hash_certificado, hash('sha256', $realDisk->get($document->ruta_archivo)));
    }

    /** @return array<string, string> */
    private function snapshot(): array
    {
        $result = [];
        foreach (['certificado.reconocimiento', 'certificado.certificado', 'certificado.certificado_documento', 'certificado.certificado_firma'] as $table) {
            $result[$table] = DB::selectOne('select md5(coalesce(jsonb_agg(to_jsonb(t) order by 1)::text, \'[]\')) as fingerprint from (select * from '.$table.' order by 1) t')->fingerprint;
        }

        return $result;
    }
}
