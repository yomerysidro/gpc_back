<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InstitutionalModulesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null]);
        DB::purge('sqlite');
        DB::statement("ATTACH DATABASE ':memory:' AS acceso");
        DB::statement('CREATE TABLE acceso.rol (id_rol INTEGER PRIMARY KEY, nombre TEXT, estado BOOLEAN)');
        DB::statement('CREATE TABLE acceso.modulo (id_modulo INTEGER PRIMARY KEY, nombre TEXT, url TEXT, imagen TEXT, estado BOOLEAN)');
        DB::statement('CREATE TABLE acceso.permiso (id_permiso INTEGER PRIMARY KEY, id_modulo INTEGER, nombre TEXT, clave TEXT, estado BOOLEAN)');
        DB::statement('CREATE TABLE acceso.rol_modulo (id_rol INTEGER, id_modulo INTEGER, PRIMARY KEY (id_rol, id_modulo))');
        DB::statement('CREATE TABLE acceso.rol_modulo_permiso (id_rol INTEGER, id_modulo INTEGER, id_permiso INTEGER, PRIMARY KEY (id_rol, id_modulo, id_permiso))');

        foreach (['Administrador', 'Digitador', 'Gerencial', 'Gestor', 'Rol personalizado'] as $name) {
            DB::table('acceso.rol')->insert(['nombre' => $name, 'estado' => true]);
        }
    }

    public function test_it_registers_modules_and_synchronizes_the_four_roles_idempotently(): void
    {
        $this->artisan('security:sync-modules')->assertSuccessful();
        $this->assertSame(0, DB::table('acceso.modulo')->count());

        $this->artisan('security:sync-modules', ['--apply' => true])->assertSuccessful();
        $admin = $this->keys(1);
        $digitador = $this->keys(2);
        $gerencial = $this->keys(3);
        $gestor = $this->keys(4);

        $this->assertContains('portal-publico.ver', $admin);
        $this->assertContains('configuracion.ver', $admin);
        foreach (['recursos-humanos.ver', 'solicitudes.revisar', 'personas.verpersonas', 'trabajadores.ver', 'actividades.editar', 'emision.gestionar'] as $key) {
            $this->assertContains($key, $digitador);
        }
        foreach (['gerencia.ver', 'solicitudes.aprobar', 'reportes.ver', 'emision.ver'] as $key) {
            $this->assertContains($key, $gerencial);
        }
        foreach (['solicitudes.propias', 'actividades.editar', 'participantes.crear'] as $key) {
            $this->assertContains($key, $gestor);
        }
        $this->assertSame([], array_values(array_intersect($digitador, ['gerencia.ver', 'configuracion.ver', 'solicitudes.aprobar', 'portal-publico.ver'])));
        $this->assertSame([], array_values(array_intersect($gerencial, ['solicitudes.propias', 'emision.gestionar', 'recursos-humanos.ver', 'portal-publico.ver'])));
        $this->assertSame([], array_values(array_intersect($gestor, ['solicitudes.revisar', 'emision.ver', 'recursos-humanos.ver', 'gerencia.ver', 'reportes.ver', 'portal-publico.ver'])));

        $legacy = (array) DB::table('acceso.permiso')->where('clave', 'usuarios.ver')->first(['id_modulo', 'id_permiso']);
        foreach ([2, 5] as $role) {
            DB::table('acceso.rol_modulo')->insert(['id_rol' => $role, 'id_modulo' => $legacy['id_modulo']]);
            DB::table('acceso.rol_modulo_permiso')->insert(['id_rol' => $role, ...$legacy]);
        }

        $this->artisan('security:sync-modules', ['--apply' => true])->assertSuccessful();
        $this->assertSame($admin, $this->keys(1));
        $this->assertSame($digitador, $this->keys(2));
        $this->assertSame($gerencial, $this->keys(3));
        $this->assertSame($gestor, $this->keys(4));
        $this->assertSame(['usuarios.ver'], $this->keys(5));
    }

    /** @return list<string> */
    private function keys(int $roleId): array
    {
        return DB::table('acceso.rol_modulo_permiso as assignment')
            ->join('acceso.permiso as permission', 'permission.id_permiso', '=', 'assignment.id_permiso')
            ->where('assignment.id_rol', $roleId)->orderBy('permission.clave')->pluck('permission.clave')->all();
    }
}
