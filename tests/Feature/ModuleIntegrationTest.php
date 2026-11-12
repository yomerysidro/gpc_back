<?php

namespace Tests\Feature;

use App\Http\Data\Person_Worker\Person_WorkerData;
use App\Http\Data\Practice\PracticeData;
use App\Http\Data\Practicing_Individual\Practicing_IndividualData;
use App\Models\Activity\Activity;
use App\Models\ActivitySignature\ActivitySignature;
use App\Models\Area\Area;
use App\Models\Attendance\Attendance;
use App\Models\Certificate\Certificate;
use App\Models\DocumentType\TipoDocumento;
use App\Models\Institution\Institution;
use App\Models\Participation\Participation;
use App\Models\Person\Persona;
use App\Models\Person_Worker\Person_Worker;
use App\Models\PersonDocument\PersonaDocumento;
use App\Models\PersonSpeaker\PersonaPonente;
use App\Models\Practice\Practice;
use App\Models\Practicing_Individual\Practicing_Individual;
use App\Models\Supervisory_internship\Supervisory_internship;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\ModulePolicy;
use Tests\Fixtures\ModuleUser as User;
use Tests\Fixtures\ReadOnlyModulePolicy;
use Tests\TestCase;

class ModuleIntegrationTest extends TestCase
{
    private const GESTION_MODELS = [Persona::class, TipoDocumento::class, PersonaDocumento::class, Area::class, Institution::class, Person_Worker::class, Practicing_Individual::class, Practice::class, Supervisory_internship::class, PersonaPonente::class];

