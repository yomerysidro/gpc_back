<?php

namespace Tests\Feature;

use App\Models\Role\Role;
use App\Models\User\User;
use App\Services\Auth\JwtService;
use App\Services\CertificateSigningStageB;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Process\Process;
use Tests\TestCase;

#[Group('postgresql')]
class IndividualRecognitionsNeonTest extends TestCase
{
    public function test_live_http_jwt_read_only_recognitions_and_original_documents(): void
    {
        if (getenv('INDIVIDUAL_RECOGNITIONS_HTTP_READ_ONLY') !== '1') {
            $this->markTestSkipped('Requiere servidor HTTP local y lectura explícita de Neon; no ejecuta confirmaciones ni escrituras.');
        }
        config(['database.default' => 'pgsql', 'database.connections.pgsql.database' => 'certificados_morales',
            'database.connections.pgsql.url' => null]);
        DB::purge('pgsql');
        DB::beginTransaction();
        DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
        $this->assertSame('certificados_morales', DB::selectOne('SELECT current_database() AS name')->name);
        $before = $this->snapshot();
        $disk = Storage::disk('local');
        $files = collect($disk->allFiles('certificados'))
            ->mapWithKeys(fn (string $path): array => [$path => hash_file('sha256', $disk->path($path))])->all();
        $this->beforeApplicationDestroyed(function () use ($before, $disk, $files): void {
            DB::rollBack();
            $this->assertSame($before, $this->snapshot());
            $this->assertSame($files, collect($disk->allFiles('certificados'))
                ->mapWithKeys(fn (string $path): array => [$path => hash_file('sha256', $disk->path($path))])->all());
        });
        $users = User::with('roles')->where('estado', true)->orderBy('id_usuario')->get();
        $actor = $users->first(fn (User $user): bool => $user->isSystemAdministrator()
            && ! $user->bloqueado_hasta?->isFuture()
            && array_diff(['emision.ver', 'reconocimientos.ver', 'actividades.ver', 'ponentes-firmantes.ver'], $user->permissionKeys()) === []);
        $this->assertNotNull($actor, 'Se requiere un usuario real habilitado con ámbito y permisos para los cinco registros.');
        $base = rtrim(getenv('CERTIFICATE_HTTP_BASE_URL') ?: config('app.url'), '/');
        $this->assertContains(parse_url($base, PHP_URL_HOST), ['127.0.0.1', 'localhost'], 'La cookie sólo se enviará al servidor local autorizado.');
        $client = new Client(['base_uri' => $base, 'http_errors' => false, 'allow_redirects' => false,
            'timeout' => 60, 'headers' => ['Accept' => 'application/json']]);
        $path = '/api/reconocimientos/28/evaluacion-certificacion';
        $this->assertSame(401, $client->get($path)->getStatusCode());
        fwrite(STDOUT, 'HTTP GET '.$path.' sin cookie: 401'.PHP_EOL);
        $cookie = [JwtService::COOKIE => app(JwtService::class)->createToken($actor)];
        $options = ['cookies' => CookieJar::fromArray($cookie, parse_url($base, PHP_URL_HOST))];
        foreach ([1 => null, 28 => null, 2 => 10, 24 => 22, 27 => 26] as $id => $documentId) {
            $path = '/api/reconocimientos/'.$id.'/evaluacion-certificacion';
            $response = $client->get($path, $options);
            $this->assertSame(200, $response->getStatusCode(), $path.' '.$response->getBody());
            $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR)['data'];
            $this->assertSame($id, $data['id_origen']);
            $this->assertNull($data['id_actividad']);
            $this->assertNull($data['id_sesion']);
            $this->assertSame($documentId === null, $data['puede_preparar']);
            $this->assertSame($documentId === null, $data['texto_editable']);
            if ($documentId !== null) {
                $this->assertSame($documentId, $data['id_certificado_documento']);
                $this->assertTrue($data['documento_existente']['facultades']['ver']);
                $this->assertTrue($data['documento_existente']['facultades']['descargar']);
                $this->assertTrue($data['documento_existente']['facultades']['visualizar']);
                $detailPath = '/api/certificados-documentos/detail/'.$documentId.'?flujo=firmas';
                $detailResponse = $client->get($detailPath, $options);
                $this->assertSame(200, $detailResponse->getStatusCode());
                $detail = json_decode((string) $detailResponse->getBody(), true, 512, JSON_THROW_ON_ERROR)['data'];
                $this->assertSame($data['documento_existente']['origen'], $detail['origen']);
                $this->assertSame($data['id_persona'], $detail['beneficiario']['id_persona']);
                $this->assertTrue($detail['facultades']['ver']);
                $this->assertTrue($detail['facultades']['descargar']);
                $download = $client->get($data['url_documento'], $options);
                $this->assertSame(200, $download->getStatusCode());
                $this->assertStringStartsWith('%PDF-', (string) $download->getBody());
                fwrite(STDOUT, 'HTTP GET '.$detailPath.' y PDF: 200'.PHP_EOL);
            }
            $consultPath = '/api/certificaciones/reconocimiento/'.$id.'/preparar?accion=consultar_preparacion';
            $consultResponse = $client->get($consultPath, $options);
            $this->assertSame(200, $consultResponse->getStatusCode());
            $consult = json_decode((string) $consultResponse->getBody(), true, 512, JSON_THROW_ON_ERROR)['data'];
            $this->assertSame($documentId !== null, $consult['recuperado']);
            $this->assertArrayNotHasKey('vista_previa_pdf', $consult);
            $this->assertArrayNotHasKey('token_revision', $consult);
            fwrite(STDOUT, json_encode(['http' => 200, 'endpoint' => $path, 'consulta_ligera_http' => 200,
                'usuario_real' => $actor->id_usuario, 'id_persona' => $data['id_persona'], 'modalidad' => $data['modalidad_reconocimiento'],
                'documento' => $data['documento_existente'], 'texto_editable' => $data['texto_editable'],
                'mensaje_predeterminado' => $data['mensaje_predeterminado']], JSON_UNESCAPED_UNICODE).PHP_EOL);
        }
        $restricted = $users->first(fn (User $user): bool => ! $user->bloqueado_hasta?->isFuture()
            && ! in_array('emision.ver', $user->permissionKeys(), true));
        if ($restricted !== null) {
            $denied = ['cookies' => CookieJar::fromArray([
                JwtService::COOKIE => app(JwtService::class)->createToken($restricted),
            ], parse_url($base, PHP_URL_HOST))];
            $this->assertSame(403, $client->get('/api/reconocimientos/28/evaluacion-certificacion', $denied)->getStatusCode());
            fwrite(STDOUT, 'HTTP GET evaluación con usuario real sin emision.ver: 403'.PHP_EOL);
        } else {
            fwrite(STDOUT, 'No hay usuario activo sin emision.ver: el caso 403 debe cubrirse en el entorno aislado.'.PHP_EOL);
        }
        $this->assertNeonHttpReviewWithoutWrites($options);
    }

    /** @param array{cookies: CookieJar} $options */
    private function assertNeonHttpReviewWithoutWrites(array $options): void
    {
        $directory = storage_path('framework/testing/neon-http-review-'.bin2hex(random_bytes(6)));
        File::ensureDirectoryExists($directory.'/private');
        file_put_contents($directory.'/fixture.json', json_encode(['postgresql_read_only' => true, 'schemas' => [], 'config' => [
            'database.default' => 'pgsql', 'database.connections.pgsql.database' => 'certificados_morales',
            'database.connections.pgsql.url' => null, 'filesystems.disks.local.root' => $directory.'/private',
            'cache.default' => 'array', 'session.driver' => 'array',
        ]], JSON_THROW_ON_ERROR));
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($socket);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $base = 'http://'.$address;
        $server = new Process([PHP_BINARY, '-S', $address, base_path('tests/Fixtures/recognition-http-router.php')], base_path(), [
            'RECOGNITION_HTTP_FIXTURE' => $directory.'/fixture.json', 'APP_ENV' => 'local',
            'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => 'certificados_morales', 'DB_URL' => '',
            'PGOPTIONS' => '-c default_transaction_read_only=on',
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
            $this->assertTrue($ready, $server->getErrorOutput());
            $client = new Client(['base_uri' => $base, 'http_errors' => false, 'allow_redirects' => false,
                'timeout' => 60, 'headers' => ['Accept' => 'application/json']]);
            $path = '/api/certificaciones/reconocimiento/28/preparar';
            $response = $client->get($path.'?accion=consultar_preparacion', $options);
            $this->assertSame(200, $response->getStatusCode());
            $consult = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR)['data'];
            $this->assertTrue($consult['texto_editable']);
            $this->assertSame('DESTACADO. E', $consult['mensaje_predeterminado']);
            $message = 'Mensaje municipal verificado por HTTP.';
            $response = $client->post($path, [...$options, 'json' => ['accion' => 'revisar_documento', 'mensaje' => $message]]);
            $this->assertSame(200, $response->getStatusCode(), 'La revisión real debe funcionar sin escribir en PostgreSQL.');
            $review = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR)['data'];
            $this->assertSame($message, $review['mensaje']);
            $this->assertTrue($review['texto_editable']);
            $pdf = base64_decode($review['vista_previa_pdf']['contenido_base64'], true);
            preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $streams);
            $decoded = implode('', array_map(fn (string $stream): string => @gzuncompress($stream) ?: $stream, $streams[1]));
            $encoded = mb_convert_encoding($message, 'UTF-16BE', 'UTF-8');
            $this->assertTrue(str_contains($decoded, $encoded) || str_contains(strtolower($decoded), bin2hex($encoded)));
            $this->assertSame(hash('sha256', $pdf), $review['hash_pdf']);
            $this->assertFileExists($directory.'/private/certificados/revisiones/'.hash('sha256', $review['token_revision']).'.manifest');
            fwrite(STDOUT, 'HTTP POST '.$path.' revisar_documento con mensaje: 200 [Neon READ ONLY, artefactos aislados]; texto exacto PDF aprobado.'.PHP_EOL);
        } finally {
            $server->stop(0);
        }
    }

    #[TestWith([28, false])]
    #[TestWith([1, true])]
    public function test_real_recognitions_and_transactional_individual_document_lifecycle(int $recognitionId, bool $custom): void
    {
        if (getenv('INDIVIDUAL_RECOGNITIONS_NEON') !== '1') {
            $this->markTestSkipped('Requiere autorización explícita para pruebas Neon con ROLLBACK.');
        }
        config(['database.default' => 'pgsql', 'database.connections.pgsql.database' => 'certificados_morales',
            'database.connections.pgsql.url' => null]);
        DB::purge('pgsql');
        $this->assertSame('certificados_morales', DB::selectOne('SELECT current_database() AS name')->name);
        $this->assertStringEndsWith('.neon.tech', DB::connection()->getConfig('host'));
        $before = $this->snapshot();
        DB::beginTransaction();
        $this->beforeApplicationDestroyed(function () use ($before): void {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $this->assertSame($before, $this->snapshot());
        });
        DB::statement("SET LOCAL lock_timeout = '3s'");
        DB::statement("SET LOCAL statement_timeout = '20s'");
        $originalDisk = Storage::disk('local');
        $fileHashes = collect($originalDisk->allFiles('certificados'))
            ->mapWithKeys(fn (string $path): array => [$path => hash_file('sha256', $originalDisk->path($path))])->all();
        $this->beforeApplicationDestroyed(function () use ($originalDisk, $fileHashes): void {
            $this->assertSame($fileHashes, collect($originalDisk->allFiles('certificados'))
                ->mapWithKeys(fn (string $path): array => [$path => hash_file('sha256', $originalDisk->path($path))])->all());
        });
        $manifests = collect($originalDisk->allFiles('certificados'))->filter(fn (string $path): bool => str_ends_with($path, '.manifest'))
            ->mapWithKeys(fn (string $path): array => [$path => $originalDisk->get($path)]);
        $this->app->instance('env', 'local');
        config(['certification.origins.reconocimiento.academic_enabled' => true,
            'certification.origins.reconocimiento.academic_ids' => [1, 28],
            'certification.origins.reconocimiento.academic_states' => ['ca', 'PREPARADO']]);
        $identity = DB::table('acceso.usuario')->where('estado', true)->orderBy('id_usuario')->first();
        $this->assertNotNull($identity);
        $actor = new class extends User
        {
            public function isSystemAdministrator(): bool
            {
                return true;
            }

            public function permissionKeys(): array
            {
                return ['emision.ver', 'emision.crear', 'emision.editar', 'reconocimientos.ver', 'actividades.ver', 'ponentes-firmantes.ver'];
            }
        };
        $actor->forceFill(['id_usuario' => $identity->id_usuario, 'id_persona' => $identity->id_persona, 'estado' => true]);
        $actor->setRelation('roles', (new Role)->newCollection());
        $this->actingAs($actor);
        foreach ([1 => 1, 2 => 2, 24 => 1, 27 => 2, 28 => 2] as $id => $person) {
            $started = microtime(true);
            $data = $this->getJson('/api/reconocimientos/'.$id.'/evaluacion-certificacion')->assertOk()
                ->assertJsonPath('data.id_persona', $person)->assertJsonPath('data.id_actividad', null)
                ->assertJsonPath('data.id_sesion', null)->json('data');
            $this->assertSame(in_array($id, [1, 28], true), $data['puede_preparar']);
            $this->assertNotEmpty($data['reconocimiento']['nombres']);
            if (in_array($id, [2, 24, 27], true)) {
                $this->assertSame([], $data['certificados_existentes']);
                $this->assertFalse($data['puede_consultar_certificado_existente']);
                $this->assertNotEmpty($data['certificados_otro_origen']);
                $this->assertNotEmpty($data['contexto_origen_real']['id_origen']);
                $this->assertSame([2 => 10, 24 => 22, 27 => 26][$id], $data['id_certificado_documento']);
                $this->assertSame($id === 2 ? 'PREPARADO' : 'PENDIENTE_FIRMA', $data['estado_certificado']);
                $this->assertSame('Vigente', $data['estado_documento']);
                $this->assertTrue($data['puede_visualizar']);
                $this->assertTrue($data['puede_descargar']);
                $this->assertFalse($data['documento_existente']['puede_preparar_como_individual']);
                $this->get($data['url_documento'])->assertOk()->assertDownload();
                $this->getJson('/api/certificaciones/reconocimiento/'.$id.'/preparar?accion=consultar_preparacion')->assertOk()
                    ->assertJsonPath('data.recuperado', true)->assertJsonPath('data.id_certificado_documento', $data['id_certificado_documento'])
                    ->assertJsonMissingPath('data.vista_previa_pdf')->assertJsonMissingPath('data.token_revision');
            }
            fwrite(STDOUT, json_encode(['id' => $id, 'persona' => $person, 'modalidad' => $data['modalidad_reconocimiento'],
                'elegible' => $data['puede_preparar'], 'motivos' => $data['motivos_bloqueo'],
                'ms' => round((microtime(true) - $started) * 1000)], JSON_UNESCAPED_UNICODE).PHP_EOL);
        }
        $this->assertSame($before, $this->snapshot(), 'Consultar y descargar no debe modificar registros.');
        Storage::set('local', Storage::fake('neon-individual-recognitions'));
        foreach ($manifests as $path => $contents) {
            Storage::disk('local')->put($path, $contents);
        }
        $url = '/api/certificaciones/reconocimiento/'.$recognitionId.'/preparar';
        $text = $custom ? ['mensaje' => 'Mensaje municipal aprobado.'] : [];
        $review = $this->postJson($url, ['accion' => 'revisar_documento'] + $text)->assertOk()->json('data');
        $this->assertStringStartsWith('%PDF-', base64_decode($review['vista_previa_pdf']['contenido_base64'], true));
        $message = $text['mensaje'] ?? 'DESTACADO. E';
        $this->assertSame($message, $review['mensaje']);
        $pdf = base64_decode($review['vista_previa_pdf']['contenido_base64'], true);
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $streams);
        $decoded = implode('', array_map(fn (string $stream): string => @gzuncompress($stream) ?: $stream, $streams[1]));
        $encoded = mb_convert_encoding($message, 'UTF-16BE', 'UTF-8');
        $this->assertTrue(str_contains($decoded, $encoded) || str_contains(strtolower($decoded), bin2hex($encoded)));
        $confirm = ['accion' => 'confirmar_preparacion', 'token_revision' => $review['token_revision'], 'confirmar' => true] + $text;
        $prepared = $this->postJson($url, $confirm)->assertCreated()->json('data');
        $this->postJson($url, $confirm)->assertOk();
        $documentId = $prepared['id_certificado_documento'];
        $this->getJson($url.'?accion=consultar_preparacion')->assertOk()->assertJsonPath('data.recuperado', true)
            ->assertJsonPath('data.id_certificado_documento', $documentId)->assertJsonPath('data.mensaje_aprobado', $message);
        $this->assertSame(1, DB::table('certificado.certificado')->where('id_reconocimiento', $recognitionId)->count());
        $base = '/api/certificados-documentos/'.$documentId;
        $send = $this->postJson($base.'/revisar-envio')->assertOk()->json('data');
        $this->postJson($base.'/enviar-gerencia', ['token_revision' => $send['token_revision'], 'confirmar' => true])->assertOk();
        $available = app(CertificateSigningStageB::class)->availableSigners($documentId, $actor);
        $this->assertNotEmpty($available);
        $plan = ['modalidad' => 'SECUENCIAL', 'firmantes' => [['id_persona_trabajador' => $available[0]['id_persona_trabajador'], 'orden_firma' => 1]]];
        $revision = $this->postJson($base.'/firma/revision-pdf', $plan)->assertOk()->json('data');
        $this->postJson($base.'/firma/asignar', $plan + ['confirmar' => true])->assertOk();
        $this->postJson($base.'/firma/confirmar-pdf', ['token_revision' => $revision['token_revision'], 'confirmar' => true])->assertOk();
        $this->getJson($base.'/firma/progreso')->assertOk()->assertJsonPath('data.disponible_rrhh', true);
        $this->postJson($url, ['accion' => 'revisar_documento', 'mensaje' => 'Otro mensaje.'])->assertConflict();
        $this->assertSame($recognitionId === 28 ? 'PREPARADO' : 'ca', DB::table('certificado.reconocimiento')->where('id_reconocimiento', $recognitionId)->value('estado'));
    }

    /** @return array<string, string> */
    private function snapshot(): array
    {
        $snapshot = [];
        foreach (['certificado.reconocimiento', 'certificado.certificado', 'certificado.certificado_documento', 'certificado.certificado_firma',
            'gestion.persona', 'gestion.persona_trabajador'] as $table) {
            $snapshot[$table] = DB::selectOne('SELECT md5(COALESCE(jsonb_agg(to_jsonb(t) ORDER BY 1)::text, \'[]\')) AS fingerprint FROM (SELECT * FROM '.$table.' ORDER BY 1) t')->fingerprint;
        }

        return $snapshot;
    }
}
