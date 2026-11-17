<?php

namespace Database\Seeders;

use App\Services\ActivityRequests\DevelopmentDatabase;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class ActivityRequestDevelopmentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        app(DevelopmentDatabase::class)->assertConnected();
        $password = config('activity_requests_development.seed_password');
        if (! is_string($password) || mb_strlen($password) < 16) {
            throw new RuntimeException('Configure una contraseña exclusiva de pruebas de al menos 16 caracteres.');
        }
        DB::transaction(function () use ($password): void {
            $areaIds = [];
            foreach (['A', 'B'] as $label) {
                $areaIds[$label] = $this->record('gestion.area', 'id_area', ['nombre' => 'PRUEBAS - Área '.$label], []);
            }
            $this->record('certificado.tipo_actividad', 'id_tipo_actividad', ['nombre' => 'PRUEBAS - Capacitación'], ['estado' => true]);
            $moduleId = $this->record('acceso.modulo', 'id_modulo', ['url' => '/solicitudes'], ['nombre' => 'PRUEBAS - Solicitudes', 'estado' => true]);
            $profiles = [
                ['administrador', 'Administrador', 'A', ['consultar', 'historial']],
                ['solicitante-a', 'Solicitante de área', 'A', ['crear', 'editar', 'enviar', 'propias']],
                ['solicitante-b', 'Solicitante de área', 'B', ['crear', 'editar', 'enviar', 'propias']],
                ['rrhh', 'Recursos Humanos', 'A', ['consultar', 'revisar', 'observar', 'derivar', 'historial']],
                ['gerencia', 'Gerencia', 'A', ['consultar', 'aprobar', 'rechazar', 'observar', 'historial']],
            ];
            foreach ($profiles as [$alias, $roleName, $area, $operations]) {
                $email = $alias.'@solicitudes.test';
                $personId = $this->record('gestion.persona', 'id_persona', ['correo' => $email], ['nombres' => 'PRUEBAS '.$alias, 'apellido_paterno' => 'Desarrollo', 'fecha_registro' => today()]);
                $userId = $this->record('acceso.usuario', 'id_usuario', ['correo' => $email], ['id_persona' => $personId, 'password_hash' => Hash::make($password), 'estado' => true]);
                $roleId = $this->record('acceso.rol', 'id_rol', ['nombre' => $roleName], ['estado' => true]);
                DB::table('acceso.usuario_rol')->updateOrInsert(['id_usuario' => $userId, 'id_rol' => $roleId], []);
                $this->record('gestion.persona_trabajador', 'id_persona_trabajador', ['id_persona' => $personId, 'id_area' => $areaIds[$area]], ['cargo' => 'PRUEBAS '.$roleName, 'fecha_ingreso' => today(), 'estado' => true]);
                DB::table('acceso.rol_modulo')->updateOrInsert(['id_rol' => $roleId, 'id_modulo' => $moduleId], []);
                foreach ($operations as $operation) {
                    $permissionId = $this->record('acceso.permiso', 'id_permiso', ['id_modulo' => $moduleId, 'clave' => 'solicitudes.'.$operation], ['nombre' => $operation, 'estado' => true]);
                    DB::table('acceso.rol_modulo_permiso')->updateOrInsert(['id_rol' => $roleId, 'id_modulo' => $moduleId, 'id_permiso' => $permissionId], []);
                }
            }
        });
    }

    /** @param array<string, mixed> $identity
     * @param  array<string, mixed>  $values
     */
    private function record(string $table, string $primaryKey, array $identity, array $values): int
    {
        $id = DB::table($table)->where($identity)->value($primaryKey);
        if ($id !== null) {
            if ($values !== []) {
                DB::table($table)->where($primaryKey, $id)->update($values);
            }

            return (int) $id;
        }

        return (int) DB::table($table)->insertGetId([...$identity, ...$values], $primaryKey);
    }
}
