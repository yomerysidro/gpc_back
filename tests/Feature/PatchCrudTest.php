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
            Schema::create($model->getTable(), function (Blueprint $table) use ($model) {
                $table->increments($model->getKeyName());
            });
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
