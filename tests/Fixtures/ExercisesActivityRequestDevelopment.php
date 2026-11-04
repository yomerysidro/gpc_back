<?php

namespace Tests\Fixtures;

use App\Models\User\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;

trait ExercisesActivityRequestDevelopment
{
    private function actor(string $alias): User
    {
        $user = User::where('correo', $alias.'@solicitudes.test')->firstOrFail();
        $this->actingAs($user, 'jwt');

        return $user;
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'id_area' => (int) DB::table('gestion.area')->where('nombre', 'PRUEBAS - Área A')->value('id_area'),
            'id_tipo_actividad' => (int) DB::table('certificado.tipo_actividad')->where('nombre', 'PRUEBAS - Capacitación')->value('id_tipo_actividad'),
            'nombre' => 'PRUEBAS - Solicitud HTTP', 'descripcion' => 'Datos exclusivos del entorno de pruebas',
            'justificacion' => 'Verificar integración', 'fecha_inicio_propuesta' => '2026-12-01',
            'fecha_fin_propuesta' => '2026-12-02', 'sede_propuesta' => 'PRUEBAS',
        ];
    }

    private function submittedRequest(): int
    {
        $owner = $this->actor('solicitante-a');
        $id = $this->postJson('/api/activity-requests', [...$this->payload(), 'id_usuario_solicitante' => 999])->assertCreated()
            ->assertJsonPath('data.id_usuario_solicitante', $owner->id_usuario)->json('data.id_solicitud_actividad');
        $this->putJson('/api/activity-requests/'.$id, ['nombre' => 'PRUEBAS - Solicitud corregida'])->assertOk();
        $this->postJson('/api/activity-requests/'.$id.'/submit')->assertOk()->assertJsonPath('data.estado', 'Enviada');

        return (int) $id;
    }

    private function pendingRequest(): int
    {
        $id = $this->submittedRequest();
        $this->actor('rrhh');
        $this->postJson('/api/activity-requests/'.$id.'/review')->assertOk()->assertJsonPath('data.estado', 'En revisión');
        $this->postJson('/api/activity-requests/'.$id.'/derive')->assertOk()->assertJsonPath('data.estado', 'Pendiente de aprobación');

        return $id;
    }

    public function test_authorized_http_flow_creates_one_activity_and_records_real_test_actor(): void
    {
        $id = $this->pendingRequest();
        $manager = $this->actor('gerencia');

        $response = $this->postJson('/api/activity-requests/'.$id.'/approve', ['id_usuario_responsable' => 999])->assertOk()->assertJsonPath('data.estado', 'Aprobada');

        $activityId = $response->json('data.id_actividad');
        self::assertNotNull($activityId);
        self::assertSame(1, DB::table('certificado.actividad')->where('id_actividad', $activityId)->count());
        self::assertSame((int) $manager->id_usuario, (int) DB::table('certificado.solicitud_decision')->where('id_solicitud_actividad', $id)->where('tipo_decision', 'APROBAR')->value('id_usuario_responsable'));
        $this->postJson('/api/activity-requests/'.$id.'/approve')->assertConflict();
        $this->getJson('/api/activity-requests/decisions/history?per_page=1')->assertOk()->assertJsonPath('data.per_page', 1);
        $this->actor('solicitante-a');
        $this->getJson('/api/activity-requests/mine')->assertOk();
        $this->getJson('/api/activity-requests/'.$id.'/history')->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_observation_correction_resubmission_and_rejection(): void
    {
        $id = $this->submittedRequest();
        $this->actor('rrhh');
        $this->postJson('/api/activity-requests/'.$id.'/observe', ['observacion' => 'PRUEBAS - Corregir'])->assertOk()->assertJsonPath('data.estado', 'Observada');
        $this->actor('solicitante-a');
        $this->putJson('/api/activity-requests/'.$id, ['justificacion' => 'PRUEBAS - Corrección'])->assertOk();
        $this->postJson('/api/activity-requests/'.$id.'/submit')->assertOk();
        $this->actor('rrhh');
        $this->postJson('/api/activity-requests/'.$id.'/review')->assertOk();
        $this->postJson('/api/activity-requests/'.$id.'/derive')->assertOk();
        $this->actor('gerencia');
        $this->postJson('/api/activity-requests/'.$id.'/reject', ['observacion' => 'PRUEBAS - Rechazo'])->assertOk()->assertJsonPath('data.estado', 'Rechazada')->assertJsonPath('data.id_actividad', null);
    }

    public function test_area_scope_filters_lists_and_forbids_cross_area_creation_and_decisions(): void
    {
        $id = $this->pendingRequest();
        $this->actor('solicitante-b');
        $this->postJson('/api/activity-requests', $this->payload())->assertForbidden();
        $this->getJson('/api/activity-requests/'.$id)->assertForbidden();
        $this->postJson('/api/activity-requests/'.$id.'/approve')->assertForbidden();
        $this->getJson('/api/activity-requests/mine')->assertOk()->assertJsonPath('data.total', 0);
        $this->actor('gerencia');
        $areaB = (int) DB::table('gestion.area')->where('nombre', 'PRUEBAS - Área B')->value('id_area');
        $this->getJson('/api/activity-requests?id_area='.$areaB)->assertForbidden();
        $this->getJson('/api/activity-requests?estado='.urlencode('Pendiente de aprobación'))->assertOk()->assertJsonPath('data.data.0.id_area', $this->payload()['id_area']);
    }

    #[TestWith(['solicitante-a'])]
    #[TestWith(['rrhh'])]
    #[TestWith(['administrador'])]
    public function test_users_without_decision_permission_cannot_approve(string $alias): void
    {
        config(['module_permissions.administrator_formal_permissions' => []]);
        $id = $this->pendingRequest();
        $this->actor($alias);

        $this->postJson('/api/activity-requests/'.$id.'/approve')->assertForbidden();
        self::assertSame(0, DB::table('certificado.solicitud_decision')->where('id_solicitud_actividad', $id)->where('tipo_decision', 'APROBAR')->count());
    }

    public function test_invalid_payload_returns_422_without_writes(): void
    {
        $this->actor('solicitante-a');

        $this->postJson('/api/activity-requests', [])->assertUnprocessable()->assertJsonPath('success', false);
        $this->actor('gerencia');
        $this->getJson('/api/activity-requests?id_area=invalid')->assertUnprocessable();
    }

    public function test_anonymous_requests_return_401(): void
    {
        $this->postJson('/api/activity-requests', ['id_usuario_solicitante' => 1])->assertUnauthorized();
        $this->getJson('/api/activity-requests')->assertUnauthorized();
    }

    public function test_missing_area_or_permission_fails_closed(): void
    {
        $user = $this->actor('gerencia');
        DB::table('gestion.persona_trabajador')->where('id_persona', $user->id_persona)->update(['estado' => false]);
        $this->getJson('/api/activity-requests')->assertForbidden();
        $this->actor('rrhh');
        DB::table('acceso.rol_modulo_permiso')->delete();
        $this->getJson('/api/activity-requests')->assertForbidden();
    }

    public function test_expired_area_membership_is_not_authorized(): void
    {
        $user = $this->actor('rrhh');
        DB::table('gestion.persona_trabajador')->where('id_persona', $user->id_persona)->update(['fecha_fin' => today()->subDay()->toDateString()]);

        $this->getJson('/api/activity-requests')->assertForbidden();
    }

    public function test_decision_permission_does_not_allow_approving_own_request(): void
    {
        $id = $this->pendingRequest();
        $manager = $this->actor('gerencia');
        DB::table('certificado.solicitud_actividad')->where('id_solicitud_actividad', $id)->update(['id_usuario_solicitante' => $manager->id_usuario]);

        $this->postJson('/api/activity-requests/'.$id.'/approve')->assertForbidden();
        self::assertSame(0, DB::table('certificado.solicitud_decision')->where('id_solicitud_actividad', $id)->where('tipo_decision', 'APROBAR')->count());
    }
}