    private const ENTITIES = ['Activity', 'ActivityType', 'ActivitySpeaker', 'Participation', 'ParticipationType', 'ActivitySession', 'Attendance', 'CertificateType', 'Certificate', 'Recognition', 'CertificateDocument', 'Signature', 'CertificateSignature', 'CertificateInquiry', 'ActivitySignature'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'database.connections.sqlite.url' => null, 'app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);
        DB::purge('sqlite');
        DB::statement("ATTACH DATABASE ':memory:' AS certificado");
        DB::statement("ATTACH DATABASE ':memory:' AS gestion");
        $definitions = [
            'gestion.persona' => ['id_persona', []],
            'gestion.persona_trabajador' => ['id_persona_trabajador', ['id_persona', 'id_area', 'estado', 'fecha_ingreso', 'fecha_fin']],
            'gestion.persona_practicante' => ['id_persona_practicante', ['id_persona']],
            'gestion.practica' => ['id_practica', ['id_persona_practicante']],
            'gestion.area' => ['id_area', ['nombre']],
            'gestion.persona_ponente' => ['id_persona_ponente', ['id_persona']],
            'certificado.tipo_actividad' => ['id_tipo_actividad', ['nombre', 'descripcion', 'estado']],
            'certificado.tipo_participacion' => ['id_tipo_participacion', ['nombre', 'estado']],
            'certificado.actividad' => ['id_actividad', ['id_area', 'id_tipo_actividad', 'nombre', 'descripcion', 'fecha_inicio', 'fecha_fin', 'sede', 'estado']],
            'certificado.solicitud_actividad' => ['id_solicitud_actividad', ['id_actividad']],
            'certificado.participacion' => ['id_participacion', ['id_persona', 'id_actividad', 'id_tipo_participacion', 'fecha_inscripcion', 'estado']],
            'certificado.actividad_sesion' => ['id_actividad_sesion', ['id_actividad', 'nombre', 'fecha', 'hora_inicio', 'hora_fin', 'lugar', 'estado']],
            'certificado.actividad_ponente' => ['id_actividad_ponente', ['id_actividad', 'id_persona_ponente']],
            'certificado.asistencia' => ['id_asistencia', ['id_participacion', 'id_actividad_sesion', 'hora_entrada', 'hora_salida', 'estado']],
            'certificado.tipo_certificado' => ['id_tipo_certificado', ['nombre_tipo', 'estado']],
            'certificado.certificado' => ['id_certificado', ['id_persona', 'id_tipo_certificado', 'id_reconocimiento', 'id_participacion', 'id_practica', 'fecha_emision', 'estado', 'notificado', 'fecha_notificacion']],
            'certificado.reconocimiento' => ['id_reconocimiento', ['id_persona_trabajador', 'titulo', 'descripcion', 'motivo', 'fecha_reconocimiento', 'estado']],
            'certificado.certificado_documento' => ['id_certificado_documento', ['id_certificado', 'codigo_verificacion', 'hash_certificado', 'ruta_archivo', 'motivo_reemision', 'estado', 'fecha_generacion']],
            'certificado.firma' => ['id_firma', ['id_persona_trabajador', 'ruta_archivo_pfx', 'passphrase_cifrada', 'fecha_firma']],
            'certificado.certificado_firma' => ['id_certificado_firma', ['id_certificado_documento', 'id_firma', 'fecha_firma', 'orden_firma']],
            'certificado.consulta_certificado' => ['id_consulta_certificado', ['id_certificado_documento', 'dni_consulta', 'codigo_consulta', 'fecha_consulta', 'resultado']],
            'certificado.actividad_firma' => ['id_actividad_firma', ['id_actividad', 'id_firma', 'orden_firma']],
        ];
        foreach ($definitions as $name => [$key, $fields]) {
            Schema::create($name, function (Blueprint $table) use ($key, $fields) {
                $table->increments($key);
                foreach ($fields as $field) {
                    if (str_starts_with($field, 'id_') || $field === 'orden_firma') {
                        $table->integer($field)->nullable();
                    } else {
                        $table->string($field, 500)->nullable();
                    }
                }
            });
        }
        DB::table('gestion.persona')->insert([['id_persona' => 1], ['id_persona' => 2]]);
        DB::table('gestion.area')->insert(['id_area' => 1, 'nombre' => 'Área de prueba']);
        DB::table('gestion.persona_ponente')->insert(['id_persona_ponente' => 1, 'id_persona' => 1]);
        DB::table('gestion.persona_trabajador')->insert(['id_persona_trabajador' => 1, 'id_persona' => 1, 'id_area' => 1, 'estado' => '1', 'fecha_ingreso' => today()->toDateString()]);
        DB::table('gestion.persona_practicante')->insert(['id_persona_practicante' => 1, 'id_persona' => 1]);
        DB::table('gestion.practica')->insert(['id_practica' => 1, 'id_persona_practicante' => 1]);
        DB::table('certificado.tipo_actividad')->insert(['id_tipo_actividad' => 1, 'nombre' => 'Curso', 'estado' => '1']);
        DB::table('certificado.tipo_participacion')->insert(['id_tipo_participacion' => 1, 'nombre' => 'Asistente', 'estado' => '1']);
        DB::table('certificado.tipo_certificado')->insert(['id_tipo_certificado' => 1, 'nombre_tipo' => 'Participación', 'estado' => 'ACTIVO']);
        foreach ([1, 2] as $id) {
            DB::table('certificado.actividad')->insert(['id_actividad' => $id, 'id_area' => 1, 'id_tipo_actividad' => 1, 'nombre' => 'Actividad '.$id, 'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-01-31', 'estado' => 'Activa']);
        }
        DB::table('certificado.participacion')->insert(['id_participacion' => 1, 'id_persona' => 1, 'id_actividad' => 1, 'id_tipo_participacion' => 1, 'fecha_inscripcion' => '2026-01-01', 'estado' => 'ACTIVO']);
        DB::table('certificado.actividad_sesion')->insert(['id_actividad_sesion' => 1, 'id_actividad' => 1, 'nombre' => 'Sesión', 'fecha' => '2026-01-20', 'hora_inicio' => '08:00', 'hora_fin' => '09:00', 'estado' => 'ACTIVO']);
    }

    private function authorize(): void
    {
        $this->actingAs(new User(['name' => 'Authorized fixture']));
        foreach (self::GESTION_MODELS as $model) {
            Gate::policy($model, ModulePolicy::class);
        }
        foreach (self::ENTITIES as $entity) {
            Gate::policy("App\\Models\\$entity\\$entity", ModulePolicy::class);
        }
    }

    private function certificate(): array
    {
        return ['id_persona' => 1, 'id_tipo_certificado' => 1, 'id_participacion' => 1, 'fecha_emision' => '2026-01-01', 'estado' => 'Vigente', 'notificado' => false];
    }

    private function attendance(): array
    {
        return ['ID_PARTICIPACION' => 1, 'ID_ACTIVIDAD_SESION' => 1, 'HORA_ENTRADA' => '08:00', 'HORA_SALIDA' => '09:00', 'ESTADO' => 'PRESENTE'];
    }

