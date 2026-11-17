<?php

namespace Tests\Feature;

use App\Http\Data\PersonDocument\PersonaDocumentoData;
use App\Http\Data\Practice\PracticeData;
use App\Models\Activity\Activity;
use App\Models\ActivitySpeaker\ActivitySpeaker;
use App\Models\Certificate\Certificate;
use App\Models\Participation\Participation;
use App\Models\Recognition\Recognition;
use App\Models\Signature\Signature;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\ModulePolicy;
use Tests\Fixtures\ModuleUser as User;
use Tests\Fixtures\ReadOnlyModulePolicy;
use Tests\TestCase;

class GestionCertificationProtectionTest extends TestCase
{
    private const MODELS = [
        'persons' => 'Person/Persona', 'document-types' => 'DocumentType/TipoDocumento',
        'person-documents' => 'PersonDocument/PersonaDocumento', 'areas' => 'Area/Area',
        'institutions' => 'Institution/Institution', 'person-workers' => 'Person_Worker/Person_Worker',
        'practicing-individuals' => 'Practicing_Individual/Practicing_Individual', 'practices' => 'Practice/Practice',
        'supervisory-internships' => 'Supervisory_internship/Supervisory_internship', 'person-speakers' => 'PersonSpeaker/PersonaPonente',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'database.connections.sqlite.url' => null]);
        DB::purge('sqlite');
        DB::statement("ATTACH DATABASE ':memory:' AS gestion");
        DB::statement("ATTACH DATABASE ':memory:' AS certificado");
        $tables = [
            'gestion.persona' => ['id_persona', 'nombres apellido_paterno apellido_materno telefono direccion correo fecha_registro'],
            'gestion.area' => ['id_area', 'nombre'],
            'gestion.institucion' => ['id_institucion', 'nombre_institucion pais tipo'],
            'gestion.tipo_documento' => ['id_tipo_documento', 'nombre estado'],
            'gestion.persona_documento' => ['id_persona_documento', 'id_persona id_tipo_documento numero_documento estado'],
            'gestion.persona_trabajador' => ['id_persona_trabajador', 'id_persona id_area cargo fecha_ingreso fecha_fin estado'],
            'gestion.persona_practicante' => ['id_persona_practicante', 'id_persona codigo estado'],
            'gestion.practica' => ['id_practica', 'id_persona_practicante id_institucion id_area carrera fecha_inicio fecha_fin estado'],
            'gestion.practica_supervisor' => ['id_practica_supervisor', 'id_practica id_persona_trabajador tipo_supervision fecha_inicio fecha_fin estado'],
            'gestion.persona_ponente' => ['id_persona_ponente', 'id_persona id_institucion cargo especialidad'],
            'certificado.certificado' => ['id_certificado', 'id_persona id_practica id_reconocimiento id_participacion'],
            'certificado.reconocimiento' => ['id_reconocimiento', 'id_persona_trabajador'],
            'certificado.firma' => ['id_firma', 'id_persona_trabajador'],
            'certificado.actividad' => ['id_actividad', 'id_area'],
            'certificado.participacion' => ['id_participacion', 'id_persona'],
            'certificado.actividad_ponente' => ['id_actividad_ponente', 'id_persona_ponente'],
        ];
        foreach ($tables as $name => [$key, $fields]) {
            Schema::create($name, function (Blueprint $table) use ($key, $fields) {
                $table->increments($key);
                foreach (explode(' ', $fields) as $field) {
                    if (str_starts_with($field, 'id_')) {
                        $table->integer($field)->nullable();
                    } else {
                        $table->string($field)->nullable();
                    }
                }
                if ($key === 'id_persona_documento') {
                    $table->unique('numero_documento');
                    $table->foreign('id_persona')->references('id_persona')->on('persona');
                }
            });
        }
    }

    private function authorize(string $policy = ModulePolicy::class): void
    {
        $this->actingAs(new User(['name' => 'Authorized fixture']));
        foreach (self::MODELS as $model) {
            Gate::policy('App\\Models\\'.str_replace('/', '\\', $model), $policy);
        }
    }

    private function seedOrigins(): void
    {
        DB::table('gestion.persona')->insert([['id_persona' => 1], ['id_persona' => 2]]);
        DB::table('gestion.area')->insert(['id_area' => 1]);
        DB::table('gestion.institucion')->insert(['id_institucion' => 1]);
        DB::table('gestion.persona_practicante')->insert([
            ['id_persona_practicante' => 1, 'id_persona' => 1],
            ['id_persona_practicante' => 2, 'id_persona' => 2],
            ['id_persona_practicante' => 3, 'id_persona' => 1],
        ]);
        DB::table('gestion.practica')->insert(['id_practica' => 1, 'id_persona_practicante' => 1, 'id_area' => 1, 'id_institucion' => 1]);
        DB::table('gestion.persona_trabajador')->insert(['id_persona_trabajador' => 1, 'id_persona' => 1, 'id_area' => 1]);
        DB::table('certificado.reconocimiento')->insert(['id_reconocimiento' => 1, 'id_persona_trabajador' => 1]);
    }

    private function practicePayload(int $practitioner): array
    {
        return ['id_persona_practicante' => $practitioner, 'id_area' => 1, 'id_institucion' => 1, 'carrera' => 'Sistemas', 'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-01-31', 'estado' => 'FINALIZADO'];
    }

    public function test_all_gestion_routes_require_authentication_and_policies(): void
    {
        $routes = array_values(array_filter(app('router')->getRoutes()->getRoutes(), fn ($route) => in_array(explode('/', $route->uri())[1] ?? '', array_keys(self::MODELS), true)));
        $this->assertCount(53, $routes);
        foreach ($routes as $route) {
            $this->json($route->methods()[0], '/'.preg_replace('/\{[^}]+\}/', '1', $route->uri()))->assertUnauthorized();
        }
        $this->actingAs(new User(['name' => 'Unprivileged fixture']));
        foreach ($routes as $route) {
            $this->json($route->methods()[0], '/'.preg_replace('/\{[^}]+\}/', '1', $route->uri()))->assertForbidden();
        }
    }

    public function test_read_permission_does_not_grant_gestion_write_permission(): void
    {
        $this->authorize(ReadOnlyModulePolicy::class);
        DB::table('gestion.area')->insert(['id_area' => 1, 'nombre' => 'Area']);
        $this->getJson('/api/areas/list')->assertOk();
        $this->getJson('/api/areas/detail/1')->assertOk();
        $this->postJson('/api/areas/create', ['nombre' => 'Otra'])->assertForbidden();
        $this->putJson('/api/areas/update/1', ['nombre' => 'Otra'])->assertForbidden();
        $this->deleteJson('/api/areas/delete/1')->assertForbidden();
        $this->assertDatabaseHas('gestion.area', ['id_area' => 1, 'nombre' => 'Area']);
    }

    public function test_authorized_gestion_crud_and_missing_record_contracts(): void
    {
        $this->authorize();
        $id = $this->postJson('/api/areas/create', ['nombre' => 'Inicial'])->assertCreated()->json('data.id_area');
        $this->putJson('/api/areas/update/'.$id, ['nombre' => 'Actualizada'])->assertOk();
        $this->getJson('/api/areas/detail/'.$id)->assertOk()->assertJsonPath('data.nombre', 'Actualizada');
        $this->deleteJson('/api/areas/delete/'.$id)->assertOk();
        $this->getJson('/api/areas/detail/'.$id)->assertNotFound();
        foreach (array_keys(self::MODELS) as $prefix) {
            $this->deleteJson('/api/'.$prefix.'/delete/999')->assertNotFound();
        }
    }

    public function test_practice_cannot_change_to_a_different_certificate_beneficiary(): void
    {
        $this->authorize();
        $this->seedOrigins();
        DB::table('certificado.certificado')->insert(['id_persona' => 1, 'id_practica' => 1]);
        $this->putJson('/api/practices/update/1', $this->practicePayload(2))->assertConflict();
        $this->assertDatabaseHas('gestion.practica', ['id_practica' => 1, 'id_persona_practicante' => 1]);
        $this->putJson('/api/practices/update/1', $this->practicePayload(3))->assertOk();
        $this->assertDatabaseHas('gestion.practica', ['id_practica' => 1, 'id_persona_practicante' => 3]);
        $this->assertDatabaseHas('certificado.certificado', ['id_persona' => 1, 'id_practica' => 1]);
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_practitioner_cannot_change_a_certified_practice_beneficiary(): void
    {
        $this->authorize();
        $this->seedOrigins();
        DB::table('certificado.certificado')->insert(['id_persona' => 1, 'id_practica' => 1]);
        $this->putJson('/api/practicing-individuals/update/1', ['id_persona' => 2, 'codigo' => 'P1', 'estado' => 'ACTIVO'])->assertConflict();
        $this->assertDatabaseHas('gestion.persona_practicante', ['id_persona_practicante' => 1, 'id_persona' => 1]);
        $this->putJson('/api/practicing-individuals/update/1', ['id_persona' => 1, 'codigo' => 'P2', 'estado' => 'ACTIVO'])->assertOk();
    }

    public function test_worker_cannot_change_a_recognition_certificate_beneficiary(): void
    {
        $this->authorize();
        $this->seedOrigins();
        DB::table('certificado.certificado')->insert(['id_persona' => 1, 'id_reconocimiento' => 1]);
        $payload = ['id_persona' => 2, 'id_area' => 1, 'cargo' => 'Cargo', 'fecha_ingreso' => '2026-01-01', 'estado' => true];
        $this->putJson('/api/person-workers/update/1', $payload)->assertConflict();
        $this->assertDatabaseHas('gestion.persona_trabajador', ['id_persona_trabajador' => 1, 'id_persona' => 1]);
        $this->putJson('/api/person-workers/update/1', array_replace($payload, ['id_persona' => 1]))->assertOk();
    }

    public function test_worker_with_signature_keeps_its_identity(): void
    {
        $this->authorize();
        $this->seedOrigins();
        DB::table('certificado.firma')->insert(['id_persona_trabajador' => 1]);
        $this->putJson('/api/person-workers/update/1', ['id_persona' => 2, 'id_area' => 1, 'cargo' => 'Cargo', 'fecha_ingreso' => '2026-01-01', 'estado' => true])->assertConflict();
    }

    public function test_uncertified_practice_can_change_practitioner_and_missing_fk_is_rejected(): void
    {
        $this->authorize();
        $this->seedOrigins();
        $this->putJson('/api/practices/update/1', $this->practicePayload(2))->assertOk();
        $this->putJson('/api/practices/update/1', $this->practicePayload(999))->assertUnprocessable();
        $this->assertDatabaseHas('gestion.practica', ['id_practica' => 1, 'id_persona_practicante' => 2]);
    }

    public function test_all_gestion_deletions_preserve_dependencies_and_certificate_history(): void
    {
        $this->authorize();
        $this->seedOrigins();
        DB::table('certificado.certificado')->insert([
            ['id_persona' => 1, 'id_practica' => 1, 'id_reconocimiento' => null],
            ['id_persona' => 1, 'id_practica' => null, 'id_reconocimiento' => 1],
        ]);
        DB::table('gestion.tipo_documento')->insert(['id_tipo_documento' => 1]);
        DB::table('gestion.persona_documento')->insert(['id_persona_documento' => 1, 'id_persona' => 1, 'id_tipo_documento' => 1]);
        DB::table('gestion.practica_supervisor')->insert(['id_practica_supervisor' => 1, 'id_practica' => 1, 'id_persona_trabajador' => 1]);
        DB::table('gestion.persona_ponente')->insert(['id_persona_ponente' => 1, 'id_persona' => 1]);
        DB::table('certificado.actividad_ponente')->insert(['id_persona_ponente' => 1]);
        foreach (array_keys(self::MODELS) as $prefix) {
            $this->deleteJson('/api/'.$prefix.'/delete/1')->assertConflict();
        }
        $this->assertDatabaseCount('certificado.certificado', 2);
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_unique_constraint_conflict_returns_409_without_sql_details(): void
    {
        $this->authorize();
        $this->seedOrigins();
        DB::table('gestion.tipo_documento')->insert(['id_tipo_documento' => 1]);
        $payload = ['id_persona' => 1, 'id_tipo_documento' => 1, 'numero_documento' => '12345678', 'estado' => 'ACTIVO'];
        $this->postJson('/api/person-documents/create', $payload)->assertCreated();
        $this->postJson('/api/person-documents/create', $payload)->assertConflict()->assertJsonMissingPath('exception');
        $this->assertDatabaseCount('gestion.persona_documento', 1);
    }

    public function test_data_translates_a_real_foreign_key_conflict_and_rolls_back(): void
    {
        $this->seedOrigins();
        $response = PersonaDocumentoData::create([
            'id_persona' => 999, 'numero_documento' => 'INVALID',
        ]);
        $this->assertFalse($response['success']);
        $this->assertSame(409, $response['status']);
        $this->assertDatabaseCount('gestion.persona_documento', 0);
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_cross_schema_eloquent_relationships_resolve_real_models(): void
    {
        $this->seedOrigins();
        DB::table('certificado.certificado')->insert(['id_certificado' => 1, 'id_persona' => 1, 'id_practica' => 1]);
        DB::table('certificado.firma')->insert(['id_firma' => 1, 'id_persona_trabajador' => 1]);
        DB::table('certificado.actividad')->insert(['id_actividad' => 1, 'id_area' => 1]);
        DB::table('certificado.participacion')->insert(['id_participacion' => 1, 'id_persona' => 1]);
        DB::table('gestion.persona_ponente')->insert(['id_persona_ponente' => 1, 'id_persona' => 1, 'id_institucion' => 1]);
        DB::table('certificado.actividad_ponente')->insert(['id_actividad_ponente' => 1, 'id_persona_ponente' => 1]);
        $certificate = Certificate::with('persona', 'practice.practitioner.persona', 'practice.area', 'practice.institution')->findOrFail(1);
        $this->assertSame(1, $certificate->persona->id_persona);
        $this->assertSame(1, $certificate->practice->practitioner->persona->id_persona);
        $this->assertSame(1, $certificate->practice->area->id_area);
        $this->assertSame(1, $certificate->practice->institution->id_institucion);
        $this->assertSame(1, Recognition::findOrFail(1)->worker->persona->id_persona);
        $this->assertSame(1, Signature::findOrFail(1)->worker->persona->id_persona);
        $this->assertSame(1, Activity::findOrFail(1)->area->id_area);
        $this->assertSame(1, Participation::findOrFail(1)->persona->id_persona);
        $this->assertSame(1, ActivitySpeaker::findOrFail(1)->personaPonente->institution->id_institucion);
        $this->assertCount(1, $certificate->persona->certificates);
        $this->assertCount(1, $certificate->practice->certificates);
    }

    public function test_worker_with_recognition_keeps_its_original_identity_without_a_certificate(): void
    {
        $this->authorize();
        $this->seedOrigins();
        $this->putJson('/api/person-workers/update/1', ['id_persona' => 2, 'id_area' => 1, 'cargo' => 'Cargo', 'fecha_ingreso' => '2026-01-01', 'estado' => true])->assertConflict();
        $this->assertDatabaseHas('gestion.persona_trabajador', ['id_persona_trabajador' => 1, 'id_persona' => 1]);
    }

    public function test_supervision_cannot_be_moved_away_from_a_certified_practice(): void
    {
        $this->authorize();
        $this->seedOrigins();
        DB::table('certificado.certificado')->insert(['id_persona' => 1, 'id_practica' => 1]);
        DB::table('gestion.practica')->insert(['id_practica' => 2, 'id_persona_practicante' => 1]);
        $payload = ['id_practica' => 1, 'id_persona_trabajador' => 1, 'tipo_supervision' => 'ACADEMICA', 'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-01-31', 'estado' => 'ACTIVO'];
        DB::table('gestion.practica_supervisor')->insert(array_merge(['id_practica_supervisor' => 1], $payload));
        $this->putJson('/api/supervisory-internships/update/1', array_replace($payload, ['id_practica' => 2]))->assertConflict();
        $this->putJson('/api/supervisory-internships/update/1', $payload)->assertOk();
        $this->assertDatabaseHas('gestion.practica_supervisor', ['id_practica_supervisor' => 1, 'id_practica' => 1]);
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_document_identity_of_a_certificate_beneficiary_cannot_be_reassigned(): void
    {
        $this->authorize();
        $this->seedOrigins();
        DB::table('certificado.certificado')->insert(['id_persona' => 1, 'id_practica' => 1]);
        DB::table('gestion.tipo_documento')->insert(['id_tipo_documento' => 1]);
        $payload = ['id_persona' => 1, 'id_tipo_documento' => 1, 'numero_documento' => '12345678', 'estado' => 'ACTIVO'];
        DB::table('gestion.persona_documento')->insert(array_merge(['id_persona_documento' => 1], $payload));
        foreach (['id_persona' => 2, 'numero_documento' => '87654321'] as $field => $value) {
            $this->putJson('/api/person-documents/update/1', array_replace($payload, [$field => $value]))->assertConflict();
        }
        $this->putJson('/api/person-documents/update/1', $payload)->assertOk();
        $this->assertDatabaseHas('gestion.persona_documento', $payload);
    }

    public function test_data_update_does_not_write_primary_keys_or_unregistered_columns(): void
    {
        $this->seedOrigins();
        $response = PracticeData::updatePractice(1, ['id_practica' => 999999, 'carrera' => 'Actualizada', 'unexpected_column' => 'ignored']);
        $this->assertTrue($response['success']);
        $this->assertDatabaseHas('gestion.practica', ['id_practica' => 1, 'carrera' => 'Actualizada']);
        $this->assertDatabaseMissing('gestion.practica', ['id_practica' => 999999]);
    }

    public function test_put_preserves_omitted_optional_fields_and_accepts_explicit_null(): void
    {
        $this->authorize();
        $this->seedOrigins();
        $payload = ['id_persona' => 1, 'id_area' => 1, 'cargo' => 'Fixture', 'fecha_ingreso' => '2026-01-01', 'estado' => false];
        $id = $this->postJson('/api/person-workers/create', array_merge($payload, ['fecha_fin' => '2026-01-31']))->assertCreated()->json('data.id_persona_trabajador');
        $this->putJson('/api/person-workers/update/'.$id, $payload)->assertOk();
        $this->assertDatabaseHas('gestion.persona_trabajador', ['id_persona_trabajador' => $id, 'fecha_fin' => '2026-01-31']);
        $this->putJson('/api/person-workers/update/'.$id, array_merge($payload, ['fecha_fin' => null]))->assertOk();
        $this->assertDatabaseHas('gestion.persona_trabajador', ['id_persona_trabajador' => $id, 'fecha_fin' => null]);
    }

    public static function gestionCrudCases(): array
    {
        return [
            'persona' => ['persons', 'persona', ['nombres' => 'Fixture', 'apellido_paterno' => 'Prueba', 'fecha_registro' => '2026-01-01'], 'nombres', 'Actualizado'],
            'tipo_documento' => ['document-types', 'tipo_documento', ['nombre' => 'Fixture', 'estado' => true], 'nombre', 'Actualizado'],
            'persona_documento' => ['person-documents', 'persona_documento', ['id_persona' => 1, 'id_tipo_documento' => 1, 'numero_documento' => '11223344', 'estado' => 'ACTIVO'], 'numero_documento', '44332211'],
            'area' => ['areas', 'area', ['nombre' => 'Fixture'], 'nombre', 'Actualizado'],
            'institucion' => ['institutions', 'institucion', ['nombre_institucion' => 'Fixture', 'pais' => 'Peru', 'tipo' => 'Publica'], 'nombre_institucion', 'Actualizado'],
            'trabajador' => ['person-workers', 'persona_trabajador', ['id_persona' => 1, 'id_area' => 1, 'cargo' => 'Fixture', 'fecha_ingreso' => '2026-01-01', 'estado' => true], 'cargo', 'Actualizado'],
            'practicante' => ['practicing-individuals', 'persona_practicante', ['id_persona' => 1, 'codigo' => 'Fixture', 'estado' => 'ACTIVO'], 'codigo', 'Actualizado'],
            'practica' => ['practices', 'practica', ['id_persona_practicante' => 1, 'id_institucion' => 1, 'id_area' => 1, 'carrera' => 'Fixture', 'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-01-31', 'estado' => 'FINALIZADO'], 'carrera', 'Actualizado'],
            'supervisor' => ['supervisory-internships', 'practica_supervisor', ['id_practica' => 1, 'id_persona_trabajador' => 1, 'tipo_supervision' => 'Fixture', 'fecha_inicio' => '2026-01-01', 'estado' => 'ACTIVO'], 'tipo_supervision', 'Actualizado'],
            'ponente' => ['person-speakers', 'persona_ponente', ['id_persona' => 1, 'id_institucion' => 1, 'cargo' => 'Fixture', 'especialidad' => 'Sistemas'], 'cargo', 'Actualizado'],
        ];
    }

    #[DataProvider('gestionCrudCases')]
    public function test_each_gestion_crud_updates_only_changed_columns(string $prefix, string $table, array $payload, string $field, string $value): void
    {
        $this->authorize();
        $this->seedOrigins();
        DB::table('gestion.tipo_documento')->insert(['id_tipo_documento' => 1]);
        $key = 'id_'.$table;
        $id = $this->postJson('/api/'.$prefix.'/create', $payload)->assertCreated()->json('data.'.$key);
        $this->assertNotNull($id);
        $this->getJson('/api/'.$prefix.'/list')->assertOk();
        $this->getJson('/api/'.$prefix.'/detail/'.$id)->assertOk();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->putJson('/api/'.$prefix.'/update/'.$id, array_replace($payload, [$field => $value], [$key => 999999]))->assertOk();
        $updates = array_values(array_filter(DB::getQueryLog(), fn (array $query): bool => str_starts_with(strtolower($query['query']), 'update ')));
        DB::disableQueryLog();
        $this->assertCount(1, $updates);
        $set = preg_split('/\s+where\s+/i', preg_split('/\s+set\s+/i', $updates[0]['query'])[1])[0];
        $this->assertSame('"'.$field.'" = ?', $set);
        $this->assertDatabaseHas('gestion.'.$table, [$key => $id, $field => $value]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->putJson('/api/'.$prefix.'/update/'.$id, array_replace($payload, [$field => $value]))->assertOk();
        $this->assertCount(0, array_filter(DB::getQueryLog(), fn (array $query): bool => str_starts_with(strtolower($query['query']), 'update ')));
        DB::disableQueryLog();
        $this->deleteJson('/api/'.$prefix.'/delete/'.$id)->assertOk();
        $this->getJson('/api/'.$prefix.'/detail/'.$id)->assertNotFound();
        $this->assertDatabaseMissing('gestion.'.$table, [$key => $id]);
        $this->assertSame(0, DB::transactionLevel());
    }
}
