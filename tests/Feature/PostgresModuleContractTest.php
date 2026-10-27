<?php

namespace Tests\Feature;

use App\Models\Activity\Activity;
use App\Models\Area\Area;
use App\Models\Attendance\Attendance;
use App\Models\Certificate\Certificate;
use App\Models\Person\Persona;
use App\Models\Practice\Practice;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Group;
use Tests\Fixtures\ModulePolicy;
use Tests\Fixtures\ModuleUser as User;
use Tests\Fixtures\PostgresModuleConnection;
use Tests\TestCase;
use Throwable;

#[Group('postgresql')]
class PostgresModuleContractTest extends TestCase
{
    private ?Connection $testConnection = null;

    protected function setUp(): void
    {
        parent::setUp();
        $url = (string) getenv('MODULES_PG_TEST_URL');
        if ($url === '') {
            $this->markTestSkipped('PostgreSQL omitido: falta MODULES_PG_TEST_URL y una base exclusiva de pruebas.');
        }
        $configuration = PostgresModuleConnection::configuration($url, getenv('MODULES_PG_TEST_EXCLUSIVE') === '1');
        if (! extension_loaded('pdo_pgsql')) {
            $this->markTestSkipped('PostgreSQL omitido: el proceso PHP necesita pdo_pgsql.');
        }
        config(['database.connections.modules_pg_test' => $configuration, 'database.default' => 'modules_pg_test']);
        $connection = DB::connection('modules_pg_test');
        try {
            $database = $connection->selectOne('SELECT current_database() AS name')->name;
        } catch (Throwable) {
            $this->fail('No se pudo conectar con PostgreSQL exclusivo de pruebas; no se utilizará la conexión de la aplicación.');
        }
        $this->assertSame('certificados_morales_test', $database);
        $connection->beginTransaction();
        $this->testConnection = $connection;
        $this->beforeApplicationDestroyed(function (): void {
            if ($this->testConnection !== null) {
                while ($this->testConnection->transactionLevel() > 0) {
                    $this->testConnection->rollBack();
                }
                $this->testConnection->disconnect();
            }
        });
        $connection->statement("SET LOCAL lock_timeout = '3s'");
        $connection->statement("SET LOCAL statement_timeout = '15s'");
        $connection->select('SELECT pg_advisory_xact_lock(63725003)');
        $existing = $connection->selectOne("SELECT count(*) AS total FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname NOT LIKE 'pg_%' AND n.nspname <> 'information_schema' AND c.relkind IN ('r', 'p', 'v', 'm', 'f')");
        $this->assertSame(0, (int) $existing->total, 'La base debe estar vacía; no se alterarán tablas preexistentes.');
        $schemas = $connection->select("SELECT schema_name FROM information_schema.schemata WHERE schema_name IN ('gestion', 'certificado', 'acceso')");
        $this->assertCount(0, $schemas, 'No se reutilizarán esquemas funcionales preexistentes.');
        foreach (['2026_10_26_000001_create_gestion_schema.php', '2026_10_26_000003_create_certificado_schema.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
    }

    public function test_local_ddl_creates_all_twenty_five_bigint_primary_keys(): void
    {
        $tables = [
            'gestion' => ['persona', 'tipo_documento', 'persona_documento', 'area', 'institucion', 'persona_trabajador', 'persona_practicante', 'practica', 'practica_supervisor', 'persona_ponente'],
            'certificado' => ['actividad', 'tipo_actividad', 'actividad_ponente', 'participacion', 'tipo_participacion', 'actividad_sesion', 'asistencia', 'tipo_certificado', 'certificado', 'reconocimiento', 'certificado_documento', 'firma', 'certificado_firma', 'consulta_certificado', 'actividad_firma'],
        ];
        foreach ($tables as $schema => $names) {
            foreach ($names as $name) {
                $column = DB::selectOne('SELECT data_type, is_nullable, column_default, is_identity FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?', [$schema, $name, 'id_'.$name]);
                $this->assertNotNull($column, $schema.'.'.$name);
                $this->assertSame('bigint', $column->data_type);
                $this->assertSame('NO', $column->is_nullable);
                $this->assertTrue($column->is_identity === 'YES' || str_contains($column->column_default ?? '', 'nextval('));
                $primary = DB::selectOne("SELECT count(*) AS total FROM information_schema.table_constraints WHERE table_schema = ? AND table_name = ? AND constraint_type = 'PRIMARY KEY'", [$schema, $name]);
                $this->assertSame(1, (int) $primary->total);
            }
        }
        $this->assertSame(0, (int) DB::selectOne("SELECT count(*) AS total FROM information_schema.tables WHERE table_schema IN ('public', 'acceso') AND table_type = 'BASE TABLE'")->total);
    }

    public function test_local_ddl_defines_all_seven_cross_schema_foreign_keys(): void
    {
        $relations = [
            ['actividad', 'id_area', 'area'], ['participacion', 'id_persona', 'persona'],
            ['actividad_ponente', 'id_persona_ponente', 'persona_ponente'], ['certificado', 'id_persona', 'persona'],
            ['certificado', 'id_practica', 'practica'], ['reconocimiento', 'id_persona_trabajador', 'persona_trabajador'],
            ['firma', 'id_persona_trabajador', 'persona_trabajador'],
        ];
        foreach ($relations as [$table, $key, $target]) {
            $foreign = DB::selectOne("SELECT tn.nspname AS target_schema, t.relname AS target_table, ta.attname AS target_column, c.confdeltype AS delete_action, c.confupdtype AS update_action FROM pg_catalog.pg_constraint c JOIN pg_catalog.pg_class s ON s.oid = c.conrelid JOIN pg_catalog.pg_namespace sn ON sn.oid = s.relnamespace JOIN pg_catalog.pg_class t ON t.oid = c.confrelid JOIN pg_catalog.pg_namespace tn ON tn.oid = t.relnamespace JOIN pg_catalog.pg_attribute a ON a.attrelid = s.oid AND a.attnum = c.conkey[1] JOIN pg_catalog.pg_attribute ta ON ta.attrelid = t.oid AND ta.attnum = c.confkey[1] WHERE c.contype = 'f' AND sn.nspname = 'certificado' AND s.relname = ? AND a.attname = ?", [$table, $key]);
            $this->assertNotNull($foreign);
            $this->assertSame('gestion', $foreign->target_schema);
            $this->assertSame($target, $foreign->target_table);
            $this->assertSame('id_'.$target, $foreign->target_column);
            $this->assertSame('a', $foreign->delete_action);
            $this->assertSame('a', $foreign->update_action);
        }
    }

    private function origins(): array
    {
        $person = DB::table('gestion.persona')->insertGetId(['nombres' => 'Fixture', 'apellido_paterno' => 'Prueba', 'fecha_registro' => '2026-01-01'], 'id_persona');
        $area = DB::table('gestion.area')->insertGetId(['nombre' => 'Fixture'], 'id_area');
        $institution = DB::table('gestion.institucion')->insertGetId(['nombre_institucion' => 'Fixture', 'tipo' => 'Publica'], 'id_institucion');
        $practitioner = DB::table('gestion.persona_practicante')->insertGetId(['id_persona' => $person, 'codigo' => 'FIXTURE', 'estado' => 'ACTIVO'], 'id_persona_practicante');
        $practice = DB::table('gestion.practica')->insertGetId(['id_persona_practicante' => $practitioner, 'id_institucion' => $institution, 'id_area' => $area, 'carrera' => 'Sistemas', 'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-01-31', 'estado' => 'FINALIZADO'], 'id_practica');
        $type = DB::table('certificado.tipo_certificado')->insertGetId(['nombre_tipo' => 'Fixture', 'estado' => true], 'id_tipo_certificado');
        $certificate = DB::table('certificado.certificado')->insertGetId(['id_persona' => $person, 'id_tipo_certificado' => $type, 'id_practica' => $practice, 'fecha_emision' => '2026-02-01', 'estado' => 'Vigente'], 'id_certificado');

        return compact('person', 'area', 'institution', 'practitioner', 'practice', 'type', 'certificate');
    }

    private function assertSqlState(string $state, callable $operation): void
    {
        try {
            DB::transaction($operation);
            $this->fail('PostgreSQL deb?a rechazar la operaci?n con SQLSTATE '.$state);
        } catch (QueryException $exception) {
            $this->assertSame($state, $exception->errorInfo[0]);
        }
    }

    public function test_foreign_keys_nullability_checks_and_unique_document_hash_are_enforced(): void
    {
        $ids = $this->origins();
        $this->assertGreaterThan(0, $ids['certificate']);
        $this->assertSame($ids['person'], Certificate::findOrFail($ids['certificate'])->practice->practitioner->persona->id_persona);
        $this->assertSqlState('23503', fn () => DB::table('certificado.certificado')->where('id_certificado', $ids['certificate'])->update(['id_persona' => 999999]));
        $this->assertSqlState('23502', fn () => DB::table('certificado.certificado')->where('id_certificado', $ids['certificate'])->update(['fecha_emision' => null]));
        $this->assertSqlState('23514', fn () => DB::table('certificado.certificado')->where('id_certificado', $ids['certificate'])->update(['id_practica' => null]));
        $document = ['id_certificado' => $ids['certificate'], 'codigo_verificacion' => 'FIXTURE1', 'hash_certificado' => str_repeat('a', 64), 'ruta_archivo' => 'private/fixture.pdf', 'fecha_generacion' => '2026-02-01 14:15:16', 'estado' => 'Vigente'];
        $id = DB::table('certificado.certificado_documento')->insertGetId($document, 'id_certificado_documento');
        $this->assertSame('2026-02-01 14:15:16', DB::table('certificado.certificado_documento')->where('id_certificado_documento', $id)->value('fecha_generacion'));
        $this->assertSqlState('23505', fn () => DB::table('certificado.certificado_documento')->insert(array_replace($document, ['codigo_verificacion' => 'FIXTURE2'])));
        $this->assertSqlState('23514', fn () => DB::table('certificado.certificado_documento')->where('id_certificado_documento', $id)->update(['estado' => 'GENERADO']));
        $this->assertSqlState('23503', fn () => DB::table('gestion.practica')->where('id_practica', $ids['practice'])->delete());
    }

    public function test_area_crud_authorization_and_historical_practice_protection(): void
    {
        $this->getJson('/api/areas/list')->assertUnauthorized();
        $this->actingAs(new User(['name' => 'Authorized fixture']));
        $this->getJson('/api/areas/list')->assertForbidden();
        foreach ([Area::class, Persona::class, Practice::class] as $model) {
            Gate::policy($model, ModulePolicy::class);
        }
        $id = $this->postJson('/api/areas/create', ['nombre' => 'Temporal'])->assertCreated()->json('data.id_area');
        $this->getJson('/api/areas/detail/'.$id)->assertOk();
        $this->putJson('/api/areas/update/'.$id, ['nombre' => 'Actualizada'])->assertOk();
        $this->deleteJson('/api/areas/delete/'.$id)->assertOk();
        $this->getJson('/api/areas/detail/'.$id)->assertNotFound();
        $this->postJson('/api/areas/create', [])->assertUnprocessable();
        $ids = $this->origins();
        $this->deleteJson('/api/practices/delete/'.$ids['practice'])->assertConflict();
        $this->deleteJson('/api/persons/delete/'.$ids['person'])->assertConflict();
        $this->assertSame(1, DB::table('certificado.certificado')->count());
    }

    public function test_activity_crud_uses_postgresql_checks_and_cross_schema_joins(): void
    {
        $this->actingAs(new User(['name' => 'Authorized fixture']));
        Gate::policy(Activity::class, ModulePolicy::class);
        $ids = $this->origins();
        $type = DB::table('certificado.tipo_actividad')->insertGetId(['nombre' => 'Fixture', 'estado' => true], 'id_tipo_actividad');
        $payload = ['id_area' => $ids['area'], 'id_tipo_actividad' => $type, 'nombre' => 'Fixture', 'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-01-31', 'estado' => 'Activa'];
        $id = $this->postJson('/api/activity', $payload)->assertCreated()->json('data.id_actividad');
        $this->assertNotNull($id);
        $this->assertSame($ids['area'], Activity::findOrFail($id)->area->id_area);
        $this->assertSqlState('23514', fn () => DB::table('certificado.actividad')->where('id_actividad', $id)->update(['fecha_fin' => '2025-12-31']));
        $this->assertSqlState('23514', fn () => DB::table('certificado.actividad')->where('id_actividad', $id)->update(['estado' => 'ACTIVO']));
        $this->patchJson('/api/activity/'.$id, ['nombre' => 'Actualizada'])->assertOk();
        $this->deleteJson('/api/activity/'.$id)->assertOk();
        $this->getJson('/api/activity/'.$id)->assertNotFound();
    }

    public function test_attendance_unique_constraint_and_historical_protection(): void
    {
        $ids = $this->origins();
        $activityType = DB::table('certificado.tipo_actividad')->insertGetId(['nombre' => 'Fixture', 'estado' => true], 'id_tipo_actividad');
        $activity = DB::table('certificado.actividad')->insertGetId(['id_area' => $ids['area'], 'id_tipo_actividad' => $activityType, 'nombre' => 'Fixture', 'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-01-31', 'estado' => 'Activa'], 'id_actividad');
        $participationType = DB::table('certificado.tipo_participacion')->insertGetId(['nombre' => 'Asistente', 'estado' => true], 'id_tipo_participacion');
        $participation = DB::table('certificado.participacion')->insertGetId(['id_persona' => $ids['person'], 'id_actividad' => $activity, 'id_tipo_participacion' => $participationType, 'fecha_inscripcion' => '2026-01-01', 'estado' => 'ACTIVO'], 'id_participacion');
        $session = DB::table('certificado.actividad_sesion')->insertGetId(['id_actividad' => $activity, 'nombre' => 'Fixture', 'fecha' => '2026-01-20', 'hora_inicio' => '08:00', 'hora_fin' => '09:00', 'estado' => 'ACTIVO'], 'id_actividad_sesion');
        $this->actingAs(new User(['name' => 'Authorized fixture']));
        Gate::policy(Attendance::class, ModulePolicy::class);
        $payload = ['ID_PARTICIPACION' => $participation, 'ID_ACTIVIDAD_SESION' => $session, 'HORA_ENTRADA' => '08:00', 'HORA_SALIDA' => '09:00', 'ESTADO' => 'PRESENTE'];
        $id = $this->postJson('/api/attendance/create', $payload)->assertCreated()->json('data.ID_ASISTENCIA');
        $this->assertNotNull($id);
        $this->postJson('/api/attendance/create', $payload)->assertUnprocessable();
        $this->assertSqlState('23505', fn () => DB::table('certificado.asistencia')->insert(['id_participacion' => $participation, 'id_actividad_sesion' => $session, 'estado' => 'PRESENTE']));
        foreach (['PUT', 'PATCH'] as $method) {
            $this->json($method, '/api/attendance/update/'.$id, ['HORA_SALIDA' => '09:15'])->assertOk();
        }
        DB::table('certificado.certificado')->insert(['id_persona' => $ids['person'], 'id_tipo_certificado' => $ids['type'], 'id_participacion' => $participation, 'fecha_emision' => '2026-02-01', 'estado' => 'Vigente']);
        $this->deleteJson('/api/attendance/delete/'.$id)->assertConflict();
        $this->assertSame(1, DB::table('certificado.asistencia')->count());
    }
}
