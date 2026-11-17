<?php

namespace App\Console\Commands;

use App\Services\ActivityRequests\DevelopmentDatabase;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

#[Signature('activity-requests:check-development')]
#[Description('Verifica el entorno PostgreSQL aislado sin ejecutar migraciones ni escrituras')]
class PrepareActivityRequestDevelopment extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(DevelopmentDatabase $database): int
    {
        try {
            $database->assertConnected();
            foreach (['acceso.usuario', 'acceso.rol', 'acceso.usuario_rol', 'acceso.modulo', 'acceso.permiso', 'acceso.rol_modulo', 'acceso.rol_modulo_permiso', 'gestion.area', 'gestion.persona', 'gestion.persona_trabajador', 'certificado.tipo_actividad', 'certificado.solicitud_actividad', 'certificado.solicitud_decision', 'certificado.actividad'] as $table) {
                if (DB::selectOne('SELECT to_regclass(?) AS name', [$table])->name === null) {
                    $this->error('Falta la tabla '.$table.'. No se ejecutarán migraciones.');

                    return self::FAILURE;
                }
            }
        } catch (Throwable) {
            $this->error('Entorno de pruebas no disponible. Revise configuración, credenciales y aislamiento. No se realizaron escrituras.');

            return self::FAILURE;
        }
        $this->info('PostgreSQL de pruebas verificado. No se ejecutaron migraciones ni escrituras.');

        return self::SUCCESS;
    }
}
