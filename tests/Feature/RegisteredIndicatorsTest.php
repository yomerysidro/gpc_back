<?php

namespace Tests\Feature;

use App\Models\User\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class RegisteredIndicatorsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null, 'app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);
        DB::purge('sqlite');
    }

    private function schema(): void
    {
        foreach (['gestion', 'certificado'] as $schema) {
            DB::statement("ATTACH DATABASE ':memory:' AS {$schema}");
        }
        foreach ([
            'gestion.area' => 'id_area INTEGER PRIMARY KEY, nombre TEXT',
            'gestion.persona_trabajador' => 'id_persona_trabajador INTEGER PRIMARY KEY, id_persona INTEGER, id_area INTEGER, estado BOOLEAN, fecha_ingreso TEXT, fecha_fin TEXT',
            'gestion.persona' => 'id_persona INTEGER PRIMARY KEY',
            'gestion.persona_practicante' => 'id_persona_practicante INTEGER PRIMARY KEY, id_persona INTEGER',
            'gestion.practica' => 'id_practica INTEGER PRIMARY KEY, id_persona_practicante INTEGER, id_area INTEGER',
            'certificado.actividad' => 'id_actividad INTEGER PRIMARY KEY, id_area INTEGER, estado TEXT',
            'certificado.participacion' => 'id_participacion INTEGER PRIMARY KEY, id_actividad INTEGER, id_persona INTEGER, estado TEXT',
            'certificado.asistencia' => 'id_asistencia INTEGER PRIMARY KEY, id_participacion INTEGER, estado TEXT',
            'certificado.certificado' => 'id_certificado INTEGER PRIMARY KEY, id_persona INTEGER, id_participacion INTEGER, id_practica INTEGER, id_reconocimiento INTEGER, id_tipo_certificado INTEGER, estado TEXT',
            'certificado.certificado_documento' => 'id_certificado_documento INTEGER PRIMARY KEY, id_certificado INTEGER',
            'certificado.tipo_certificado' => 'id_tipo_certificado INTEGER PRIMARY KEY',
            'certificado.reconocimiento' => 'id_reconocimiento INTEGER PRIMARY KEY, id_persona_trabajador INTEGER',
        ] as $table => $columns) {
            DB::statement("CREATE TABLE {$table} ({$columns})");
        }
    }

    private function actor(bool $administrator = true, array $permissions = ['reportes.ver', 'emision.ver', 'actividades.ver', 'participantes.ver']): void
    {
        $user = new class extends User
        {
            public bool $administrator;

            public array $permissions;

            public function isSystemAdministrator(): bool
            {
                return $this->administrator;
            }

            public function permissionKeys(): array
            {
                return $this->permissions;
            }
        };
        $user->forceFill(['id_usuario' => 50, 'id_persona' => 50, 'estado' => true]);
        $user->administrator = $administrator;
        $user->permissions = $permissions;
        $this->actingAs($user, 'jwt');
    }

    #[TestWith(['certificados'])]
    #[TestWith(['actividades'])]
    #[TestWith(['participaciones'])]
    #[TestWith(['asistencias'])]
    public function test_counts_real_rows_in_all_states_without_loading_records(string $indicator): void
    {
        $this->schema();
        $this->actor();
        $table = match ($indicator) {
            'certificados' => 'certificado.certificado',
            'actividades' => 'certificado.actividad',
            'participaciones' => 'certificado.participacion',
            'asistencias' => 'certificado.asistencia',
        };
        DB::table($table)->insert([['estado' => 'PREPARADO'], ['estado' => 'PENDIENTE_FIRMA'], ['estado' => 'ANULADO']]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson('/api/reportes/indicadores/'.$indicator)->assertOk()
            ->assertJsonPath('success', true)->assertJsonPath('data.indicador', $indicator)
            ->assertJsonPath('data.total', 3)->assertJsonPath('data.filtros', []);
        $queries = DB::getQueryLog();
        self::assertCount(1, $queries);
        self::assertStringContainsString('count(', strtolower($queries[0]['query']));
        self::assertSame(3, DB::table($table)->count());
    }

    public function test_empty_table_returns_numeric_zero(): void
    {
        $this->schema();
        $this->actor();
        $this->getJson('/api/reportes/indicadores/certificados')->assertOk()->assertJsonPath('data.total', 0);
    }

    public function test_missing_session_returns_401(): void
    {
        $this->getJson('/api/reportes/indicadores/actividades')->assertUnauthorized();
    }

    #[TestWith(['emision.ver'])]
    #[TestWith(['reportes.ver'])]
    public function test_missing_report_or_source_permission_returns_403(string $permission): void
    {
        $this->actor(permissions: [$permission]);
        $this->getJson('/api/reportes/indicadores/certificados')->assertForbidden();
    }

    public function test_unknown_indicator_returns_404(): void
    {
        $this->actor();
        $this->getJson('/api/reportes/indicadores/usuarios')->assertNotFound();
    }

    #[TestWith(['actividades', 1])]
    #[TestWith(['participaciones', 2])]
    #[TestWith(['asistencias', 3])]
    public function test_non_administrator_counts_only_active_authorized_areas(string $indicator, int $total): void
    {
        $this->freezeTime();
        $this->schema();
        $this->actor(administrator: false);
        DB::table('gestion.area')->insert([['id_area' => 1], ['id_area' => 2]]);
        DB::table('gestion.persona_trabajador')->insert([
            ['id_persona' => 50, 'id_area' => 1, 'estado' => true, 'fecha_ingreso' => today()->subDay()->toDateString()],
            ['id_persona' => 50, 'id_area' => 2, 'estado' => false, 'fecha_ingreso' => today()->subDay()->toDateString()],
        ]);
        DB::table('certificado.actividad')->insert([['id_actividad' => 1, 'id_area' => 1], ['id_actividad' => 2, 'id_area' => 2]]);
        DB::table('certificado.participacion')->insert([
            ['id_participacion' => 1, 'id_actividad' => 1], ['id_participacion' => 2, 'id_actividad' => 1], ['id_participacion' => 3, 'id_actividad' => 2],
        ]);
        DB::table('certificado.asistencia')->insert([
            ['id_participacion' => 1], ['id_participacion' => 1], ['id_participacion' => 2], ['id_participacion' => 3],
        ]);
        $this->getJson('/api/reportes/indicadores/'.$indicator.'?id_area=2')->assertOk()->assertJsonPath('data.total', $total);
    }

    public function test_actor_without_authorized_areas_gets_zero(): void
    {
        $this->schema();
        $this->actor(administrator: false);
        DB::table('certificado.actividad')->insert(['id_area' => 1]);
        $this->getJson('/api/reportes/indicadores/actividades')->assertOk()->assertJsonPath('data.total', 0);
    }

    public function test_certificate_count_reuses_document_scope_and_does_not_duplicate_pdf_versions(): void
    {
        $this->freezeTime();
        $this->schema();
        $this->actor(administrator: false);
        DB::table('gestion.area')->insert([['id_area' => 1], ['id_area' => 2]]);
        DB::table('gestion.persona_trabajador')->insert(['id_persona' => 50, 'id_area' => 1, 'estado' => true, 'fecha_ingreso' => today()->subDay()->toDateString()]);
        DB::table('gestion.persona')->insert(['id_persona' => 1]);
        DB::table('certificado.tipo_certificado')->insert(['id_tipo_certificado' => 1]);
        DB::table('certificado.actividad')->insert([['id_actividad' => 1, 'id_area' => 1], ['id_actividad' => 2, 'id_area' => 2]]);
        DB::table('certificado.participacion')->insert([
            ['id_participacion' => 1, 'id_actividad' => 1, 'id_persona' => 1], ['id_participacion' => 2, 'id_actividad' => 2, 'id_persona' => 1],
        ]);
        DB::table('certificado.certificado')->insert([
            ['id_certificado' => 1, 'id_participacion' => 1, 'id_persona' => 1, 'id_tipo_certificado' => 1],
            ['id_certificado' => 2, 'id_participacion' => 2, 'id_persona' => 1, 'id_tipo_certificado' => 1],
            ['id_certificado' => 3, 'id_participacion' => 1, 'id_persona' => 1, 'id_tipo_certificado' => 1],
        ]);
        DB::table('certificado.certificado_documento')->insert([['id_certificado' => 1], ['id_certificado' => 1], ['id_certificado' => 2]]);
        $this->getJson('/api/reportes/indicadores/certificados')->assertOk()->assertJsonPath('data.total', 2);
    }
}