    public function test_all_protected_module_routes_deny_guests_and_users_without_policies(): void
    {
        $controllers = array_map(fn ($entity) => $entity.'Controller', self::ENTITIES);
        $routes = array_values(array_filter(app('router')->getRoutes()->getRoutes(), fn ($route) => in_array(class_basename($route->getControllerClass() ?? ''), $controllers, true)
            && in_array('auth.cookie', $route->gatherMiddleware(), true)));
        $this->assertCount(95, $routes);
        foreach ($routes as $route) {
            $uri = preg_replace('/\{[^}]+\}/', '1', $route->uri());
            $this->json($route->methods()[0], '/'.$uri)->assertUnauthorized()->assertExactJson(['success' => false, 'message' => 'No autenticado.', 'data' => []]);
        }
        $this->actingAs(new User(['name' => 'Unprivileged fixture']));
        foreach ($routes as $route) {
            $uri = preg_replace('/\{[^}]+\}/', '1', $route->uri());
            $this->json($route->methods()[0], '/'.$uri)->assertForbidden();
        }
    }

    public function test_activity_cruds_use_the_same_schemas_as_certificates(): void
    {
        $this->authorize();
        $cases = [
            ['activity', ['id_area' => 1, 'id_tipo_actividad' => 1, 'nombre' => 'Nueva', 'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-01-31', 'estado' => 'Activa'], 'id_actividad', 'nombre', 'Actualizada'],
            ['ativicty-type', ['nombre' => 'Taller', 'estado' => true], 'id_tipo_actividad', 'nombre', 'Taller actualizado'],
            ['activity-speaker', ['id_actividad' => 1, 'id_persona_ponente' => 1], 'id_actividad_ponente', 'id_actividad', 2],
            ['participation', ['id_persona' => 2, 'id_actividad' => 1, 'id_tipo_participacion' => 1, 'fecha_inscripcion' => '2026-01-01', 'estado' => 'ACTIVO'], 'id_participacion', 'estado', 'RETIRADO'],
            ['participation-type', ['nombre' => 'Organizador', 'estado' => true], 'id_tipo_participacion', 'nombre', 'Apoyo'],
            ['activity-session', ['id_actividad' => 1, 'nombre' => 'Nueva sesión', 'fecha' => '2026-01-15', 'hora_inicio' => '10:00', 'hora_fin' => '11:00', 'estado' => 'ACTIVO'], 'id_actividad_sesion', 'nombre', 'Sesión actualizada'],
            ['attendance', $this->attendance(), 'ID_ASISTENCIA', 'ESTADO', 'JUSTIFICADO'],
        ];
        foreach ($cases as [$prefix, $payload, $key, $field, $value]) {
            $attendance = $prefix === 'attendance';
            $root = '/api/'.$prefix;
            if ($prefix === 'activity') {
                $this->postJson($root, $payload)->assertForbidden();
                $id = DB::table('certificado.actividad')->insertGetId($payload, 'id_actividad');
            } else {
                $id = $this->postJson($root.($attendance ? '/create' : ''), $payload)->assertCreated()->json('data.'.$key);
            }
            $detail = $root.'/'.$id;
            $update = $root.($attendance ? '/update' : '').'/'.$id;
            $delete = $root.($attendance ? '/delete' : '').'/'.$id;
            $this->getJson($root.($attendance ? '/list' : ''))->assertOk();
            $this->getJson($detail)->assertOk();
            $table = 'certificado.'.match ($prefix) {
                'activity' => 'actividad',
                'ativicty-type' => 'tipo_actividad',
                'activity-speaker' => 'actividad_ponente',
                'participation' => 'participacion',
                'participation-type' => 'tipo_participacion',
                'activity-session' => 'actividad_sesion',
                'attendance' => 'asistencia',
            };
            $this->assertSame([strtolower($field)], $this->updateColumns(fn () => $this->patchJson($update, [$field => $value])->assertOk(), $table));
            $this->assertSame([strtolower($field)], $this->updateColumns(fn () => $this->putJson($update, $payload)->assertOk(), $table));
            $this->assertSame([], $this->updateColumns(fn () => $this->putJson($update, $payload)->assertOk(), $table));
            $this->deleteJson($delete)->assertOk();
            $this->getJson($detail)->assertNotFound();
        }
        $this->postJson('/api/certificados/create', $this->certificate())->assertCreated();
        $this->assertFalse(Schema::hasTable('persona'));
        $this->assertFalse(Schema::hasTable('participacion'));
    }

