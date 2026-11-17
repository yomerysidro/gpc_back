<?php

namespace Tests\Feature;

use App\Http\Controllers\ActivityRequests\ActivityRequestController;
use App\Http\Controllers\ActivityRequests\ActivityRequestDecisionController;
use App\Models\ActivityRequest;
use App\Models\ActivityType\ActivityType;
use App\Models\Area\Area;
use App\Models\Role\Role;
use App\Models\User\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Fixtures\ModulePolicy;
use Tests\Fixtures\ModuleUser;
use Tests\Fixtures\RegistersActivityRequestReadRoutes;
use Tests\TestCase;

class ActivityRequestHttpTest extends TestCase
{
    use RegistersActivityRequestReadRoutes;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'database.connections.sqlite.url' => null]);
        DB::purge('sqlite');
        DB::statement("ATTACH DATABASE ':memory:' AS gestion");
        DB::statement("ATTACH DATABASE ':memory:' AS certificado");
        DB::statement("ATTACH DATABASE ':memory:' AS acceso");
        DB::statement('CREATE TABLE acceso.usuario (id_usuario INTEGER PRIMARY KEY, id_persona INTEGER)');
        DB::statement('CREATE TABLE gestion.persona (id_persona INTEGER PRIMARY KEY, nombres TEXT, apellido_paterno TEXT, apellido_materno TEXT)');
        DB::statement('CREATE TABLE gestion.area (id_area INTEGER PRIMARY KEY, nombre TEXT NOT NULL)');
        DB::statement('CREATE TABLE certificado.tipo_actividad (id_tipo_actividad INTEGER PRIMARY KEY, nombre TEXT NOT NULL, descripcion TEXT, estado BOOLEAN NOT NULL)');
        DB::statement('CREATE TABLE certificado.solicitud_actividad (id_solicitud_actividad INTEGER PRIMARY KEY, id_usuario_solicitante INTEGER, id_area INTEGER, id_tipo_actividad INTEGER, nombre TEXT, estado TEXT, fecha_solicitud TEXT)');
        DB::statement('CREATE TABLE certificado.solicitud_decision (id_solicitud_decision INTEGER PRIMARY KEY, id_solicitud_actividad INTEGER, id_usuario_responsable INTEGER, tipo_decision TEXT, estado_anterior TEXT, estado_nuevo TEXT, observacion TEXT, fecha_decision TEXT)');
        $this->registerActivityRequestReadRoutes();
        Route::post('api/activity-requests', [ActivityRequestController::class, 'store'])->middleware('auth:web');
        Route::post('api/activity-requests/{requestId}/approve', [ActivityRequestDecisionController::class, 'approve'])
            ->middleware(['auth:web', 'role:Gerencial']);
    }

    public function test_catalog_contracts_return_database_rows_and_filter_active_types(): void
    {
        $this->actingAs(new ModuleUser(['name' => 'Authorized fixture']), 'jwt');
        Gate::policy(Area::class, ModulePolicy::class);
        Gate::policy(ActivityType::class, ModulePolicy::class);
        DB::table('gestion.area')->insert(['id_area' => 43, 'nombre' => 'Área exclusiva del test']);
        DB::table('certificado.tipo_actividad')->insert([
            ['id_tipo_actividad' => 51, 'nombre' => 'Tipo activo del test', 'estado' => true],
            ['id_tipo_actividad' => 52, 'nombre' => 'Tipo inactivo del test', 'estado' => false],
        ]);

        $this->getJson('/api/areas/list')->assertOk()->assertJsonPath('data.0.id_area', 43)->assertJsonCount(1, 'data');
        $this->getJson('/api/ativicty-type')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/ativicty-type?estado=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id_tipo_actividad', 51);
        $this->getJson('/api/ativicty-type?estado=0')->assertOk()->assertJsonPath('data.0.id_tipo_actividad', 52);
        $this->getJson('/api/ativicty-type?estado=activo')->assertUnprocessable()->assertJsonPath('success', false);
        DB::table('gestion.area')->delete();
        $this->getJson('/api/areas/list')->assertOk()->assertExactJson(['success' => true, 'message' => 'Registros obtenidos correctamente.', 'data' => []]);
    }

    #[TestWith(['Enviada'])]
    #[TestWith(['En revisión'])]
    #[TestWith(['Pendiente de aprobación'])]
    public function test_review_queues_filter_database_rows_and_paginate(string $state): void
    {
        $this->requests();
        $this->actingAs($this->user(7, 'Gerencial'), 'web');

        $this->getJson('/api/activity-requests?'.http_build_query(['estado' => $state, 'per_page' => 1]))
            ->assertOk()->assertJsonPath('success', true)->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.per_page', 1)->assertJsonPath('data.last_page', 2)
            ->assertJsonPath('data.data.0.estado', $state)->assertJsonCount(1, 'data.data');
        $this->getJson('/api/activity-requests?'.http_build_query(['estado' => $state, 'per_page' => 1, 'page' => 2]))
            ->assertOk()->assertJsonPath('data.current_page', 2)->assertJsonCount(1, 'data.data');
    }

    public function test_owner_detail_history_and_catalog_relationships_use_persisted_ids(): void
    {
        $this->requests();
        $this->actingAs($this->user(7), 'web');

        $this->getJson('/api/activity-requests/mine')->assertOk()->assertJsonPath('data.total', 3);
        $this->getJson('/api/activity-requests/1')->assertOk()->assertJsonPath('data.id_area', 43)->assertJsonPath('data.id_tipo_actividad', 51);
        $this->getJson('/api/activity-requests/2')->assertNotFound();
        $this->getJson('/api/activity-requests/1/history')->assertOk()->assertJsonPath('data.0.observacion', 'Motivo exclusivo del test');
        $model = ActivityRequest::findOrFail(1);
        self::assertSame('Área exclusiva del test', $model->area->nombre);
        self::assertSame('Tipo exclusivo del test', $model->activityType->nombre);

        $this->actingAs($this->user(12, 'Gerencial'), 'web');
        $this->getJson('/api/activity-requests/1/review-detail')->assertOk();
        $this->getJson('/api/activity-requests/decisions/history?per_page=1')->assertOk()
            ->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.solicitud_nombre', 'Solicitud del test 1');
    }

    #[TestWith(['GET', '/api/activity-requests/mine'])]
    #[TestWith(['GET', '/api/activity-requests'])]
    #[TestWith(['POST', '/api/activity-requests'])]
    #[TestWith(['POST', '/api/activity-requests/1/approve'])]
    public function test_test_only_routes_reject_anonymous_requests(string $method, string $uri): void
    {
        $this->json($method, $uri, ['id_usuario_solicitante' => 7, 'id_usuario_responsable' => 12])->assertUnauthorized();
        self::assertSame(0, DB::table('certificado.solicitud_actividad')->count());
    }

    #[TestWith(['GET', '/api/activity-requests'])]
    #[TestWith(['GET', '/api/activity-requests/decisions/history'])]
    #[TestWith(['POST', '/api/activity-requests/1/approve'])]
    public function test_existing_role_middleware_rejects_users_without_required_role(string $method, string $uri): void
    {
        $this->actingAs($this->user(7), 'web');

        $this->json($method, $uri)->assertForbidden();
        self::assertSame(0, DB::table('certificado.solicitud_decision')->count());
    }

    private function user(int $id, ?string $roleName = null): User
    {
        $user = new User;
        $user->setAttribute('id_usuario', $id);
        $roles = (new Role)->newCollection();
        if ($roleName !== null) {
            $role = new Role;
            $role->setRawAttributes(['nombre' => $roleName, 'estado' => true]);
            $roles->push($role);
        }
        $user->setRelation('roles', $roles);

        return $user;
    }

    public function test_query_failures_keep_the_module_json_contract_without_exposing_sql(): void
    {
        $this->actingAs($this->user(7, 'Gerencial'), 'web');
        DB::statement('DROP TABLE certificado.solicitud_actividad');
        $expected = ['success' => false, 'message' => 'No se pudo completar la operación.', 'data' => null];

        $this->getJson('/api/activity-requests')->assertInternalServerError()->assertExactJson($expected);
        $this->getJson('/api/activity-requests/mine')->assertInternalServerError()->assertExactJson($expected);
        $this->getJson('/api/activity-requests/decisions/history')->assertInternalServerError()->assertExactJson($expected);
    }

    public function test_empty_database_lists_are_paginated_without_placeholder_records(): void
    {
        $this->actingAs($this->user(7, 'Gerencial'), 'web');

        foreach (['/api/activity-requests', '/api/activity-requests/mine', '/api/activity-requests/decisions/history'] as $uri) {
            $this->getJson($uri)->assertOk()->assertJsonPath('success', true)
                ->assertJsonPath('data.total', 0)->assertJsonPath('data.current_page', 1)
                ->assertJsonPath('data.per_page', 20)->assertJsonPath('data.data', []);
        }
    }

    private function requests(): void
    {
        DB::table('gestion.area')->insert(['id_area' => 43, 'nombre' => 'Área exclusiva del test']);
        DB::table('certificado.tipo_actividad')->insert(['id_tipo_actividad' => 51, 'nombre' => 'Tipo exclusivo del test', 'estado' => true]);
        foreach (['Enviada', 'En revisión', 'Pendiente de aprobación'] as $index => $state) {
            foreach ([7, 8] as $offset => $owner) {
                $id = $index * 2 + $offset + 1;
                DB::table('certificado.solicitud_actividad')->insert([
                    'id_solicitud_actividad' => $id, 'id_usuario_solicitante' => $owner,
                    'id_area' => 43, 'id_tipo_actividad' => 51, 'nombre' => 'Solicitud del test '.$id,
                    'estado' => $state, 'fecha_solicitud' => '2026-11-03 10:00:00',
                ]);
            }
        }
        DB::table('certificado.solicitud_decision')->insert([
            'id_solicitud_decision' => 1, 'id_solicitud_actividad' => 1, 'id_usuario_responsable' => 12,
            'tipo_decision' => 'OBSERVAR', 'estado_anterior' => 'Enviada', 'estado_nuevo' => 'Observada',
            'observacion' => 'Motivo exclusivo del test', 'fecha_decision' => '2026-11-03 10:01:00',
        ]);

    }
}
