<?php

namespace Tests\Feature;

use App\Http\Controllers\ActivityRequests\ActivityRequestController;
use App\Http\Controllers\ActivityRequests\ActivityRequestDecisionController;
use App\Http\Middleware\ActivityRequests\AuthorizeActivityRequest;
use App\Models\Activity\Activity;
use App\Models\ActivityRequest;
use App\Models\ActivityType\ActivityType;
use App\Models\Area\Area;
use App\Models\User\User;
use App\Services\ActivityRequests\ActivityRequestWorkflow;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ActivityRequestWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'database.connections.sqlite.url' => null]);
        DB::purge('sqlite');
        DB::statement("ATTACH DATABASE ':memory:' AS certificado");
        DB::statement("ATTACH DATABASE ':memory:' AS gestion");
        DB::statement("ATTACH DATABASE ':memory:' AS acceso");
        DB::statement('CREATE TABLE acceso.usuario (id_usuario INTEGER PRIMARY KEY, id_persona INTEGER)');
        DB::statement('CREATE TABLE gestion.persona (id_persona INTEGER PRIMARY KEY, nombres TEXT, apellido_paterno TEXT, apellido_materno TEXT)');
        Schema::create('gestion.area', function (Blueprint $table): void {
            $table->increments('id_area');
            $table->string('nombre')->nullable();
        });
        Schema::create('certificado.tipo_actividad', function (Blueprint $table): void {
            $table->increments('id_tipo_actividad');
            $table->string('nombre')->nullable();
        });
        Schema::create('certificado.actividad', function (Blueprint $table): void {
            $table->increments('id_actividad');
            $table->integer('id_area');
            $table->integer('id_tipo_actividad');
            $table->string('nombre');
            $table->text('descripcion')->nullable();
            $table->date('fecha_inicio');
            $table->date('fecha_fin');
            $table->string('sede')->nullable();
            $table->string('estado')->default('Activa');
        });
        Schema::create('certificado.solicitud_actividad', function (Blueprint $table): void {
            $table->increments('id_solicitud_actividad');
            $table->integer('id_area');
            $table->integer('id_usuario_solicitante');
            $table->integer('id_tipo_actividad');
            $table->string('nombre');
            $table->text('descripcion')->nullable();
            $table->text('justificacion');
            $table->date('fecha_inicio_propuesta');
            $table->date('fecha_fin_propuesta');
            $table->string('sede_propuesta')->nullable();
            $table->string('estado')->default('Borrador');
            $table->dateTime('fecha_solicitud');
            $table->integer('id_actividad')->nullable();
        });
        Schema::create('certificado.solicitud_decision', function (Blueprint $table): void {
            $table->increments('id_solicitud_decision');
            $table->integer('id_solicitud_actividad');
            $table->integer('id_usuario_responsable');
            $table->string('tipo_decision');
            $table->string('estado_anterior');
            $table->string('estado_nuevo');
            $table->text('observacion')->nullable();
            $table->dateTime('fecha_decision');
        });
        DB::table('gestion.area')->insert(['id_area' => 1]);
        DB::table('certificado.tipo_actividad')->insert(['id_tipo_actividad' => 1]);
    }

    public function test_owner_can_create_update_and_submit_a_draft(): void
    {
        $workflow = app(ActivityRequestWorkflow::class);
        $draft = $workflow->createDraft(7, $this->payload());
        self::assertSame(201, $draft['status'], json_encode($draft));
        $requestId = (int) $draft['data']->id_solicitud_actividad;

        $updated = $workflow->updateDraft(7, $requestId, ['nombre' => 'Actividad editada']);
        self::assertSame('Actividad editada', $updated['data']->nombre);
        self::assertSame(1, $workflow->ownRequests(7, ['estado' => 'Borrador'])['data']->total());
        self::assertSame('Enviada', $workflow->submit(7, $requestId)['data']->estado);
        self::assertSame(404, $workflow->detail(8, $requestId)['status']);
    }

    public function test_review_records_transition_and_approval_creates_one_linked_activity(): void
    {
        $workflow = app(ActivityRequestWorkflow::class);
        $requestId = (int) $workflow->createDraft(7, $this->payload())['data']->id_solicitud_actividad;
        $workflow->submit(7, $requestId);
        $workflow->decide(12, $requestId, 'REVISION_CONFORME');
        $workflow->decide(12, $requestId, 'DERIVAR_GERENCIA');
        $approved = $workflow->decide(15, $requestId, 'APROBAR', 'Aprobada');

        self::assertSame(200, $approved['status'], json_encode($approved));
        self::assertSame('Aprobada', $approved['data']->estado);
        self::assertNotNull($approved['data']->id_actividad);
        self::assertSame(1, DB::table('certificado.actividad')->count());
        self::assertSame(3, DB::table('certificado.solicitud_decision')->count());
        self::assertSame(409, $workflow->decide(15, $requestId, 'APROBAR')['status']);
        self::assertSame(1, DB::table('certificado.actividad')->count());
    }

    public function test_observation_returns_request_for_correction_and_preserves_history(): void
    {
        $workflow = app(ActivityRequestWorkflow::class);
        $requestId = (int) $workflow->createDraft(7, $this->payload())['data']->id_solicitud_actividad;
        $workflow->submit(7, $requestId);
        $workflow->decide(12, $requestId, 'REVISION_CONFORME');
        $observed = $workflow->decide(12, $requestId, 'OBSERVAR', 'Adjuntar sustento');

        self::assertSame(200, $observed['status'], json_encode($observed));
        self::assertSame('Observada', $observed['data']->estado);
        self::assertSame(2, DB::table('certificado.solicitud_decision')->count());
        self::assertSame(404, $workflow->updateDraft(8, $requestId, ['nombre' => 'Intruso'])['status']);
        self::assertSame('Observada', $workflow->updateDraft(7, $requestId, ['justificacion' => 'Sustento corregido'])['data']->estado);
        self::assertSame('Enviada', $workflow->submit(7, $requestId)['data']->estado);
    }

    public function test_history_returns_decisions_and_rejection_is_recorded(): void
    {
        $workflow = app(ActivityRequestWorkflow::class);
        $requestId = (int) $workflow->createDraft(7, $this->payload())['data']->id_solicitud_actividad;
        $workflow->submit(7, $requestId);
        $workflow->decide(12, $requestId, 'REVISION_CONFORME');
        $workflow->decide(12, $requestId, 'DERIVAR_GERENCIA');
        $rejected = $workflow->decide(15, $requestId, 'RECHAZAR', 'No cumple criterios');
        $history = $workflow->history($requestId);

        self::assertSame('Rechazada', $rejected['data']->estado);
        self::assertCount(3, $history['data']);
        self::assertSame('RECHAZAR', $history['data'][2]->tipo_decision);
        self::assertSame('Pendiente de aprobación', $history['data'][2]->estado_anterior);
        self::assertSame('Rechazada', $history['data'][2]->estado_nuevo);
        self::assertSame('No cumple criterios', $history['data'][2]->observacion);
        $decisionHistory = $workflow->decisionHistory(['tipo_decision' => 'RECHAZAR']);
        self::assertSame(1, $decisionHistory['data']->total());
        self::assertSame('Taller municipal', $decisionHistory['data']->items()[0]->solicitud_nombre);
    }

    public function test_invalid_transitions_and_competing_decisions_return_conflict(): void
    {
        $workflow = app(ActivityRequestWorkflow::class);
        $requestId = (int) $workflow->createDraft(7, $this->payload())['data']->id_solicitud_actividad;

        self::assertSame(409, $workflow->decide(15, $requestId, 'APROBAR')['status']);
        $workflow->submit(7, $requestId);
        $workflow->decide(12, $requestId, 'REVISION_CONFORME');
        $workflow->decide(12, $requestId, 'DERIVAR_GERENCIA');
        self::assertSame(200, $workflow->decide(15, $requestId, 'APROBAR')['status']);
        self::assertSame(409, $workflow->decide(16, $requestId, 'RECHAZAR')['status']);
        self::assertSame(1, DB::table('certificado.actividad')->count());
    }

    public function test_unregistered_contract_controllers_fail_closed_without_authenticated_identity(): void
    {
        $request = Request::create('/api/activity-requests', 'POST', ['id_usuario_solicitante' => 99] + $this->payload());
        $request->setUserResolver(static fn () => null);
        $create = app(ActivityRequestController::class)->store($request);

        $decisionRequest = Request::create('/api/activity-requests/1/approve', 'POST', ['id_usuario_responsable' => 99]);
        $decisionRequest->setUserResolver(static fn () => null);
        $approve = app(ActivityRequestDecisionController::class)->approve($decisionRequest, 1);

        self::assertSame(401, $create->getStatusCode());
        self::assertSame(401, $approve->getStatusCode());
        self::assertSame(0, DB::table('certificado.solicitud_actividad')->count());

        $testUser = new User;
        $testUser->setAttribute('id_usuario', 7);
        $this->actingAs($testUser, 'web');
        $authenticatedRequest = Request::create('/api/activity-requests', 'POST', ['id_usuario_solicitante' => 99] + $this->payload());
        $authenticatedRequest->setUserResolver(fn () => $this->app['auth']->guard('web')->user());
        $created = app(ActivityRequestController::class)->store($authenticatedRequest);

        self::assertSame(201, $created->getStatusCode());
        self::assertSame(7, (int) DB::table('certificado.solicitud_actividad')->value('id_usuario_solicitante'));
    }

    public function test_activity_request_model_uses_existing_relationships_and_guards_applicant_identity(): void
    {
        $request = new ActivityRequest(['id_usuario_solicitante' => 999]);

        self::assertNull($request->id_usuario_solicitante);
        self::assertInstanceOf(Area::class, $request->area()->getRelated());
        self::assertInstanceOf(User::class, $request->applicant()->getRelated());
        self::assertInstanceOf(ActivityType::class, $request->activityType()->getRelated());
        self::assertInstanceOf(Activity::class, $request->activity()->getRelated());
    }

    public function test_activity_request_routes_and_authorized_areas_are_registered_with_authentication_and_authorization(): void
    {
        $paths = array_map(static fn ($route): string => $route->uri(), app('router')->getRoutes()->getRoutes());

        self::assertCount(15, array_values(array_filter($paths, static fn (string $path): bool => str_contains($path, 'activity-requests'))));
        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            if (str_contains($route->uri(), 'activity-requests')) {
                self::assertContains('auth.cookie', $route->gatherMiddleware());
                self::assertContains(AuthorizeActivityRequest::class, $route->gatherMiddleware());
            }
        }
    }

    #[TestWith(['nombre', 101])]
    #[TestWith(['sede_propuesta', 101])]
    #[TestWith(['descripcion', 256])]
    public function test_returns_422_for_fields_exceeding_activity_limits(string $field, int $length): void
    {
        $result = app(ActivityRequestWorkflow::class)->createDraft(7, array_replace($this->payload(), [$field => str_repeat('á', $length)]));

        self::assertSame(422, $result['status']);
        self::assertArrayHasKey($field, $result['data']);
        self::assertSame(0, DB::table('certificado.solicitud_actividad')->count());
    }

    public function test_creates_draft_at_activity_limits_without_creating_activity(): void
    {
        $result = app(ActivityRequestWorkflow::class)->createDraft(7, array_replace($this->payload(), [
            'nombre' => str_repeat('á', 100), 'sede_propuesta' => str_repeat('á', 100), 'descripcion' => str_repeat('á', 255),
            'id_usuario_solicitante' => 99, 'estado' => 'Aprobada', 'id_actividad' => 99,
        ]));

        self::assertSame(201, $result['status']);
        self::assertSame('Borrador', $result['data']->estado);
        self::assertSame(7, (int) $result['data']->id_usuario_solicitante);
        self::assertNull($result['data']->id_actividad);
        self::assertSame(0, DB::table('certificado.actividad')->count());
    }

    public function test_empty_draft_returns_422_for_all_database_required_fields(): void
    {
        $result = app(ActivityRequestWorkflow::class)->createDraft(7, []);

        self::assertSame(422, $result['status']);
        foreach (['id_area', 'id_tipo_actividad', 'nombre', 'justificacion', 'fecha_inicio_propuesta', 'fecha_fin_propuesta'] as $field) {
            self::assertArrayHasKey($field, $result['data']);
        }
        self::assertSame(0, DB::table('certificado.solicitud_actividad')->count());
    }

    #[TestWith(['id_area', 999])]
    #[TestWith(['id_tipo_actividad', 999])]
    #[TestWith(['fecha_inicio_propuesta', '2026-02-30'])]
    #[TestWith(['fecha_fin_propuesta', '2026-11-01'])]
    #[TestWith(['nombre', '   '])]
    public function test_invalid_form_values_return_422(string $field, mixed $value): void
    {
        $result = app(ActivityRequestWorkflow::class)->createDraft(7, array_replace($this->payload(), [$field => $value]));

        self::assertSame(422, $result['status']);
        self::assertArrayHasKey($field, $result['data']);
        self::assertSame(0, DB::table('certificado.solicitud_actividad')->count());
    }

    #[TestWith(['OBSERVAR', null])]
    #[TestWith(['OBSERVAR', '   '])]
    #[TestWith(['RECHAZAR', null])]
    public function test_service_requires_reason_without_changing_request_or_history(string $decision, ?string $reason): void
    {
        $workflow = app(ActivityRequestWorkflow::class);
        $id = (int) $workflow->createDraft(7, $this->payload())['data']->id_solicitud_actividad;
        $workflow->submit(7, $id);
        $workflow->decide(12, $id, 'REVISION_CONFORME');
        $workflow->decide(12, $id, 'DERIVAR_GERENCIA');

        $result = $workflow->decide(15, $id, $decision, $reason);

        self::assertSame(422, $result['status']);
        self::assertArrayHasKey('observacion', $result['data']);
        self::assertSame('Pendiente de aprobación', $workflow->reviewDetail($id)['data']->estado);
        self::assertCount(2, $workflow->history($id)['data']);
    }

    public function test_submit_revalidates_persisted_fields_and_preserves_draft_on_failure(): void
    {
        $workflow = app(ActivityRequestWorkflow::class);
        $id = (int) $workflow->createDraft(7, $this->payload())['data']->id_solicitud_actividad;
        DB::table('certificado.solicitud_actividad')->where('id_solicitud_actividad', $id)->update(['nombre' => str_repeat('x', 101)]);

        $result = $workflow->submit(7, $id);

        self::assertSame(422, $result['status']);
        self::assertSame('Borrador', $workflow->detail(7, $id)['data']->estado);
        self::assertSame(0, DB::table('certificado.actividad')->count());
    }

    public function test_approval_failure_rolls_back_decision_activity_and_request(): void
    {
        $workflow = app(ActivityRequestWorkflow::class);
        $id = (int) $workflow->createDraft(7, $this->payload())['data']->id_solicitud_actividad;
        $workflow->submit(7, $id);
        $workflow->decide(12, $id, 'REVISION_CONFORME');
        $workflow->decide(12, $id, 'DERIVAR_GERENCIA');
        DB::statement("CREATE TRIGGER certificado.fail_activity BEFORE INSERT ON actividad BEGIN SELECT RAISE(ABORT, 'simulated integrity failure'); END");

        $result = $workflow->decide(15, $id, 'APROBAR');

        self::assertSame(409, $result['status']);
        self::assertSame('Pendiente de aprobación', $workflow->detail(7, $id)['data']->estado);
        self::assertNull($workflow->detail(7, $id)['data']->id_actividad);
        self::assertCount(2, $workflow->history($id)['data']);
        self::assertSame(0, DB::table('certificado.actividad')->count());
    }

    public function test_lists_support_upper_date_only_and_explicit_pagination(): void
    {
        $workflow = app(ActivityRequestWorkflow::class);
        $id = (int) $workflow->createDraft(7, $this->payload())['data']->id_solicitud_actividad;
        $workflow->createDraft(8, $this->payload());
        $workflow->submit(7, $id);
        $workflow->decide(12, $id, 'REVISION_CONFORME');
        $workflow->decide(12, $id, 'DERIVAR_GERENCIA');

        $pending = $workflow->list(['estado' => 'Pendiente de aprobación', 'fecha_hasta' => '2099-01-01']);
        $page = $workflow->decisionHistory(['fecha_hasta' => '2099-01-01', 'per_page' => 1, 'page' => 2])['data'];

        self::assertSame(1, $pending['data']->total());
        self::assertSame(1, $workflow->ownRequests(7)['data']->total());
        self::assertSame(2, $page->currentPage());
        self::assertSame(2, $page->total());
        self::assertSame('REVISION_CONFORME', $page->items()[0]->tipo_decision);
    }

    #[TestWith([['page' => 0]])]
    #[TestWith([['per_page' => 101]])]
    #[TestWith([['estado' => 'En revision']])]
    #[TestWith([['fecha_desde' => '2026-12-02', 'fecha_hasta' => '2026-12-01']])]
    public function test_invalid_list_filters_fail_validation(array $filters): void
    {
        $this->expectException(ValidationException::class);

        app(ActivityRequestWorkflow::class)->list($filters);
    }

    public function test_edit_validates_merged_dates_and_ignores_server_owned_fields(): void
    {
        $workflow = app(ActivityRequestWorkflow::class);
        $id = (int) $workflow->createDraft(7, $this->payload())['data']->id_solicitud_actividad;

        $invalid = $workflow->updateDraft(7, $id, ['fecha_inicio_propuesta' => '2026-12-03']);
        $ignored = $workflow->updateDraft(7, $id, ['estado' => 'Aprobada', 'id_actividad' => 3]);

        self::assertSame(422, $invalid['status']);
        self::assertSame(200, $ignored['status']);
        self::assertSame('Borrador', $ignored['data']->estado);
        self::assertSame('2026-12-01', $ignored['data']->fecha_inicio_propuesta);
        self::assertNull($ignored['data']->id_actividad);
        $workflow->submit(7, $id);
        self::assertSame(409, $workflow->updateDraft(7, $id, ['nombre' => 'Cambio'])['status']);
        self::assertSame(404, $workflow->history($id, 8)['status']);
        self::assertSame(404, $workflow->submit(8, $id)['status']);
    }

    public function test_approval_revalidates_legacy_data_before_creating_activity_or_decision(): void
    {
        $workflow = app(ActivityRequestWorkflow::class);
        $id = (int) $workflow->createDraft(7, $this->payload())['data']->id_solicitud_actividad;
        $workflow->submit(7, $id);
        $workflow->decide(12, $id, 'REVISION_CONFORME');
        $workflow->decide(12, $id, 'DERIVAR_GERENCIA');
        DB::table('certificado.solicitud_actividad')->where('id_solicitud_actividad', $id)->update(['sede_propuesta' => str_repeat('x', 101)]);

        $result = $workflow->decide(15, $id, 'APROBAR');

        self::assertSame(422, $result['status']);
        self::assertArrayHasKey('sede_propuesta', $result['data']);
        self::assertCount(2, $workflow->history($id)['data']);
        self::assertSame(0, DB::table('certificado.actividad')->count());
        self::assertSame('Pendiente de aprobación', $workflow->detail(7, $id)['data']->estado);
    }

    public function test_controller_returns_json_validation_and_uses_authenticated_decision_actor(): void
    {
        $user = new User;
        $user->setAttribute('id_usuario', 12);
        $invalid = Request::create('/api/activity-requests', 'POST', []);
        $invalid->setUserResolver(fn () => $user);
        $response = app(ActivityRequestController::class)->store($invalid);

        self::assertSame(422, $response->getStatusCode());
        self::assertFalse($response->getData(true)['success']);
        self::assertArrayHasKey('nombre', $response->getData(true)['data']);

        $workflow = app(ActivityRequestWorkflow::class);
        $id = (int) $workflow->createDraft(7, $this->payload())['data']->id_solicitud_actividad;
        $workflow->submit(7, $id);
        $request = Request::create('/unused', 'POST', ['observacion' => 'Corregir fechas', 'id_usuario_responsable' => 99]);
        $request->setUserResolver(fn () => $user);
        $observed = app(ActivityRequestDecisionController::class)->observe($request, $id);

        self::assertSame(200, $observed->getStatusCode());
        self::assertSame('Observada', $observed->getData(true)['data']['estado']);
        self::assertSame(12, (int) $workflow->history($id)['data'][0]->id_usuario_responsable);
        self::assertSame('Corregir fechas', $workflow->history($id)['data'][0]->observacion);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'id_area' => 1,
            'id_tipo_actividad' => 1,
            'nombre' => 'Taller municipal',
            'descripcion' => null,
            'justificacion' => 'Capacitación institucional',
            'fecha_inicio_propuesta' => '2026-12-01',
            'fecha_fin_propuesta' => '2026-12-02',
            'sede_propuesta' => 'Morales',
        ];
    }
}
