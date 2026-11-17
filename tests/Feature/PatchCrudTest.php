<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\ModulePolicy;
use Tests\Fixtures\ModuleUser as User;
use Tests\TestCase;

class PatchCrudTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32)), 'database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'database.connections.sqlite.url' => null]);
        DB::purge('sqlite');
        DB::statement("ATTACH DATABASE ':memory:' AS gestion");
        DB::statement('CREATE TABLE gestion.area (id_area INTEGER PRIMARY KEY, nombre TEXT)');
        DB::statement('CREATE TABLE gestion.persona_trabajador (id_persona INTEGER, id_area INTEGER, estado BOOLEAN, fecha_ingreso TEXT, fecha_fin TEXT)');
        DB::table('gestion.area')->insert(['id_area' => 1, 'nombre' => 'Área de prueba']);
        DB::table('gestion.persona_trabajador')->insert(['id_persona' => 1, 'id_area' => 1, 'estado' => true, 'fecha_ingreso' => today()->toDateString()]);
        $this->actingAs(new User(['name' => 'Authorized fixture']));
        Gate::policy(User::class, ModulePolicy::class);
    }

    #[DataProvider('crudUpdateRequests')]
    public function test_patch_and_put_reach_validation_for_each_crud(string $path, string $field): void
    {
        $prefix = explode('/', $path)[2];
        $entities = ['attendance' => 'Attendance', 'activity' => 'Activity', 'activity-session' => 'ActivitySession', 'ativicty-type' => 'ActivityType', 'activity-speaker' => 'ActivitySpeaker', 'participation' => 'Participation', 'participation-type' => 'ParticipationType'];
        if (isset($entities[$prefix])) {
            if (! in_array('certificado', array_column(DB::select('PRAGMA database_list'), 'name'), true)) {
                DB::statement("ATTACH DATABASE ':memory:' AS certificado");
            }
            $entity = $entities[$prefix];
            $class = "App\\Models\\$entity\\$entity";
            $model = new $class;
            Schema::create($model->getTable(), function (Blueprint $table) use ($model, $entity) {
                $table->increments($model->getKeyName());
                if ($entity === 'Activity') {
                    $table->integer('id_area')->default(1);
                } elseif (in_array($entity, ['ActivitySession', 'Participation', 'ActivitySpeaker'], true)) {
                    $table->integer('id_actividad')->default(1);
                } elseif ($entity === 'Attendance') {
                    $table->integer('id_participacion')->default(1);
                    $table->integer('id_actividad_sesion')->default(1);
                }
            });
            if (in_array($entity, ['ActivitySession', 'Participation', 'ActivitySpeaker', 'Attendance'], true)) {
                DB::statement('CREATE TABLE certificado.actividad (id_actividad INTEGER PRIMARY KEY, id_area INTEGER)');
                DB::table('certificado.actividad')->insert(['id_actividad' => 1, 'id_area' => 1]);
            }
            if ($entity === 'Attendance') {
                DB::statement('CREATE TABLE certificado.participacion (id_participacion INTEGER PRIMARY KEY, id_actividad INTEGER)');
                DB::table('certificado.participacion')->insert(['id_participacion' => 1, 'id_actividad' => 1]);
            }
            DB::table($model->getTable())->insert([$model->getKeyName() => 1]);
            $this->actingAs(new User(['name' => 'Authorized fixture']));
            Gate::policy($class, ModulePolicy::class);
        }
        foreach (['PATCH', 'PUT'] as $method) {
            $this->json($method, $path, [$field => null])
                ->assertUnprocessable()
                ->assertJsonPath('success', false)
                ->assertJsonStructure(['data' => [$field]]);
        }
    }

    public static function crudUpdateRequests(): array
    {
        return [
            'attendance' => ['/api/attendance/update/1', 'ESTADO'],
            'activity' => ['/api/activity/1', 'estado'],
            'activity session' => ['/api/activity-session/1', 'estado'],
            'activity type' => ['/api/ativicty-type/1', 'estado'],
            'activity speaker' => ['/api/activity-speaker/1', 'id_actividad'],
            'participation' => ['/api/participation/1', 'estado'],
            'participation type' => ['/api/participation-type/1', 'estado'],
        ];
    }
}
