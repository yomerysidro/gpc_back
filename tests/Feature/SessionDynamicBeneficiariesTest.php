<?php

namespace Tests\Feature;

use App\Models\Role\Role;
use App\Models\User\User;
use App\Services\CertificationReadiness;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SessionDynamicBeneficiariesTest extends TestCase
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

    private function enableDynamicPolicy(): void
    {
        $this->approveTestPolicy();
        config(['certification.development_closure_enabled' => true,
            'certification.session_closure.scope' => 'development',
            'certification.development_session_type_ids' => [1]]);
        $this->partialMock(CertificationReadiness::class)->shouldReceive('developmentClosureBlockers')->andReturn([]);
    }

    private function addParticipant(int $id, string $state, ?string $attendance): void
    {
        DB::table('gestion.persona')->insert(['id_persona' => $id, 'nombres' => 'Candidato '.$id]);
        DB::table('certificado.participacion')->insert(['id_participacion' => $id, 'id_persona' => $id, 'id_actividad' => 1, 'estado' => $state]);
        if ($attendance !== null) {
            DB::table('certificado.asistencia')->insert(['id_asistencia' => $id, 'id_participacion' => $id, 'id_actividad_sesion' => 1, 'estado' => $attendance]);
        }
    }

    public function test_present_active_candidates_use_real_sql_pagination_and_normalized_states(): void
    {
        $this->seedSession(' finalizada ', ' presente ');
        DB::table('certificado.participacion')->where('id_participacion', 1)->update(['estado' => ' activo ']);
        $this->addParticipant(2, 'Activo', 'AUSENTE');
        $this->addParticipant(3, 'Inactivo', 'PRESENTE');
        $this->addParticipant(4, 'Activo', null);
        $this->addParticipant(5, 'Activo', 'PRESENTE');
        $this->authorize();
        $this->enableDynamicPolicy();
        $this->getJson('/api/activity-session/1/beneficiarios?id_tipo_certificado=1&per_page=1')->assertOk()
            ->assertJsonPath('data.beneficiarios.total', 2)->assertJsonPath('data.total_elegibles', 2)
            ->assertJsonPath('data.contadores.presentes', 2)->assertJsonPath('data.contadores.ausentes', 1)
            ->assertJsonPath('data.beneficiarios.data.0.id_participacion', 1)
            ->assertJsonPath('data.beneficiarios.data.0.id_asistencia', 1)
            ->assertJsonPath('data.beneficiarios.data.0.nombre_sesion', 'Sesión 1')
            ->assertJsonPath('data.beneficiarios.data.0.estado_elegibilidad', 'Elegible')
            ->assertJsonPath('data.beneficiarios.data.0.certificado_sesion', null)
            ->assertJsonPath('data.beneficiarios.data.0.certificados_registrados_participacion', [])
            ->assertJsonPath('data.puede_consultar_beneficiarios', true)->assertJsonPath('data.puede_preparar', false)
            ->assertJsonPath('data.puede_emitir', false)->assertJsonPath('data.finalizada', true);
        $this->getJson('/api/activity-session/1/beneficiarios?id_tipo_certificado=1&per_page=1&page=2')->assertOk()
            ->assertJsonPath('data.beneficiarios.current_page', 2)->assertJsonPath('data.beneficiarios.data.0.id_participacion', 5)
            ->assertJsonPath('data.total_elegibles', 2)->assertJsonCount(1, 'data.beneficiarios.data');
        $this->getJson('/api/activity-session/1/beneficiarios?per_page=1&page=3')->assertOk()->assertJsonCount(0, 'data.beneficiarios.data');
        $this->assertDatabaseCount('certificado.certificado', 0);
    }

    public function test_session_id_is_dynamic_and_one_participation_can_be_present_in_two_sessions(): void
    {
        $this->seedSession();
        $this->authorize();
        $this->enableDynamicPolicy();
        DB::table('certificado.actividad_sesion')->where('id_actividad_sesion', 2)->update(['estado' => 'Finalizada']);
        DB::table('certificado.asistencia')->insert(['id_asistencia' => 22, 'id_participacion' => 1, 'id_actividad_sesion' => 2, 'estado' => 'PRESENTE']);
        foreach ([1 => 1, 2 => 22] as $sessionId => $attendanceId) {
            $this->getJson('/api/activity-session/'.$sessionId.'/beneficiarios?id_tipo_certificado=1')->assertOk()
                ->assertJsonPath('data.beneficiarios.total', 1)->assertJsonPath('data.beneficiarios.data.0.id_sesion', $sessionId)
                ->assertJsonPath('data.beneficiarios.data.0.id_participacion', 1)
                ->assertJsonPath('data.beneficiarios.data.0.id_asistencia', $attendanceId)
                ->assertJsonPath('data.beneficiarios.data.0.estado_elegibilidad', 'Elegible');
        }
        DB::table('certificado.asistencia')->where('id_asistencia', 22)->update(['estado' => 'AUSENTE']);
        $this->getJson('/api/activity-session/2/beneficiarios?id_tipo_certificado=1')->assertOk()
            ->assertJsonPath('data.beneficiarios.total', 0)->assertJsonPath('data.total_elegibles', 0);
    }

    public function test_open_session_never_returns_certification_candidates(): void
    {
        $this->seedSession('Activo');
        $this->authorize();
        $this->enableDynamicPolicy();
        $this->getJson('/api/activity-session/1/beneficiarios?id_tipo_certificado=1')->assertOk()
            ->assertJsonPath('data.beneficiarios.total', 0)->assertJsonPath('data.total_elegibles', 0);
    }

    public function test_existing_participation_certificates_and_documents_are_never_attributed_to_either_session(): void
    {
        $this->seedSession();
        $this->authorize(permissions: ['actividades.ver', 'participantes.ver', 'emision.ver', 'emision.crear']);
        $this->enableDynamicPolicy();
        DB::table('certificado.actividad_sesion')->where('id_actividad_sesion', 2)->update(['estado' => 'Finalizada']);
        DB::table('certificado.asistencia')->insert(['id_asistencia' => 2, 'id_participacion' => 1, 'id_actividad_sesion' => 2, 'estado' => 'PRESENTE']);
        /** Static read fixtures in SQLite memory represent existing records without issuing certificates. */
        DB::statement('DROP TABLE certificado.certificado');
        DB::statement("CREATE VIEW certificado.certificado AS SELECT 9 AS id_certificado, 1 AS id_participacion, 1 AS id_persona, 1 AS id_tipo_certificado, 'Vigente' AS estado, NULL AS fecha_emision UNION ALL SELECT 10, 1, 1, 1, 'Registrado', NULL");
        DB::statement("CREATE VIEW certificado.certificado_documento AS SELECT 5 AS id_certificado_documento, 9 AS id_certificado, 'Vigente' AS estado");
        foreach ([1, 2] as $sessionId) {
            $this->getJson('/api/activity-session/'.$sessionId.'/beneficiarios?id_tipo_certificado=1')->assertOk()
                ->assertJsonPath('data.beneficiarios.total', 1)
                ->assertJsonPath('data.beneficiarios.data.0.estado_elegibilidad', 'Elegible')
                ->assertJsonCount(2, 'data.beneficiarios.data.0.certificados_registrados_participacion')
                ->assertJsonPath('data.beneficiarios.data.0.certificado_sesion', null)
                ->assertJsonPath('data.beneficiarios.data.0.estado_certificado', null)
                ->assertJsonPath('data.beneficiarios.data.0.id_certificado_documento', null)
                ->assertJsonPath('data.puede_preparar', true)->assertJsonPath('data.puede_emitir', false);
        }
    }

    public function test_preparation_is_a_repeatable_read_only_evaluation_without_extra_tables(): void
    {
        $this->seedSession();
        $this->authorize(permissions: ['actividades.ver', 'participantes.ver', 'emision.ver', 'emision.crear']);
        $this->enableDynamicPolicy();
        DB::enableQueryLog();
        foreach ([1, 2] as $attempt) {
            $this->postJson('/api/activity-session/1/preparar-certificados', ['id_tipo_certificado' => 1])->assertOk()
                ->assertJsonPath('data.preparacion_persistida', false)->assertJsonPath('data.preparaciones_creadas', 0)
                ->assertJsonPath('data.puede_preparar', true)->assertJsonPath('data.puede_emitir', false)
                ->assertJsonPath('data.beneficiarios.data.0.estado_elegibilidad', 'Elegible');
        }
        foreach (DB::getQueryLog() as $query) {
            self::assertDoesNotMatchRegularExpression('/\b(insert|update|delete|create|alter|drop)\b/i', $query['query']);
            self::assertStringNotContainsString('certificacion_sesion_preparacion', $query['query']);
        }
        DB::disableQueryLog();
        $this->assertDatabaseCount('certificado.certificado', 0);
    }

    public function test_duplicate_attendance_does_not_duplicate_candidates_or_claim_a_single_attendance(): void
    {
        $this->seedSession();
        $this->authorize();
        $this->enableDynamicPolicy();
        DB::statement('DROP TABLE certificado.asistencia');
        DB::statement('CREATE TABLE certificado.asistencia (id_asistencia INTEGER PRIMARY KEY, id_participacion INTEGER, id_actividad_sesion INTEGER, estado TEXT, hora_entrada TEXT, hora_salida TEXT)');
        foreach ([1, 2] as $id) {
            DB::table('certificado.asistencia')->insert(['id_asistencia' => $id, 'id_participacion' => 1, 'id_actividad_sesion' => 1, 'estado' => 'PRESENTE']);
        }
        $this->getJson('/api/activity-session/1/beneficiarios?id_tipo_certificado=1')->assertOk()
            ->assertJsonPath('data.beneficiarios.total', 1)->assertJsonCount(1, 'data.beneficiarios.data')
            ->assertJsonPath('data.beneficiarios.data.0.id_asistencia', null)
            ->assertJsonPath('data.beneficiarios.data.0.estado_elegibilidad', 'Pendiente de validación')
            ->assertJsonPath('data.total_elegibles', 0);
    }

    public function test_reading_remains_available_for_missing_or_disallowed_certificate_type(): void
    {
        $this->seedSession();
        $this->authorize();
        $this->enableDynamicPolicy();
        $this->getJson('/api/activity-session/1/beneficiarios')->assertOk()
            ->assertJsonPath('data.beneficiarios.total', 1)->assertJsonPath('data.puede_consultar_beneficiarios', true)
            ->assertJsonPath('data.beneficiarios.data.0.estado_elegibilidad', 'Pendiente de validación');
        $this->getJson('/api/activity-session/1/beneficiarios?id_tipo_certificado=2')->assertOk()
            ->assertJsonPath('data.tipo_certificado_compatible', false)
            ->assertJsonPath('data.beneficiarios.data.0.estado_elegibilidad', 'No elegible');
    }

    public function test_jwt_permissions_scope_and_context_are_required(): void
    {
        $this->seedSession();
        $this->getJson('/api/activity-session/1/beneficiarios')->assertUnauthorized();
        $this->withCredentials()->withUnencryptedCookie('access_token', 'invalid')->getJson('/api/activity-session/1/beneficiarios')->assertUnauthorized();
        $this->authorize(permissions: ['actividades.ver']);
        $this->getJson('/api/activity-session/1/beneficiarios')->assertForbidden();
        $this->authorize(false);
        $this->getJson('/api/activity-session/3/beneficiarios')->assertForbidden();
        $this->getJson('/api/activity-session/1/beneficiarios?id_sesion=2')->assertUnprocessable();
        $this->getJson('/api/activity-session/1/beneficiarios?per_page=101')->assertUnprocessable();
        $this->getJson('/api/activity-session/999/beneficiarios')->assertNotFound();
        $this->getJson('/api/activity-session/abc/beneficiarios')->assertNotFound();
    }
}
