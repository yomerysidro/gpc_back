<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\ReadOnlyModulePolicy;
use Tests\TestCase;

class UserSecurityTest extends TestCase
{
    private const REQUESTS = [
        ['GET', '/api/users/list'],
        ['GET', '/api/users/detail/1'],
        ['POST', '/api/users/create'],
        ['PUT', '/api/users/update/1'],
        ['PATCH', '/api/users/update/1'],
        ['DELETE', '/api/users/delete/1'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'database.connections.sqlite.url' => null]);
        DB::purge('sqlite');
    }

    public function test_all_user_operations_require_authentication_without_redirecting(): void
    {
        foreach (self::REQUESTS as [$method, $uri]) {
            $this->call($method, $uri)->assertUnauthorized()
                ->assertHeaderMissing('Location')
                ->assertExactJson(['success' => false, 'message' => 'No autenticado.', 'data' => []]);
        }
    }

    public function test_authentication_does_not_grant_user_administration(): void
    {
        $this->actingAs(new User(['name' => 'Unprivileged fixture']));
        DB::connection()->enableQueryLog();
        try {
            foreach (self::REQUESTS as [$method, $uri]) {
                $this->json($method, $uri)->assertForbidden()
                    ->assertExactJson(['success' => false, 'message' => 'No autorizado.', 'data' => []]);
            }
            $this->assertSame([], DB::connection()->getQueryLog());
        } finally {
            DB::connection()->disableQueryLog();
            DB::connection()->flushQueryLog();
        }
    }

    public function test_user_read_permission_does_not_grant_write_permission(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('email');
            $table->string('password');
            $table->timestamps();
        });
        DB::table('users')->insert(['id' => 1, 'name' => 'Target fixture', 'email' => 'fixture@example.test', 'password' => 'secret-not-returned']);
        $this->actingAs(new User(['name' => 'Authorized fixture']));
        Gate::policy(User::class, ReadOnlyModulePolicy::class);

        $this->getJson('/api/users/list')->assertOk()->assertDontSee('secret-not-returned');
        $this->getJson('/api/users/detail/1')->assertOk()->assertJsonMissingPath('data.password');
        foreach (array_slice(self::REQUESTS, 2) as [$method, $uri]) {
            $this->json($method, $uri, ['name' => 'Denied'])->assertForbidden();
        }
        $this->assertDatabaseHas('users', ['id' => 1, 'name' => 'Target fixture']);
    }
}
