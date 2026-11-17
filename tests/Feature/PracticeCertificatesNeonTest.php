<?php

namespace Tests\Feature;

use App\Models\Role\Role;
use App\Models\User\User;
use App\Services\CertificateOrigins;
use App\Services\CertificateSigningStageB;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('postgresql')]
class PracticeCertificatesNeonTest extends TestCase
{
    public function test_practice_lifecycle_with_isolated_writes_and_rollback(): void
    {
        if (getenv('PRACTICE_CERTIFICATES_NEON_WRITES') !== '1') {
            $this->markTestSkipped('Requiere escritura aislada explícita con ROLLBACK.');
        }
        config(['database.default' => 'pgsql', 'database.connections.pgsql.database' => 'certificados_morales',
            'database.connections.pgsql.url' => null]);
        DB::purge('pgsql');
        $this->assertSame('certificados_morales', DB::selectOne('SELECT current_database() AS name')->name);
        $this->assertStringEndsWith('.neon.tech', DB::connection()->getConfig('host'));
        $before = $this->snapshot();
        $disk = Storage::disk('local');
        $files = collect($disk->allFiles('certificados'))->mapWithKeys(fn (string $path): array => [$path => hash_file('sha256', $disk->path($path))])->all();
        DB::beginTransaction();
        $this->beforeApplicationDestroyed(function () use ($before, $disk, $files): void {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $this->assertSame($before, $this->snapshot(), 'Los datos históricos deben conservarse tras ROLLBACK.');
            $this->assertSame($files, collect($disk->allFiles('certificados'))->mapWithKeys(fn (string $path): array => [$path => hash_file('sha256', $disk->path($path))])->all());
        });
        DB::statement("SET LOCAL lock_timeout = '3s'");
        DB::statement("SET LOCAL statement_timeout = '20s'");
        DB::statement("SET LOCAL idle_in_transaction_session_timeout = '20min'");
        Storage::set('local', Storage::fake('neon-practice-lifecycle'));
        $source = DB::table('gestion.practica')->orderBy('id_practica')->first();
        $this->assertNotNull($source);
        $start = $source->fecha_inicio;
        $end = today()->toDateString();
        $this->assertNotNull($source->fecha_fin);
        $this->assertLessThanOrEqual($end, $source->fecha_fin, 'La práctica real debe haber concluido; no se alteran fechas.');
        $identity = DB::table('acceso.usuario as u')->join('gestion.persona_trabajador as t', 't.id_persona', '=', 'u.id_persona')
            ->where('u.estado', true)->where('t.estado', true)->where('t.id_area', $source->id_area)
            ->where('t.fecha_ingreso', '<=', $end)->where(fn ($query) => $query->whereNull('t.fecha_fin')->orWhere('t.fecha_fin', '>=', $end))
            ->orderBy('u.id_usuario')->first(['u.id_usuario', 'u.id_persona']);
        $this->assertNotNull($identity, 'Se requiere un usuario municipal del área de la práctica para la bandeja RR. HH.');
        $actor = new class extends User
        {
            public function isSystemAdministrator(): bool
            {
                return true;
            }

            public function permissionKeys(): array
            {
                return ['practicantes.ver', 'practicantes.editar', 'emision.ver', 'emision.crear', 'emision.editar'];
            }
        };
        $actor->forceFill(['id_usuario' => $identity->id_usuario, 'id_persona' => $identity->id_persona, 'estado' => true]);
        $actor->setRelation('roles', (new Role)->newCollection());
        $this->actingAs($actor);
        $this->assertSame('finalizado', config('certification.origins.practica.completion_state'));
        $this->assertFalse(config('certification.origins.practica.supervision_required'));
        $id = (int) $source->id_practica;
        $supervisorsBefore = DB::table('gestion.practica_supervisor')->where('id_practica', $id)->get()->toJson();
        $finalize = '/api/practices/'.$id.'/finalizar';
        $evaluate = '/api/practices/'.$id.'/evaluacion-certificacion';
        $url = '/api/certificaciones/practica/'.$id.'/preparar';
        $alreadyCompleted = $source->estado === 'finalizado';
        $this->getJson($evaluate)->assertOk()->assertJsonPath('data.situacion_constancia', $alreadyCompleted ? 'finalizada_elegible' : 'activa')
            ->assertJsonPath('data.puede_preparar', $alreadyCompleted);
        $this->postJson($finalize)->assertOk()->assertJsonPath('data.practica.estado', 'finalizado')
            ->assertJsonPath('data.practica_finalizada', true)->assertJsonPath('data.motivos_bloqueo', []);
        $this->assertSame('finalizado', DB::table('gestion.practica')->where('id_practica', $id)->value('estado'));
        $this->assertSame($supervisorsBefore, DB::table('gestion.practica_supervisor')->where('id_practica', $id)->get()->toJson());
        $this->assertSame($source->fecha_fin, DB::table('gestion.practica')->where('id_practica', $id)->value('fecha_fin'));
        $this->getJson($evaluate)->assertOk()->assertJsonPath('data.finalizacion_registrada', true)
            ->assertJsonPath('data.situacion_constancia', 'finalizada_elegible')->assertJsonPath('data.puede_preparar', true);
        $this->postJson($finalize)->assertOk()->assertJsonPath('data.reintento', true);
        $this->assertSame(0, DB::table('certificado.certificado')->where('id_practica', $id)->count());
        $list = $this->getJson('/api/practices/evaluacion-certificacion')->assertOk()->json('data.practicas');
        $this->assertNotNull(collect($list)->firstWhere('id_origen', $id));
        $this->getJson($url.'?accion=consultar_preparacion')->assertOk()
            ->assertJsonPath('data.texto_editable', true)->assertJsonMissingPath('data.vista_previa_pdf')
            ->assertJsonMissingPath('data.token_revision');
        $this->assertSame([], Storage::disk('local')->allFiles());
        $firstMessage = 'Constancia de prácticas revisada con datos reales; validación transaccional.';
        $firstReview = $this->postJson($url, ['accion' => 'revisar_documento', 'mensaje' => $firstMessage])->assertOk()->json('data');
        $message = 'Por su dedicación y aporte durante las prácticas en '.$source->carrera.'.';
        $review = $this->postJson($url, ['accion' => 'revisar_documento', 'mensaje' => $message])->assertOk()
            ->assertJsonPath('data.mensaje', $message)->json('data');
        $this->postJson($url, ['accion' => 'confirmar_preparacion', 'token_revision' => $firstReview['token_revision'],
            'mensaje' => $firstMessage, 'confirmar' => true])->assertConflict();
        $this->postJson($url, ['accion' => 'revisar_documento', 'mensaje' => $message])->assertOk()
            ->assertJsonPath('data.token_revision', $review['token_revision']);
        $this->assertStringStartsWith('%PDF-', base64_decode($review['vista_previa_pdf']['contenido_base64'], true));
        $confirm = ['accion' => 'confirmar_preparacion', 'mensaje' => $message, 'token_revision' => $review['token_revision'], 'confirmar' => true];
        $prepared = $this->postJson($url, $confirm)->assertCreated()->assertJsonPath('data.mensaje', $message)->json('data');
        $this->assertDatabaseHas('certificado.certificado', ['id_certificado' => $prepared['id_certificado'],
            'id_practica' => $id, 'id_persona' => DB::table('gestion.persona_practicante')->where('id_persona_practicante', $source->id_persona_practicante)->value('id_persona'),
            'estado' => 'PREPARADO', 'id_participacion' => null, 'id_reconocimiento' => null, 'fecha_emision' => null]);
        $this->postJson($url, $confirm)->assertOk()->assertJsonPath('data.creado', false);
        $documentId = $prepared['id_certificado_documento'];
        $this->getJson($url.'?accion=consultar_preparacion')->assertOk()
            ->assertJsonPath('data.recuperado', true)->assertJsonPath('data.id_certificado_documento', $documentId)
            ->assertJsonPath('data.mensaje_aprobado', $message)->assertJsonPath('data.texto_editable', false)
            ->assertJsonMissingPath('data.vista_previa_pdf');
        $this->postJson($url, ['accion' => 'revisar_documento'])->assertConflict();
        $this->assertSame(1, DB::table('certificado.certificado')->where('id_practica', $id)->count());
        $base = '/api/certificados-documentos/'.$documentId;
        $send = $this->postJson($base.'/revisar-envio')->assertOk()->json('data');
        $this->postJson($base.'/enviar-gerencia', ['token_revision' => $send['token_revision'], 'confirmar' => true])->assertOk();
        $available = app(CertificateSigningStageB::class)->availableSigners($documentId, $actor);
        $this->assertNotEmpty($available);
        $plan = ['modalidad' => 'SECUENCIAL', 'firmantes' => [['id_persona_trabajador' => $available[0]['id_persona_trabajador'], 'orden_firma' => 1]]];
        $revision = $this->postJson($base.'/firma/revision-pdf', $plan)->assertOk()->assertJsonPath('data.mensaje', $message)->json('data');
        $this->postJson($base.'/firma/asignar', $plan + ['confirmar' => true])->assertOk();
        $this->postJson($base.'/firma/confirmar-pdf', ['token_revision' => $revision['token_revision'], 'confirmar' => true])->assertOk();
        $this->getJson($base.'/firma/progreso')->assertOk()->assertJsonPath('data.disponible_rrhh', true);
        $this->getJson($url.'?accion=consultar_preparacion')->assertOk()->assertJsonPath('data.mensaje', $message)
            ->assertJsonPath('data.texto_editable', false)->assertJsonMissingPath('data.vista_previa_pdf');
        $this->assertNotNull(collect($this->getJson('/api/rrhh/certificados')->assertOk()->json('data.otros_origenes'))
            ->firstWhere('id_certificado_documento', $documentId));
        $document = DB::table('certificado.certificado_documento')->where('id_certificado_documento', $documentId)->first();
        $this->assertSame($review['hash_pdf'], $document->hash_certificado);
        $this->assertSame($review['hash_pdf'], hash_file('sha256', Storage::disk('local')->path($document->ruta_archivo)));
        $this->assertSame($prepared['codigo_verificacion'], $document->codigo_verificacion);
        fwrite(STDOUT, 'Prácticas Neon: finalización, recarga, PDF, recuperación, Gerencia y confirmación administrativa; ROLLBACK.'.PHP_EOL);
    }

