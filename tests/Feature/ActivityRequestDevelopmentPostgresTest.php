<?php

namespace Tests\Feature;

use App\Services\ActivityRequests\DevelopmentDatabase;
use Database\Seeders\ActivityRequestDevelopmentSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\ExercisesActivityRequestDevelopment;
use Tests\TestCase;

class ActivityRequestDevelopmentPostgresTest extends TestCase
{
    use ExercisesActivityRequestDevelopment;

    protected function setUp(): void
    {
        parent::setUp();
        if (! config('activity_requests_development.exclusive')) {
            $this->markTestSkipped('Requiere PostgreSQL exclusivo ya preparado. No ejecuta migraciones.');
        }
        config(['database.default' => 'activity_requests_test', 'activity_requests_development.enabled' => true,
            'activity_requests_development.seed_password' => 'only-for-automated-tests-password']);
        app(DevelopmentDatabase::class)->assertConnected();
        $connection = DB::connection();
        $connection->beginTransaction();
        $this->beforeApplicationDestroyed(function () use ($connection): void {
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        });
        $this->seed(ActivityRequestDevelopmentSeeder::class);
    }

    public function test_postgresql_rejects_invalid_request_state(): void
    {
        $id = $this->submittedRequest();
        try {
            DB::transaction(fn () => DB::table('certificado.solicitud_actividad')->where('id_solicitud_actividad', $id)->update(['estado' => 'Estado inválido']));
            self::fail('PostgreSQL debe rechazar un estado fuera del CHECK.');
        } catch (QueryException $exception) {
            self::assertSame('23514', $exception->errorInfo[0]);
        }
    }

    public function test_concurrent_request_lock_cannot_pass_an_exclusive_lock(): void
    {
        config(['database.connections.activity_requests_contender' => config('database.connections.activity_requests_test')]);
        $contender = DB::connection('activity_requests_contender');
        $this->beforeApplicationDestroyed(fn () => DB::disconnect('activity_requests_contender'));
        $contender->statement("SET lock_timeout = '100ms'");
        DB::statement('LOCK TABLE certificado.solicitud_actividad IN EXCLUSIVE MODE');

        try {
            $contender->select('SELECT id_solicitud_actividad FROM certificado.solicitud_actividad WHERE id_solicitud_actividad = -1 FOR UPDATE');
            self::fail('La conexión competidora no debe superar el bloqueo.');
        } catch (QueryException $exception) {
            self::assertSame('55P03', $exception->errorInfo[0]);
        }
    }
}
