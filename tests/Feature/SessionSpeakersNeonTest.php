<?php

namespace Tests\Feature;

use App\Models\Role\Role;
use App\Models\User\User;
use App\Services\CertificateSigningStageB;
use App\Services\SessionSpeakers;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

#[Group('postgresql')]
class SessionSpeakersNeonTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('SESSION_SPEAKERS_NEON_TESTS') !== '1') {
            $this->markTestSkipped('Requiere autorización explícita para Neon con ROLLBACK.');
        }
        config(['database.default' => 'pgsql', 'database.connections.pgsql.database' => 'certificados_morales',
            'database.connections.pgsql.url' => null]);
        DB::purge('pgsql');
        $this->assertSame('certificados_morales', DB::selectOne('SELECT current_database() AS name')->name);
        $this->assertStringEndsWith('.neon.tech', DB::connection()->getConfig('host'));
        DB::beginTransaction();
        DB::statement("SET LOCAL lock_timeout = '3s'");
        DB::statement("SET LOCAL statement_timeout = '20s'");
        $before = $this->snapshot();
        $disk = Storage::disk('local');
        $files = collect($disk->allFiles('certificados'))->mapWithKeys(fn (string $path): array => [$path => hash_file('sha256', $disk->path($path))])->all();
        $this->beforeApplicationDestroyed(function () use ($before, $disk, $files): void {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $this->assertSame($before, $this->snapshot(), 'Los datos reales deben conservarse tras ROLLBACK.');
            $this->assertSame($files, collect($disk->allFiles('certificados'))->mapWithKeys(fn (string $path): array => [$path => hash_file('sha256', $disk->path($path))])->all());
        });
    }

    /** @return array<string, string> */
    private function snapshot(): array
    {
        $snapshot = [];
        foreach (['certificado.actividad', 'certificado.actividad_sesion', 'certificado.actividad_ponente', 'certificado.reconocimiento',
            'certificado.certificado', 'certificado.certificado_documento', 'certificado.certificado_firma',
            'gestion.persona', 'gestion.persona_ponente', 'gestion.persona_trabajador'] as $table) {
            $snapshot[$table] = DB::selectOne('SELECT md5(COALESCE(jsonb_agg(to_jsonb(t) ORDER BY 1)::text, \'[]\')) AS fingerprint FROM (SELECT * FROM '.$table.' ORDER BY 1) t')->fingerprint;
        }

        return $snapshot;
    }

    private function authenticate(?int $areaId = null): void
    {
        $identity = DB::table('acceso.usuario as u')->join('gestion.persona_trabajador as t', 't.id_persona', '=', 'u.id_persona')
            ->where('u.estado', true)->where('t.estado', true)
            ->when($areaId !== null, fn ($query) => $query->where('t.id_area', $areaId))
            ->orderBy('u.id_usuario')->first(['u.id_usuario', 'u.id_persona']);
        $this->assertNotNull($identity, 'Se requiere un usuario municipal existente.');
        $actor = new class extends User
        {
            public function isSystemAdministrator(): bool
            {
                return true;
            }

            public function permissionKeys(): array
            {
                return ['emision.ver', 'emision.crear', 'emision.editar', 'actividades.ver', 'ponentes-firmantes.ver',
                    'ponentes-firmantes.crear', 'ponentes-firmantes.eliminar', 'reconocimientos.crear', 'reconocimientos.ver'];
            }
        };
        $actor->forceFill(['id_usuario' => $identity->id_usuario, 'id_persona' => $identity->id_persona, 'estado' => true]);
        $actor->setRelation('roles', (new Role)->newCollection());
        $this->actingAs($actor);
    }

    /** @return array{activity: int, sessions: list<int>, speakers: list<int>} */
    private function existingFixtures(): array
    {
        $sessions = DB::table('certificado.actividad_sesion')->where('estado', 'Finalizada')->orderBy('id_actividad_sesion')->get();
        $group = $sessions->groupBy('id_actividad')->first(fn ($group): bool => $group->count() >= 2);
        $this->assertNotNull($group, 'Se requieren dos sesiones existentes de la misma actividad.');
        $speakers = DB::table('gestion.persona_ponente')->orderBy('id_persona_ponente')->pluck('id_persona_ponente')->map(fn ($id): int => (int) $id)->all();
        $this->assertGreaterThanOrEqual(2, count($speakers));

        return ['activity' => (int) $group->first()->id_actividad, 'sessions' => $group->pluck('id_actividad_sesion')->map(fn ($id): int => (int) $id)->all(), 'speakers' => $speakers];
    }

    private function assertRejected(callable $operation, int $status): void
    {
        DB::beginTransaction();
        try {
            $operation();
            $this->fail('La operación debía rechazarse.');
        } catch (HttpException $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        } finally {
            DB::rollBack();
        }
    }

    private function assertConstraint(callable $operation, string $sqlState): void
    {
        DB::beginTransaction();
        try {
            $operation();
            $this->fail('PostgreSQL debía rechazar la operación.');
        } catch (QueryException $exception) {
            $this->assertSame($sqlState, (string) $exception->getCode());
        } finally {
            DB::rollBack();
        }
    }

    public function test_real_session_assignments_persist_in_transaction_and_respect_constraints(): void
    {
        $this->authenticate();
        $fixture = $this->existingFixtures();
        ['activity' => $activity, 'sessions' => $sessions, 'speakers' => $speakers] = $fixture;
        $service = app(SessionSpeakers::class);
        $url = '/api/activity/'.$activity.'/sessions/'.$sessions[0].'/ponentes';
        $this->getJson($url)->assertOk()->assertJsonCount(0, 'data.vinculos');
        $assignment = $this->postJson($url, ['id_persona_ponente' => $speakers[0]])->assertOk()->json('data.vinculo');
        $this->getJson($url)->assertOk()->assertJsonPath('data.vinculos.0.id_actividad_ponente', $assignment['id_actividad_ponente'])
            ->assertJsonPath('data.vinculos.0.id_persona', (int) DB::table('gestion.persona_ponente')->where('id_persona_ponente', $speakers[0])->value('id_persona'));
        $this->postJson($url, ['id_persona_ponente' => $speakers[0]])->assertConflict();
        $this->postJson($url, ['id_persona_ponente' => $speakers[0], 'id_persona' => 999999])->assertUnprocessable();
        $this->postJson($url, ['id_persona_ponente' => 2147483647])->assertNotFound();
        $this->assertRejected(fn () => $service->link($activity + 100000, $sessions[0], $speakers[0]), 404);
        $other = DB::table('certificado.actividad_sesion')->where('id_actividad', '<>', $activity)->first();
        $this->assertNotNull($other);
        $this->postJson('/api/activity/'.$activity.'/sessions/'.$other->id_actividad_sesion.'/ponentes', ['id_persona_ponente' => $speakers[0]])->assertConflict();
        $service->link($activity, $sessions[1], $speakers[0]);
        $service->link($activity, $sessions[0], $speakers[1]);
        $this->assertCount(2, $service->assigned($activity, $sessions[0]));
        $this->assertCount(1, $service->assigned($activity, $sessions[1]));
        $this->assertConstraint(fn () => DB::table('certificado.actividad_ponente')->insert([
            'id_actividad' => $activity, 'id_actividad_sesion' => $other->id_actividad_sesion, 'id_persona_ponente' => $speakers[0],
        ]), '23503');
        $this->assertConstraint(fn () => DB::table('certificado.actividad_ponente')->insert([
            'id_actividad' => $activity, 'id_actividad_sesion' => $sessions[0], 'id_persona_ponente' => $speakers[0],
        ]), '23505');
        $recognition = ['id_actividad_ponente' => $assignment['id_actividad_ponente'], 'titulo' => 'PRUEBA ROLLBACK',
            'fecha_reconocimiento' => today()->toDateString(), 'estado' => 'PREPARADO'];
        $worker = DB::table('gestion.persona_trabajador')->value('id_persona_trabajador');
        $this->assertConstraint(fn () => DB::table('certificado.reconocimiento')->insert($recognition + ['id_persona_trabajador' => $worker]), '23514');
        $this->assertConstraint(fn () => DB::table('certificado.reconocimiento')->insert(array_replace($recognition, ['id_actividad_ponente' => null])), '23514');
        DB::table('certificado.reconocimiento')->insert($recognition);
        $this->assertConstraint(fn () => DB::table('certificado.reconocimiento')->insert($recognition), '23505');
        $this->deleteJson($url, ['id_actividad_ponente' => $assignment['id_actividad_ponente']])->assertConflict();
        $this->assertConstraint(fn () => DB::table('certificado.actividad_ponente')->where('id_actividad_ponente', $assignment['id_actividad_ponente'])->delete(), '23503');
        $second = $service->assigned($activity, $sessions[0])->firstWhere('id_persona_ponente', $speakers[1]);
        $this->deleteJson($url, ['id_actividad_ponente' => $second->id_actividad_ponente])->assertOk()->assertJsonPath('data.desvinculado', true);
    }

    public function test_real_recognition_preparation_recovery_and_management_use_isolated_pdf_storage(): void
    {
        DB::statement("SET LOCAL idle_in_transaction_session_timeout = '20min'");
        Storage::set('local', Storage::fake('neon-session-speakers'));
        ['activity' => $activity, 'sessions' => $sessions, 'speakers' => $speakers] = $this->existingFixtures();
        $this->authenticate((int) DB::table('certificado.actividad')->where('id_actividad', $activity)->value('id_area'));
        $external = DB::table('gestion.persona_ponente as pp')->whereNotExists(fn ($query) => $query
            ->selectRaw('1')->from('gestion.persona_trabajador as t')->whereColumn('t.id_persona', 'pp.id_persona'))->first();
        if ($external === null) {
            $personId = DB::table('gestion.persona')->insertGetId(['nombres' => 'PRUEBA TRANSACCIONAL',
                'apellido_paterno' => 'ROLLBACK', 'fecha_registro' => now()], 'id_persona');
            $speakerId = DB::table('gestion.persona_ponente')->insertGetId(['id_persona' => $personId], 'id_persona_ponente');
        } else {
            $personId = (int) $external->id_persona;
            $speakerId = (int) $external->id_persona_ponente;
        }
        $this->assertFalse(DB::table('gestion.persona_trabajador')->where('id_persona', $personId)->exists());
        fwrite(STDOUT, "Neon: ponente externo de prueba, almacenamiento PDF aislado.\n");
        $assignment = app(SessionSpeakers::class)->link($activity, $sessions[0], $speakerId);
        $url = '/api/certificaciones/ponente_sesion/'.$assignment->id_actividad_ponente.'/preparar';
        $input = ['accion' => 'revisar_documento', 'modalidad_reconocimiento' => 'sesion', 'id_sesion' => $sessions[0]];
        $review = $this->postJson($url, $input)->assertOk()->json('data');
        $confirm = array_replace($input, ['accion' => 'confirmar_preparacion', 'token_revision' => $review['token_revision'], 'confirmar' => true]);
        $prepared = $this->postJson($url, $confirm)->assertCreated()->json('data');
        fwrite(STDOUT, "Neon: reconocimiento externo preparado; comprobando recuperación y Gerencia.\n");
        $this->postJson($url, $confirm)->assertOk()->assertJsonPath('data.creado', false);
        $this->assertDatabaseHas('certificado.reconocimiento', ['id_reconocimiento' => $prepared['id_reconocimiento'],
            'id_actividad_ponente' => $assignment->id_actividad_ponente, 'id_persona_trabajador' => null]);
        $certificate = DB::table('certificado.certificado')->where('id_reconocimiento', $prepared['id_reconocimiento'])->sole();
        $this->assertNull($certificate->id_participacion);
        $this->assertNull($certificate->id_actividad_sesion);
        $this->assertSame('Reconocimiento', DB::table('certificado.tipo_certificado')->where('id_tipo_certificado', $certificate->id_tipo_certificado)->value('nombre_tipo'));
        $id = $prepared['id_certificado_documento'];
        $base = '/api/certificados-documentos/'.$id;
        $send = $this->postJson($base.'/revisar-envio', [])->assertOk()->json('data');
        $this->postJson($base.'/enviar-gerencia', ['token_revision' => $send['token_revision'], 'confirmar' => true])->assertOk();
        fwrite(STDOUT, "Neon: envío explícito a Gerencia comprobado.\n");
        $available = app(CertificateSigningStageB::class)->availableSigners($id, auth()->user());
        fwrite(STDOUT, "Neon: responsables habilitados consultados.\n");
        $this->assertNotEmpty($available, 'Se requiere un responsable municipal vigente y habilitado.');
        $plan = ['modalidad' => 'SECUENCIAL', 'firmantes' => [['id_persona_trabajador' => $available[0]['id_persona_trabajador'], 'orden_firma' => 1]]];
        $revision = $this->postJson($base.'/firma/revision-pdf', $plan)->assertOk()->json('data');
        fwrite(STDOUT, "Neon: revisión PDF con responsables generada.\n");
        $this->postJson($base.'/firma/asignar', $plan + ['confirmar' => true])->assertOk();
        fwrite(STDOUT, "Neon: responsables asignados.\n");
        $this->postJson($base.'/firma/confirmar-pdf', ['token_revision' => $revision['token_revision'], 'confirmar' => true])->assertOk();
        fwrite(STDOUT, "Neon: PDF administrativo confirmado.\n");
        $this->getJson($base.'/firma/progreso')->assertOk()->assertJsonPath('data.disponible_rrhh', true)
            ->assertJsonPath('data.etiqueta_estado', 'Listo para entrega');
        $inbox = $this->getJson('/api/rrhh/certificados')->assertOk()->json('data.otros_origenes');
        $this->assertNotNull(collect($inbox)->firstWhere('id_certificado_documento', $id));
        $original = DB::table('certificado.certificado_documento')->where('id_certificado_documento', $id)->first();
        $this->assertSame($review['hash_pdf'], $original->hash_certificado);
        $this->assertSame($review['hash_pdf'], hash_file('sha256', Storage::disk('local')->path($original->ruta_archivo)));
        $this->assertSame($prepared['codigo_verificacion'], $original->codigo_verificacion);
        $this->assertSame('PENDIENTE_FIRMA', DB::table('certificado.certificado')->where('id_certificado', $certificate->id_certificado)->value('estado'));
        fwrite(STDOUT, "Neon: PDF confirmado, disponible en RR. HH.; se revierte la transacción.\n");
    }

    public function test_real_session_custom_text_is_persisted_and_recovered_inside_rollback(): void
    {
        DB::statement("SET LOCAL idle_in_transaction_session_timeout = '20min'");
        Storage::set('local', Storage::fake('neon-session-recognition-text'));
        ['activity' => $activity, 'sessions' => $sessions, 'speakers' => $speakers] = $this->existingFixtures();
        $this->authenticate((int) DB::table('certificado.actividad')->where('id_actividad', $activity)->value('id_area'));
        $personId = (int) DB::table('gestion.persona_ponente')->where('id_persona_ponente', $speakers[0])->value('id_persona');
        $sessionId = collect($sessions)->first(fn (int $session): bool => ! DB::table('certificado.actividad_ponente as ap')
            ->join('gestion.persona_ponente as pp', 'pp.id_persona_ponente', '=', 'ap.id_persona_ponente')
            ->where('ap.id_actividad_sesion', $session)->where('pp.id_persona', $personId)->exists());
        $this->assertNotNull($sessionId, 'Se requiere un vínculo nuevo; no se alteran asignaciones históricas.');
        $assignment = app(SessionSpeakers::class)->link($activity, $sessionId, $speakers[0]);
        $url = '/api/certificaciones/ponente_sesion/'.$assignment->id_actividad_ponente.'/preparar';
        $input = ['accion' => 'revisar_documento', 'modalidad_reconocimiento' => 'sesion', 'id_sesion' => $sessionId,
            'mensaje' => 'Prueba transaccional de texto personalizado; documento aislado sin validez oficial.',
            'motivo' => 'Validación técnica con ROLLBACK, sin modificar históricos.'];
        $review = $this->postJson($url, $input)->assertOk()->assertJsonPath('data.mensaje', $input['mensaje'])
            ->assertJsonPath('data.motivo', $input['motivo'])->json('data');
        $confirm = array_replace($input, ['accion' => 'confirmar_preparacion', 'token_revision' => $review['token_revision'], 'confirmar' => true]);
        $prepared = $this->postJson($url, $confirm)->assertCreated()->json('data');
        $this->assertDatabaseHas('certificado.reconocimiento', ['id_reconocimiento' => $prepared['id_reconocimiento'], 'motivo' => $input['motivo']]);
        $this->assertSame(1, DB::table('certificado.reconocimiento')->where('id_actividad_ponente', $assignment->id_actividad_ponente)->count());
        $recovered = $this->postJson($url, $input)->assertOk()->assertJsonPath('data.recuperado', true)
            ->assertJsonPath('data.texto_editable', false)->assertJsonPath('data.mensaje', $input['mensaje'])
            ->assertJsonPath('data.motivo', $input['motivo'])->json('data');
        $this->assertSame($prepared['codigo_verificacion'], $recovered['codigo_verificacion']);
        $this->assertSame($review['hash_pdf'], hash('sha256', base64_decode($recovered['vista_previa_pdf']['contenido_base64'], true)));
        $base = '/api/certificados-documentos/'.$prepared['id_certificado_documento'];
        $this->getJson('/api/certificados-documentos/detail/'.$prepared['id_certificado_documento'].'?flujo=firmas')->assertOk()
            ->assertJsonPath('data.mensaje_aprobado', $input['mensaje'])->assertJsonPath('data.motivo', $input['motivo']);
        $this->postJson($base.'/revisar-envio', [])->assertOk();
        $certificate = DB::table('certificado.certificado')->where('id_reconocimiento', $prepared['id_reconocimiento'])->sole();
        $this->assertSame($personId, (int) $certificate->id_persona);
        $this->assertNull($certificate->id_actividad_sesion);
        $this->assertSame('PREPARADO', $certificate->estado);
        fwrite(STDOUT, "Neon: motivo SQL y mensaje cifrado recuperados con PDF íntegro; ROLLBACK.\n");
    }

    public function test_live_connection_and_installed_schema(): void
    {
        $identity = DB::selectOne("SELECT current_database() AS database, current_user AS username, current_schema() AS schema,
            inet_server_addr() AS server, inet_server_port() AS port,
            current_setting('transaction_timeout', true) AS transaction_timeout,
            current_setting('idle_in_transaction_session_timeout') AS idle_transaction_timeout,
            current_setting('idle_session_timeout') AS idle_session_timeout");
        $tables = ['actividad', 'actividad_sesion', 'actividad_ponente', 'reconocimiento', 'certificado', 'certificado_documento', 'persona', 'persona_ponente', 'persona_trabajador'];
        $columns = DB::table('information_schema.columns')->whereIn('table_schema', ['certificado', 'gestion'])->whereIn('table_name', $tables)
            ->orderBy('table_schema')->orderBy('table_name')->orderBy('ordinal_position')->get(['table_schema', 'table_name', 'column_name', 'is_nullable', 'column_default']);
        $constraints = DB::select("SELECT conrelid::regclass::text AS relation, conname, pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE connamespace IN ('certificado'::regnamespace, 'gestion'::regnamespace) ORDER BY conrelid, conname");
        $indexes = DB::table('pg_indexes')->whereIn('schemaname', ['certificado', 'gestion'])->whereIn('tablename', $tables)->get(['tablename', 'indexname', 'indexdef']);
        $triggers = DB::select("SELECT t.tgrelid::regclass::text AS relation, t.tgname, pg_get_triggerdef(t.oid) AS definition, pg_get_functiondef(t.tgfoid) AS function FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid JOIN pg_namespace n ON n.oid = c.relnamespace WHERE NOT t.tgisinternal AND n.nspname IN ('certificado', 'gestion')");
        fwrite(STDOUT, json_encode(compact('identity', 'columns', 'constraints', 'indexes', 'triggers'), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL);
        fwrite(STDOUT, json_encode([
            'sessions' => DB::table('certificado.actividad_sesion')->orderBy('id_actividad_sesion')->get(['id_actividad_sesion', 'id_actividad', 'estado', 'fecha']),
            'speaker_count' => DB::table('gestion.persona_ponente')->count(),
            'historical_assignments' => DB::table('certificado.actividad_ponente')->whereNull('id_actividad_sesion')->count(),
            'recognition_types' => DB::table('certificado.tipo_certificado')->where('nombre_tipo', 'ilike', '%reconoc%')->get(),
            'approved_activities' => DB::table('certificado.solicitud_actividad')->where('estado', 'Aprobada')->pluck('id_actividad'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL);
        $this->assertTrue($columns->contains(fn (object $column): bool => $column->table_name === 'actividad_ponente' && $column->column_name === 'id_actividad_sesion'));
        $this->assertTrue($columns->contains(fn (object $column): bool => $column->table_name === 'reconocimiento' && $column->column_name === 'id_actividad_ponente'));
        $definitions = implode('\n', array_column($constraints, 'definition'));
        $this->assertStringContainsString('FOREIGN KEY (id_actividad_sesion, id_actividad)', $definitions);
        $this->assertStringContainsString('num_nonnulls(id_persona_trabajador, id_actividad_ponente) = 1', $definitions);
        $this->assertStringContainsString('FOREIGN KEY (id_participacion, id_actividad_sesion)', $definitions);
        $this->assertStringContainsString('id_persona_trabajador', $definitions);
    }
}
