<?php

namespace Tests\Feature;

use App\Models\User\User;
use App\Services\CertificateDocumentGenerator;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('postgresql')]
class SessionRecognitionRecoveryPostgresTest extends TestCase
{
    public function test_concurrent_confirmation_has_a_bounded_lock_wait_and_does_not_modify_the_existing_document(): void
    {
        if (getenv('SESSION_RECOVERY_READ_ONLY') !== '1') {
            $this->markTestSkipped('Requires authorized Neon lock diagnostics without writes.');
        }
        config(['database.default' => 'pgsql', 'database.connections.pgsql.database' => 'certificados_morales', 'database.connections.pgsql.url' => null]);
        DB::purge('pgsql');
        self::assertSame('certificados_morales', DB::selectOne('select current_database() as name')->name);
        $document = DB::table('certificado.actividad_ponente as ap')
            ->join('certificado.reconocimiento as r', 'r.id_actividad_ponente', '=', 'ap.id_actividad_ponente')
            ->join('certificado.certificado as c', 'c.id_reconocimiento', '=', 'r.id_reconocimiento')
            ->join('certificado.certificado_documento as d', 'd.id_certificado', '=', 'c.id_certificado')
            ->whereNotNull('ap.id_actividad_sesion')->where('c.estado', 'PREPARADO')->where('d.estado', 'Vigente')
            ->first(['ap.id_actividad', 'ap.id_actividad_ponente', 'ap.id_actividad_sesion', 'd.*']);
        self::assertNotNull($document);
        $realDisk = Storage::disk('local');
        $manifest = null;
        foreach ($realDisk->files('certificados/revisiones') as $path) {
            if (! str_ends_with($path, '.manifest')) {
                continue;
            }
            try {
                $candidate = json_decode(Crypt::decryptString($realDisk->get($path)), true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                continue;
            }
            if (($candidate['code'] ?? null) === $document->codigo_verificacion && ($candidate['hash'] ?? null) === $document->hash_certificado) {
                $manifest = $candidate;
                break;
            }
        }
        self::assertNotNull($manifest);
        $this->actingAs(User::findOrFail($manifest['actor']));
        Storage::set('local', Storage::fake('session-recognition-contention'));
        $token = bin2hex(random_bytes(32));
        Storage::disk('local')->put('certificados/revisiones/'.hash('sha256', $token).'.manifest', Crypt::encryptString(json_encode($manifest, JSON_THROW_ON_ERROR)));
        config(['database.connections.recognition_contention' => config('database.connections.pgsql')]);
        $blocking = DB::connection('recognition_contention');
        $blocking->beginTransaction();
        $blocking->statement("SET LOCAL lock_timeout = '2s'");
        DB::connection()->beforeExecuting(function (string $sql): void {
            if (preg_match('/^\s*(insert|update|delete|alter|create|drop|truncate)\b/i', $sql) === 1) {
                throw new \RuntimeException('Historical diagnostic forbids writes.');
            }
        });
        try {
            $blocking->table('certificado.actividad')->where('id_actividad', $document->id_actividad)->lockForUpdate()->first();
            $started = hrtime(true);
            $text = $manifest['context']['texto_reconocimiento'] ?? [];
            $this->postJson('/api/certificaciones/ponente_sesion/'.$document->id_actividad_ponente.'/preparar',
                ['accion' => 'confirmar_preparacion', 'modalidad_reconocimiento' => 'sesion', 'id_sesion' => $document->id_actividad_sesion,
                    'token_revision' => $token, 'confirmar' => true, ...array_intersect_key($text, array_flip(['mensaje', 'motivo']))])
                ->assertConflict()->assertJsonPath('message', 'El origen está ocupado por otra operación; vuelva a consultar.');
            $totalMs = (hrtime(true) - $started) / 1e6;
            self::assertLessThan(15000, $totalMs);
            self::assertSame(0, DB::transactionLevel());
            self::assertSame($document->hash_certificado, hash_file('sha256', $realDisk->path($document->ruta_archivo)));
            self::assertSame('PREPARADO', DB::table('certificado.certificado')->where('id_certificado', $document->id_certificado)->value('estado'));
            fwrite(STDOUT, json_encode(['contention_total_ms' => $totalMs]).PHP_EOL);
        } finally {
            $blocking->rollBack();
            DB::purge('recognition_contention');
            Storage::set('local', $realDisk);
        }
    }

    public function test_new_dynamic_session_recognition_is_prepared_recovered_and_explicitly_remitted_with_rollback(): void
    {
        if (getenv('SESSION_RECOVERY_ROLLBACK') !== '1') {
            $this->markTestSkipped('Requires authorized Neon rollback and isolated storage.');
        }
        config(['database.default' => 'pgsql', 'database.connections.pgsql.database' => 'certificados_morales', 'database.connections.pgsql.url' => null]);
        DB::purge('pgsql');
        self::assertSame('certificados_morales', DB::selectOne('select current_database() as name')->name);
        $actorId = DB::table('acceso.usuario as u')->join('acceso.usuario_rol as ur', 'ur.id_usuario', '=', 'u.id_usuario')
            ->join('acceso.rol as r', 'r.id_rol', '=', 'ur.id_rol')->where('u.estado', true)->where('r.estado', true)
            ->whereIn('r.id_rol', config('module_permissions.administrator_role_ids', []))
            ->whereIn('r.nombre', config('module_permissions.administrator_role_names', []))->value('u.id_usuario');
        self::assertNotNull($actorId);
        $this->actingAs(User::findOrFail($actorId));
        $source = DB::table('certificado.actividad_ponente as ap')
            ->join('certificado.reconocimiento as r', 'r.id_actividad_ponente', '=', 'ap.id_actividad_ponente')
            ->whereNotNull('ap.id_actividad_sesion')->orderBy('ap.id_actividad_ponente')->first(['ap.*']);
        self::assertNotNull($source);
        $realDisk = Storage::disk('local');
        Storage::set('local', Storage::fake('session-recognition-rollback'));
        DB::beginTransaction();
        DB::statement("SET LOCAL lock_timeout = '3s'");
        try {
            $session = (array) DB::table('certificado.actividad_sesion')->where('id_actividad_sesion', $source->id_actividad_sesion)->first();
            unset($session['id_actividad_sesion']);
            $sessionId = DB::table('certificado.actividad_sesion')->insertGetId($session, 'id_actividad_sesion');
            $assignment = (array) $source;
            unset($assignment['id_actividad_ponente']);
            $assignment['id_actividad_sesion'] = $sessionId;
            $assignmentId = DB::table('certificado.actividad_ponente')->insertGetId($assignment, 'id_actividad_ponente');
            $url = '/api/certificaciones/ponente_sesion/'.$assignmentId.'/preparar';
            $input = ['modalidad_reconocimiento' => 'sesion', 'id_sesion' => $sessionId,
                'mensaje' => 'Reconocimiento de prueba aislada.', 'motivo' => 'Participación de prueba aislada.'];
            $started = hrtime(true);
            $review = $this->postJson($url, [...$input, 'accion' => 'revisar_documento'])->assertOk()->json('data');
            $previewMs = (hrtime(true) - $started) / 1e6;
            $confirmation = [...$input, 'accion' => 'confirmar_preparacion', 'confirmar' => true, 'token_revision' => $review['token_revision']];
            $started = hrtime(true);
            $prepared = $this->postJson($url, $confirmation)->assertCreated()->assertJsonPath('data.estado_flujo', 'PREPARADO')->json('data');
            $confirmMs = (hrtime(true) - $started) / 1e6;
            $this->postJson($url, $confirmation)->assertOk()->assertJsonPath('data.creado', false);
            $consult = ['accion' => 'consultar_preparacion', 'modalidad_reconocimiento' => 'sesion', 'id_sesion' => $sessionId];
            $this->getJson($url.'?'.http_build_query($consult))->assertOk()->assertJsonPath('data.recuperado', true)->assertJsonMissingPath('data.vista_previa_pdf');
            $id = $prepared['id_certificado_documento'];
            $this->get($prepared['url_documento'])->assertOk()->assertHeader('Content-Type', 'application/pdf');
            $sendReview = $this->postJson('/api/certificados-documentos/'.$id.'/revisar-envio', [])->assertOk()->json('data');
            $send = ['token_revision' => $sendReview['token_revision'], 'confirmar' => true];
            $this->postJson('/api/certificados-documentos/'.$id.'/enviar-gerencia', $send)->assertOk()->assertJsonPath('data.estado_flujo', 'PENDIENTE_FIRMA');
            $this->postJson('/api/certificados-documentos/'.$id.'/enviar-gerencia', $send)->assertOk()->assertJsonPath('data.reintento', true);
            $items = $this->getJson('/api/certificados-documentos/list?bandeja=por_firmar&id_actividad='.$source->id_actividad.'&per_page=100')->assertOk()->json('data.data');
            self::assertContains($id, array_column($items, 'id_certificado_documento'));
            self::assertSame(1, DB::table('certificado.reconocimiento')->where('id_actividad_ponente', $assignmentId)->count());
            self::assertSame(1, DB::table('certificado.certificado_documento')->where('id_certificado', $prepared['id_certificado'])->count());
            fwrite(STDOUT, json_encode(compact('previewMs', 'confirmMs')).PHP_EOL);
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            Storage::set('local', $realDisk);
        }
        self::assertFalse(DB::table('certificado.actividad_ponente')->where('id_actividad_ponente', $assignmentId)->exists());
        self::assertFalse(DB::table('certificado.certificado_documento')->where('id_certificado_documento', $id)->exists());
    }

    public function test_existing_session_documents_are_recovered_read_only_with_dynamic_identifiers(): void
    {
        if (getenv('SESSION_RECOVERY_READ_ONLY') !== '1') {
            $this->markTestSkipped('Requires authorized read-only Neon access.');
        }
        config(['database.default' => 'pgsql', 'database.connections.pgsql.database' => 'certificados_morales', 'database.connections.pgsql.url' => null]);
        DB::purge('pgsql');
        $started = hrtime(true);
        $pdo = DB::connection()->getPdo();
        $connectionMs = (hrtime(true) - $started) / 1e6;
        $pdo->beginTransaction();
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
        $pdo->exec("SET LOCAL statement_timeout = '10s'");
        try {
            self::assertSame('certificados_morales', DB::selectOne('select current_database() as name')->name);
            $actorId = DB::table('acceso.usuario as u')->join('acceso.usuario_rol as ur', 'ur.id_usuario', '=', 'u.id_usuario')
                ->join('acceso.rol as r', 'r.id_rol', '=', 'ur.id_rol')->where('u.estado', true)->where('r.estado', true)
                ->whereIn('r.id_rol', config('module_permissions.administrator_role_ids', []))
                ->whereIn('r.nombre', config('module_permissions.administrator_role_names', []))->value('u.id_usuario');
            self::assertNotNull($actorId);
            $this->actingAs(User::findOrFail($actorId));
            $documents = DB::table('certificado.actividad_ponente as ap')
                ->join('certificado.reconocimiento as r', 'r.id_actividad_ponente', '=', 'ap.id_actividad_ponente')
                ->join('certificado.certificado as c', 'c.id_reconocimiento', '=', 'r.id_reconocimiento')
                ->join('certificado.certificado_documento as d', 'd.id_certificado', '=', 'c.id_certificado')
                ->whereNotNull('ap.id_actividad_sesion')->where('d.estado', 'Vigente')->where('c.estado', '<>', 'ANULADO')
                ->orderBy('ap.id_actividad_ponente')->limit(5)->get(['ap.id_actividad_ponente', 'ap.id_actividad_sesion', 'd.*']);
            self::assertNotEmpty($documents);
            $this->partialMock(CertificateDocumentGenerator::class)->shouldNotReceive('reviewPdf');
            foreach ($documents as $document) {
                $disk = Storage::disk('local');
                $files = $disk->allFiles('certificados');
                $started = hrtime(true);
                $hash = hash_file('sha256', $disk->path($document->ruta_archivo));
                $fileMs = (hrtime(true) - $started) / 1e6;
                self::assertSame($document->hash_certificado, $hash);
                $sqlMs = 0;
                $queryCount = 0;
                DB::listen(function ($query) use (&$sqlMs, &$queryCount): void {
                    $sqlMs += $query->time;
                    $queryCount++;
                });
                $started = hrtime(true);
                $response = $this->postJson('/api/certificaciones/ponente_sesion/'.$document->id_actividad_ponente.'/preparar',
                    ['accion' => 'consultar_preparacion', 'modalidad_reconocimiento' => 'sesion', 'id_sesion' => $document->id_actividad_sesion]);
                $totalMs = (hrtime(true) - $started) / 1e6;
                $response->assertOk()->assertJsonPath('data.recuperado', true)
                    ->assertJsonPath('data.id_certificado_documento', $document->id_certificado_documento)
                    ->assertJsonMissingPath('data.vista_previa_pdf');
                $started = hrtime(true);
                json_encode($response->json(), JSON_THROW_ON_ERROR);
                $jsonMs = (hrtime(true) - $started) / 1e6;
                self::assertSame($files, $disk->allFiles('certificados'));
                self::assertSame($hash, hash_file('sha256', $disk->path($document->ruta_archivo)));
                self::assertLessThan(15000, $totalMs);
                fwrite(STDOUT, json_encode(compact('connectionMs', 'fileMs', 'sqlMs', 'queryCount', 'totalMs', 'jsonMs')).PHP_EOL);
            }
            self::assertSame('on', DB::selectOne('show transaction_read_only')->transaction_read_only);
            fwrite(STDOUT, json_encode(DB::select("select tablename,indexdef from pg_indexes where schemaname='certificado' and tablename in ('reconocimiento','certificado','certificado_documento','actividad_ponente')")).PHP_EOL);
        } finally {
            $pdo->rollBack();
        }

    }
}
