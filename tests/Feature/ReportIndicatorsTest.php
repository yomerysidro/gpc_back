<?php

namespace Tests\Feature;

use App\Models\User\User;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ReportIndicatorsTest extends TestCase
{
    private array $totals = [];

    private array $queries = [];

    private bool $databaseFailure = false;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'report_indicators_test', 'database.connections.report_indicators_test' => ['driver' => 'report_indicators_test']]);
        DB::extend('report_indicators_test', function (array $config): Connection {
            $connection = Mockery::mock(Connection::class, [null, '', '', $config])->makePartial();
            $connection->shouldReceive('select')->andReturnUsing(function (string $sql): array {
                $this->queries[] = $sql;
                if ($this->databaseFailure) {
                    throw new RuntimeException('Simulated read failure');
                }
                foreach ($this->totals as $table => $total) {
                    if (str_contains($sql, '"certificado"."'.$table.'"')) {
                        return [(object) ['aggregate' => $total]];
                    }
                }

                return [(object) ['aggregate' => 0]];
            });

            return $connection;
        });
    }

    private function authorize(array $permissions): void
    {
        $user = new class extends User
        {
            public array $fixturePermissions = [];

            public function permissionKeys(): array
            {
                return $this->fixturePermissions;
            }
        };
        $user->estado = true;
        $user->fixturePermissions = $permissions;
        $this->actingAs($user, 'jwt');
    }

    public function test_registered_totals_use_one_count_per_principal_table_without_joins_or_pagination(): void
    {
        $this->authorize(['reportes.ver', 'actividades.ver', 'emision.ver', 'participantes.ver']);
        $this->totals = ['actividad' => 126, 'certificado' => 207, 'participacion' => 305, 'asistencia' => 418];

        foreach (['actividades' => 126, 'certificados' => 207, 'participaciones' => 305, 'asistencias' => 418] as $indicator => $total) {
            $this->getJson('/api/reportes/indicadores/'.$indicator)->assertOk()
                ->assertJsonPath('data.indicador', $indicator)
                ->assertJsonPath('data.total', $total)
                ->assertJsonPath('data.filtros', []);
        }

        $this->assertCount(4, $this->queries);
        foreach ($this->queries as $sql) {
            $this->assertStringContainsString('count(*)', strtolower($sql));
            foreach ([' join ', ' distinct ', ' limit ', ' offset ', ' where '] as $unexpected) {
                $this->assertStringNotContainsString($unexpected, strtolower($sql));
            }
        }
    }

    public function test_successful_empty_count_returns_zero(): void
    {
        $this->authorize(['reportes.ver', 'participantes.ver']);
        $this->getJson('/api/reportes/indicadores/participaciones')->assertOk()->assertJsonPath('data.total', 0);
    }

    public function test_unauthenticated_request_returns_401_without_querying_counts(): void
    {
        $this->getJson('/api/reportes/indicadores/actividades')->assertUnauthorized();
        $this->assertSame([], $this->queries);
    }

    public function test_missing_reports_permission_returns_403_without_querying_counts(): void
    {
        $this->authorize(['actividades.ver']);
        $this->getJson('/api/reportes/indicadores/actividades')->assertForbidden();
        $this->assertSame([], $this->queries);
    }

    public function test_reports_permission_does_not_grant_access_to_source_modules(): void
    {
        $this->authorize(['reportes.ver', 'actividades.ver']);
        $this->getJson('/api/reportes/indicadores/certificados')->assertForbidden();
        $this->getJson('/api/reportes/indicadores/asistencias')->assertForbidden();
        $this->assertSame([], $this->queries);
    }

    public function test_query_filters_are_rejected_instead_of_silently_changing_or_ignoring_scope(): void
    {
        $this->authorize(['reportes.ver', 'actividades.ver']);
        $this->getJson('/api/reportes/indicadores/actividades?estado=Activa')->assertUnprocessable();
        $this->assertSame([], $this->queries);
    }

    public function test_failed_count_returns_500_and_never_a_successful_zero(): void
    {
        $this->authorize(['reportes.ver', 'emision.ver']);
        $this->databaseFailure = true;
        $this->getJson('/api/reportes/indicadores/certificados')->assertStatus(500)->assertJsonPath('success', false)->assertJsonPath('data', []);
    }

    public function test_unknown_indicator_returns_404_without_querying_counts(): void
    {
        $this->authorize(['reportes.ver', 'actividades.ver']);
        $this->getJson('/api/reportes/indicadores/desconocido')->assertNotFound();
        $this->assertSame([], $this->queries);
    }
}
