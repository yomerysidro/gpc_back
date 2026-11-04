<?php

namespace Tests\Feature;

use App\Models\ActivityRequest;
use App\Models\Role\Role;
use App\Models\User\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Fixtures\RegistersActivityRequestReadRoutes;
use Tests\TestCase;

#[Group('postgresql-readonly')]
class ActivityRequestPostgresReadOnlyTest extends TestCase
{
    use RegistersActivityRequestReadRoutes;

    protected function setUp(): void
    {
        parent::setUp();
        $encoded = getenv('ACTIVITY_REQUESTS_PG_READONLY_CONFIG');
        if (! $encoded) {
            $this->markTestSkipped('Requiere conexión PostgreSQL explícita de solo lectura; no hereda la base institucional por defecto.');
        }
        $configuration = json_decode(base64_decode($encoded, true), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('pgsql', $configuration['driver'] ?? null);
        config(['database.default' => 'activity_requests_readonly', 'database.connections.activity_requests_readonly' => $configuration]);
        $connection = DB::connection('activity_requests_readonly');
        $connection->beginTransaction();
        $this->beforeApplicationDestroyed(function () use ($connection): void {
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            DB::disconnect('activity_requests_readonly');
        });
        $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
        self::assertSame('on', $connection->selectOne('SHOW transaction_read_only')->transaction_read_only);
        $this->registerActivityRequestReadRoutes();
    }

    public function test_catalog_http_contracts_match_real_postgresql_rows(): void
    {
        $areas = DB::table('gestion.area')->orderByDesc('id_area')->get()->map(fn (object $row): array => (array) $row)->all();
        $types = DB::table('certificado.tipo_actividad')->select('id_tipo_actividad', 'nombre', 'descripcion', 'estado')
            ->orderByDesc('id_tipo_actividad')->get()->map(fn (object $row): array => (array) $row)->all();

        $this->getJson('/api/areas/list')->assertOk()->assertJsonPath('success', true)->assertJsonPath('data', $areas);
        $this->getJson('/api/ativicty-type')->assertOk()->assertJsonPath('data', $types);
        $active = array_values(array_filter($types, fn (array $type): bool => $type['estado'] === true));
        $this->getJson('/api/ativicty-type?estado=1')->assertOk()->assertJsonPath('data', $active);
    }

    #[TestWith(['Enviada'])]
    #[TestWith(['En revisión'])]
    #[TestWith(['Pendiente de aprobación'])]
    public function test_read_queues_match_postgresql_counts_and_page_metadata(string $state): void
    {
        $this->simulateManager();
        $total = DB::table('certificado.solicitud_actividad')->where('estado', $state)->count();

        $response = $this->getJson('/api/activity-requests?'.http_build_query(['estado' => $state, 'per_page' => 2]));

        $response->assertOk()->assertJsonPath('data.total', $total)->assertJsonPath('data.current_page', 1)
            ->assertJsonPath('data.per_page', 2)->assertJsonCount(min(2, $total), 'data.data');
        foreach ($response->json('data.data') as $row) {
            self::assertSame($state, $row['estado']);
            self::assertTrue(DB::table('gestion.area')->where('id_area', $row['id_area'])->exists());
            self::assertTrue(DB::table('certificado.tipo_actividad')->where('id_tipo_actividad', $row['id_tipo_actividad'])->exists());
        }
    }

    public function test_global_decision_history_matches_real_postgresql(): void
    {
        $this->simulateManager();
        $count = DB::table('certificado.solicitud_decision')->count();

        $this->getJson('/api/activity-requests/decisions/history?per_page=2')->assertOk()
            ->assertJsonPath('data.total', $count)->assertJsonPath('data.per_page', 2)
            ->assertJsonCount(min(2, $count), 'data.data');
    }

    public function test_own_requests_and_existing_details_use_real_rows_only(): void
    {
        $row = DB::table('certificado.solicitud_actividad')->orderBy('id_solicitud_actividad')->first();
        $owner = $row ? (int) $row->id_usuario_solicitante : 1;
        $user = new User;
        $user->setAttribute('id_usuario', $owner);
        $this->actingAs($user, 'web');
        $count = DB::table('certificado.solicitud_actividad')->where('id_usuario_solicitante', $owner)->count();

        $this->getJson('/api/activity-requests/mine')->assertOk()->assertJsonPath('data.total', $count);
        if ($row === null) {
            $this->getJson('/api/activity-requests/0')->assertNotFound();

            return;
        }

        $id = (int) $row->id_solicitud_actividad;
        $this->getJson('/api/activity-requests/'.$id)->assertOk()->assertJsonPath('data.id_solicitud_actividad', $id);
        $decisions = DB::table('certificado.solicitud_decision')->where('id_solicitud_actividad', $id)->count();
        $this->getJson('/api/activity-requests/'.$id.'/history')->assertOk()->assertJsonCount($decisions, 'data');
        $model = ActivityRequest::findOrFail($id);
        self::assertNotNull($model->area);
        self::assertNotNull($model->activityType);
    }

    private function simulateManager(): void
    {
        $user = new User;
        $user->setAttribute('id_usuario', 1);
        $role = new Role;
        $role->setRawAttributes(['nombre' => 'Gerencial', 'estado' => true]);
        $user->setRelation('roles', $role->newCollection([$role]));

        $this->actingAs($user, 'web');
    }
}
