<?php

namespace Tests\Feature;

use App\Models\Role\Role;
use App\Models\User\User;
use App\Services\CertificationReadiness;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class CertificationReadinessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'database.connections.sqlite.url' => null]);
        DB::purge('sqlite');
        DB::statement("ATTACH DATABASE ':memory:' AS gestion");
        DB::statement("ATTACH DATABASE ':memory:' AS certificado");
        foreach ([
            'gestion.area' => 'id_area INTEGER PRIMARY KEY, nombre TEXT',
            'gestion.persona' => 'id_persona INTEGER PRIMARY KEY, nombres TEXT, apellido_paterno TEXT, apellido_materno TEXT',
            'gestion.persona_trabajador' => 'id_persona_trabajador INTEGER PRIMARY KEY, id_persona INTEGER, id_area INTEGER, estado BOOLEAN, fecha_ingreso DATE, fecha_fin DATE',
            'certificado.actividad' => 'id_actividad INTEGER PRIMARY KEY, id_area INTEGER, nombre TEXT, estado TEXT, fecha_inicio DATE, fecha_fin DATE',
            'certificado.actividad_sesion' => 'id_actividad_sesion INTEGER PRIMARY KEY, id_actividad INTEGER, nombre TEXT, estado TEXT, fecha DATE, hora_inicio TEXT, hora_fin TEXT, lugar TEXT',
            'certificado.solicitud_actividad' => 'id_solicitud_actividad INTEGER PRIMARY KEY, id_actividad INTEGER, estado TEXT',
            'certificado.participacion' => 'id_participacion INTEGER PRIMARY KEY, id_actividad INTEGER, id_persona INTEGER, estado TEXT',
            'certificado.asistencia' => 'id_asistencia INTEGER PRIMARY KEY, id_participacion INTEGER, id_actividad_sesion INTEGER, estado TEXT, hora_entrada TEXT, hora_salida TEXT, UNIQUE(id_participacion,id_actividad_sesion)',
            'certificado.certificado' => 'id_certificado INTEGER PRIMARY KEY, id_participacion INTEGER, id_persona INTEGER, id_tipo_certificado INTEGER, id_practica INTEGER, id_reconocimiento INTEGER, estado TEXT, fecha_emision DATE',
            'certificado.tipo_certificado' => 'id_tipo_certificado INTEGER PRIMARY KEY, nombre_tipo TEXT, estado BOOLEAN',
        ] as $table => $columns) {
            DB::statement("CREATE TABLE {$table} ({$columns})");
        }
    }

    private function authorize(bool $administrator = true, array $permissions = ['actividades.ver', 'actividades.editar', 'emision.ver', 'participantes.ver', 'participantes.editar', 'participantes.eliminar']): void
    {
        $user = new class extends User
        {
            public bool $administrator = false;

            public array $permissions = [];

            public function isSystemAdministrator(): bool
            {
                return $this->administrator;
            }

            public function permissionKeys(): array
            {
                return $this->permissions;
            }
        };
        $user->forceFill(['id_usuario' => 8, 'id_persona' => 1, 'estado' => true]);
        $user->administrator = $administrator;
        $user->permissions = $permissions;
        $user->setRelation('roles', (new Role)->newCollection());
        $this->actingAs($user);
    }

    private function seedSession(string $state = 'Finalizada', ?string $attendance = 'PRESENTE'): void
    {
        DB::table('gestion.area')->insert([['id_area' => 1, 'nombre' => 'Área de prueba'], ['id_area' => 2, 'nombre' => 'Otra área']]);
        DB::table('gestion.persona')->insert(['id_persona' => 1, 'nombres' => 'Persona', 'apellido_paterno' => 'Prueba']);
        DB::table('gestion.persona_trabajador')->insert(['id_persona_trabajador' => 1, 'id_persona' => 1, 'id_area' => 1, 'estado' => true, 'fecha_ingreso' => '2020-01-01']);
        DB::table('certificado.actividad')->insert([
            ['id_actividad' => 1, 'id_area' => 1, 'nombre' => 'Actividad de prueba', 'estado' => 'Activa', 'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-12-31'],
            ['id_actividad' => 2, 'id_area' => 2, 'nombre' => 'Fuera de ámbito', 'estado' => 'Activa', 'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-12-31'],
        ]);
        foreach ([1 => [1, $state], 2 => [1, 'Activo'], 3 => [2, 'Activo']] as $id => [$activity, $status]) {
            DB::table('certificado.actividad_sesion')->insert(['id_actividad_sesion' => $id, 'id_actividad' => $activity, 'nombre' => 'Sesión '.$id, 'estado' => $status, 'fecha' => '2026-06-01', 'hora_inicio' => '08:00', 'hora_fin' => '10:00']);
        }
        DB::table('certificado.solicitud_actividad')->insert(['id_solicitud_actividad' => 1, 'id_actividad' => 1, 'estado' => 'Aprobada']);
        DB::table('certificado.participacion')->insert(['id_participacion' => 1, 'id_actividad' => 1, 'id_persona' => 1, 'estado' => 'Activo']);
        DB::table('certificado.tipo_certificado')->insert([['id_tipo_certificado' => 1, 'nombre_tipo' => 'Capacitación', 'estado' => true], ['id_tipo_certificado' => 2, 'nombre_tipo' => 'Reconocimiento', 'estado' => true]]);
        if ($attendance !== null) {
            DB::table('certificado.asistencia')->insert(['id_asistencia' => 1, 'id_participacion' => 1, 'id_actividad_sesion' => 1, 'estado' => $attendance]);
        }
    }

    private function approveTestPolicy(bool $entry = false, bool $exit = false): void
    {
        $policy = [
            'reference' => 'Política exclusiva de pruebas; no es norma municipal',
            'active_participation_states' => ['Activo'], 'eligible_attendance_states' => ['PRESENTE'],
            'ineligible_attendance_states' => ['AUSENTE'], 'require_entry' => $entry, 'require_exit' => $exit,
            'regularizations' => 'not_applicable', 'require_closed_activity' => false, 'required_sessions' => 'all',
        ];
        config(['certification.eligibility.sesion.1' => $policy, 'certification.eligibility.actividad.1' => $policy,
            'certification.session_closure' => [...$policy, 'final_state' => 'Finalizada', 'open_states' => ['Activo', 'activo']]]);
    }

    public function test_session_closure_requires_selected_attendances_rather_than_every_activity_enrollment(): void
    {
        $this->seedSession('Activo');
        $this->approveTestPolicy();
        DB::table('gestion.persona')->insert(['id_persona' => 2, 'nombres' => 'No seleccionado']);
        DB::table('certificado.participacion')->insert(['id_participacion' => 2, 'id_actividad' => 1, 'id_persona' => 2, 'estado' => 'Activo']);
        $session = DB::table('certificado.actividad_sesion')->where('id_actividad_sesion', 1)->first();
        self::assertSame([], app(CertificationReadiness::class)->closureBlockers($session));
        $this->assertDatabaseMissing('certificado.asistencia', ['id_participacion' => 2, 'id_actividad_sesion' => 1]);
        DB::table('certificado.asistencia')->delete();
        self::assertNotEmpty(app(CertificationReadiness::class)->closureBlockers($session));
    }

    public function test_new_routes_require_401(): void
    {
        $this->getJson('/api/certificaciones/pendientes')->assertUnauthorized();
        $this->getJson('/api/activity-session/1/beneficiarios')->assertUnauthorized();
        $this->getJson('/api/activity/1/beneficiarios-certificacion')->assertUnauthorized();
        $this->postJson('/api/activity-session/1/finalizar')->assertUnauthorized();
        $this->postJson('/api/activity-session/1/preparar-certificados')->assertUnauthorized();
    }

    public function test_preparation_is_explicitly_blocked_even_for_eligible_session_and_writes_nothing(): void
    {
        $this->seedSession();
        $this->approveTestPolicy();
        $this->authorize(permissions: ['actividades.ver', 'emision.ver', 'emision.crear', 'participantes.ver']);
        $this->getJson('/api/activity-session/1/beneficiarios?id_tipo_certificado=1')
            ->assertOk()->assertJsonPath('data.finalizada', true)
            ->assertJsonPath('data.tipo_certificado_compatible', true)
            ->assertJsonPath('data.beneficiarios.data.0.estado_elegibilidad', 'Elegible');
        $this->postJson('/api/activity-session/1/preparar-certificados', ['id_tipo_certificado' => 1])
            ->assertConflict()->assertJsonPath('data.puede_preparar', false)
            ->assertJsonPath('data.certificado_sesion', null);
        $this->assertDatabaseCount('certificado.certificado', 0);
        $this->assertDatabaseHas('certificado.actividad', ['id_actividad' => 1, 'estado' => 'Activa']);
    }

    public function test_preparation_requires_permission_and_consistent_valid_context(): void
    {
        $this->seedSession();
        $this->authorize();
        $this->postJson('/api/activity-session/1/preparar-certificados', ['id_tipo_certificado' => 1])->assertForbidden();
        $this->authorize(permissions: ['actividades.ver', 'emision.ver', 'emision.crear', 'participantes.ver']);
        $this->postJson('/api/activity-session/1/preparar-certificados', [])->assertUnprocessable();
        $this->postJson('/api/activity-session/1/preparar-certificados', ['id_tipo_certificado' => 1, 'id_actividad' => 2])->assertUnprocessable();
        $this->postJson('/api/activity-session/1/preparar-certificados', ['id_tipo_certificado' => 1, 'fecha_emision' => '2026-11-05'])->assertUnprocessable();
        $this->assertDatabaseCount('certificado.certificado', 0);
    }

    public function test_malformed_policy_blocks_closure_and_eligibility_without_500(): void
    {
        $this->seedSession('Activo');
        $this->authorize();
        $this->approveTestPolicy();
        config(['certification.session_closure.eligible_attendance_states' => [['invalid']]]);
        $this->postJson('/api/activity-session/1/finalizar')->assertConflict();
        $this->assertDatabaseHas('certificado.actividad_sesion', ['id_actividad_sesion' => 1, 'estado' => 'Activo']);
        DB::table('certificado.actividad_sesion')->where('id_actividad_sesion', 1)->update(['estado' => 'Finalizada']);
        config(['certification.eligibility.sesion.1.eligible_attendance_states' => [['invalid']]]);
        $this->getJson('/api/activity-session/1/beneficiarios?id_tipo_certificado=1')->assertOk()
            ->assertJsonPath('data.tipo_certificado_compatible', null)
            ->assertJsonPath('data.beneficiarios.data.0.estado_elegibilidad', 'Pendiente de validación');
    }

    public function test_read_permissions_do_not_grant_finalization_and_missing_permissions_return_403(): void
    {
        $this->seedSession('Activo');
        $this->authorize(permissions: ['actividades.ver', 'emision.ver', 'participantes.ver']);
        $this->postJson('/api/activity-session/1/finalizar')->assertForbidden();
        $this->authorize(permissions: ['actividades.ver']);
        $this->getJson('/api/activity-session/1/beneficiarios')->assertForbidden();
        $this->getJson('/api/certificaciones/pendientes')->assertForbidden();
    }

    public function test_finalization_without_approved_policy_returns_409_and_changes_nothing(): void
    {
        $this->seedSession('Activo');
        $this->authorize();
        $response = $this->postJson('/api/activity-session/1/finalizar')->assertConflict()
            ->assertJsonPath('success', false);
        $response->assertJsonPath('message', 'Falta una política aprobada de cierre, estados de asistencia y regularizaciones.')
            ->assertJsonPath('data.motivos_bloqueo.0', $response->json('message'));
        $this->assertDatabaseHas('certificado.actividad_sesion', ['id_actividad_sesion' => 1, 'estado' => 'Activo']);
        $this->assertDatabaseCount('certificado.certificado', 0);
    }

    public function test_session_finalization_is_idempotent_and_keeps_activity_active_and_other_sessions_open(): void
    {
        $this->seedSession('Activo');
        $this->authorize();
        $this->approveTestPolicy();
        Log::shouldReceive('info')->once()->with('actividad_sesion.finalizada', \Mockery::on(fn (array $context): bool => $context['id_usuario'] === 8 && $context['id_actividad_sesion'] === 1));
        $this->postJson('/api/activity-session/1/finalizar')->assertOk()->assertJsonPath('data.sesion.estado', 'Finalizada');
        $this->postJson('/api/activity-session/1/finalizar')->assertOk();
        $this->assertDatabaseHas('certificado.actividad', ['id_actividad' => 1, 'estado' => 'Activa']);
        $this->assertDatabaseHas('certificado.actividad_sesion', ['id_actividad_sesion' => 2, 'estado' => 'Activo']);
        $this->assertDatabaseCount('certificado.certificado', 0);
        self::assertSame(0, DB::transactionLevel());
    }

    #[TestWith([null])]
    #[TestWith(['nn'])]
    #[TestWith(['PENDIENTE'])]
    #[TestWith(['POR_REGULARIZAR'])]
    public function test_pending_attendance_prevents_finalization_with_409(?string $attendance): void
    {
        $this->seedSession('Activo', $attendance);
        $this->authorize();
        $this->approveTestPolicy();
        $this->postJson('/api/activity-session/1/finalizar')->assertConflict();
        $this->assertDatabaseHas('certificado.actividad_sesion', ['id_actividad_sesion' => 1, 'estado' => 'Activo']);
    }

    public function test_unapproved_activity_prevents_finalization_with_409(): void
    {
        $this->seedSession('Activo');
        $this->authorize();
        $this->approveTestPolicy();
        DB::table('certificado.solicitud_actividad')->delete();
        $this->postJson('/api/activity-session/1/finalizar')->assertConflict();
    }

    #[TestWith(['PRESENTE', 'Elegible'])]
    #[TestWith(['AUSENTE', null])]
    #[TestWith(['nn', null])]
    #[TestWith([null, null])]
    public function test_session_evaluation_uses_policy_and_real_attendance(?string $attendance, ?string $expected): void
    {
        $this->seedSession(attendance: $attendance);
        $this->authorize();
        $this->approveTestPolicy();
        $this->getJson('/api/activity-session/1/beneficiarios?id_tipo_certificado=1')->assertOk()
            ->assertJsonPath('data.beneficiarios.data.0.estado_elegibilidad', $expected)
            ->assertJsonCount($attendance === 'PRESENTE' ? 1 : 0, 'data.beneficiarios.data')
            ->assertJsonPath('data.total_elegibles', $attendance === 'PRESENTE' ? 1 : 0)
            ->assertJsonPath('data.contexto.estado_actividad', 'Activa')->assertJsonPath('data.puede_preparar', false)
            ->assertJsonMissingPath('data.beneficiarios.data.0.dni');
        $this->assertDatabaseCount('certificado.certificado', 0);
    }

    public function test_present_attendance_without_policy_is_pending_and_open_session_has_no_candidates(): void
    {
        $this->seedSession();
        $this->authorize();
        $this->getJson('/api/activity-session/1/beneficiarios?id_tipo_certificado=1')->assertOk()->assertJsonPath('data.beneficiarios.data.0.estado_elegibilidad', 'Pendiente de validación');
        $this->getJson('/api/activity-session/2/beneficiarios?id_tipo_certificado=1')->assertOk()
            ->assertJsonCount(0, 'data.beneficiarios.data')->assertJsonPath('data.total_elegibles', 0)
            ->assertJsonPath('data.finalizada', false)->assertJsonPath('data.puede_consultar_beneficiarios', true);
    }

    #[TestWith([true, false])]
    #[TestWith([false, true])]
    public function test_entry_or_exit_is_required_only_by_explicit_policy(bool $entry, bool $exit): void
    {
        $this->seedSession();
        $this->authorize();
        $this->approveTestPolicy($entry, $exit);
        $this->getJson('/api/activity-session/1/beneficiarios?id_tipo_certificado=1')->assertOk()->assertJsonPath('data.beneficiarios.data.0.estado_elegibilidad', 'Pendiente de validación');
    }

    public function test_regularization_requirement_without_persistent_model_stays_pending(): void
    {
        $this->seedSession();
        $this->authorize();
        $this->approveTestPolicy();
        config(['certification.eligibility.sesion.1.regularizations' => 'required']);
        $this->getJson('/api/activity-session/1/beneficiarios?id_tipo_certificado=1')->assertOk()->assertJsonPath('data.beneficiarios.data.0.estado_elegibilidad', 'Pendiente de validación');
    }

    public function test_global_evaluation_requires_all_sessions_only_when_policy_defines_it(): void
    {
        $this->seedSession();
        $this->authorize();
        $this->approveTestPolicy();
        $this->getJson('/api/activity/1/beneficiarios-certificacion?id_tipo_certificado=1')->assertOk()->assertJsonPath('data.beneficiarios.data.0.estado_elegibilidad', 'Pendiente de validación');
        DB::table('certificado.actividad_sesion')->where('id_actividad_sesion', 2)->update(['estado' => 'Finalizada']);
        DB::table('certificado.asistencia')->insert(['id_asistencia' => 2, 'id_participacion' => 1, 'id_actividad_sesion' => 2, 'estado' => 'PRESENTE']);
        $this->getJson('/api/activity/1/beneficiarios-certificacion?id_tipo_certificado=1')->assertOk()->assertJsonPath('data.beneficiarios.data.0.estado_elegibilidad', 'Elegible');
        config(['certification.eligibility.actividad.1.require_closed_activity' => true]);
        $this->getJson('/api/activity/1/beneficiarios-certificacion?id_tipo_certificado=1')->assertOk()->assertJsonPath('data.beneficiarios.data.0.estado_elegibilidad', 'Pendiente de validación');
    }

    public function test_unmapped_certificate_type_cannot_be_assumed_compatible(): void
    {
        $this->seedSession();
        $this->authorize();
        $this->approveTestPolicy();
        $this->getJson('/api/activity-session/1/beneficiarios?id_tipo_certificado=2')->assertOk()->assertJsonPath('data.beneficiarios.data.0.estado_elegibilidad', 'Pendiente de validación');
    }

    public function test_queue_is_paginated_scoped_and_does_not_attribute_global_certificates_to_sessions(): void
    {
        $this->seedSession();
        $this->authorize(false);
        DB::table('certificado.certificado')->insert(['id_certificado' => 9, 'id_participacion' => 1, 'id_persona' => 1, 'id_tipo_certificado' => 1, 'estado' => 'registrado']);
        $this->getJson('/api/certificaciones/pendientes?per_page=1')->assertOk()->assertJsonPath('data.total', 2)->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.certificados_registrados_participaciones', 1)->assertJsonPath('data.data.0.certificados_atribuidos_sesion', null);
        $this->getJson('/api/certificaciones/pendientes?id_sesion=3')->assertOk()->assertJsonPath('data.total', 0);
        $this->getJson('/api/activity-session/3/beneficiarios')->assertForbidden();
        $this->postJson('/api/activity-session/3/finalizar')->assertForbidden();
        $this->getJson('/api/activity/2/beneficiarios-certificacion')->assertForbidden();
        $this->getJson('/api/certificaciones/pendientes?origen=actividad')->assertOk()->assertJsonPath('data.total', 1);
        $this->assertDatabaseCount('certificado.certificado', 1);
    }

    public function test_administrator_with_effective_permissions_has_global_scope(): void
    {
        $this->seedSession();
        $this->authorize();
        $this->getJson('/api/certificaciones/pendientes')->assertOk()->assertJsonPath('data.total', 3);
    }

    public function test_context_tampering_and_invalid_pagination_return_422(): void
    {
        $this->seedSession();
        $this->authorize();
        $this->getJson('/api/activity-session/1/beneficiarios?id_actividad=2')->assertUnprocessable();
        $this->getJson('/api/activity/1/beneficiarios-certificacion?id_sesion=1')->assertUnprocessable();
        $this->getJson('/api/certificaciones/pendientes?per_page=101')->assertUnprocessable();
        $this->getJson('/api/certificaciones/pendientes?origen=actividad&id_sesion=1')->assertUnprocessable();
        $this->postJson('/api/activity-session/1/finalizar', ['estado' => 'Finalizada'])->assertUnprocessable();
        $this->getJson('/api/activity-session/999/beneficiarios')->assertNotFound();
        $this->postJson('/api/activity-session/999/finalizar')->assertNotFound();
    }

    public function test_generic_session_crud_cannot_bypass_closure_or_reopen_finalized_session(): void
    {
        $this->seedSession('Activo');
        $this->authorize();
        $this->patchJson('/api/activity-session/1', ['estado' => 'Finalizada'])->assertUnprocessable();
        DB::table('certificado.actividad_sesion')->where('id_actividad_sesion', 1)->update(['estado' => 'Finalizada']);
        $this->patchJson('/api/activity-session/1', ['estado' => 'Activo'])->assertConflict();
        $this->deleteJson('/api/activity-session/1')->assertForbidden();
    }

    public function test_finalized_attendance_cannot_be_moved_or_deleted_by_generic_crud(): void
    {
        $this->seedSession();
        $this->authorize();
        $this->patchJson('/api/attendance/update/1', ['ID_ACTIVIDAD_SESION' => 2])->assertConflict();
        $this->deleteJson('/api/attendance/delete/1')->assertConflict();
        $this->assertDatabaseHas('certificado.asistencia', ['id_asistencia' => 1, 'id_actividad_sesion' => 1]);
    }

    public function test_activity_operator_can_consult_closure_capabilities_without_certificate_permissions(): void
    {
        $this->seedSession('Activo');
        $this->authorize(permissions: ['actividades.ver', 'actividades.editar']);
        $this->getJson('/api/activity-session/1')->assertOk()->assertJsonPath('data.puede_finalizarse', false)
            ->assertJsonPath('data.puede_certificarse', false);
        $this->approveTestPolicy();
        $this->getJson('/api/activity-session/1')->assertOk()->assertJsonPath('data.puede_finalizarse', true);
    }

    public function test_certificate_administrative_crud_rejects_session_context_instead_of_silently_ignoring_it(): void
    {
        $this->seedSession();
        $this->authorize(permissions: ['emision.crear']);
        $this->postJson('/api/certificados/create', [
            'id_persona' => 1, 'id_participacion' => 1, 'id_tipo_certificado' => 1,
            'estado' => true, 'notificado' => false, 'id_sesion' => 1, 'origen' => 'sesion',
        ])->assertUnprocessable();
        $this->assertDatabaseCount('certificado.certificado', 0);
    }

    public function test_beneficiary_query_count_does_not_grow_per_person(): void
    {
        $this->seedSession();
        $this->authorize();
        DB::enableQueryLog();
        $this->getJson('/api/activity-session/1/beneficiarios?per_page=100')->assertOk();
        $singleCount = count(DB::getQueryLog());
        for ($id = 2; $id <= 30; $id++) {
            DB::table('gestion.persona')->insert(['id_persona' => $id, 'nombres' => 'Prueba', 'apellido_paterno' => 'Lote']);
            DB::table('certificado.participacion')->insert(['id_participacion' => $id, 'id_persona' => $id, 'id_actividad' => 1, 'estado' => 'Activo']);
            DB::table('certificado.asistencia')->insert(['id_asistencia' => $id, 'id_participacion' => $id, 'id_actividad_sesion' => 1, 'estado' => 'PRESENTE']);
        }
        DB::flushQueryLog();
        $this->getJson('/api/activity-session/1/beneficiarios?per_page=100')->assertOk()->assertJsonCount(30, 'data.beneficiarios.data');
        self::assertSame($singleCount, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }

    public function test_two_present_participants_have_the_same_counters_on_list_detail_and_beneficiaries_and_can_finalize(): void
    {
        $this->seedSession('Activo');
        $this->authorize();
        DB::table('gestion.persona')->insert(['id_persona' => 2, 'nombres' => 'Segunda', 'apellido_paterno' => 'Prueba']);
        DB::table('certificado.participacion')->insert(['id_participacion' => 2, 'id_persona' => 2, 'id_actividad' => 1, 'estado' => 'Activo']);
        DB::table('certificado.asistencia')->insert(['id_asistencia' => 2, 'id_participacion' => 2, 'id_actividad_sesion' => 1, 'estado' => 'PRESENTE']);

        $detail = $this->getJson('/api/activity-session/1')->assertOk()
            ->assertJsonPath('data.contadores.inscritos_actividad_vigentes', 2)
            ->assertJsonPath('data.contadores.participantes_sesion', 2)
            ->assertJsonPath('data.contadores.presentes', 2)
            ->assertJsonPath('data.contadores.ausentes', 0)
            ->assertJsonPath('data.contadores.pendientes', 0)
            ->assertJsonPath('data.contadores.por_regularizar', null)
            ->assertJsonPath('data.puede_finalizarse', false)->json('data.contadores');
        $list = collect($this->getJson('/api/activity-session')->assertOk()->json('data'))->firstWhere('id_actividad_sesion', 1);
        self::assertSame($detail, $list['contadores']);
        $this->getJson('/api/activity-session/1/beneficiarios?per_page=1')->assertOk()
            ->assertJsonPath('data.contadores', $detail)->assertJsonCount(0, 'data.beneficiarios.data')
            ->assertJsonPath('data.beneficiarios.total', 0);
        $this->postJson('/api/activity-session/1/finalizar')->assertConflict()
            ->assertJsonPath('data.sesion.contadores', $detail);

        $this->approveTestPolicy();
        $this->getJson('/api/activity-session/1')->assertOk()->assertJsonPath('data.puede_finalizarse', true);
        foreach ([1, 2] as $attempt) {
            $this->postJson('/api/activity-session/1/finalizar')->assertOk()
                ->assertJsonPath('data.sesion.estado', 'Finalizada')
                ->assertJsonPath('data.sesion.contadores', $detail)->assertJsonPath('data.estado_actividad', 'Activa');
        }
        $this->getJson('/api/activity-session/1/beneficiarios?per_page=1')->assertOk()
            ->assertJsonPath('data.contadores', $detail)->assertJsonCount(1, 'data.beneficiarios.data')
            ->assertJsonPath('data.beneficiarios.total', 2);
        $this->assertDatabaseHas('certificado.actividad_sesion', ['id_actividad_sesion' => 2, 'estado' => 'Activo']);
        $this->assertDatabaseCount('certificado.certificado', 0);
        $this->assertDatabaseCount('certificado.asistencia', 2);
    }

    public function test_counters_exclude_inactive_participations_and_other_sessions_and_keep_unknown_states_pending(): void
    {
        $this->seedSession('Activo');
        $this->authorize();
        foreach ([2 => 'Inactivo', 3 => 'Activo', 4 => 'Activo', 5 => 'Activo'] as $id => $state) {
            DB::table('certificado.participacion')->insert(['id_participacion' => $id, 'id_persona' => $id, 'id_actividad' => 1, 'estado' => $state]);
        }
        foreach ([2 => 'PRESENTE', 3 => 'AUSENTE', 4 => 'POR_REGULARIZAR'] as $id => $state) {
            DB::table('certificado.asistencia')->insert(['id_asistencia' => $id, 'id_participacion' => $id, 'id_actividad_sesion' => 1, 'estado' => $state]);
        }
        DB::table('certificado.asistencia')->insert(['id_asistencia' => 5, 'id_participacion' => 5, 'id_actividad_sesion' => 2, 'estado' => 'PRESENTE']);
        $this->getJson('/api/activity-session/1')->assertOk()
            ->assertJsonPath('data.contadores.inscritos_actividad_total', 5)
            ->assertJsonPath('data.contadores.inscritos_actividad_vigentes', 4)
            ->assertJsonPath('data.contadores.presentes', 1)
            ->assertJsonPath('data.contadores.ausentes', 1)
            ->assertJsonPath('data.contadores.pendientes', 2)
            ->assertJsonPath('data.contadores.por_regularizar', null);
    }

    public function test_approved_active_enrollment_states_are_used_for_both_closure_and_counters(): void
    {
        $this->seedSession('Activo');
        $this->authorize();
        $this->approveTestPolicy();
        config(['certification.session_closure.active_participation_states' => ['Inscrito']]);
        DB::table('certificado.participacion')->where('id_participacion', 1)->update(['estado' => 'Inscrito']);
        $this->getJson('/api/activity-session/1')->assertOk()
            ->assertJsonPath('data.contadores.inscritos_actividad_vigentes', 1)
            ->assertJsonPath('data.contadores.presentes', 1)->assertJsonPath('data.puede_finalizarse', true);
    }

    public function test_invalid_reporting_mapping_returns_unknown_counts_instead_of_false_zeroes(): void
    {
        $this->seedSession('Activo');
        $this->authorize();
        config(['certification.attendance_summary.present_states' => [['invalid']]]);
        $this->getJson('/api/activity-session/1')->assertOk()
            ->assertJsonPath('data.contadores.inscritos_actividad_total', 1)
            ->assertJsonPath('data.contadores.configuracion_conteo_valida', false)
            ->assertJsonPath('data.contadores.presentes', null);
    }

    public function test_historical_duplicate_attendance_is_counted_as_one_pending_person(): void
    {
        $this->seedSession('Activo');
        $this->authorize();
        DB::statement('DROP TABLE certificado.asistencia');
        DB::statement('CREATE TABLE certificado.asistencia (id_asistencia INTEGER PRIMARY KEY, id_participacion INTEGER, id_actividad_sesion INTEGER, estado TEXT, hora_entrada TEXT, hora_salida TEXT)');
        foreach ([1, 2] as $id) {
            DB::table('certificado.asistencia')->insert(['id_asistencia' => $id, 'id_participacion' => 1, 'id_actividad_sesion' => 1, 'estado' => 'PRESENTE']);
        }
        $this->getJson('/api/activity-session/1')->assertOk()
            ->assertJsonPath('data.contadores.participantes_sesion', 1)
            ->assertJsonPath('data.contadores.presentes', 0)
            ->assertJsonPath('data.contadores.pendientes', 1)
            ->assertJsonPath('data.contadores.asistencias_duplicadas', 1);
        $this->approveTestPolicy();
        $this->postJson('/api/activity-session/1/finalizar')->assertConflict();
    }

    public function test_inactive_proposal_cannot_enable_closure_without_explicit_approval_and_regularization_decision(): void
    {
        $this->seedSession('Activo');
        $this->authorize();
        $this->postJson('/api/activity-session/1/finalizar')->assertConflict();
        $proposal = config('certification.session_closure_proposal');
        self::assertNull($proposal['reference']);
        self::assertNull($proposal['regularizations']);
        config(['certification.session_closure' => $proposal]);
        $this->postJson('/api/activity-session/1/finalizar')->assertConflict();
        config(['certification.session_closure.reference' => 'Aprobación exclusiva de pruebas, sin validez institucional']);
        $this->postJson('/api/activity-session/1/finalizar')->assertConflict();
        config(['certification.session_closure.regularizations' => 'not_applicable']);
        $this->postJson('/api/activity-session/1/finalizar')->assertOk()->assertJsonPath('data.finalizada', true);
        self::assertSame([], config('certification.eligibility.sesion'));
        $this->assertDatabaseHas('certificado.actividad', ['id_actividad' => 1, 'estado' => 'Activa']);
        $this->assertDatabaseCount('certificado.certificado', 0);
    }

    #[TestWith([null])]
    #[TestWith(['Cerrada'])]
    public function test_closure_requires_the_explicit_supported_final_state(?string $state): void
    {
        $this->seedSession('Activo');
        $this->authorize();
        $this->approveTestPolicy();
        config(['certification.session_closure.final_state' => $state]);
        $this->postJson('/api/activity-session/1/finalizar')->assertConflict()
            ->assertJsonPath('data.finalizada', false);
        $this->getJson('/api/activity-session/1')->assertOk()->assertJsonPath('data.puede_finalizarse', false);
        $this->assertDatabaseHas('certificado.actividad_sesion', ['id_actividad_sesion' => 1, 'estado' => 'Activo']);
    }

    public function test_list_and_detail_report_the_same_closure_fields_before_and_after_finalization(): void
    {
        $this->seedSession('Activo');
        $this->authorize();
        $this->approveTestPolicy();
        foreach ([false, true] as $finalized) {
            $detail = $this->getJson('/api/activity-session/1')->assertOk()
                ->assertJsonPath('data.finalizada', $finalized)
                ->assertJsonPath('data.puede_finalizarse', ! $finalized)
                ->assertJsonPath('data.motivos_bloqueo_finalizacion', [])
                ->assertJsonPath('data.estado', $finalized ? 'Finalizada' : 'Activo')->json('data');
            $list = collect($this->getJson('/api/activity-session')->assertOk()->json('data'))->firstWhere('id_actividad_sesion', 1);
            foreach (['finalizada', 'puede_finalizarse', 'motivos_bloqueo_finalizacion', 'estado', 'contadores'] as $field) {
                self::assertSame($detail[$field], $list[$field]);
            }
            $this->postJson('/api/activity-session/1/finalizar')->assertOk();
        }
        config(['certification.session_closure' => null]);
        $this->getJson('/api/activity-session/1')->assertOk()->assertJsonPath('data.finalizada', true)
            ->assertJsonPath('data.puede_finalizarse', false);
        $this->postJson('/api/activity-session/1/finalizar')->assertOk();
    }

    public function test_read_only_operator_gets_permission_blocker_on_list_and_detail(): void
    {
        $this->seedSession('Activo');
        $this->approveTestPolicy();
        $this->authorize(permissions: ['actividades.ver']);
        $detail = $this->getJson('/api/activity-session/1')->assertOk()->assertJsonPath('data.puede_finalizarse', false)
            ->assertJsonPath('data.motivos_bloqueo_finalizacion.0', 'No dispone del permiso efectivo para finalizar esta sesión.')->json('data');
        $list = collect($this->getJson('/api/activity-session')->assertOk()->json('data'))->firstWhere('id_actividad_sesion', 1);
        self::assertSame($detail['motivos_bloqueo_finalizacion'], $list['motivos_bloqueo_finalizacion']);
        $this->postJson('/api/activity-session/1/finalizar')->assertForbidden();
    }

    #[TestWith([true, false])]
    #[TestWith([false, true])]
    #[TestWith([true, true])]
    public function test_closure_requires_recorded_hours_only_when_declared_by_policy(bool $entry, bool $exit): void
    {
        $this->seedSession('Activo');
        $this->authorize();
        $this->approveTestPolicy($entry, $exit);
        $this->postJson('/api/activity-session/1/finalizar')->assertConflict();
        DB::table('certificado.asistencia')->where('id_asistencia', 1)->update([
            'hora_entrada' => $entry ? '08:00:00' : null, 'hora_salida' => $exit ? '10:00:00' : null,
        ]);
        $this->postJson('/api/activity-session/1/finalizar')->assertOk();
        $this->assertDatabaseCount('certificado.certificado', 0);
    }

    public function test_list_closure_queries_do_not_grow_per_session(): void
    {
        $this->seedSession('Activo');
        $this->authorize();
        $this->approveTestPolicy();
        DB::enableQueryLog();
        $this->getJson('/api/activity-session')->assertOk();
        $queryCount = count(DB::getQueryLog());
        for ($id = 4; $id <= 20; $id++) {
            DB::table('certificado.actividad_sesion')->insert(['id_actividad_sesion' => $id, 'id_actividad' => 1,
                'nombre' => 'Sesión de prueba', 'estado' => 'Activo', 'fecha' => '2026-06-01', 'hora_inicio' => '08:00', 'hora_fin' => '10:00']);
        }
        DB::flushQueryLog();
        $this->getJson('/api/activity-session')->assertOk()->assertJsonCount(20, 'data');
        self::assertSame($queryCount, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }

    public function test_final_state_cannot_also_be_an_open_state_in_the_approved_policy(): void
    {
        $this->seedSession('Activo');
        $this->authorize();
        $this->approveTestPolicy();
        config(['certification.session_closure.open_states' => ['Activo', 'Finalizada']]);
        $this->postJson('/api/activity-session/1/finalizar')->assertConflict();
        $this->assertDatabaseHas('certificado.actividad_sesion', ['id_actividad_sesion' => 1, 'estado' => 'Activo']);
    }

    #[TestWith(['local'])]
    #[TestWith(['testing'])]
    public function test_development_closure_cannot_write_using_an_unverified_connection(string $environment): void
    {
        $this->seedSession('Activo');
        $this->authorize();
        $this->approveTestPolicy();
        $this->app['env'] = $environment;
        config(['certification.development_closure_enabled' => true, 'certification.session_closure.scope' => 'development',
            'database.connections.pgsql.host' => 'institutional.invalid', 'database.connections.pgsql.database' => 'certificados_morales']);
        $this->getJson('/api/activity-session/1')->assertOk()->assertJsonPath('data.puede_finalizarse', false);
        $this->postJson('/api/activity-session/1/finalizar')->assertConflict()->assertJsonPath('data.finalizada', false);
        $this->assertDatabaseHas('certificado.actividad_sesion', ['id_actividad_sesion' => 1, 'estado' => 'Activo']);
        $this->assertDatabaseCount('certificado.certificado', 0);
    }

    #[TestWith(['production'])]
    #[TestWith(['staging'])]
    public function test_development_policy_cannot_be_used_outside_local_or_testing(string $environment): void
    {
        $this->seedSession('Activo');
        $this->authorize();
        $this->approveTestPolicy();
        config(['certification.development_closure_enabled' => true, 'certification.session_closure.scope' => 'development']);
        $this->app['env'] = $environment;
        $this->postJson('/api/activity-session/1/finalizar')->assertConflict()
            ->assertJsonPath('data.motivos_bloqueo.0', 'El cierre provisional solo se admite con activación explícita en local/testing aislado.');
        $this->assertDatabaseHas('certificado.actividad_sesion', ['id_actividad_sesion' => 1, 'estado' => 'Activo']);
    }

    public function test_development_policy_cannot_be_used_when_the_feature_is_disabled(): void
    {
        $this->seedSession('Activo');
        $this->authorize();
        $this->approveTestPolicy();
        config(['certification.development_closure_enabled' => false, 'certification.session_closure.scope' => 'development']);
        $this->postJson('/api/activity-session/1/finalizar')->assertConflict();
        $this->assertDatabaseHas('certificado.actividad_sesion', ['id_actividad_sesion' => 1, 'estado' => 'Activo']);
    }

    private function enableSessionPreparationForTest(): void
    {
        $this->approveTestPolicy();
        config(['certification.development_closure_enabled' => true,
            'certification.session_closure.scope' => 'development',
            'certification.development_session_type_ids' => [1],
            'certification.eligibility.sesion' => [], 'certification.eligibility.actividad' => []]);
        $this->partialMock(CertificationReadiness::class)->shouldReceive('developmentClosureBlockers')->andReturn([]);
    }

    private function authorizePreparation(): void
    {
        $this->authorize(permissions: ['actividades.ver', 'actividades.editar', 'emision.ver', 'emision.crear', 'participantes.ver']);
    }

    private function secondParticipant(string $attendance): void
    {
        DB::table('gestion.persona')->insert(['id_persona' => 2, 'nombres' => 'Segunda', 'apellido_paterno' => 'Prueba']);
        DB::table('certificado.participacion')->insert(['id_participacion' => 2, 'id_persona' => 2, 'id_actividad' => 1, 'estado' => 'Activo']);
        DB::table('certificado.asistencia')->insert(['id_asistencia' => 2, 'id_participacion' => 2, 'id_actividad_sesion' => 1, 'estado' => $attendance]);
    }

    private function assertOnlyReadQueries(): void
    {
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        self::assertNotEmpty($queries);
        foreach ($queries as $query) {
            self::assertMatchesRegularExpression('/^\s*select\b/i', $query['query']);
            self::assertStringNotContainsString('certificacion_sesion_preparacion', $query['query']);
        }
    }

    private function representExistingCertificate(bool $withDocument = false): void
    {
        DB::statement('DROP TABLE certificado.certificado');
        DB::statement("CREATE VIEW certificado.certificado AS SELECT 9 AS id_certificado, 1 AS id_persona,
            1 AS id_participacion, 1 AS id_tipo_certificado, 'Vigente' AS estado, NULL AS fecha_emision");
        if ($withDocument) {
            DB::statement("CREATE VIEW certificado.certificado_documento AS SELECT 5 AS id_certificado_documento,
                9 AS id_certificado, 'Vigente' AS estado");
        }
    }

    public function test_development_flow_closes_two_present_participants_and_repeats_dynamic_preparation_without_writes(): void
    {
        $this->seedSession('Activo');
        $this->secondParticipant('PRESENTE');
        $this->authorizePreparation();
        $this->enableSessionPreparationForTest();
        $this->getJson('/api/activity-session/1')->assertOk()->assertJsonPath('data.puede_finalizarse', true);
        $this->postJson('/api/activity-session/1/finalizar', [])->assertOk()->assertJsonPath('data.finalizada', true);
        $this->getJson('/api/activity-session/1/beneficiarios?id_tipo_certificado=1&per_page=1')->assertOk()
            ->assertJsonPath('data.modo_certificacion', 'desarrollo_provisional')->assertJsonPath('data.total_elegibles', 2)
            ->assertJsonPath('data.puede_preparar', true)->assertJsonPath('data.tipo_certificado_compatible', true);
        Log::spy();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $data = $this->postJson('/api/activity-session/1/preparar-certificados', ['id_tipo_certificado' => 1])->assertOk()
            ->assertJsonPath('data.preparaciones_creadas', 0)->assertJsonPath('data.puede_emitir', false)
            ->assertJsonPath('data.preparacion_persistida', false)->assertJsonPath('data.total_elegibles', 2)
            ->assertJsonPath('data.estado_preparacion', 'Evaluada sin persistencia')->json('data');
        $retry = $this->postJson('/api/activity-session/1/preparar-certificados', ['id_tipo_certificado' => 1])->assertOk()
            ->assertJsonPath('data.preparaciones_creadas', 0)->assertJsonPath('data.preparaciones_existentes', 0)->json('data');
        self::assertSame([1, 2], array_column($data['beneficiarios']['data'], 'id_participacion'));
        self::assertSame($data['beneficiarios'], $retry['beneficiarios']);
        self::assertSame([], $data['preparaciones']);
        self::assertSame([], $retry['preparaciones']);
        $this->getJson('/api/activity-session/1/beneficiarios?id_tipo_certificado=1')->assertOk()
            ->assertJsonPath('data.beneficiarios.data.0.id_preparacion', null)
            ->assertJsonPath('data.beneficiarios.data.1.id_preparacion', null)
            ->assertJsonPath('data.beneficiarios.data.0.estado_generacion', 'No determinable por esquema');
        $this->assertOnlyReadQueries();
        $this->assertDatabaseCount('certificado.certificado', 0);
        $this->assertDatabaseHas('certificado.actividad', ['id_actividad' => 1, 'estado' => 'Activa']);
        $this->assertDatabaseHas('certificado.actividad_sesion', ['id_actividad_sesion' => 2, 'estado' => 'Activo']);
        Log::shouldNotHaveReceived('info', ['certificacion_sesion.preparada_desarrollo', \Mockery::any()]);
    }

    public function test_development_preparation_excludes_absent_participants_and_does_not_apply_to_global_certification(): void
    {
        $this->seedSession('Activo');
        $this->secondParticipant('AUSENTE');
        $this->authorizePreparation();
        $this->enableSessionPreparationForTest();
        $this->postJson('/api/activity-session/1/finalizar')->assertOk();
        $this->getJson('/api/activity-session/1/beneficiarios?id_tipo_certificado=1')->assertOk()
            ->assertJsonPath('data.beneficiarios.data.0.estado_elegibilidad', 'Elegible')
            ->assertJsonCount(1, 'data.beneficiarios.data')->assertJsonPath('data.contadores.ausentes', 1);
        $this->postJson('/api/activity-session/1/preparar-certificados', ['id_tipo_certificado' => 1])->assertOk()
            ->assertJsonPath('data.preparaciones_creadas', 0)->assertJsonPath('data.total_elegibles', 1)
            ->assertJsonCount(1, 'data.beneficiarios.data')->assertJsonPath('data.beneficiarios.data.0.id_persona', 1)
            ->assertJsonPath('data.preparaciones', [])->assertJsonPath('data.preparacion_persistida', false);
        $this->assertDatabaseCount('certificado.certificado', 0);
        $this->getJson('/api/activity/1/beneficiarios-certificacion?id_tipo_certificado=1')->assertOk()
            ->assertJsonPath('data.beneficiarios.data.0.estado_elegibilidad', 'Pendiente de validación');
    }

    public function test_development_preparation_blocks_open_session_pending_attendance_and_incompatible_type(): void
    {
        $this->seedSession('Activo', 'PENDIENTE');
        $this->authorizePreparation();
        $this->enableSessionPreparationForTest();
        $this->postJson('/api/activity-session/1/finalizar')->assertConflict();
        $this->postJson('/api/activity-session/1/preparar-certificados', ['id_tipo_certificado' => 1])->assertConflict();
        DB::table('certificado.actividad_sesion')->where('id_actividad_sesion', 1)->update(['estado' => 'Finalizada']);
        $this->getJson('/api/activity-session/1/beneficiarios?id_tipo_certificado=1')->assertOk()
            ->assertJsonCount(0, 'data.beneficiarios.data')->assertJsonPath('data.total_elegibles', 0)
            ->assertJsonPath('data.contadores.pendientes', 1);
        $this->postJson('/api/activity-session/1/preparar-certificados', ['id_tipo_certificado' => 1])->assertConflict();
        $this->getJson('/api/activity-session/1/beneficiarios?id_tipo_certificado=2')->assertOk()->assertJsonPath('data.tipo_certificado_compatible', false);
        $this->postJson('/api/activity-session/1/preparar-certificados', ['id_tipo_certificado' => 2])->assertConflict();
        $this->assertDatabaseCount('certificado.certificado', 0);
    }

    public function test_missing_preparation_schema_does_not_block_dynamic_preparation_or_eligibility(): void
    {
        $this->seedSession();
        $this->authorizePreparation();
        $this->enableSessionPreparationForTest();
        $this->getJson('/api/activity-session/1/beneficiarios?id_tipo_certificado=1')->assertOk()
            ->assertJsonPath('data.beneficiarios.data.0.estado_elegibilidad', 'Elegible')->assertJsonPath('data.puede_preparar', true)
            ->assertJsonPath('data.puede_consultar_beneficiarios', true)
            ->assertJsonPath('data.codigo_bloqueo_preparacion', null)
            ->assertJsonPath('data.beneficiarios.data.0.estado_generacion', 'No determinable por esquema')
            ->assertJsonPath('data.puede_emitir', false);
        $this->postJson('/api/activity-session/1/preparar-certificados', ['id_tipo_certificado' => 1])->assertOk()
            ->assertJsonPath('data.estado_preparacion', 'Evaluada sin persistencia')->assertJsonPath('data.puede_preparar', true)
            ->assertJsonPath('data.preparacion_persistida', false)->assertJsonPath('data.puede_emitir', false)
            ->assertJsonPath('data.motivos_bloqueo_emision.0', CertificationReadiness::SESSION_IDENTITY_BLOCKER);
        $this->getJson('/api/activity/1/beneficiarios-certificacion?id_tipo_certificado=1')->assertOk()
            ->assertJsonPath('data.codigo_bloqueo_preparacion', null);
    }

    public function test_read_capability_does_not_grant_preparation_permission(): void
    {
        $this->seedSession();
        $this->authorize();
        $this->enableSessionPreparationForTest();
        $this->getJson('/api/activity-session/1/beneficiarios?id_tipo_certificado=1')->assertOk()->assertJsonPath('data.puede_preparar', false);
        $this->postJson('/api/activity-session/1/preparar-certificados', ['id_tipo_certificado' => 1])->assertForbidden();
        $this->authorizePreparation();
        $this->postJson('/api/activity-session/1/preparar-certificados', ['id_tipo_certificado' => 1, 'id_usuario' => 999])->assertUnprocessable();
    }

    public function test_existing_certificate_without_session_identity_allows_evaluation_and_blocks_session_issuance(): void
    {
        $this->seedSession();
        $this->authorizePreparation();
        $this->enableSessionPreparationForTest();
        $this->representExistingCertificate();
        $this->getJson('/api/activity-session/1/beneficiarios?id_tipo_certificado=1')->assertOk()
            ->assertJsonPath('data.puede_preparar', true)->assertJsonPath('data.puede_emitir', false)
            ->assertJsonPath('data.beneficiarios.data.0.certificados_registrados_participacion.0.id_certificado', 9)
            ->assertJsonPath('data.beneficiarios.data.0.certificado_sesion', null)
            ->assertJsonPath('data.beneficiarios.data.0.estado_certificado', null);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->postJson('/api/activity-session/1/preparar-certificados', ['id_tipo_certificado' => 1])->assertOk()
            ->assertJsonPath('data.preparaciones_creadas', 0)->assertJsonPath('data.preparaciones', [])
            ->assertJsonPath('data.puede_emitir', false)
            ->assertJsonPath('data.motivos_bloqueo_emision.0', CertificationReadiness::SESSION_IDENTITY_BLOCKER);
        $this->assertOnlyReadQueries();
        $this->assertDatabaseCount('certificado.certificado', 1);
    }

    public function test_dynamic_preparations_for_different_sessions_keep_their_real_context_without_persistent_identities(): void
    {
        $this->seedSession();
        $this->authorizePreparation();
        $this->enableSessionPreparationForTest();
        DB::table('certificado.asistencia')->insert(['id_asistencia' => 2, 'id_participacion' => 1, 'id_actividad_sesion' => 2, 'estado' => 'PRESENTE']);
        $this->postJson('/api/activity-session/2/finalizar')->assertOk();
        $first = $this->postJson('/api/activity-session/1/preparar-certificados', ['id_tipo_certificado' => 1])->assertOk()
            ->assertJsonPath('data.id_sesion', 1)->assertJsonPath('data.beneficiarios.data.0.id_asistencia', 1)
            ->assertJsonPath('data.preparaciones', [])->assertJsonPath('data.preparacion_persistida', false)->json('data');
        $second = $this->postJson('/api/activity-session/2/preparar-certificados', ['id_tipo_certificado' => 1])->assertOk()
            ->assertJsonPath('data.id_sesion', 2)->assertJsonPath('data.beneficiarios.data.0.id_asistencia', 2)
            ->assertJsonPath('data.preparaciones', [])->assertJsonPath('data.preparacion_persistida', false)->json('data');
        self::assertNotSame($first['id_sesion'], $second['id_sesion']);
        self::assertSame(1, $first['beneficiarios']['data'][0]['id_participacion']);
        self::assertSame(1, $second['beneficiarios']['data'][0]['id_participacion']);
        $this->assertDatabaseCount('certificado.certificado', 0);
        $this->getJson('/api/certificaciones/pendientes?origen=sesion&id_sesion=1&id_tipo_certificado=1')->assertOk()
            ->assertJsonPath('data.data.0.id_sesion', 1)->assertJsonPath('data.data.0.preparaciones', [])
            ->assertJsonPath('data.data.0.puede_preparar', true);
    }

    public function test_existing_participation_document_is_preserved_without_attributing_it_to_a_session(): void
    {
        $this->seedSession();
        $this->authorizePreparation();
        $this->enableSessionPreparationForTest();
        $this->representExistingCertificate(withDocument: true);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->postJson('/api/activity-session/1/preparar-certificados', ['id_tipo_certificado' => 1])->assertOk()
            ->assertJsonPath('data.preparaciones_creadas', 0)->assertJsonPath('data.preparaciones', [])
            ->assertJsonPath('data.beneficiarios.data.0.id_certificado_documento', null)
            ->assertJsonPath('data.beneficiarios.data.0.estado_generacion', 'No determinable por esquema');
        $this->getJson('/api/activity-session/1/beneficiarios?id_tipo_certificado=1')->assertOk()
            ->assertJsonPath('data.beneficiarios.data.0.certificado_sesion', null)
            ->assertJsonPath('data.beneficiarios.data.0.id_certificado_documento', null)
            ->assertJsonPath('data.beneficiarios.data.0.certificados_registrados_participacion.0.id_certificado', 9);
        $this->assertOnlyReadQueries();
        $this->assertDatabaseCount('certificado.certificado', 1);
        $this->assertDatabaseCount('certificado.certificado_documento', 1);
        $this->assertDatabaseHas('certificado.certificado_documento', ['id_certificado_documento' => 5, 'id_certificado' => 9]);
    }

    public function test_development_closure_requires_at_least_one_current_enrollment(): void
    {
        $this->seedSession('Activo');
        $this->authorizePreparation();
        $this->enableSessionPreparationForTest();
        DB::table('certificado.asistencia')->delete();
        DB::table('certificado.participacion')->delete();
        $this->postJson('/api/activity-session/1/finalizar')->assertConflict();
        $this->assertDatabaseHas('certificado.actividad_sesion', ['id_actividad_sesion' => 1, 'estado' => 'Activo']);
    }

    public function test_duplicate_attendance_never_creates_a_provisional_preparation(): void
    {
        $this->seedSession();
        $this->authorizePreparation();
        $this->enableSessionPreparationForTest();
        DB::statement('DROP TABLE certificado.asistencia');
        DB::statement('CREATE TABLE certificado.asistencia (id_asistencia INTEGER PRIMARY KEY, id_participacion INTEGER, id_actividad_sesion INTEGER, estado TEXT, hora_entrada TEXT, hora_salida TEXT)');
        foreach ([1, 2] as $id) {
            DB::table('certificado.asistencia')->insert(['id_asistencia' => $id, 'id_participacion' => 1, 'id_actividad_sesion' => 1, 'estado' => 'PRESENTE']);
        }
        $this->getJson('/api/activity-session/1/beneficiarios?id_tipo_certificado=1')->assertOk()
            ->assertJsonPath('data.beneficiarios.data.0.estado_elegibilidad', 'Pendiente de validación')->assertJsonPath('data.puede_preparar', false);
        $this->postJson('/api/activity-session/1/preparar-certificados', ['id_tipo_certificado' => 1])->assertConflict();
        $this->assertDatabaseCount('certificado.certificado', 0);
    }

    public function test_shared_connection_requires_exact_explicit_authorization_before_any_write(): void
    {
        $this->seedSession('Activo');
        $this->authorizePreparation();
        $this->approveTestPolicy();
        config(['certification.development_closure_enabled' => true, 'certification.session_closure.scope' => 'development',
            'certification.development_connection.authorized' => true, 'certification.development_connection.connection' => 'pgsql']);
        $this->postJson('/api/activity-session/1/finalizar')->assertConflict()
            ->assertJsonPath('data.motivos_bloqueo.0', 'La conexión actual no es la conexión de desarrollo autorizada.');
        $this->assertDatabaseHas('certificado.actividad_sesion', ['id_actividad_sesion' => 1, 'estado' => 'Activo']);
    }

    public function test_dynamic_preparation_never_changes_schema_even_when_legacy_schema_flag_is_enabled(): void
    {
        $this->seedSession();
        $this->authorizePreparation();
        $this->enableSessionPreparationForTest();
        config(['certification.development_schema_authorized' => true]);
        $schemaBefore = array_map(fn (object $row): array => (array) $row, DB::select('SELECT name, type, sql FROM certificado.sqlite_master ORDER BY name'));
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->postJson('/api/activity-session/1/preparar-certificados', ['id_tipo_certificado' => 1])->assertOk()
            ->assertJsonPath('data.preparacion_persistida', false)->assertJsonPath('data.preparaciones_creadas', 0);
        $this->assertOnlyReadQueries();
        self::assertSame($schemaBefore, array_map(fn (object $row): array => (array) $row, DB::select('SELECT name, type, sql FROM certificado.sqlite_master ORDER BY name')));
        $this->assertDatabaseCount('certificado.certificado', 0);
    }

    public function test_missing_preparation_schema_keeps_session_closure_counters_beneficiaries_and_types_available(): void
    {
        $this->seedSession('Activo');
        $this->secondParticipant('PRESENTE');
        $this->authorizePreparation();
        $this->enableSessionPreparationForTest();
        $this->getJson('/api/activity-session')->assertOk();
        $this->getJson('/api/activity-session/1')->assertOk()
            ->assertJsonPath('data.puede_finalizarse', true)->assertJsonPath('data.contadores.presentes', 2);
        $this->getJson('/api/tipos-certificado/list')->assertOk()->assertJsonPath('data.total', 2);
        $this->postJson('/api/activity-session/1/finalizar', [])->assertOk()->assertJsonPath('data.finalizada', true);
        $this->getJson('/api/activity-session/1')->assertOk()->assertJsonPath('data.finalizada', true);
        $this->getJson('/api/activity-session/1/beneficiarios?id_tipo_certificado=1')->assertOk()
            ->assertJsonCount(2, 'data.beneficiarios.data')->assertJsonPath('data.total_elegibles', 2)
            ->assertJsonPath('data.puede_consultar_beneficiarios', true)->assertJsonPath('data.puede_preparar', true);
        $this->getJson('/api/certificaciones/pendientes?origen=sesion&id_sesion=1&id_tipo_certificado=1')->assertOk()
            ->assertJsonPath('data.data.0.codigo_bloqueo_preparacion', null)->assertJsonPath('data.data.0.puede_preparar', true)
            ->assertJsonPath('data.data.0.puede_emitir', false);
        $this->postJson('/api/activity-session/1/preparar-certificados', ['id_tipo_certificado' => 1])->assertOk()
            ->assertJsonPath('data.preparacion_persistida', false)->assertJsonPath('data.preparaciones_creadas', 0)
            ->assertJsonPath('data.puede_emitir', false);
        $this->assertDatabaseCount('certificado.certificado', 0);
        $this->assertDatabaseHas('certificado.actividad', ['id_actividad' => 1, 'estado' => 'Activa']);
        $this->assertDatabaseHas('certificado.actividad_sesion', ['id_actividad_sesion' => 2, 'estado' => 'Activo']);
    }
}
