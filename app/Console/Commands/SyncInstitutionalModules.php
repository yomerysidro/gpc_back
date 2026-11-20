<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

#[Signature('security:sync-modules {--apply : Aplicar los accesos institucionales}')]
#[Description('Organiza los módulos y permisos de Administrador, Digitador, Gerencial y Gestor')]
class SyncInstitutionalModules extends Command
{
    /** @var array<string, array{string, string, string, list<string>}> */
    private const MODULES = [
        'dashboard' => ['Inicio', '/dashboard', 'dashboard', ['ver', 'acceso-modular']],
        'usuarios' => ['Gestión de Usuarios', '/usuarios', 'people', ['ver', 'crear', 'editar', 'eliminar']],
        'roles' => ['Roles y Permisos', '/roles', 'admin_panel_settings', ['ver', 'crear', 'editar', 'eliminar']],
        'recursos-humanos' => ['Recursos Humanos', '/solicitudes/revision', 'groups', ['ver']],
        'personas' => ['Gestión de personas', '/gestion/personas', 'people', ['verpersonas', 'crear', 'editar', 'eliminar']],
        'trabajadores' => ['Trabajadores', '/trabajadores', 'group', ['ver', 'crear', 'editar', 'eliminar']],
        'practicantes' => ['Practicantes', '/practicantes', 'school', ['ver', 'crear', 'editar', 'eliminar']],
        'ponentes-firmantes' => ['Ponentes y Firmantes', '/ponentes-firmantes', 'campaign', ['ver', 'crear', 'editar', 'eliminar']],
        'reconocimientos' => ['Reconocimientos', '/reconocimientos', 'military_tech', ['ver', 'crear', 'editar', 'eliminar']],
        'solicitudes' => ['Solicitudes', '/solicitudes', 'inbox', ['crear', 'editar', 'enviar', 'propias', 'consultar', 'revisar', 'observar', 'derivar', 'historial', 'aprobar', 'rechazar']],
        'actividades' => ['Gestión de Actividades', '/actividades', 'event', ['ver', 'crear', 'editar', 'eliminar']],
        'participantes' => ['Participantes', '/participantes', 'group', ['ver', 'crear', 'editar', 'eliminar']],
        'emision' => ['Emisión de Certificados', '/emision', 'workspace_premium', ['ver', 'crear', 'editar', 'eliminar', 'gestionar']],
        'gerencia' => ['Gerencia', '/pendientes/pendientes-aprobacion', 'account_balance', ['ver']],
        'reportes' => ['Reportes', '/reportes', 'bar_chart', ['ver']],
        'configuracion' => ['Configuración General', '/gestion/configuracion', 'settings', ['ver']],
        'areas' => ['Áreas', '/areas', 'group', ['ver', 'crear', 'editar', 'eliminar']],
        'instituciones' => ['Gestión instituciones', '/instituciones', 'account_balance', ['ver', 'crear', 'editar', 'eliminar']],
        'tipos-documento' => ['Gestión tipo documentos', '/documentos', 'badge', ['ver', 'crear', 'editar', 'eliminar']],
        'portal-publico' => ['Portal público', '/portal-certificados', 'public', ['ver']],
    ];

    private const ROLES = [
        'Administrador' => [],
        'Digitador' => [
            'dashboard' => '*', 'recursos-humanos' => '*', 'personas' => '*', 'trabajadores' => '*', 'practicantes' => '*',
            'ponentes-firmantes' => '*', 'reconocimientos' => '*', 'actividades' => '*', 'participantes' => '*', 'emision' => '*',
            'solicitudes' => ['crear', 'editar', 'enviar', 'propias', 'consultar', 'revisar', 'observar', 'derivar', 'historial'],
            'areas' => ['ver'], 'tipos-documento' => ['ver'], 'instituciones' => ['ver'],
        ],
        'Gerencial' => [
            'dashboard' => '*', 'gerencia' => '*', 'reportes' => '*', 'emision' => ['ver', 'editar'],
            'solicitudes' => ['consultar', 'historial', 'aprobar', 'rechazar', 'observar'],
        ],
        'Gestor' => [
            'dashboard' => '*', 'actividades' => '*', 'participantes' => '*',
            'solicitudes' => ['crear', 'editar', 'enviar', 'propias'],
            'areas' => ['ver'], 'personas' => ['verpersonas'], 'tipos-documento' => ['ver'], 'ponentes-firmantes' => ['ver'],
        ],
    ];

