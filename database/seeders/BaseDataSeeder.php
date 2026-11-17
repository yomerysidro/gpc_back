<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Datos base: roles, módulos y permisos, áreas del organigrama, catálogos
 * y usuarios de prueba.
 *
 * Idempotente: cada fila se busca por su clave natural antes de insertarse,
 * así que correr db:seed varias veces no duplica nada.
 */
class BaseDataSeeder extends Seeder
{
    /**
     * Unidades del Organigrama oficial (Anexo ROF, Modelo 4) de la
     * Municipalidad Distrital de Morales.
     */
    private const AREAS = [
        'Alcaldía',
        'Gerencia Municipal',
        'Oficina General de Atención al Ciudadano y Gestión Documentaria',
        'Oficina General de Asesoría Jurídica',
        'Oficina General de Administración',
        'Oficina General de Planeamiento y Presupuesto',
        'Gerencia de Administración Tributaria',
        'Gerencia de Desarrollo Territorial y Económico',
        'Gerencia de Servicios Municipales y Gestión Ambiental',
        'Gerencia de Desarrollo Social',
    ];

    /**
     * Módulos del menú de Angular: nombre => [url, ícono de Angular Material].
     */
    private const MODULES = [
        'Gestión de Usuarios' => ['/usuarios', 'people'],
        'Roles y Permisos' => ['/roles', 'admin_panel_settings'],
        'Dashboard' => ['/dashboard', 'dashboard'],
        'Actividades' => ['/actividades', 'event'],
        'Participantes' => ['/participantes', 'group'],
        'Practicantes' => ['/practicantes', 'school'],
        'Reconocimientos' => ['/reconocimientos', 'military_tech'],
        'Ponentes y Firmantes' => ['/ponentes-firmantes', 'campaign'],
        'Emisión de Certificados' => ['/emision', 'workspace_premium'],
        'Reportes' => ['/reportes', 'bar_chart'],
    ];

    /**
     * Cada módulo tiene un permiso por acción, con clave "<slug>.<acción>".
     */
    private const ACTIONS = [
        'ver' => 'Ver',
        'crear' => 'Crear',
        'editar' => 'Editar',
        'eliminar' => 'Eliminar',
    ];

    /**
     * Módulos de cada rol y las acciones que tiene sobre ellos.
     */
    private const ROLE_MODULES = [
        'Administrador' => [
            'modules' => ['Gestión de Usuarios', 'Roles y Permisos', 'Dashboard', 'Actividades', 'Participantes', 'Practicantes', 'Reconocimientos', 'Ponentes y Firmantes', 'Emisión de Certificados', 'Reportes'],
            'actions' => ['ver', 'crear', 'editar', 'eliminar'],
        ],
        'Digitador' => [
            'modules' => ['Actividades', 'Participantes', 'Practicantes', 'Reconocimientos', 'Ponentes y Firmantes', 'Emisión de Certificados'],
            'actions' => ['ver', 'crear', 'editar', 'eliminar'],
        ],
        'Gerencial' => [
            'modules' => ['Dashboard', 'Reportes'],
            'actions' => ['ver'],
        ],
    ];

    private const USERS = [
        [
            'first_names' => 'Antón',
            'last_name' => 'Talían',
            'dni' => '12345678',
            'email' => 'anton.informatica@moralesmuni.pe',
            'role' => 'Administrador',
            'area' => 'Oficina General de Administración',
            'position' => 'Jefe de Informática',
        ],
        [
            'first_names' => 'Maria',
            'last_name' => 'Perez',
            'dni' => '87654321',
            'email' => 'maria.rrhh@moralesmuni.pe',
            'role' => 'Digitador',
            'area' => 'Oficina General de Administración',
            'position' => 'Especialista de RR.HH.',
        ],
        [
            'first_names' => 'Juan',
            'last_name' => 'Alcalde',
            'dni' => '11112222',
            'email' => 'alcalde@moralesmuni.pe',
            'role' => 'Gerencial',
            'area' => 'Alcaldía',
            'position' => 'Alcalde',
        ],
    ];

    public function run(): void
    {
        DB::transaction(function () {
            foreach (['Administrador', 'Digitador', 'Gerencial'] as $role) {
                $this->firstOrInsert('acceso.rol', 'id_rol', ['nombre' => $role], ['estado' => true]);
            }

            $this->createModules();

            foreach (self::AREAS as $area) {
                $this->firstOrInsert('gestion.area', 'id_area', ['nombre' => $area]);
            }

            foreach (['DNI', 'Carnet de Extranjería', 'Pasaporte'] as $type) {
                $this->firstOrInsert('gestion.tipo_documento', 'id_tipo_documento', ['nombre' => $type], ['estado' => true]);
            }

            $this->firstOrInsert('certificado.tipo_actividad', 'id_tipo_actividad', ['nombre' => 'Capacitación'], ['estado' => true]);

            foreach (['Municipal', 'Externo'] as $type) {
                $this->firstOrInsert('certificado.tipo_participacion', 'id_tipo_participacion', ['nombre' => $type], ['estado' => true]);
            }

            foreach (['Capacitación', 'Reconocimiento', 'Prácticas'] as $type) {
                $this->firstOrInsert('certificado.tipo_certificado', 'id_tipo_certificado', ['nombre_tipo' => $type], ['estado' => true]);
            }

            foreach (self::USERS as $user) {
                $this->createUser($user);
            }
        });
    }