    public function test_certified_participation_and_its_attendance_are_preserved(): void
    {
        $this->authorize();
        $this->postJson('/api/attendance/create', $this->attendance())->assertCreated();
        $this->postJson('/api/certificados/create', $this->certificate())->assertCreated();
        foreach (['PUT', 'PATCH'] as $method) {
            $this->json($method, '/api/participation/1', ['id_persona' => 2])->assertConflict();
            $this->json($method, '/api/participation/1', ['id_actividad' => 2])->assertConflict();
            $this->json($method, '/api/attendance/update/1', ['ESTADO' => 'AUSENTE'])->assertConflict();
            $this->json($method, '/api/activity-session/1', ['fecha' => '2026-01-21'])->assertConflict();
        }
        $this->postJson('/api/attendance/create', $this->attendance())->assertConflict();
        foreach (['/api/participation/1', '/api/activity-session/1', '/api/activity/1', '/api/attendance/delete/1'] as $path) {
            $this->deleteJson($path)->assertConflict();
        }
        $this->assertDatabaseHas('certificado.participacion', ['id_participacion' => 1, 'id_persona' => 1]);
        $this->assertDatabaseCount('certificado.asistencia', 1);
        $this->patchJson('/api/participation/1', ['estado' => 'FINALIZADO'])->assertOk();
    }

    public function test_session_with_attendance_cannot_change_activity(): void
    {
        $this->authorize();
        $this->postJson('/api/attendance/create', $this->attendance())->assertCreated();
        foreach (['PUT', 'PATCH'] as $method) {
            $this->json($method, '/api/activity-session/1', ['id_actividad' => 2])->assertConflict();
            $this->json($method, '/api/participation/1', ['id_actividad' => 2])->assertConflict();
        }
        $this->assertDatabaseHas('certificado.actividad_sesion', ['id_actividad_sesion' => 1, 'id_actividad' => 1]);
    }

    public function test_activity_period_must_cover_existing_sessions(): void
    {
        $this->authorize();
        foreach (['PUT', 'PATCH'] as $method) {
            $this->json($method, '/api/activity/1', ['fecha_fin' => '2026-01-10'])->assertUnprocessable();
            $this->json($method, '/api/activity/1', ['fecha_inicio' => '2026-01-21'])->assertUnprocessable();
        }
        $this->patchJson('/api/activity/1', ['fecha_fin' => '2026-02-01'])->assertOk();
        $this->postJson('/api/certificados/create', $this->certificate())->assertCreated();
        $this->patchJson('/api/activity/1', ['nombre' => 'Cambiar histórico'])->assertConflict();
    }

    public function test_activity_assignments_prevent_deletion(): void
    {
        $this->authorize();
        DB::table('certificado.actividad_ponente')->insert(['id_actividad' => 2, 'id_persona_ponente' => 1]);
        $this->deleteJson('/api/activity/2')->assertConflict();
        DB::table('certificado.actividad_ponente')->delete();
        DB::table('certificado.actividad_firma')->insert(['id_actividad' => 2, 'id_firma' => 1, 'orden_firma' => 1]);
        $this->deleteJson('/api/activity/2')->assertConflict();
    }

    public function test_cross_schema_foreign_keys_and_beneficiaries_are_checked(): void
    {
        $this->authorize();
        $this->postJson('/api/activity', ['id_area' => 99, 'id_tipo_actividad' => 1, 'nombre' => 'Inválida', 'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-01-31', 'estado' => 'ACTIVO'])->assertForbidden();
        $this->postJson('/api/certificados/create', array_replace($this->certificate(), ['id_persona' => 2]))->assertUnprocessable();
        $this->postJson('/api/certificados/create', array_replace($this->certificate(), ['id_participacion' => null, 'id_practica' => 1]))->assertCreated();
        $this->postJson('/api/certificados/create', array_replace($this->certificate(), ['id_persona' => 2, 'id_participacion' => null, 'id_practica' => 1]))->assertUnprocessable();
    }