    public function test_real_practice_schema_policy_and_eligibility_without_writes(): void
    {
        if (getenv('PRACTICE_CERTIFICATES_NEON_READ_ONLY') !== '1') {
            $this->markTestSkipped('Requiere lectura real explícita; no prepara ni remite documentos.');
        }
        config(['database.default' => 'pgsql', 'database.connections.pgsql.database' => 'certificados_morales',
            'database.connections.pgsql.url' => null]);
        DB::purge('pgsql');
        $identity = DB::selectOne('SELECT current_database() AS database, current_user AS username, current_schema() AS schema, inet_server_addr() AS server, inet_server_port() AS port');
        $this->assertSame('certificados_morales', $identity->database);
        $this->assertStringEndsWith('.neon.tech', DB::connection()->getConfig('host'));
        DB::beginTransaction();
        DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
        DB::statement("SET LOCAL statement_timeout = '20s'");
        $before = $this->snapshot();
        $disk = Storage::disk('local');
        $files = collect($disk->allFiles('certificados'))->mapWithKeys(fn (string $path): array => [$path => hash_file('sha256', $disk->path($path))])->all();
        $this->beforeApplicationDestroyed(function () use ($before, $disk, $files): void {
            try {
                $this->assertSame($before, $this->snapshot());
                $this->assertSame($files, collect($disk->allFiles('certificados'))->mapWithKeys(fn (string $path): array => [$path => hash_file('sha256', $disk->path($path))])->all());
            } finally {
                while (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }
            }
        });
        $tables = ['persona', 'persona_practicante', 'practica', 'practica_supervisor', 'persona_trabajador', 'institucion', 'area',
            'certificado', 'certificado_documento', 'certificado_firma', 'tipo_certificado'];
        $columns = DB::table('information_schema.columns')->whereIn('table_schema', ['gestion', 'certificado'])->whereIn('table_name', $tables)
            ->orderBy('table_schema')->orderBy('table_name')->orderBy('ordinal_position')->get(['table_schema', 'table_name', 'column_name', 'data_type', 'is_nullable']);
        $constraints = DB::select("SELECT conrelid::regclass::text AS relation, conname, pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE connamespace IN ('gestion'::regnamespace,'certificado'::regnamespace) ORDER BY conrelid,conname");
        $indexes = DB::table('pg_indexes')->whereIn('schemaname', ['gestion', 'certificado'])->whereIn('tablename', $tables)->get(['tablename', 'indexname', 'indexdef']);
        $policy = config('certification.origins.practica');
        $registeredStates = [
            'practicas' => DB::table('gestion.practica')->distinct()->orderBy('estado')->pluck('estado')->all(),
            'supervisiones' => DB::table('gestion.practica_supervisor')->distinct()->orderBy('estado')->pluck('estado')->all(),
        ];
        $rawAssignments = DB::table('gestion.practica_supervisor')->where('id_practica', 1)
            ->orderBy('id_practica_supervisor')->get();
        fwrite(STDOUT, json_encode(compact('registeredStates', 'rawAssignments'), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL);
        $type = DB::table('certificado.tipo_certificado')->where('nombre_tipo', $policy['type_name'])->get(['id_tipo_certificado', 'nombre_tipo', 'estado']);
        fwrite(STDOUT, json_encode(compact('identity', 'columns', 'constraints', 'indexes', 'policy', 'type'), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL);
        foreach ($tables as $table) {
            $this->assertTrue($columns->contains('table_name', $table), 'Tabla requerida: '.$table);
        }
        $ids = DB::table('gestion.practica')->orderBy('id_practica')->pluck('id_practica');
        $this->assertNotEmpty($ids, 'Se requieren prácticas existentes para validar la integración real.');
        $reports = [];
        foreach ($ids as $id) {
            $evaluation = app(CertificateOrigins::class)->practice((int) $id);
            $this->assertSame(DB::table('gestion.practica_supervisor')->where('id_practica', $id)->count(),
                $evaluation['supervisores']->count(), 'La evaluación debe incluir todas las asignaciones reales, sin perder vínculos incoherentes en los JOIN.');
            $practice = $evaluation['practica'];
            $reports[] = [
                'id_practica' => (int) $id, 'id_persona_practicante' => $practice->id_persona_practicante,
                'id_persona' => $evaluation['id_persona'], 'institucion' => $practice->institucion, 'area' => $practice->area,
                'carrera' => $practice->carrera, 'fecha_inicio' => $practice->fecha_inicio, 'fecha_fin' => $practice->fecha_fin,
                'estado' => $practice->estado, 'supervision' => $evaluation['supervisores']->map(fn (object $row): array => [
                    'id_practica_supervisor' => $row->id_practica_supervisor, 'id_persona_trabajador' => $row->id_persona_trabajador,
                    'estado' => $row->estado, 'fecha_inicio' => $row->fecha_inicio, 'fecha_fin' => $row->fecha_fin,
                    'fecha_ingreso_trabajador' => $row->fecha_ingreso_trabajador ?? null,
                    'fecha_fin_trabajador' => $row->fecha_fin_trabajador ?? null,
                ])->all(), 'estado_elegibilidad' => $evaluation['estado_elegibilidad'], 'motivos_bloqueo' => $evaluation['motivos_bloqueo'],
                'periodo_supervision_cubierto' => $evaluation['periodo_supervision_cubierto'] ?? null,
                'supervision_acreditada' => $evaluation['supervision_acreditada'] ?? null,
                'puede_preparar' => $evaluation['puede_preparar'], 'constancias_existentes' => $evaluation['certificados_existentes']->count(),
            ];
            $this->assertSame((int) $practice->id_persona, $evaluation['id_persona']);
            $this->assertFalse($evaluation['requiere_asistencia_capacitacion']);
            if ($evaluation['motivos_bloqueo'] !== []) {
                $this->assertSame('Pendiente de validación', $evaluation['estado_elegibilidad']);
                $this->assertFalse($evaluation['puede_preparar']);
            }
        }
        fwrite(STDOUT, json_encode(['practices' => $reports], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL);
        $actorRecord = DB::table('acceso.usuario')->where('estado', true)->orderBy('id_usuario')->first(['id_usuario', 'id_persona']);
        $this->assertNotNull($actorRecord);
        $actor = new class extends User
        {
            public function isSystemAdministrator(): bool
            {
                return true;
            }

            public function permissionKeys(): array
            {
                return ['emision.ver', 'practicantes.ver'];
            }
        };
        $actor->forceFill(['id_usuario' => $actorRecord->id_usuario, 'id_persona' => $actorRecord->id_persona, 'estado' => true]);
        $actor->setRelation('roles', (new Role)->newCollection());
        $this->actingAs($actor);
        $this->getJson('/api/practices/'.$ids->first().'/evaluacion-certificacion')->assertOk()
            ->assertJsonPath('data.practica.id_practica', (int) $ids->first());
        $this->assertSame('on', DB::selectOne('SHOW transaction_read_only')->transaction_read_only);
    }

    /** @return array<string, string> */
    private function snapshot(): array
    {
        $snapshot = [];
        foreach (['gestion.persona', 'gestion.persona_practicante', 'gestion.practica', 'gestion.practica_supervisor', 'gestion.persona_trabajador',
            'gestion.institucion', 'gestion.area', 'certificado.certificado', 'certificado.certificado_documento', 'certificado.certificado_firma', 'certificado.tipo_certificado'] as $table) {
            $snapshot[$table] = DB::selectOne('SELECT md5(COALESCE(jsonb_agg(to_jsonb(t) ORDER BY 1)::text, \'[]\')) AS fingerprint FROM (SELECT * FROM '.$table.' ORDER BY 1) t')->fingerprint;
        }

        return $snapshot;
    }
}