    /**
     * modulo → permiso → rol_modulo → rol_modulo_permiso.
     * El módulo se identifica por su nombre y el permiso por su clave.
     * Las asignaciones solo se agregan: no se quita lo que se asignó desde la API.
     */
    private function createModules(): void
    {
        $moduleIds = [];
        $permissionIds = [];

        foreach (self::MODULES as $name => [$url, $icon]) {
            $moduleId = $this->firstOrInsert('acceso.modulo', 'id_modulo', ['nombre' => $name], ['url' => $url, 'imagen' => $icon], ['estado' => true]);
            $moduleIds[$name] = $moduleId;
            $slug = ltrim($url, '/');

            foreach (self::ACTIONS as $action => $label) {
                $permissionIds[$name][$action] = $this->firstOrInsert(
                    'acceso.permiso',
                    'id_permiso',
                    ['clave' => "$slug.$action"],
                    ['id_modulo' => $moduleId, 'nombre' => "$label $name"],
                    ['estado' => true]
                );
            }
        }

        foreach (self::ROLE_MODULES as $role => $assignment) {
            $roleId = DB::table('acceso.rol')->where('nombre', $role)->value('id_rol');

            foreach ($assignment['modules'] as $module) {
                DB::table('acceso.rol_modulo')->insertOrIgnore([
                    'id_rol' => $roleId,
                    'id_modulo' => $moduleIds[$module],
                ]);

                foreach ($assignment['actions'] as $action) {
                    DB::table('acceso.rol_modulo_permiso')->insertOrIgnore([
                        'id_rol' => $roleId,
                        'id_modulo' => $moduleIds[$module],
                        'id_permiso' => $permissionIds[$module][$action],
                    ]);
                }
            }
        }
    }

    /**
     * persona → persona_documento (DNI) → persona_trabajador → usuario → usuario_rol.
     * La persona se identifica por su DNI y el usuario por su correo.
     */
    private function createUser(array $data): void
    {
        $today = now()->toDateString();
        $dniTypeId = DB::table('gestion.tipo_documento')->where('nombre', 'DNI')->value('id_tipo_documento');
        $areaId = DB::table('gestion.area')->where('nombre', $data['area'])->value('id_area');
        $roleId = DB::table('acceso.rol')->where('nombre', $data['role'])->value('id_rol');

        $person = [
            'nombres' => $data['first_names'],
            'apellido_paterno' => $data['last_name'],
        ];

        $personId = DB::table('gestion.persona_documento')
            ->where('id_tipo_documento', $dniTypeId)
            ->where('numero_documento', $data['dni'])
            ->value('id_persona');

        if ($personId) {
            DB::table('gestion.persona')->where('id_persona', $personId)->update($person);
        } else {
            $personId = DB::table('gestion.persona')->insertGetId(
                $person + ['fecha_registro' => $today],
                'id_persona'
            );

            DB::table('gestion.persona_documento')->insert([
                'id_persona' => $personId,
                'id_tipo_documento' => $dniTypeId,
                'numero_documento' => $data['dni'],
                'estado' => 'Activo',
            ]);
        }

        $this->firstOrInsert(
            'gestion.persona_trabajador',
            'id_persona_trabajador',
            ['id_persona' => $personId],
            ['id_area' => $areaId, 'cargo' => $data['position'], 'estado' => true],
            ['fecha_ingreso' => $today]
        );

        // La contraseña solo se fija al crear el usuario, para no pisarla en cada seed.
        $userId = $this->firstOrInsert(
            'acceso.usuario',
            'id_usuario',
            ['correo' => $data['email']],
            ['id_persona' => $personId],
            ['password_hash' => Hash::make('Test1234')]
        );

        $this->firstOrInsert('acceso.usuario_rol', 'id_usuario_rol', ['id_usuario' => $userId], ['id_rol' => $roleId]);
    }

    /**
     * Busca la fila por $keys: si existe actualiza $values, si no la inserta
     * junto con $insertOnly. Devuelve el id de la fila.
     */
    private function firstOrInsert(string $table, string $primaryKey, array $keys, array $values = [], array $insertOnly = []): int
    {
        $id = DB::table($table)->where($keys)->value($primaryKey);

        if ($id) {
            if ($values) {
                DB::table($table)->where($primaryKey, $id)->update($values);
            }

            return $id;
        }

        return DB::table($table)->insertGetId($keys + $values + $insertOnly, $primaryKey);
    }
}