    public function test_eloquent_relations_resolve_the_shared_records(): void
    {
        $activity = Activity::findOrFail(1);
        $this->assertSame('Curso', $activity->activityType->nombre);
        $this->assertCount(1, $activity->sessions);
        $this->assertCount(1, $activity->participations);
        $this->assertCount(0, $activity->speakers);
        $this->assertCount(0, $activity->signatures);
        $this->authorize();
        $this->postJson('/api/attendance/create', $this->attendance())->assertCreated();
        $this->postJson('/api/certificados/create', $this->certificate())->assertCreated();
        $participation = Participation::findOrFail(1);
        $this->assertSame('Asistente', $participation->participationType->nombre);
        $this->assertCount(1, $participation->attendances);
        $attendance = Attendance::findOrFail(1);
        $this->assertSame(1, $attendance->participation->id_persona);
        $this->assertSame(1, $attendance->activitySession->id_actividad);
        $this->assertCount(1, $attendance->activitySession->attendances);
        $this->assertSame(1, Certificate::findOrFail(1)->participation->id_persona);
        DB::table('certificado.actividad_firma')->insert(['id_actividad_firma' => 1, 'id_actividad' => 1, 'id_firma' => 1, 'orden_firma' => 1]);
        $this->assertSame(1, ActivitySignature::findOrFail(1)->activity->id_actividad);
    }

    public function test_read_permission_does_not_grant_write_permission(): void
    {
        $this->authorize();
        Gate::policy(Activity::class, ReadOnlyModulePolicy::class);
        $this->getJson('/api/activity')->assertOk();
        $this->getJson('/api/activity/1')->assertOk();
        $this->postJson('/api/activity', [])->assertForbidden();
        $this->patchJson('/api/activity/1', ['nombre' => 'Denied'])->assertForbidden();
        $this->putJson('/api/activity/1', ['nombre' => 'Denied'])->assertForbidden();
        $this->deleteJson('/api/activity/1')->assertForbidden();
        $this->assertDatabaseHas('certificado.actividad', ['id_actividad' => 1, 'nombre' => 'Actividad 1']);
    }

    public function test_bearer_tokens_cannot_replace_the_institutional_cookie(): void
    {
        $this->withToken('invalid')->getJson('/api/activity')->assertUnauthorized();
        $this->withToken(str_repeat('a', 64))->getJson('/api/activity')->assertUnauthorized();
    }

    public function test_duplicate_attendance_is_rejected_even_with_different_hours(): void
    {
        $this->authorize();
        $this->postJson('/api/attendance/create', $this->attendance())->assertCreated();
        $this->postJson('/api/attendance/create', array_replace($this->attendance(), ['HORA_ENTRADA' => '10:00', 'HORA_SALIDA' => '11:00']))->assertUnprocessable();
        $this->assertDatabaseCount('certificado.asistencia', 1);
    }

