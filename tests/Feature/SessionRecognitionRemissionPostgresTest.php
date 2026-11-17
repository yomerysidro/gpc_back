<?php

namespace Tests\Feature;

use App\Models\User\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('postgresql')]
class SessionRecognitionRemissionPostgresTest extends TestCase
{
    public function test_explicit_remission_of_real_document_is_visible_only_inside_rollback_and_preserves_pdf(): void
    {
        if (getenv('SESSION_RECOGNITION_REMISSION_NEON') !== '1') {
            $this->markTestSkipped('Requires real database diagnostics with rollback.');
        }
        config(['database.default' => 'pgsql', 'database.connections.pgsql.database' => 'certificados_morales', 'database.connections.pgsql.url' => null]);
        DB::purge('pgsql');
        $this->assertSame('certificados_morales', DB::selectOne('select current_database() as name')->name);
        $snapshot = function (): array {
            $result = [];
            foreach (['certificado.certificado', 'certificado.certificado_documento', 'certificado.reconocimiento', 'certificado.certificado_firma'] as $table) {
                $result[$table] = DB::selectOne('select md5(coalesce(jsonb_agg(to_jsonb(t) order by 1)::text, \'[]\')) as fingerprint from (select * from '.$table.' order by 1) t')->fingerprint;
            }

            return $result;
        };
        $before = $snapshot();
        $realDisk = Storage::disk('local');
        $fake = Storage::fake('neon-session-remission');
        foreach ($realDisk->allFiles('certificados') as $path) {
            $fake->put($path, $realDisk->get($path));
        }
        Storage::set('local', $fake);
        Log::spy();
        DB::beginTransaction();
        DB::statement("SET LOCAL lock_timeout = '3s'");
        DB::statement("SET LOCAL statement_timeout = '10s'");
        try {
            $document = DB::table('certificado.certificado_documento')->where('id_certificado_documento', 22)->first();
            $this->assertSame(23, $document->id_certificado);
            $this->assertSame('PREPARADO', DB::table('certificado.certificado')->where('id_certificado', 23)->value('estado'));
            $this->assertSame(null, DB::table('certificado.reconocimiento')->where('id_reconocimiento', 24)->value('id_persona_trabajador'));
            $this->actingAs(User::findOrFail(1));
            $inbox = '/api/certificados-documentos/list?bandeja=por_firmar&id_actividad=3&per_page=100';
            $items = $this->getJson($inbox)->assertOk()->json('data.data');
            $this->assertNotContains(22, array_column($items, 'id_certificado_documento'));
            $input = ['accion' => 'revisar_documento', 'modalidad_reconocimiento' => 'sesion', 'id_sesion' => 5];
            $this->postJson('/api/certificaciones/ponente_sesion/17/preparar', $input)->assertOk()
                ->assertJsonPath('data.recuperado', true)->assertJsonPath('data.enviado', false)->assertJsonPath('data.estado_flujo', 'PREPARADO')
                ->assertJsonPath('data.id_certificado_documento', 22)->assertJsonPath('data.puede_enviar_gerencia', true);
            $review = $this->postJson('/api/certificados-documentos/22/revisar-envio', [])->assertOk()->json('data');
            $this->assertSame([], $review['documento']['firmantes']);
            $this->assertSame('PREPARADO', DB::table('certificado.certificado')->where('id_certificado', 23)->value('estado'));
            $send = ['token_revision' => $review['token_revision'], 'confirmar' => true];
            $this->postJson('/api/certificados-documentos/22/enviar-gerencia', $send)->assertOk()->assertJsonPath('data.enviado', true)
                ->assertJsonPath('data.estado_flujo', 'PENDIENTE_FIRMA')->assertJsonPath('data.documento.id_certificado_documento', 22);
            $this->assertSame('PENDIENTE_FIRMA', DB::table('certificado.certificado')->where('id_certificado', 23)->value('estado'));
            $items = $this->getJson($inbox)->assertOk()->json('data.data');
            $item = collect($items)->firstWhere('id_certificado_documento', 22);
            $this->assertNotNull($item);
            $this->assertSame('ponente_sesion', $item['origen']);
            $this->assertSame(3, $item['id_actividad']);
            $this->assertSame(5, $item['id_sesion']);
            $this->postJson('/api/certificados-documentos/22/enviar-gerencia', $send)->assertOk()->assertJsonPath('data.reintento', true);
            $this->assertSame($document->hash_certificado, hash_file('sha256', $fake->path($document->ruta_archivo)));
            $this->assertSame($document->codigo_verificacion, DB::table('certificado.certificado_documento')->where('id_certificado_documento', 22)->value('codigo_verificacion'));
            Log::shouldHaveReceived('info')->once()->withArgs(fn (string $event, array $context): bool => $event === 'certificado_documento.remitido_gerencia' && $context['id_certificado_documento'] === 22);
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
        $this->assertSame($before, $snapshot());
        $this->assertSame('PREPARADO', DB::table('certificado.certificado')->where('id_certificado', 23)->value('estado'));
        $this->assertSame($document->hash_certificado, hash_file('sha256', $realDisk->path($document->ruta_archivo)));
    }

    public function test_real_document_relations_and_persisted_state_without_remitting(): void
    {
        if (getenv('SESSION_RECOGNITION_REMISSION_NEON') !== '1') {
            $this->markTestSkipped('Requires explicit real database diagnostics.');
        }
        config(['database.default' => 'pgsql', 'database.connections.pgsql.database' => 'certificados_morales', 'database.connections.pgsql.url' => null]);
        DB::purge('pgsql');
        $this->assertSame('certificados_morales', DB::selectOne('select current_database() as name')->name);
        DB::beginTransaction();
        DB::statement('SET TRANSACTION READ ONLY');
        try {
            $row = DB::table('certificado.certificado_documento as d')->join('certificado.certificado as c', 'c.id_certificado', '=', 'd.id_certificado')
                ->leftJoin('certificado.reconocimiento as r', 'r.id_reconocimiento', '=', 'c.id_reconocimiento')
                ->leftJoin('certificado.actividad_ponente as ap', 'ap.id_actividad_ponente', '=', 'r.id_actividad_ponente')
                ->leftJoin('certificado.actividad_sesion as s', 's.id_actividad_sesion', '=', 'ap.id_actividad_sesion')
                ->leftJoin('certificado.actividad as a', 'a.id_actividad', '=', 's.id_actividad')
                ->where('d.id_certificado_documento', 22)->first(['d.id_certificado_documento', 'c.id_certificado', 'r.id_reconocimiento', 'ap.id_actividad_ponente', 's.id_actividad_sesion', 'a.id_actividad', 'a.id_area', 'd.estado as estado_documento', 'c.estado as estado_certificado', 'r.estado as estado_reconocimiento', 'd.ruta_archivo', 'd.hash_certificado', 'r.id_persona_trabajador']);
            Log::info('recognition.remission.real_state', (array) $row);
            $this->assertNotNull($row);
            $this->assertSame(23, $row->id_certificado);
            $this->assertSame(24, $row->id_reconocimiento);
            $this->assertSame(17, $row->id_actividad_ponente);
            $columns = Schema::getColumnListing('certificado.solicitud_decision');
            Log::info('recognition.remission.audit_schema', ['document_link_available' => in_array('id_certificado_documento', $columns, true)]);
            if (in_array('id_certificado_documento', $columns, true)) {
                Log::info('recognition.remission.events', ['events' => DB::table('certificado.solicitud_decision')->where('id_certificado_documento', 22)->get(array_values(array_intersect($columns, ['tipo_decision', 'estado_anterior', 'estado_nuevo', 'fecha_decision'])))->all()]);
            }
            $this->assertSame('on', DB::selectOne('show transaction_read_only')->transaction_read_only);
        } finally {
            DB::rollBack();
        }
    }
}
