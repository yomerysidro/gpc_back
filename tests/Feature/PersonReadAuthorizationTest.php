<?php

namespace Tests\Feature;

use App\Models\Activity\Activity;
use App\Models\Area\Area;
use App\Models\DocumentType\TipoDocumento;
use App\Models\Person\Persona;
use App\Models\PersonDocument\PersonaDocumento;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\ModuleUser;
use Tests\TestCase;

class PersonReadAuthorizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'database.connections.sqlite.url' => null]);
        DB::purge('sqlite');
    }

    public function test_both_tables_allow_list_and_detail_with_the_shared_read_permission(): void
    {
        DB::statement("ATTACH DATABASE ':memory:' AS gestion");
        $this->actingAs($this->userWithPermissions(['personas.verpersonas']), 'jwt');

        foreach (['persons' => ['persona', 'id_persona'], 'person-documents' => ['persona_documento', 'id_persona_documento']] as $endpoint => [$table, $key]) {
            Schema::create('gestion.'.$table, function (Blueprint $table) use ($key): void {
                $table->increments($key);
            });
            DB::table('gestion.'.$table)->insert([$key => 1]);

            $this->getJson('/api/'.$endpoint.'/list')->assertOk()->assertJsonPath('data.0.'.$key, 1);
            $this->getJson('/api/'.$endpoint.'/detail/1')->assertOk()->assertJsonPath('data.'.$key, 1);
            $this->putJson('/api/'.$endpoint.'/update/1', [])->assertForbidden();
            $this->deleteJson('/api/'.$endpoint.'/delete/1')->assertForbidden();
            $this->assertDatabaseHas('gestion.'.$table, [$key => 1]);
        }
    }

    public function test_document_routes_require_authentication(): void
    {
        $this->getJson('/api/person-documents/list')->assertUnauthorized();
        $this->getJson('/api/person-documents/detail/1')->assertUnauthorized();
    }

    public function test_document_routes_reject_the_old_read_permission(): void
    {
        $this->actingAs($this->userWithPermissions(['personas.ver', 'tipos-documento.ver']), 'jwt');

        $this->getJson('/api/person-documents/list')->assertForbidden();
        $this->getJson('/api/person-documents/detail/1')->assertForbidden();
    }

    public function test_both_models_require_the_exact_permission_for_each_ability(): void
    {
        $permissions = ['viewAny' => 'personas.verpersonas', 'view' => 'personas.verpersonas', 'create' => 'personas.crear', 'update' => 'personas.editar', 'delete' => 'personas.eliminar'];

        foreach ([Persona::class, PersonaDocumento::class] as $model) {
            foreach ($permissions as $ability => $permission) {
                foreach (array_unique(array_values($permissions)) as $grantedPermission) {
                    $user = $this->userWithPermissions([$grantedPermission]);
                    $this->assertSame($permission === $grantedPermission, Gate::forUser($user)->allows($ability, $model), $model.' '.$ability.' '.$grantedPermission);
                }

                $inactiveUser = $this->userWithPermissions([$permission]);
                $inactiveUser->estado = false;
                $this->assertFalse(Gate::forUser($inactiveUser)->allows($ability, $model));
            }
        }
    }

    public function test_shared_read_permission_does_not_allow_writes_through_either_route_group(): void
    {
        $this->actingAs($this->userWithPermissions(['personas.verpersonas']), 'jwt');

        foreach (['persons', 'person-documents'] as $endpoint) {
            $this->postJson('/api/'.$endpoint.'/create', [])->assertForbidden();
        }
    }

    public function test_document_types_keep_their_own_permissions(): void
    {
        $personUser = $this->userWithPermissions(['personas.verpersonas', 'personas.crear', 'personas.editar', 'personas.eliminar']);

        foreach (['viewAny' => 'ver', 'view' => 'ver', 'create' => 'crear', 'update' => 'editar', 'delete' => 'eliminar'] as $ability => $action) {
            $this->assertFalse(Gate::forUser($personUser)->allows($ability, TipoDocumento::class));
            $documentTypeUser = $this->userWithPermissions(['tipos-documento.'.$action]);
            $this->assertTrue(Gate::forUser($documentTypeUser)->allows($ability, TipoDocumento::class));
            $this->assertFalse(Gate::forUser($documentTypeUser)->allows($ability, PersonaDocumento::class));
        }
    }

    public function test_person_routes_require_authentication(): void
    {
        $this->getJson('/api/persons/list')->assertUnauthorized();
        $this->getJson('/api/persons/detail/1')->assertUnauthorized();
    }

    public function test_person_routes_return_403_without_the_exact_permission(): void
    {
        $this->actingAs($this->userWithPermissions(['personas.ver', 'actividades.ver']), 'jwt');

        $this->getJson('/api/persons/list')->assertForbidden();
        $this->getJson('/api/persons/detail/1')->assertForbidden();
    }

    public function test_existing_person_permission_authorizes_list_and_detail(): void
    {
        $user = $this->userWithPermissions(['personas.verpersonas']);

        $this->assertTrue(Gate::forUser($user)->allows('viewAny', Persona::class));
        $this->assertTrue(Gate::forUser($user)->allows('view', new Persona));
    }

    public function test_person_write_permissions_authorize_each_action(): void
    {
        $user = $this->userWithPermissions(['personas.verpersonas', 'personas.crear', 'personas.editar', 'personas.eliminar']);

        $this->assertTrue(Gate::forUser($user)->allows('create', Persona::class));
        $this->assertTrue(Gate::forUser($user)->allows('update', new Persona));
        $this->assertTrue(Gate::forUser($user)->allows('delete', new Persona));
    }

    public function test_inactive_user_cannot_read_persons(): void
    {
        $user = $this->userWithPermissions(['personas.verpersonas']);
        $user->estado = false;

        $this->assertFalse(Gate::forUser($user)->allows('viewAny', Persona::class));
    }

    public function test_existing_module_scopes_keep_their_behavior(): void
    {
        $user = $this->userWithPermissions(['actividades.ver', 'actividades.crear', 'areas.ver']);

        $this->assertTrue(Gate::forUser($user)->allows('viewAny', Activity::class));
        $this->assertTrue(Gate::forUser($user)->allows('create', Activity::class));
        $this->assertFalse(Gate::forUser($user)->allows('delete', new Activity));
        $this->assertTrue(Gate::forUser($user)->allows('viewAny', Area::class));
    }

    /**
     * @param  list<string>  $permissions
     */
    private function userWithPermissions(array $permissions): ModuleUser
    {
        return new class($permissions) extends ModuleUser
        {
            /**
             * @param  list<string>  $permissions
             */
            public function __construct(private readonly array $permissions = [])
            {
                parent::__construct();
            }

            public function permissionKeys(): array
            {
                return $this->permissions;
            }
        };
    }
}