    public function test_put_and_patch_reject_duplicate_attendance_and_allow_its_own_pair(): void
    {
        $this->authorize();
        $this->postJson('/api/attendance/create', $this->attendance())->assertCreated();
        DB::table('certificado.actividad_sesion')->insert(['id_actividad_sesion' => 2, 'id_actividad' => 1, 'fecha' => '2026-01-21']);
        $this->postJson('/api/attendance/create', array_replace($this->attendance(), ['ID_ACTIVIDAD_SESION' => 2]))->assertCreated();
        foreach (['PUT', 'PATCH'] as $method) {
            $this->json($method, '/api/attendance/update/2', ['ID_ACTIVIDAD_SESION' => 1])->assertUnprocessable();
            $this->json($method, '/api/attendance/update/1', ['HORA_SALIDA' => '09:30'])->assertOk();
        }
        $this->assertDatabaseHas('certificado.asistencia', ['id_asistencia' => 2, 'id_actividad_sesion' => 2]);
        $this->assertDatabaseCount('certificado.asistencia', 2);
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_merged_gestion_records_can_support_a_practice_certificate(): void
    {
        $this->authorize();
        $this->prepareGestionFixtures();
        $person = $this->postJson('/api/persons/create', ['nombres' => 'Persona de prueba', 'apellido_paterno' => 'Fixture', 'fecha_registro' => '2026-01-01'])->assertCreated()->json('data.id_persona');
        $area = $this->postJson('/api/areas/create', ['nombre' => 'Área de prueba'])->assertCreated()->json('data.id_area');
        $institution = $this->postJson('/api/institutions/create', ['nombre_institucion' => 'Institución de prueba', 'pais' => 'Perú', 'tipo' => 'Pública'])->assertCreated()->json('data.id_institucion');
        $practitioner = Practicing_IndividualData::createPracticingIndividual(['id_persona' => $person, 'codigo' => 'FIXTURE-001', 'estado' => 'ACTIVO']);
        $this->assertTrue($practitioner['success']);
        $practice = PracticeData::createPractice(['id_persona_practicante' => $practitioner['data']->id_persona_practicante, 'id_institucion' => $institution, 'id_area' => $area, 'carrera' => 'Sistemas', 'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-01-31', 'estado' => 'FINALIZADO']);
        $this->assertTrue($practice['success']);
        $payload = array_replace($this->certificate(), ['id_persona' => $person, 'id_participacion' => null, 'id_practica' => $practice['data']->id_practica]);
        $this->postJson('/api/certificados/create', $payload)->assertCreated()->assertJsonPath('data.id_persona', $person);
        $this->postJson('/api/certificados/create', array_replace($payload, ['id_persona' => 2]))->assertUnprocessable();
        $this->assertSame($person, Persona::findOrFail($person)->id_persona);
        $this->assertSame($practitioner['data']->id_persona_practicante, Practice::findOrFail($practice['data']->id_practica)->id_persona_practicante);
    }

    public function test_merged_worker_records_can_support_a_recognition_certificate(): void
    {
        $this->authorize();
        $this->prepareGestionFixtures();
        $person = $this->postJson('/api/persons/create', ['nombres' => 'Trabajador de prueba', 'apellido_paterno' => 'Fixture', 'fecha_registro' => '2026-01-01'])->assertCreated()->json('data.id_persona');
        $worker = Person_WorkerData::createPersonWorker(['id_persona' => $person, 'id_area' => 1, 'cargo' => 'Cargo de prueba', 'fecha_ingreso' => '2026-01-01', 'estado' => true]);
        $this->assertTrue($worker['success']);
        $recognition = $this->postJson('/api/reconocimientos/create', ['id_persona_trabajador' => $worker['data']->id_persona_trabajador, 'titulo' => 'Mérito', 'descripcion' => 'Prueba', 'motivo' => 'Prueba', 'fecha_reconocimiento' => '2026-01-01', 'estado' => 'ACTIVO'])->assertCreated()->json('data.id_reconocimiento');
        $payload = array_replace($this->certificate(), ['id_persona' => $person, 'id_participacion' => null, 'id_reconocimiento' => $recognition]);
        $this->postJson('/api/certificados/create', $payload)->assertCreated()->assertJsonPath('data.id_persona', $person);
        $this->postJson('/api/certificados/create', array_replace($payload, ['id_persona' => 2]))->assertUnprocessable();
    }

    private function prepareGestionFixtures(): void
    {
        foreach ([
            'gestion.persona' => ['nombres', 'apellido_paterno', 'apellido_materno', 'telefono', 'direccion', 'correo', 'fecha_registro'],
            'gestion.area' => ['nombre'],
            'gestion.practica' => ['id_institucion', 'id_area', 'carrera', 'fecha_inicio', 'fecha_fin', 'estado'],
            'gestion.persona_practicante' => ['codigo', 'estado'],
            'gestion.persona_trabajador' => ['id_area', 'cargo', 'fecha_ingreso', 'fecha_fin', 'estado'],
        ] as $name => $fields) {
            $fields = array_values(array_filter($fields, fn (string $field): bool => ! Schema::hasColumn($name, $field)));
            Schema::table($name, function (Blueprint $table) use ($fields) {
                foreach ($fields as $field) {
                    if (str_starts_with($field, 'id_')) {
                        $table->integer($field)->nullable();
                    } else {
                        $table->string($field)->nullable();
                    }
                }
            });
        }
        Schema::create('gestion.institucion', function (Blueprint $table) {
            $table->increments('id_institucion');
            $table->string('nombre_institucion');
            $table->string('pais')->nullable();
            $table->string('tipo');
        });
    }

    private function updateColumns(callable $operation, string $table): array
    {
        DB::connection()->flushQueryLog();
        DB::connection()->enableQueryLog();
        try {
            $operation();
            $wrapped = DB::connection()->getQueryGrammar()->wrapTable($table);
            $queries = array_values(array_filter(DB::connection()->getQueryLog(), fn ($query) => str_starts_with($query['query'], 'update '.$wrapped.' set ')));
            if ($queries === []) {
                return [];
            }
            $this->assertCount(1, $queries);
            $set = explode(' where ', explode(' set ', $queries[0]['query'], 2)[1], 2)[0];
            preg_match_all('/"([a-z_]+)" = /', $set, $matches);

            return $matches[1];
        } finally {
            DB::connection()->disableQueryLog();
            DB::connection()->flushQueryLog();
        }
    }
}
