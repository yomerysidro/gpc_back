<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\ModulePolicy;
use Tests\TestCase;

class PatchCrudTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);
        $this->actingAs(new User(['name' => 'Authorized fixture']));
        Gate::policy(User::class, ModulePolicy::class);
    }

    #[DataProvider('crudUpdateRequests')]
    public function test_patch_and_put_reach_validation_for_each_crud(string $path, string $field): void
    {
        $prefix = explode('/', $path)[2];
        if ($prefix === 'users') {
            $this->createUser();
        }
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
            'users' => ['/api/users/update/1', 'name'],
            'attendance' => ['/api/attendance/update/1', 'ESTADO'],
            'activity' => ['/api/activity/1', 'estado'],
            'activity session' => ['/api/activity-session/1', 'estado'],
            'activity type' => ['/api/ativicty-type/1', 'estado'],
            'activity speaker' => ['/api/activity-speaker/1', 'id_actividad'],
            'participation' => ['/api/participation/1', 'estado'],
            'participation type' => ['/api/participation-type/1', 'estado'],
        ];
    }

    public function test_patch_updates_user_name_and_preserves_omitted_fields(): void
    {
        $id = $this->createUser();
        $password = DB::table('users')->where('id', $id)->value('password');

        $this->patchJson('/api/users/update/'.$id, ['name' => 'Nombre actualizado'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Nombre actualizado')
            ->assertJsonPath('data.email', 'isait@example.com');

        $this->assertDatabaseHas('users', [
            'id' => $id,
            'name' => 'Nombre actualizado',
            'email' => 'isait@example.com',
            'password' => $password,
        ]);
    }

    public function test_patch_updates_only_the_user_password(): void
    {
        $id = $this->createUser();

        $this->patchJson('/api/users/update/'.$id, [
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertOk()->assertJsonMissingPath('data.password');

        $this->assertDatabaseHas('users', ['id' => $id, 'name' => 'Isait', 'email' => 'isait@example.com']);
        $this->assertTrue(Hash::check('new-password', DB::table('users')->where('id', $id)->value('password')));
    }

    public function test_put_still_requires_both_user_name_and_email(): void
    {
        $id = $this->createUser();

        $this->putJson('/api/users/update/'.$id, ['name' => 'Otro nombre'])
            ->assertUnprocessable()
            ->assertJsonStructure(['data' => ['email']]);

        $this->assertDatabaseHas('users', ['id' => $id, 'name' => 'Isait', 'email' => 'isait@example.com']);
    }

    private function createUser(): int
    {
        return $this->postJson('/api/users/create', [
            'name' => 'Isait',
            'email' => 'isait@example.com',
            'password' => 'initial-password',
            'password_confirmation' => 'initial-password',
        ])->assertCreated()->json('data.id');
    }
}