    public function handle(): int
    {
        foreach (self::ROLES as $role => $modules) {
            $this->line($role.': '.($modules === [] ? 'todos los módulos activos' : implode(', ', array_keys($modules))));
        }

        if (! $this->option('apply')) {
            $this->info('Solo lectura. Usa --apply para sincronizar los accesos.');

            return self::SUCCESS;
        }

        DB::transaction(function (): void {
            $roles = DB::table('acceso.rol')->whereIn('nombre', array_keys(self::ROLES))->orderBy('id_rol')->lockForUpdate()->get();
            if ($roles->count() !== count(self::ROLES) || $roles->pluck('nombre')->unique()->count() !== count(self::ROLES)
                || $roles->contains(fn (object $role): bool => ! $role->estado)) {
                throw new RuntimeException('Los cuatro roles deben existir, estar activos y tener nombres únicos.');
            }

            $catalog = [];
            foreach (self::MODULES as $key => [$name, $url, $icon, $actions]) {
                $matches = DB::table('acceso.modulo')->where('url', $url)->get();
                if ($matches->count() > 1 || ($matches->isNotEmpty() && ! $matches->first()->estado)) {
                    throw new RuntimeException('Módulo duplicado o inactivo: '.$url);
                }
                $moduleId = $matches->first()?->id_modulo
                    ?? DB::table('acceso.modulo')->insertGetId(['nombre' => $name, 'url' => $url, 'imagen' => $icon, 'estado' => true], 'id_modulo');
                foreach ($actions as $action) {
                    $permissionKey = $key.'.'.$action;
                    $permissions = DB::table('acceso.permiso')->where('clave', $permissionKey)->get();
                    $permission = $permissions->first();
                    if ($permissions->count() > 1 || ($permission && (! $permission->estado || (int) $permission->id_modulo !== (int) $moduleId))) {
                        throw new RuntimeException('Permiso duplicado, inactivo o fuera de su módulo: '.$permissionKey);
                    }
                    $permissionId = $permission?->id_permiso ?? DB::table('acceso.permiso')->insertGetId([
                        'id_modulo' => $moduleId, 'nombre' => ucfirst($action), 'clave' => $permissionKey, 'estado' => true,
                    ], 'id_permiso');
                    $catalog[$key][$action] = ['id_modulo' => $moduleId, 'id_permiso' => $permissionId];
                }
            }

            foreach ($roles as $role) {
                $assignments = [];
                foreach (self::ROLES[$role->nombre] ?: array_fill_keys(array_keys($catalog), '*') as $module => $actions) {
                    $permissions = $actions === '*' ? $catalog[$module] : array_intersect_key($catalog[$module], array_flip($actions));
                    array_push($assignments, ...array_values($permissions));
                }
                $permissionIds = array_column($assignments, 'id_permiso');
                $moduleIds = array_unique(array_column($assignments, 'id_modulo'));
                foreach ($moduleIds as $moduleId) {
                    DB::table('acceso.rol_modulo')->insertOrIgnore(['id_rol' => $role->id_rol, 'id_modulo' => $moduleId]);
                }
                foreach ($assignments as $assignment) {
                    DB::table('acceso.rol_modulo_permiso')->insertOrIgnore(['id_rol' => $role->id_rol, ...$assignment]);
                }
                if ($role->nombre !== 'Administrador') {
                    DB::table('acceso.rol_modulo_permiso')->where('id_rol', $role->id_rol)->whereNotIn('id_permiso', $permissionIds)->delete();
                    DB::table('acceso.rol_modulo')->where('id_rol', $role->id_rol)->whereNotIn('id_modulo', $moduleIds)->delete();
                }
            }
        });

        $this->info('Módulos y permisos sincronizados.');

        return self::SUCCESS;
    }
}
