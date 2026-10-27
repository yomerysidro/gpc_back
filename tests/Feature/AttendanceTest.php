<?php

namespace Tests\Feature;

use App\Http\Data\Attendance\AttendanceData;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AttendanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');
        DB::statement("ATTACH DATABASE ':memory:' AS certificado");

        /*
         * Esquema de prueba basado en el diseño proporcionado.
         * No sustituye la comprobación del esquema de producción.
         */
        Schema::create('certificado.participacion', function (Blueprint $table) {
            $table->increments('id_participacion');
            $table->integer('id_persona');
            $table->integer('id_actividad');
        });
        Schema::create('certificado.actividad_sesion', function (Blueprint $table) {
            $table->increments('id_actividad_sesion');
            $table->integer('id_actividad');
        });
        Schema::create('certificado.asistencia', function (Blueprint $table) {
            $table->increments('id_asistencia');
            $table->unsignedInteger('id_participacion');
            $table->unsignedInteger('id_actividad_sesion');
            $table->time('hora_entrada')->nullable();
            $table->time('hora_salida')->nullable();
            $table->string('estado', 30);
            $table->foreign('id_participacion')->references('id_participacion')->on('participacion');
            $table->foreign('id_actividad_sesion')->references('id_actividad_sesion')->on('actividad_sesion');
        });
    }

    public function test_creates_attendance_and_returns_201_without_timestamps(): void
    {
        $data = $this->attendanceData();

        $this->postJson('/api/attendance/create', $data)
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.ID_PERSONA', 20)
            ->assertJsonPath('data.ID_ACTIVIDAD_PARTICIPACION', 10)
            ->assertJsonPath('data.ID_ACTIVIDAD_SESION_ACTIVIDAD', 10);

        $this->assertDatabaseHas('certificado.asistencia', $data);
    }

    public function test_allows_null_hours_and_a_business_defined_status(): void
    {
        $data = $this->attendanceData();
        unset($data['HORA_ENTRADA'], $data['HORA_SALIDA']);
        $data['ESTADO'] = 'Estado definido por el cliente';

        $this->postJson('/api/attendance/create', $data)
            ->assertCreated()
            ->assertJsonPath('data.HORA_ENTRADA', null)
            ->assertJsonPath('data.HORA_SALIDA', null);

        $this->assertDatabaseHas('certificado.asistencia', $data + ['HORA_ENTRADA' => null, 'HORA_SALIDA' => null]);
    }

    public function test_lists_attendances_in_descending_order_with_related_data(): void
    {
        $data = $this->attendanceData();
        DB::table('certificado.asistencia')->insert($data + ['ID_ASISTENCIA' => 1]);
        DB::table('certificado.asistencia')->insert($data + ['ID_ASISTENCIA' => 2]);

        $this->getJson('/api/attendance/list')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.ID_ASISTENCIA', 2)
            ->assertJsonPath('data.1.ID_ASISTENCIA', 1)
            ->assertJsonPath('data.0.ID_PERSONA', 20);
    }

    public function test_returns_attendance_detail_with_related_data(): void
    {
        DB::table('certificado.asistencia')->insert($this->attendanceData() + ['ID_ASISTENCIA' => 1]);

        $this->getJson('/api/attendance/1')
            ->assertOk()
            ->assertJsonPath('data.ID_ASISTENCIA', 1)
            ->assertJsonPath('data.ID_ACTIVIDAD_SESION_ACTIVIDAD', 10);
    }

    public function test_partially_updates_without_changing_primary_key_or_other_fields(): void
    {
        $data = $this->attendanceData();
        DB::table('certificado.asistencia')->insert($data + ['ID_ASISTENCIA' => 1]);

        $this->putJson('/api/attendance/update/1', ['HORA_SALIDA' => '18:00', 'ID_ASISTENCIA' => 99])
            ->assertOk()
            ->assertJsonPath('data.ID_ASISTENCIA', 1)
            ->assertJsonPath('data.HORA_SALIDA', '18:00')
            ->assertJsonPath('data.HORA_ENTRADA', '08:00');

        $this->assertDatabaseHas('certificado.asistencia', array_replace($data, ['ID_ASISTENCIA' => 1, 'HORA_SALIDA' => '18:00']));
        $this->assertDatabaseMissing('certificado.asistencia', ['ID_ASISTENCIA' => 99]);
    }

    public function test_clears_nullable_hour_and_accepts_an_empty_partial_update(): void
    {
        DB::table('certificado.asistencia')->insert($this->attendanceData() + ['ID_ASISTENCIA' => 1]);

        $this->putJson('/api/attendance/update/1', ['HORA_ENTRADA' => null])
            ->assertOk()
            ->assertJsonPath('data.HORA_ENTRADA', null);
        $this->putJson('/api/attendance/update/1', [])
            ->assertOk()
            ->assertJsonPath('data.ID_ASISTENCIA', 1);

        $this->assertDatabaseHas('certificado.asistencia', ['ID_ASISTENCIA' => 1, 'HORA_ENTRADA' => null]);
    }

    public function test_updates_both_references_together_and_accepts_equal_hours(): void
    {
        DB::table('certificado.asistencia')->insert($this->attendanceData() + ['ID_ASISTENCIA' => 1]);
        DB::table('certificado.participacion')->insert(['ID_PARTICIPACION' => 2, 'ID_PERSONA' => 21, 'ID_ACTIVIDAD' => 11]);
        DB::table('certificado.actividad_sesion')->insert(['ID_ACTIVIDAD_SESION' => 2, 'ID_ACTIVIDAD' => 11]);
        $update = [
            'ID_PARTICIPACION' => 2,
            'ID_ACTIVIDAD_SESION' => 2,
            'HORA_ENTRADA' => '09:00',
            'HORA_SALIDA' => '09:00',
            'ESTADO' => 'Actualizado',
        ];

        $this->putJson('/api/attendance/update/1', $update)
            ->assertOk()
            ->assertJsonPath('data.ID_PERSONA', 21)
            ->assertJsonPath('data.ID_ACTIVIDAD_PARTICIPACION', 11);

        $this->assertDatabaseHas('certificado.asistencia', $update + ['ID_ASISTENCIA' => 1]);
    }

    public function test_deletes_attendance_physically(): void
    {
        DB::table('certificado.asistencia')->insert($this->attendanceData() + ['ID_ASISTENCIA' => 1]);

        $this->deleteJson('/api/attendance/delete/1')
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'message' => 'Asistencia eliminada correctamente.',
                'data' => null,
            ]);

        $this->assertDatabaseMissing('certificado.asistencia', ['ID_ASISTENCIA' => 1]);
    }

    #[DataProvider('missingRecordRequests')]
    public function test_returns_404_for_a_missing_attendance(string $method): void
    {
        $this->json($method, '/api/attendance/'.match ($method) {
            'PUT' => 'update/',
            'DELETE' => 'delete/',
            default => '',
        }.'999')
            ->assertNotFound()
            ->assertJsonPath('message', 'Asistencia no encontrada.');
    }

    public static function missingRecordRequests(): array
    {
        return [['GET'], ['PUT'], ['DELETE']];
    }

    public function test_returns_422_for_missing_required_fields(): void
    {
        $this->postJson('/api/attendance/create', [])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Invalid validation data.')
            ->assertJsonStructure(['data' => ['ID_PARTICIPACION', 'ID_ACTIVIDAD_SESION', 'ESTADO']]);

        $this->assertDatabaseCount('certificado.asistencia', 0);
    }

    #[DataProvider('invalidFields')]
    public function test_returns_422_for_invalid_fields_on_create_and_update(string $field, mixed $value): void
    {
        $data = $this->attendanceData();
        DB::table('certificado.asistencia')->insert($data + ['ID_ASISTENCIA' => 1]);

        $this->postJson('/api/attendance/create', array_replace($data, [$field => $value]))
            ->assertUnprocessable()
            ->assertJsonStructure(['data' => [$field]]);
        $this->putJson('/api/attendance/update/1', [$field => $value])
            ->assertUnprocessable()
            ->assertJsonStructure(['data' => [$field]]);

        $this->assertDatabaseCount('certificado.asistencia', 1);
        $this->assertDatabaseHas('certificado.asistencia', $data + ['ID_ASISTENCIA' => 1]);
    }

    public static function invalidFields(): array
    {
        return [
            'missing participation' => ['ID_PARTICIPACION', 999],
            'missing session' => ['ID_ACTIVIDAD_SESION', 999],
            'noninteger participation' => ['ID_PARTICIPACION', 'invalid'],
            'noninteger session' => ['ID_ACTIVIDAD_SESION', 'invalid'],
            'null participation' => ['ID_PARTICIPACION', null],
            'null session' => ['ID_ACTIVIDAD_SESION', null],
            'invalid entry hour' => ['HORA_ENTRADA', '25:00'],
            'seconds in input' => ['HORA_ENTRADA', '08:00:00'],
            'invalid exit hour' => ['HORA_SALIDA', 'bad'],
            'null status' => ['ESTADO', null],
            'nonstring status' => ['ESTADO', 123],
            'long status' => ['ESTADO', str_repeat('x', 31)],
        ];
    }

    public function test_returns_422_for_different_activities_on_create_and_partial_update(): void
    {
        $data = $this->attendanceData();
        DB::table('certificado.actividad_sesion')->insert(['ID_ACTIVIDAD_SESION' => 2, 'ID_ACTIVIDAD' => 11]);
        DB::table('certificado.asistencia')->insert($data + ['ID_ASISTENCIA' => 1]);

        $this->postJson('/api/attendance/create', array_replace($data, ['ID_ACTIVIDAD_SESION' => 2]))
            ->assertUnprocessable()
            ->assertJsonPath('data.ID_ACTIVIDAD_SESION.0', 'La participación y la sesión deben pertenecer a la misma actividad.');
        $this->putJson('/api/attendance/update/1', ['ID_ACTIVIDAD_SESION' => 2])
            ->assertUnprocessable();

        $this->assertDatabaseCount('certificado.asistencia', 1);
        $this->assertDatabaseHas('certificado.asistencia', $data + ['ID_ASISTENCIA' => 1]);
    }

    public function test_returns_422_for_exit_before_entry_including_stored_seconds(): void
    {
        $data = $this->attendanceData();

        $this->postJson('/api/attendance/create', array_replace($data, ['HORA_SALIDA' => '07:00']))
            ->assertUnprocessable()
            ->assertJsonPath('data.HORA_SALIDA.0', 'La hora de salida no puede ser anterior a la hora de entrada.');

        DB::table('certificado.asistencia')->insert(array_replace($data, ['ID_ASISTENCIA' => 1, 'HORA_ENTRADA' => '08:00:30']));
        $this->putJson('/api/attendance/update/1', ['HORA_SALIDA' => '08:00'])
            ->assertUnprocessable();

        $this->assertDatabaseHas('certificado.asistencia', ['ID_ASISTENCIA' => 1, 'HORA_SALIDA' => '17:00']);
    }

    public function test_rechecks_relations_in_data_and_rolls_back(): void
    {
        $data = $this->attendanceData();
        $data['ID_PARTICIPACION'] = 999;
        $data['ID_ACTIVIDAD_SESION'] = 999;

        $response = AttendanceData::createAttendance($data);

        $this->assertSame(422, $response['status']);
        $this->assertSame(['La participación seleccionada no existe.'], $response['errors']['ID_PARTICIPACION']);
        $this->assertSame(['La sesión seleccionada no existe.'], $response['errors']['ID_ACTIVIDAD_SESION']);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertDatabaseCount('certificado.asistencia', 0);
    }

    public function test_returns_500_without_database_details_and_rolls_back_failed_insert(): void
    {
        $data = $this->attendanceData();
        Schema::table('certificado.asistencia', function (Blueprint $table) {
            $table->string('REQUIRED_TEST_COLUMN');
        });

        $this->postJson('/api/attendance/create', $data)
            ->assertInternalServerError()
            ->assertExactJson([
                'success' => false,
                'message' => 'Error al registrar la asistencia.',
                'data' => [],
            ]);

        $this->assertSame(0, DB::transactionLevel());
        $this->assertDatabaseCount('certificado.asistencia', 0);
    }

    public function test_returns_500_instead_of_404_on_database_failure(): void
    {
        Schema::drop('certificado.asistencia');

        $this->getJson('/api/attendance/list')->assertInternalServerError();
        $this->getJson('/api/attendance/1')->assertInternalServerError();
        $this->putJson('/api/attendance/update/1', [])->assertInternalServerError();
        $this->deleteJson('/api/attendance/delete/1')->assertInternalServerError();

        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_returns_500_with_api_response_when_validation_database_query_fails(): void
    {
        $data = $this->attendanceData();
        Schema::rename('certificado.participacion', 'participacion_unavailable');

        $this->postJson('/api/attendance/create', $data)
            ->assertInternalServerError()
            ->assertExactJson(['success' => false, 'message' => 'Error al registrar la asistencia.', 'data' => []]);
        $this->putJson('/api/attendance/update/1', ['ID_PARTICIPACION' => 1])
            ->assertInternalServerError()
            ->assertExactJson(['success' => false, 'message' => 'Error al actualizar la asistencia.', 'data' => []]);

        $this->assertDatabaseCount('certificado.asistencia', 0);
    }

    /**
     * Crear únicamente las referencias necesarias para cada prueba.
     */
    private function attendanceData(): array
    {
        DB::table('certificado.participacion')->insert([
            'ID_PARTICIPACION' => 1,
            'ID_PERSONA' => 20,
            'ID_ACTIVIDAD' => 10,
        ]);
        DB::table('certificado.actividad_sesion')->insert([
            'ID_ACTIVIDAD_SESION' => 1,
            'ID_ACTIVIDAD' => 10,
        ]);

        return [
            'ID_PARTICIPACION' => 1,
            'ID_ACTIVIDAD_SESION' => 1,
            'HORA_ENTRADA' => '08:00',
            'HORA_SALIDA' => '17:00',
            'ESTADO' => 'Presente',
        ];
    }
}
