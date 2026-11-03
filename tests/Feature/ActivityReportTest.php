<?php

namespace Tests\Feature;

use App\Models\User\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use PDO;
use RuntimeException;
use Tests\TestCase;

class ActivityReportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'database.connections.sqlite.url' => null]);
        DB::purge('sqlite');
        if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            throw new LogicException('Las pruebas requieren SQLite temporal en memoria.');
        }
        DB::statement("ATTACH DATABASE ':memory:' AS certificado");
        Schema::create('certificado.tipo_actividad', function (Blueprint $table): void {
            $table->increments('id_tipo_actividad');
            $table->string('nombre');
        });
        Schema::create('certificado.actividad', function (Blueprint $table): void {
            $table->increments('id_actividad');
            $table->integer('id_tipo_actividad')->nullable();
            $table->string('nombre');
            $table->date('fecha_inicio');
            $table->date('fecha_fin')->nullable();
            $table->string('estado');
            $table->string('sede')->nullable();
        });
    }

    private function authorize(array $permissions = ['reportes.ver', 'actividades.ver']): void
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

    private function activities(): void
    {
        DB::table('certificado.tipo_actividad')->insert(['id_tipo_actividad' => 1, 'nombre' => 'Curso']);
        for ($id = 1; $id <= 35; $id++) {
            DB::table('certificado.actividad')->insert([
                'id_actividad' => $id, 'id_tipo_actividad' => 1, 'nombre' => 'Actividad '.$id,
                'fecha_inicio' => $id <= 20 ? '2026-01-15' : '2026-02-15',
                'fecha_fin' => '2026-02-28', 'estado' => $id % 2 ? 'Activa' : 'Cerrada', 'sede' => 'Municipalidad',
            ]);
        }
    }

    public function test_lists_all_matching_activities_without_requiring_code_and_paginates_on_server(): void
    {
        $this->authorize();
        $this->activities();
        DB::enableQueryLog();
        $this->getJson('/api/reportes/actividades?page=2&per_page=20')->assertOk()
            ->assertJsonPath('data.total', 35)->assertJsonPath('data.current_page', 2)
            ->assertJsonPath('data.last_page', 2)->assertJsonCount(15, 'data.data')
            ->assertJsonPath('data.data.0.id_actividad', 15)->assertJsonPath('data.data.0.tipo_actividad', 'Curso');
        $this->assertCount(2, DB::getQueryLog());
        DB::disableQueryLog();
    }

    public function test_date_state_and_search_filters_are_combined_before_counting_and_paginating(): void
    {
        $this->authorize();
        $this->activities();
        $this->getJson('/api/reportes/actividades?desde=2026-02-15&hasta=2026-02-15&estado=Activa&q=Actividad&per_page=3')
            ->assertOk()->assertJsonPath('data.total', 8)->assertJsonCount(3, 'data.data')
            ->assertJsonPath('data.criterio_fecha', 'fecha_inicio')->assertJsonPath('data.filtros.estado', 'Activa');
        $this->getJson('/api/reportes/actividades?q=35')->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.id_actividad', 35);
        $this->getJson('/api/reportes/actividades?hasta=2026-01-31')->assertOk()->assertJsonPath('data.total', 20);
    }

    public function test_empty_results_and_a_missing_type_preserve_valid_activity_records(): void
    {
        $this->authorize();
        $this->getJson('/api/reportes/actividades')->assertOk()->assertJsonPath('data.total', 0)->assertJsonCount(0, 'data.data');
        DB::table('certificado.actividad')->insert(['nombre' => 'Sin tipo', 'fecha_inicio' => '2026-01-01', 'estado' => 'Cerrada']);
        $this->getJson('/api/reportes/actividades')->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.tipo_actividad', null);
    }

    public function test_search_treats_sql_patterns_and_injection_text_as_literal_values(): void
    {
        $this->authorize();
        $this->activities();
        foreach (["%' OR 1=1 --", '%', '_'] as $search) {
            $this->getJson('/api/reportes/actividades?q='.rawurlencode($search))->assertOk()->assertJsonPath('data.total', 0);
        }
    }

    public function test_invalid_dates_and_page_sizes_return_422(): void
    {
        $this->authorize();
        $this->getJson('/api/reportes/actividades?desde=2026-03-01&hasta=2026-01-01')->assertUnprocessable()
            ->assertJsonPath('errors.hasta.0', 'La fecha de inicio no puede ser posterior a la fecha de fin.');
        $this->getJson('/api/reportes/actividades?per_page=101')->assertUnprocessable();
        $this->getJson('/api/reportes/actividades?page=0')->assertUnprocessable();
    }

    public function test_authentication_and_both_source_and_reports_permissions_are_required(): void
    {
        $this->getJson('/api/reportes/actividades')->assertUnauthorized();
        $this->authorize(['reportes.ver']);
        $this->getJson('/api/reportes/actividades')->assertForbidden();
        $this->authorize(['actividades.ver']);
        $this->getJson('/api/reportes/actividades')->assertForbidden();
    }

    public function test_a_failed_read_is_an_error_and_not_an_empty_successful_report(): void
    {
        $this->authorize();
        DB::connection()->setReadPdo(function (): PDO {
            throw new RuntimeException('Simulated read failure');
        });
        $this->getJson('/api/reportes/actividades')->assertStatus(500)->assertJsonPath('success', false)->assertJsonPath('data', []);
    }
}
