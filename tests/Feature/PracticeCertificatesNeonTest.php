<?php

namespace Tests\Feature;

use App\Models\Role\Role;
use App\Models\User\User;
use App\Services\CertificateOrigins;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('postgresql')]
class PracticeCertificatesNeonTest extends TestCase
{
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
