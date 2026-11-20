<?php

namespace Tests\Feature;

use App\Models\User\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null, 'app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);
        DB::purge('sqlite');
        foreach (['gestion', 'certificado'] as $schema) {
            DB::statement("ATTACH DATABASE ':memory:' AS {$schema}");
        }
        foreach ([
            'gestion.area' => 'id_area INTEGER PRIMARY KEY, nombre TEXT',
            'gestion.persona_trabajador' => 'id_persona_trabajador INTEGER PRIMARY KEY, id_persona INTEGER, id_area INTEGER, estado BOOLEAN, fecha_ingreso TEXT, fecha_fin TEXT',
            'gestion.persona' => 'id_persona INTEGER PRIMARY KEY, nombres TEXT, apellido_paterno TEXT, apellido_materno TEXT',
            'gestion.persona_practicante' => 'id_persona_practicante INTEGER PRIMARY KEY, id_persona INTEGER',
            'gestion.practica' => 'id_practica INTEGER PRIMARY KEY, id_persona_practicante INTEGER, id_area INTEGER',
            'certificado.tipo_actividad' => 'id_tipo_actividad INTEGER PRIMARY KEY, nombre TEXT',
            'certificado.actividad' => 'id_actividad INTEGER PRIMARY KEY, nombre TEXT, id_area INTEGER, id_tipo_actividad INTEGER, fecha_inicio TEXT, fecha_fin TEXT, estado TEXT, sede TEXT',
            'certificado.actividad_sesion' => 'id_actividad_sesion INTEGER PRIMARY KEY, id_actividad INTEGER, nombre TEXT, fecha TEXT, estado TEXT',
            'certificado.participacion' => 'id_participacion INTEGER PRIMARY KEY, id_actividad INTEGER, id_persona INTEGER, estado TEXT',
            'certificado.asistencia' => 'id_asistencia INTEGER PRIMARY KEY, id_participacion INTEGER, id_actividad_sesion INTEGER, hora_entrada TEXT, hora_salida TEXT, estado TEXT',
            'certificado.certificado' => 'id_certificado INTEGER PRIMARY KEY, id_persona INTEGER, id_participacion INTEGER, id_practica INTEGER, id_reconocimiento INTEGER, id_tipo_certificado INTEGER, estado TEXT, fecha_emision TEXT',
            'certificado.certificado_documento' => 'id_certificado_documento INTEGER PRIMARY KEY, id_certificado INTEGER, codigo_verificacion TEXT, estado TEXT, fecha_generacion TEXT',
            'certificado.tipo_certificado' => 'id_tipo_certificado INTEGER PRIMARY KEY, nombre_tipo TEXT, estado BOOLEAN',
            'certificado.reconocimiento' => 'id_reconocimiento INTEGER PRIMARY KEY, id_persona_trabajador INTEGER',
        ] as $table => $columns) {
            DB::statement("CREATE TABLE {$table} ({$columns})");
        }
        DB::table('gestion.area')->insert([['id_area' => 1, 'nombre' => 'Cultura'], ['id_area' => 2, 'nombre' => 'Educación']]);
        DB::table('gestion.persona')->insert([['id_persona' => 1, 'nombres' => 'Ana', 'apellido_paterno' => 'Pérez'], ['id_persona' => 2, 'nombres' => 'Luis', 'apellido_paterno' => 'Ríos']]);
        DB::table('certificado.tipo_actividad')->insert(['id_tipo_actividad' => 1, 'nombre' => 'Curso']);
        DB::table('certificado.tipo_certificado')->insert(['id_tipo_certificado' => 1, 'nombre_tipo' => 'Constancia', 'estado' => true]);
        DB::table('certificado.actividad')->insert([
            ['id_actividad' => 1, 'nombre' => 'Curso de arte', 'id_area' => 1, 'id_tipo_actividad' => 1, 'fecha_inicio' => '2026-02-01', 'estado' => 'Activa'],
            ['id_actividad' => 2, 'nombre' => 'Taller externo', 'id_area' => 2, 'id_tipo_actividad' => 1, 'fecha_inicio' => '2026-03-01', 'estado' => 'Finalizada'],
        ]);
        DB::table('certificado.actividad_sesion')->insert([
            ['id_actividad_sesion' => 1, 'id_actividad' => 1, 'nombre' => 'Sesión inicial', 'fecha' => '2026-02-10'],
            ['id_actividad_sesion' => 2, 'id_actividad' => 2, 'nombre' => 'Sesión externa', 'fecha' => '2026-03-10'],
        ]);
        DB::table('certificado.participacion')->insert([
            ['id_participacion' => 1, 'id_actividad' => 1, 'id_persona' => 1], ['id_participacion' => 2, 'id_actividad' => 2, 'id_persona' => 2],
        ]);
        DB::table('certificado.asistencia')->insert([
            ['id_asistencia' => 1, 'id_participacion' => 1, 'id_actividad_sesion' => 1, 'estado' => 'PRESENTE'],
            ['id_asistencia' => 2, 'id_participacion' => 2, 'id_actividad_sesion' => 2, 'estado' => 'AUSENTE'],
        ]);
        DB::table('certificado.certificado')->insert([
            ['id_certificado' => 1, 'id_persona' => 1, 'id_participacion' => 1, 'id_tipo_certificado' => 1, 'fecha_emision' => '2026-02-10', 'estado' => 'EMITIDO'],
            ['id_certificado' => 2, 'id_persona' => 2, 'id_participacion' => 2, 'id_tipo_certificado' => 1, 'fecha_emision' => null, 'estado' => 'PREPARADO'],
        ]);
        DB::table('certificado.certificado_documento')->insert([
            ['id_certificado_documento' => 1, 'id_certificado' => 1, 'codigo_verificacion' => 'anterior', 'estado' => 'Anulado'],
            ['id_certificado_documento' => 2, 'id_certificado' => 1, 'codigo_verificacion' => 'actual', 'estado' => 'Vigente'],
        ]);
        $this->actor();
    }

    private function actor(bool $administrator = true, array $permissions = ['reportes.ver', 'reportes.exportar', 'actividades.ver', 'emision.ver', 'participantes.ver']): void
    {
        $user = new class extends User
        {
            public bool $administrator;

            public array $permissions;

            public function isSystemAdministrator(): bool
            {
                return $this->administrator;
            }

            public function permissionKeys(): array
            {
                return $this->permissions;
            }
        };
        $user->forceFill(['id_usuario' => 50, 'id_persona' => 50, 'estado' => true]);
        $user->administrator = $administrator;
        $user->permissions = $permissions;
        $this->actingAs($user, 'jwt');
    }

    public function test_activity_filters_and_summary_are_global_and_dates_inclusive(): void
    {
        $this->getJson('/api/reportes/actividades?desde=2026-02-01&hasta=2026-02-01&q=arte&estado=Activa&per_page=1')
            ->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.tipo_actividad', 'Curso')
            ->assertJsonPath('data.resumen.sesiones', 1)->assertJsonPath('data.resumen.participaciones', 1);
        $this->getJson('/api/reportes/actividades?per_page=1')->assertOk()
            ->assertJsonCount(1, 'data.data')->assertJsonPath('data.total', 2)->assertJsonPath('data.resumen.total', 2);
    }

    public function test_certificate_filters_search_all_rows_and_versions_do_not_duplicate_certificates(): void
    {
        $this->getJson('/api/reportes/certificados?per_page=1')->assertOk()->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.resumen.con_fecha_emision', 1)->assertJsonPath('data.resumen.personas_unicas', 2);
        $this->getJson('/api/reportes/certificados?q=Ana%20P%C3%A9rez&desde=2026-02-10&hasta=2026-02-10&estado=EMITIDO&id_tipo_certificado=1&estado_documento=Vigente')
            ->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.id_certificado_documento', 2)
            ->assertJsonPath('data.data.0.beneficiario', 'Ana Pérez')->assertJsonPath('data.data.0.actividad', 'Curso de arte');
        $this->getJson('/api/reportes/certificados?q=anterior')->assertOk()->assertJsonPath('data.total', 0);
    }

    public function test_attendance_uses_session_dates_and_counts_people_separately(): void
    {
        DB::table('certificado.asistencia')->insert(['id_participacion' => 1, 'id_actividad_sesion' => 1, 'estado' => 'PRESENTE']);
        $this->getJson('/api/reportes/asistencia?id_actividad=1&id_actividad_sesion=1&desde=2026-02-10&hasta=2026-02-10&q=Ana&per_page=1')
            ->assertOk()->assertJsonPath('data.total', 2)->assertJsonPath('data.resumen.personas_unicas', 1)
            ->assertJsonPath('data.resumen.presentes', 2)->assertJsonPath('data.data.0.participante', 'Ana Pérez');
        $this->getJson('/api/reportes/asistencia?id_actividad=1&id_actividad_sesion=2')->assertUnprocessable();
        $this->getJson('/api/reportes/sesiones?id_actividad=1')->assertOk()->assertJsonCount(1, 'data.data')->assertJsonPath('data.data.0.id_actividad_sesion', 1);
    }

    #[TestWith(['actividades'])]
    #[TestWith(['certificados'])]
    #[TestWith(['asistencia'])]
    public function test_rows_summaries_and_exports_respect_authorized_areas(string $resource): void
    {
        $this->actor(administrator: false);
        DB::table('gestion.persona_trabajador')->insert(['id_persona' => 50, 'id_area' => 1, 'estado' => true, 'fecha_ingreso' => '2020-01-01']);
        $this->getJson('/api/reportes/'.$resource)->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.resumen.total', 1);
        $csv = $this->get('/api/reportes/'.$resource.'?exportar=csv')->assertOk()->streamedContent();
        self::assertStringNotContainsString('Taller externo', $csv);
        self::assertStringNotContainsString('Luis', $csv);
        $this->getJson('/api/reportes/asistencia?id_actividad=2')->assertNotFound();
    }

    public function test_export_includes_all_filtered_rows_and_neutralizes_formulas(): void
    {
        DB::table('certificado.actividad')->where('id_actividad', 1)->update(['nombre' => ' =HYPERLINK("unsafe")']);
        $csv = $this->get('/api/reportes/actividades?per_page=1&exportar=csv')->assertOk()->streamedContent();
        self::assertStringStartsWith("\xEF\xBB\xBF", $csv);
        self::assertStringContainsString("' =HYPERLINK", $csv);
        self::assertStringContainsString('Taller externo', $csv);
    }

    public function test_yearly_series_fill_missing_months_and_ignore_null_emission_dates(): void
    {
        $this->getJson('/api/reportes/series/certificados?anio=2026')->assertOk()->assertJsonCount(12, 'data.meses')
            ->assertJsonPath('data.meses.0.total', 0)->assertJsonPath('data.meses.1.total', 1)->assertJsonPath('data.meses.2.total', 0);
        $this->getJson('/api/reportes/series/actividades?anio=2026')->assertOk()->assertJsonPath('data.meses.2.total', 1);
    }

    public function test_report_and_source_permissions_are_required_and_export_has_its_own_permission(): void
    {
        $this->actor(permissions: ['reportes.ver']);
        foreach (['actividades', 'certificados', 'asistencia', 'sesiones?id_actividad=1', 'series/actividades?anio=2026'] as $path) {
            $this->getJson('/api/reportes/'.$path)->assertForbidden();
        }
        $this->actor(permissions: ['actividades.ver']);
        $this->getJson('/api/reportes/actividades')->assertForbidden();
        $this->actor(permissions: ['reportes.ver', 'actividades.ver']);
        $this->getJson('/api/reportes/actividades?exportar=csv')->assertForbidden();
    }

    public function test_gerencial_can_consult_and_export_institutional_reports_without_access_to_source_modules(): void
    {
        $this->actor(administrator: false, permissions: ['reportes.ver', 'gerencia.ver']);

        foreach (['actividades', 'certificados', 'asistencia'] as $resource) {
            $this->getJson('/api/reportes/'.$resource)->assertOk()->assertJsonPath('data.total', 2);
        }
        $this->getJson('/api/reportes/sesiones?id_actividad=2')->assertOk()->assertJsonPath('data.data.0.id_actividad_sesion', 2);
        $this->getJson('/api/reportes/indicadores/asistencias')->assertOk()->assertJsonPath('data.total', 2);
        $this->getJson('/api/reportes/series/actividades?anio=2026')->assertOk();
        self::assertStringContainsString('Luis', $this->get('/api/reportes/asistencia?exportar=csv')->assertOk()->streamedContent());
    }

    #[TestWith(['desde=2026-03-02&hasta=2026-03-01'])]
    #[TestWith(['desde=invalid'])]
    #[TestWith(['per_page=101'])]
    #[TestWith(['page=0'])]
    public function test_invalid_filters_are_rejected(string $filters): void
    {
        $this->getJson('/api/reportes/actividades?'.$filters)->assertUnprocessable();
    }

    public function test_search_treats_wildcards_as_literal_characters(): void
    {
        $this->getJson('/api/reportes/actividades?q=%25')->assertOk()->assertJsonPath('data.total', 0);
    }
}
