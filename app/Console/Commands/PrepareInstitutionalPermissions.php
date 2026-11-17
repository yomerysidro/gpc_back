<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

#[Signature('security:prepare-permissions {--apply : Aplicar exclusivamente el plan revisado}')]
#[Description('Muestra el plan idempotente de permisos de Solicitudes; no escribe sin --apply')]
class PrepareInstitutionalPermissions extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $roles = DB::table('acceso.rol')->whereIn('id_rol', array_keys(config('module_permissions.request_role_permissions')))->get()->keyBy('id_rol');
        foreach ([1 => 'Administrador', 2 => 'Digitador', 3 => 'Gerencial'] as $id => $name) {
            if (! isset($roles[$id]) || ! $roles[$id]->estado || $roles[$id]->nombre !== $name) {
                $this->error('Los roles actuales no coinciden con el plan revisable. No se realizaron cambios.');

                return self::FAILURE;
            }
        }
        $existingModules = DB::table('acceso.modulo')->where('url', '/solicitudes')->get();
        if ($existingModules->count() > 1 || ($existingModules->isNotEmpty() && ! $existingModules->first()->estado)) {
            $this->error('El módulo Solicitudes está duplicado o inactivo; requiere revisión manual.');

            return self::FAILURE;
        }
        $module = $existingModules->first();
        $this->line($module ? 'Reutilizar módulo activo /solicitudes.' : 'Crear módulo Solicitudes con URL /solicitudes.');
        foreach (config('module_permissions.request_role_permissions') as $roleId => $permissions) {
            $this->line($roles[$roleId]->nombre.': '.implode(', ', array_map(fn (string $permission): string => 'solicitudes.'.$permission, $permissions)));
        }
        $this->line('Administrador: completar menú y capacidades administrativas activas; no otorgar aprobación formal ni firma por sustitución.');
        if (! $this->option('apply')) {
            $this->info('Solo lectura: plan no aplicado. Revisar antes de usar --apply.');

            return self::SUCCESS;
        }
        DB::transaction(function () use ($module): void {
            $lockedRoles = DB::table('acceso.rol')->whereIn('id_rol', [1, 2, 3])->lockForUpdate()->get()->keyBy('id_rol');
            foreach ([1 => 'Administrador', 2 => 'Digitador', 3 => 'Gerencial'] as $id => $name) {
                if (! isset($lockedRoles[$id]) || ! $lockedRoles[$id]->estado || $lockedRoles[$id]->nombre !== $name) {
                    throw new RuntimeException('Las asignaciones cambiaron durante la revisión del plan.');
                }
            }
            $modules = DB::table('acceso.modulo')->where('url', '/solicitudes')->get();
            if ($modules->count() > 1 || ($modules->isNotEmpty() && ! $modules->first()->estado)) {
                throw new RuntimeException('El módulo cambió durante la revisión del plan.');
            }
            $module = $modules->first();
            $moduleId = $module?->id_modulo ?? DB::table('acceso.modulo')->insertGetId(['nombre' => 'Solicitudes', 'url' => '/solicitudes', 'estado' => true], 'id_modulo');
            foreach (config('module_permissions.request_role_permissions') as $roleId => $permissions) {
                DB::table('acceso.rol_modulo')->updateOrInsert(['id_rol' => $roleId, 'id_modulo' => $moduleId], []);
                foreach ($permissions as $operation) {
                    $key = 'solicitudes.'.$operation;
                    $permission = DB::table('acceso.permiso')->where('id_modulo', $moduleId)->where('clave', $key)->first();
                    if ($permission && ! $permission->estado) {
                        throw new RuntimeException('Un permiso del plan está inactivo; no se reactivará automáticamente.');
                    }
                    $permissionId = $permission?->id_permiso ?? DB::table('acceso.permiso')->insertGetId(['id_modulo' => $moduleId, 'nombre' => $operation, 'clave' => $key, 'estado' => true], 'id_permiso');
                    DB::table('acceso.rol_modulo_permiso')->updateOrInsert(['id_rol' => $roleId, 'id_modulo' => $moduleId, 'id_permiso' => $permissionId], []);
                }
            }
        });
        $this->info('Plan aplicado sin modificar usuarios, vínculos laborales ni permisos ajenos a Solicitudes.');

        return self::SUCCESS;
    }
}
