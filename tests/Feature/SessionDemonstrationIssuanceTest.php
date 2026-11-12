<?php

namespace Tests\Feature;

use App\Models\Role\Role;
use App\Models\User\User;
use App\Services\CertificateDocumentGenerator;
use App\Services\CertificationReadiness;
use App\Services\SessionCertificatePreparation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class SessionDemonstrationIssuanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'database.connections.sqlite.url' => null]);
        DB::purge('sqlite');
        DB::statement("ATTACH DATABASE ':memory:' AS gestion");
        DB::statement("ATTACH DATABASE ':memory:' AS certificado");
        foreach ([
            'gestion.area' => 'id_area INTEGER PRIMARY KEY, nombre TEXT',
            'gestion.persona' => 'id_persona INTEGER PRIMARY KEY, nombres TEXT, apellido_paterno TEXT, apellido_materno TEXT',
            'gestion.persona_trabajador' => 'id_persona_trabajador INTEGER PRIMARY KEY, id_persona INTEGER, id_area INTEGER, estado BOOLEAN, fecha_ingreso DATE, fecha_fin DATE',
            'certificado.actividad' => 'id_actividad INTEGER PRIMARY KEY, id_area INTEGER, nombre TEXT, estado TEXT, fecha_inicio DATE, fecha_fin DATE',
            'certificado.actividad_sesion' => 'id_actividad_sesion INTEGER PRIMARY KEY, id_actividad INTEGER, nombre TEXT, estado TEXT, fecha DATE, hora_inicio TEXT, hora_fin TEXT, lugar TEXT',
            'certificado.solicitud_actividad' => 'id_solicitud_actividad INTEGER PRIMARY KEY, id_actividad INTEGER, estado TEXT',
            'certificado.participacion' => 'id_participacion INTEGER PRIMARY KEY, id_actividad INTEGER, id_persona INTEGER, estado TEXT',
            'certificado.asistencia' => 'id_asistencia INTEGER PRIMARY KEY, id_participacion INTEGER, id_actividad_sesion INTEGER, estado TEXT, hora_entrada TEXT, hora_salida TEXT, UNIQUE(id_participacion,id_actividad_sesion)',
            'certificado.certificado' => 'id_certificado INTEGER PRIMARY KEY, id_participacion INTEGER, id_persona INTEGER, id_tipo_certificado INTEGER, id_practica INTEGER, id_reconocimiento INTEGER, estado TEXT, fecha_emision DATE',
            'certificado.tipo_certificado' => 'id_tipo_certificado INTEGER PRIMARY KEY, nombre_tipo TEXT, estado BOOLEAN',
        ] as $table => $columns) {
            DB::statement("CREATE TABLE {$table} ({$columns})");
        }
    }

    private function authorize(bool $administrator = true, array $permissions = ['actividades.ver', 'actividades.editar', 'emision.ver', 'participantes.ver', 'participantes.editar', 'participantes.eliminar']): void
    {
        $user = new class extends User
        {
            public bool $administrator = false;

            public array $permissions = [];

            public function isSystemAdministrator(): bool
            {
                return $this->administrator;
            }

            public function permissionKeys(): array
            {
                return $this->permissions;
            }
        };
        $user->forceFill(['id_usuario' => 8, 'id_persona' => 1, 'estado' => true]);
        $user->administrator = $administrator;
        $user->permissions = $permissions;
        $user->setRelation('roles', (new Role)->newCollection());
        $this->actingAs($user);
    }

    private function seedSession(string $state = 'Finalizada', ?string $attendance = 'PRESENTE'): void
    {
        DB::table('gestion.area')->insert([['id_area' => 1, 'nombre' => 'Área de prueba'], ['id_area' => 2, 'nombre' => 'Otra área']]);
        DB::table('gestion.persona')->insert(['id_persona' => 1, 'nombres' => 'Persona', 'apellido_paterno' => 'Prueba']);
        DB::table('gestion.persona_trabajador')->insert(['id_persona_trabajador' => 1, 'id_persona' => 1, 'id_area' => 1, 'estado' => true, 'fecha_ingreso' => '2020-01-01']);
        DB::table('certificado.actividad')->insert([
            ['id_actividad' => 1, 'id_area' => 1, 'nombre' => 'Actividad de prueba', 'estado' => 'Activa', 'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-12-31'],
            ['id_actividad' => 2, 'id_area' => 2, 'nombre' => 'Fuera de ámbito', 'estado' => 'Activa', 'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-12-31'],
        ]);
        foreach ([1 => [1, $state], 2 => [1, 'Activo'], 3 => [2, 'Activo']] as $id => [$activity, $status]) {
            DB::table('certificado.actividad_sesion')->insert(['id_actividad_sesion' => $id, 'id_actividad' => $activity, 'nombre' => 'Sesión '.$id, 'estado' => $status, 'fecha' => '2026-06-01', 'hora_inicio' => '08:00', 'hora_fin' => '10:00']);
        }
        DB::table('certificado.solicitud_actividad')->insert(['id_solicitud_actividad' => 1, 'id_actividad' => 1, 'estado' => 'Aprobada']);
        DB::table('certificado.participacion')->insert(['id_participacion' => 1, 'id_actividad' => 1, 'id_persona' => 1, 'estado' => 'Activo']);
        DB::table('certificado.tipo_certificado')->insert([['id_tipo_certificado' => 1, 'nombre_tipo' => 'Capacitación', 'estado' => true], ['id_tipo_certificado' => 2, 'nombre_tipo' => 'Reconocimiento', 'estado' => true]]);
        if ($attendance !== null) {
            DB::table('certificado.asistencia')->insert(['id_asistencia' => 1, 'id_participacion' => 1, 'id_actividad_sesion' => 1, 'estado' => $attendance]);
        }
    }

    private function approveTestPolicy(bool $entry = false, bool $exit = false): void
    {
        $policy = [
            'reference' => 'Política exclusiva de pruebas; no es norma municipal',
            'active_participation_states' => ['Activo'], 'eligible_attendance_states' => ['PRESENTE'],
            'ineligible_attendance_states' => ['AUSENTE'], 'require_entry' => $entry, 'require_exit' => $exit,
            'regularizations' => 'not_applicable', 'require_closed_activity' => false, 'required_sessions' => 'all',
        ];
        config(['certification.eligibility.sesion.1' => $policy, 'certification.eligibility.actividad.1' => $policy,
            'certification.session_closure' => [...$policy, 'final_state' => 'Finalizada', 'open_states' => ['Activo', 'activo']]]);
    }

    private function enableDynamicPolicy(): void
    {
        $this->approveTestPolicy();
        config(['certification.development_closure_enabled' => true,
            'certification.session_closure.scope' => 'development',
            'certification.development_session_type_ids' => [1]]);
        $this->partialMock(CertificationReadiness::class)->shouldReceive('developmentClosureBlockers')->andReturn([]);
    }

    private function addParticipant(int $id, string $state, ?string $attendance): void
    {
        DB::table('gestion.persona')->insert(['id_persona' => $id, 'nombres' => 'Candidato '.$id]);
        DB::table('certificado.participacion')->insert(['id_participacion' => $id, 'id_persona' => $id, 'id_actividad' => 1, 'estado' => $state]);
        if ($attendance !== null) {
            DB::table('certificado.asistencia')->insert(['id_asistencia' => $id, 'id_participacion' => $id, 'id_actividad_sesion' => 1, 'estado' => $attendance]);
        }
    }

    private function demonstration(bool $withIdentity = true): void
    {
        $this->seedSession();
        $this->authorize(permissions: ['actividades.ver', 'emision.ver', 'emision.crear', 'participantes.ver']);
        $this->enableDynamicPolicy();
        config(['app.url' => 'http://127.0.0.1:8000', 'app.frontend_url' => 'http://localhost:4200',
            'certification.documents.verification_base_url' => null,
            'certification.documents.allow_http_lan' => false]);
        Storage::fake('local');
        if ($withIdentity) {
            DB::statement('DROP TABLE certificado.certificado');
            DB::statement('CREATE TABLE certificado.certificado (
                id_certificado INTEGER PRIMARY KEY, id_persona INTEGER NOT NULL, id_participacion INTEGER,
                id_actividad_sesion INTEGER, id_tipo_certificado INTEGER NOT NULL, id_practica INTEGER, id_reconocimiento INTEGER,
                fecha_emision TEXT NOT NULL, estado TEXT NOT NULL, notificado BOOLEAN, fecha_notificacion TEXT,
                FOREIGN KEY(id_participacion,id_actividad_sesion) REFERENCES asistencia(id_participacion,id_actividad_sesion) ON DELETE RESTRICT ON UPDATE RESTRICT,
                CHECK(id_actividad_sesion IS NULL OR (id_participacion IS NOT NULL AND id_practica IS NULL AND id_reconocimiento IS NULL)))');
            DB::statement('CREATE UNIQUE INDEX certificado.certificado_participacion_sesion_tipo_unique ON certificado(id_participacion,id_actividad_sesion,id_tipo_certificado) WHERE id_actividad_sesion IS NOT NULL');
        }
        DB::statement("CREATE TABLE certificado.certificado_documento (id_certificado_documento INTEGER PRIMARY KEY,
            id_certificado INTEGER NOT NULL, codigo_verificacion TEXT UNIQUE NOT NULL, hash_certificado TEXT UNIQUE NOT NULL,
            ruta_archivo TEXT NOT NULL, fecha_generacion TEXT NOT NULL, estado TEXT NOT NULL CHECK(estado IN ('Vigente','Anulado')))");
        DB::statement('CREATE TABLE certificado.certificado_firma (id_certificado_firma INTEGER PRIMARY KEY, id_certificado_documento INTEGER)');
        DB::statement('CREATE TABLE certificado.consulta_certificado (id_consulta_certificado INTEGER PRIMARY KEY, id_certificado_documento INTEGER)');
    }

    /** @return array<string, mixed> */
    private function confirmation(int $participationId = 1): array
    {
        return ['accion' => 'emitir_demostracion', 'id_participacion' => $participationId, 'confirmar' => true];
    }

    public function test_preview_is_formal_and_read_only_even_without_authorized_schema(): void
    {
        $this->demonstration(false);
        DB::enableQueryLog();
        $preview = $this->postJson('/api/activity-session/1/preparar-certificados', ['accion' => 'vista_previa', 'id_participacion' => 1])
            ->assertOk()->assertJsonPath('data.emitida', false)->assertJsonPath('data.identidad_sesion_disponible', false)
            ->assertJsonPath('data.puede_emitir_demostracion', false)->json('data.vista_previa_html');
        self::assertStringContainsString('Se otorga el presente certificado a:', $preview);
        self::assertStringContainsString('Persona Prueba', $preview);
        self::assertStringContainsString('Sesión 1', $preview);
        self::assertStringContainsString('01/06/2026', $preview);
        self::assertSame(1, substr_count($preview, 'Actividad de prueba'));
        self::assertSame(1, substr_count($preview, 'class="signature-space"'));
        self::assertStringContainsString('<div class="signature-name">', $preview);
        $signatureHtml = explode('<div class="signers">', $preview, 2)[1];
        self::assertStringNotContainsString('<img', $signatureHtml);
        self::assertStringNotContainsString('Responsable', $signatureHtml);
        foreach (['Elegible', 'PRESENTE', 'No emitida', 'Lugar no informado', 'Código de verificación', 'Fecha de emisión', 'Vista previa · Sin emisión ni firma digital institucional'] as $text) {
            self::assertStringNotContainsString($text, $preview);
        }
        foreach (DB::getQueryLog() as $query) {
            self::assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|create|alter|drop)\b/i', $query['query']);
        }
        DB::disableQueryLog();
        $this->assertDatabaseCount('certificado.certificado', 0);
        $this->assertDatabaseCount('certificado.certificado_documento', 0);
        self::assertSame([], Storage::disk('local')->allFiles());
        $this->postJson('/api/activity-session/1/preparar-certificados', $this->confirmation())->assertConflict();
    }

    public function test_custom_message_can_be_edited_and_rendered_in_a_real_preview_pdf_without_persistence(): void
    {
        $this->demonstration(false);
        $payload = ['accion' => 'vista_previa', 'formato' => 'pdf', 'id_participacion' => 1];
        $first = "  Capacitación auténtica: acción, niñez y Perú.\nTexto confirmado & autorizado.  ";
        $second = 'Mensaje corregido: participación destacada.';
        foreach ([$first, $second] as $message) {
            $response = $this->postJson('/api/activity-session/1/preparar-certificados', $payload + ['mensaje' => $message])
                ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
                ->assertJsonPath('data.mensaje', $message)->assertJsonPath('data.mensaje_persistido', false)
                ->assertJsonPath('data.emitida', false)->assertJsonPath('data.puede_emitir_demostracion', false)
                ->assertJsonPath('data.vista_previa_pdf.tipo_contenido', 'application/pdf')
                ->assertJsonPath('data.siguiente_accion_preparacion', null)
                ->assertJsonPath('data.vista_previa_pdf.persistido', false);
            $html = $response->json('data.vista_previa_html');
            self::assertStringContainsString(e($message), $html);
            self::assertStringContainsString('Persona Prueba', $html);
            self::assertStringContainsString('CERTIFICADO DE CAPACITACIÓN', $html);
            self::assertStringContainsString('Sesión 1', $html);
            self::assertStringContainsString('01/06/2026', $html);
            self::assertStringNotContainsString('Código de verificación', $html);
            $pdf = base64_decode($response->json('data.vista_previa_pdf.contenido_base64'), true);
            self::assertIsString($pdf);
            self::assertSame(hash('sha256', $pdf), $response->json('data.vista_previa_pdf.hash_pdf'));
            self::assertStringStartsWith('%PDF-', $pdf);
            self::assertStringContainsString('%%EOF', $pdf);
            preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $streams);
            $decoded = implode('', array_map(fn (string $stream): string => @gzuncompress($stream) ?: $stream, $streams[1]));
            self::assertStringContainsString(mb_convert_encoding($message === $first ? 'auténtica' : 'corregido', 'UTF-16BE', 'UTF-8'), $decoded);
            unset($response, $html, $pdf, $streams, $decoded);
            gc_collect_cycles();
        }
        $this->assertDatabaseCount('certificado.certificado', 0);
        $this->assertDatabaseCount('certificado.certificado_documento', 0);
        self::assertSame([], Storage::disk('local')->allFiles());
        $this->postJson('/api/activity-session/1/preparar-certificados', $this->confirmation())->assertConflict();
    }

    #[TestWith([1, 'Antón', 'Talián'])]
    #[TestWith([2, 'María', 'Perez'])]
    public function test_session_two_preview_renders_selected_beneficiary_without_a_registered_certificate(int $participationId, string $firstName, string $lastName): void
    {
        $this->demonstration(false);
        DB::table('gestion.persona')->where('id_persona', 1)->update(['nombres' => 'Antón', 'apellido_paterno' => 'Talián']);
        $this->addParticipant(2, 'Activo', 'PRESENTE');
        DB::table('gestion.persona')->where('id_persona', 2)->update(['nombres' => 'María', 'apellido_paterno' => 'Perez']);
        DB::table('certificado.actividad_sesion')->where('id_actividad_sesion', 2)->update(['estado' => 'Finalizada']);
        DB::table('certificado.asistencia')->update(['id_actividad_sesion' => 2]);
        $message = 'Por su participación y compromiso con Morales.';
        DB::enableQueryLog();

        $response = $this->postJson('/api/activity-session/2/preparar-certificados', [
            'accion' => 'vista_previa', 'formato' => 'pdf', 'id_participacion' => $participationId,
            'id_tipo_certificado' => 1, 'mensaje' => $message,
        ])->assertOk()->assertJsonPath('data.contexto.id_sesion', 2)
            ->assertJsonPath('data.contexto.id_participacion', $participationId)
            ->assertJsonPath('data.mensaje', $message)->assertJsonPath('data.creado', false)
            ->assertJsonPath('data.vista_previa_pdf.persistido', false);

        $html = $response->json('data.vista_previa_html');
        self::assertStringContainsString($firstName.' '.$lastName, $html);
        self::assertStringContainsString($message, $html);
        $pdf = base64_decode($response->json('data.vista_previa_pdf.contenido_base64'), true);
        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertStringContainsString('/MediaBox [0.000 0.000 841.890 595.280]', $pdf);
        self::assertSame(hash('sha256', $pdf), $response->json('data.vista_previa_pdf.hash_pdf'));
        foreach (DB::getQueryLog() as $query) {
            self::assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|create|alter|drop)\b/i', $query['query']);
        }
        DB::disableQueryLog();
        $this->assertDatabaseCount('certificado.certificado', 0);
        $this->assertDatabaseCount('certificado.certificado_documento', 0);
        self::assertSame([], Storage::disk('local')->allFiles());
    }

    #[TestWith([''])]
    #[TestWith(['   '])]
    #[TestWith(['<script>alert(1)</script>'])]
    #[TestWith(['<img src="https://invalid.example/a">'])]
    #[TestWith([null])]
    #[TestWith([['texto']])]
    public function test_custom_preview_rejects_invalid_or_active_content(mixed $message): void
    {
        $this->demonstration(false);
        $this->postJson('/api/activity-session/1/preparar-certificados', [
            'accion' => 'vista_previa', 'id_participacion' => 1, 'mensaje' => $message, 'formato' => 'pdf',
        ])->assertUnprocessable();
        $this->assertDatabaseCount('certificado.certificado', 0);
        self::assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_preview_rejects_oversized_messages_and_never_silently_discards_a_message_on_issuance(): void
    {
        $this->demonstration(false);
        $this->postJson('/api/activity-session/1/preparar-certificados', [
            'accion' => 'vista_previa', 'id_participacion' => 1, 'mensaje' => str_repeat('á', 1201),
        ])->assertUnprocessable();
        $this->postJson('/api/activity-session/1/preparar-certificados', $this->confirmation() + ['mensaje' => 'Texto aprobado'])
            ->assertUnprocessable();
        $this->assertDatabaseCount('certificado.certificado', 0);
        $this->assertDatabaseCount('certificado.certificado_documento', 0);
    }

    public function test_custom_pdf_preview_cannot_replace_authorized_identity_or_bypass_permissions(): void
    {
        $this->demonstration(false);
        $payload = ['accion' => 'vista_previa', 'formato' => 'pdf', 'id_participacion' => 1, 'mensaje' => 'Participación auténtica.'];
        foreach ([['id_persona' => 2], ['id_sesion' => 2], ['id_tipo_certificado' => 2]] as $changes) {
            $this->postJson('/api/activity-session/1/preparar-certificados', array_replace($payload, $changes))->assertUnprocessable();
        }
        $this->authorize(false, ['actividades.ver', 'emision.ver', 'emision.crear', 'participantes.ver']);
        $this->postJson('/api/activity-session/3/preparar-certificados', $payload)->assertForbidden();
        $this->authorize(permissions: ['actividades.ver', 'emision.ver', 'participantes.ver']);
        $this->postJson('/api/activity-session/1/preparar-certificados', $payload)->assertForbidden();
        $this->assertDatabaseCount('certificado.certificado', 0);
        self::assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_preview_never_truncates_text_to_make_it_fit_one_page(): void
    {
        $this->demonstration(false);
        $this->postJson('/api/activity-session/1/preparar-certificados', [
            'accion' => 'vista_previa', 'formato' => 'pdf', 'id_participacion' => 1, 'mensaje' => str_repeat("Texto\n", 90),
        ])->assertUnprocessable();
        $this->assertDatabaseCount('certificado.certificado', 0);
        self::assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_confirmed_demonstration_persists_real_pdf_and_retry_recovers_the_same_document(): void
    {
        $this->demonstration();
        $this->travelTo(Carbon::parse('2026-11-10 12:00:00'));
        self::assertTrue(app(SessionCertificatePreparation::class)->identityAvailable());
        $issued = $this->postJson('/api/activity-session/1/preparar-certificados', $this->confirmation())->assertCreated()
            ->assertJsonPath('data.emitida', true)->assertJsonPath('data.demostracion', true)
            ->assertJsonPath('data.oficial', false)->assertJsonPath('data.estado_certificado', 'Demostracion')
            ->assertJsonPath('data.id_persona', 1)->assertJsonPath('data.id_participacion', 1)
            ->assertJsonPath('data.id_actividad', 1)->assertJsonPath('data.id_sesion', 1)->assertJsonPath('data.id_tipo_certificado', 1)->json('data');
        self::assertSame('http://127.0.0.1:8000/api/certificados/verificar/'.$issued['codigo_verificacion'], $issued['url_verificacion']);
        $document = DB::table('certificado.certificado_documento')->first();
        $pdf = Storage::disk('local')->get($document->ruta_archivo);
        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertSame(hash('sha256', $pdf), $document->hash_certificado);
        $this->assertDatabaseHas('certificado.certificado', ['id_certificado' => $issued['id_certificado'], 'id_actividad_sesion' => 1, 'fecha_emision' => '2026-11-10']);
        $this->postJson('/api/activity-session/1/preparar-certificados', $this->confirmation())->assertOk()
            ->assertJsonPath('data.creado', false)->assertJsonPath('data.id_certificado', $issued['id_certificado'])
            ->assertJsonPath('data.codigo_verificacion', $issued['codigo_verificacion']);
        $this->assertDatabaseCount('certificado.certificado', 1);
        $this->assertDatabaseCount('certificado.certificado_documento', 1);
        self::assertCount(1, Storage::disk('local')->allFiles());
        $this->get($issued['url_descarga'])->assertDownload();
        $this->getJson('/api/activity-session/1/beneficiarios?id_tipo_certificado=1')->assertOk()
            ->assertJsonPath('data.beneficiarios.data.0.id_certificado', $issued['id_certificado'])
            ->assertJsonPath('data.beneficiarios.data.0.id_certificado_documento', $issued['id_certificado_documento']);
    }

    public function test_two_beneficiaries_and_another_session_receive_independent_unique_documents(): void
    {
        $this->demonstration();
        $this->addParticipant(2, 'Activo', 'PRESENTE');
        $first = $this->postJson('/api/activity-session/1/preparar-certificados', $this->confirmation())->assertCreated()->json('data');
        $second = $this->postJson('/api/activity-session/1/preparar-certificados', $this->confirmation(2))->assertCreated()->json('data');
        DB::table('certificado.actividad_sesion')->where('id_actividad_sesion', 2)->update(['id_actividad_sesion' => 47, 'estado' => 'Finalizada']);
        DB::table('certificado.asistencia')->insert(['id_asistencia' => 47, 'id_participacion' => 1, 'id_actividad_sesion' => 47, 'estado' => 'PRESENTE']);
        $third = $this->postJson('/api/activity-session/47/preparar-certificados', $this->confirmation())->assertCreated()
            ->assertJsonPath('data.id_sesion', 47)->json('data');
        self::assertCount(3, array_unique([$first['codigo_verificacion'], $second['codigo_verificacion'], $third['codigo_verificacion']]));
        self::assertCount(3, array_unique([$first['id_certificado'], $second['id_certificado'], $third['id_certificado']]));
        $this->assertDatabaseCount('certificado.certificado', 3);
        $this->getJson('/api/activity-session/47/beneficiarios?id_tipo_certificado=1')->assertOk()
            ->assertJsonPath('data.beneficiarios.data.0.id_certificado', $third['id_certificado']);
        $this->getJson('/api/activity-session/1/beneficiarios?id_tipo_certificado=1')->assertOk()
            ->assertJsonPath('data.beneficiarios.data.0.id_certificado', $first['id_certificado']);
    }

    public function test_verification_of_a_demo_uses_its_persisted_session_and_serves_the_actual_pdf_without_claiming_signature(): void
    {
        $this->demonstration();
        config(['certification.documents.public_beneficiary_name' => true]);
        $issued = $this->postJson('/api/activity-session/1/preparar-certificados', $this->confirmation())->assertCreated()->json('data');
        $url = '/api/certificados/verificar/'.$issued['codigo_verificacion'];
        $this->getJson($url)->assertOk()->assertJsonPath('data.demostracion', true)
            ->assertJsonPath('data.identidad_sesion_verificada', true)->assertJsonPath('data.sesion', 'Sesión 1')
            ->assertJsonPath('data.actividad', 'Actividad de prueba')->assertJsonPath('data.tipo_certificado', 'Capacitación')
            ->assertJsonPath('data.beneficiario', 'Persona Prueba')->assertJsonPath('data.integridad_verificada', true)
            ->assertJsonPath('data.puede_ver_documento', true)->assertJsonPath('data.vigente', false)
            ->assertJsonPath('data.firma_verificada', false)->assertJsonPath('data.emision_oficial_verificada', false)
            ->assertJsonMissingPath('data.ruta_archivo')->assertJsonMissingPath('data.id_persona');
        $this->get($url, ['Accept' => 'text/html'])->assertOk()->assertSee('Certificado de demostración')->assertSee('<iframe', false);
        $response = $this->get($url.'?archivo=1')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $document = DB::table('certificado.certificado_documento')->first();
        self::assertSame(Storage::disk('local')->get($document->ruta_archivo), file_get_contents($response->baseResponse->getFile()->getPathname()));
        config(['certification.documents.public_beneficiary_name' => false]);
        $this->getJson($url)->assertOk()->assertJsonPath('data.beneficiario', null);
    }

    #[TestWith(['missing'])]
    #[TestWith(['false'])]
    #[TestWith(['forged_date'])]
    public function test_confirmation_is_explicit_and_the_client_cannot_forge_the_issue_date(string $condition): void
    {
        $this->demonstration();
        $input = $this->confirmation();
        if ($condition === 'missing') {
            unset($input['confirmar']);
        }
        if ($condition === 'false') {
            $input['confirmar'] = false;
        }
        if ($condition === 'forged_date') {
            $input['fecha_emision'] = '2000-01-01';
        }
        $this->postJson('/api/activity-session/1/preparar-certificados', $input)->assertUnprocessable();
        $this->assertDatabaseCount('certificado.certificado', 0);
        self::assertSame([], Storage::disk('local')->allFiles());
    }

    #[TestWith(['absent'])]
    #[TestWith(['open'])]
    #[TestWith(['inactive'])]
    public function test_noneligible_people_cannot_preview_or_issue_a_demonstration(string $condition): void
    {
        $this->demonstration();
        if ($condition === 'absent') {
            DB::table('certificado.asistencia')->update(['estado' => 'AUSENTE']);
        }
        if ($condition === 'open') {
            DB::table('certificado.actividad_sesion')->where('id_actividad_sesion', 1)->update(['estado' => 'Activo']);
        }
        if ($condition === 'inactive') {
            DB::table('certificado.participacion')->update(['estado' => 'Inactivo']);
        }
        $this->postJson('/api/activity-session/1/preparar-certificados', ['accion' => 'vista_previa', 'id_participacion' => 1])->assertConflict();
        $this->postJson('/api/activity-session/1/preparar-certificados', $this->confirmation())->assertConflict();
        $this->assertDatabaseCount('certificado.certificado', 0);
        self::assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_failed_document_insert_rolls_back_the_certificate_and_its_real_file(): void
    {
        $this->demonstration();
        DB::statement("CREATE TRIGGER certificado.reject_document BEFORE INSERT ON certificado_documento BEGIN SELECT RAISE(ABORT, 'Isolated persistence failure'); END");
        $this->postJson('/api/activity-session/1/preparar-certificados', $this->confirmation())->assertConflict();
        $this->assertDatabaseCount('certificado.certificado', 0);
        $this->assertDatabaseCount('certificado.certificado_documento', 0);
        self::assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_participation_only_certificate_is_not_assigned_to_a_session_or_silently_duplicated(): void
    {
        $this->demonstration();
        DB::table('certificado.certificado')->insert(['id_persona' => 1, 'id_participacion' => 1, 'id_tipo_certificado' => 1,
            'id_actividad_sesion' => null, 'estado' => 'Vigente', 'fecha_emision' => '2026-01-01']);
        $this->getJson('/api/activity-session/1/beneficiarios?id_tipo_certificado=1')->assertOk()
            ->assertJsonPath('data.beneficiarios.data.0.certificado_sesion', null);
        $this->postJson('/api/activity-session/1/preparar-certificados', $this->confirmation())->assertConflict();
        $this->assertDatabaseCount('certificado.certificado', 1);
        $this->assertDatabaseCount('certificado.certificado_documento', 0);
    }

    public function test_the_unique_constraint_prevents_a_duplicate_even_outside_the_endpoint(): void
    {
        $this->demonstration();
        $this->postJson('/api/activity-session/1/preparar-certificados', $this->confirmation())->assertCreated();
        $duplicate = (array) DB::table('certificado.certificado')->first();
        unset($duplicate['id_certificado']);
        try {
            DB::table('certificado.certificado')->insert($duplicate);
            self::fail('La restricción debe impedir dos certificados para participación, sesión y tipo.');
        } catch (QueryException $exception) {
            self::assertSame('23000', $exception->getCode());
        }
        $this->assertDatabaseCount('certificado.certificado', 1);
        $this->assertDatabaseCount('certificado.certificado_documento', 1);
    }

    public function test_a_column_without_the_unique_constraint_never_enables_issuance(): void
    {
        $this->demonstration();
        DB::statement('DROP INDEX certificado.certificado_participacion_sesion_tipo_unique');
        self::assertFalse(app(SessionCertificatePreparation::class)->identityAvailable());
        $this->postJson('/api/activity-session/1/preparar-certificados', $this->confirmation())->assertConflict();
        $this->getJson('/api/activity-session/1/beneficiarios?id_tipo_certificado=1')->assertOk()
            ->assertJsonPath('data.puede_consultar_beneficiarios', true)->assertJsonPath('data.puede_emitir_demostracion', false);
        $this->assertDatabaseCount('certificado.certificado', 0);
    }

    public function test_demo_actions_require_jwt_permissions_and_the_authorized_area(): void
    {
        $this->postJson('/api/activity-session/1/preparar-certificados', $this->confirmation())->assertUnauthorized();
        $this->demonstration();
        $this->authorize(permissions: ['actividades.ver', 'emision.ver', 'participantes.ver']);
        $this->postJson('/api/activity-session/1/preparar-certificados', $this->confirmation())->assertForbidden();
        $this->authorize(false, ['actividades.ver', 'emision.ver', 'emision.crear', 'participantes.ver']);
        $this->postJson('/api/activity-session/3/preparar-certificados', $this->confirmation())->assertForbidden();
        $this->postJson('/api/activity-session/1/preparar-certificados', $this->confirmation() + ['id_sesion' => 2])->assertUnprocessable();
        $this->assertDatabaseCount('certificado.certificado', 0);
    }

    #[TestWith([1])]
    #[TestWith([2])]
    #[TestWith([3])]
    #[TestWith([4])]
    #[TestWith([5])]
    #[TestWith([6])]
    #[TestWith([2, true])]
    public function test_only_current_configured_signers_appear_without_a_forged_signature(int $signerCount, bool $duplicatePerson = false): void
    {
        $this->demonstration();
        DB::statement('ALTER TABLE gestion.persona_trabajador ADD COLUMN cargo TEXT');
        DB::statement('CREATE TABLE certificado.firma (id_firma INTEGER PRIMARY KEY, id_persona_trabajador INTEGER)');
        DB::statement('CREATE TABLE certificado.actividad_firma (id_actividad_firma INTEGER PRIMARY KEY, id_actividad INTEGER, id_firma INTEGER, orden_firma INTEGER)');
        foreach (range(10, 10 + $signerCount) as $id) {
            $active = $id < 10 + $signerCount;
            DB::table('gestion.persona')->insert(['id_persona' => $id, 'nombres' => $active ? 'Responsable Autorizado '.$id : 'Responsable Inactivo']);
            DB::table('gestion.persona_trabajador')->insert(['id_persona_trabajador' => $id, 'id_persona' => $id,
                'cargo' => 'Responsable de capacitación', 'estado' => $active, 'fecha_ingreso' => '2020-01-01']);
            DB::table('certificado.firma')->insert(['id_firma' => $id, 'id_persona_trabajador' => $id]);
            DB::table('certificado.actividad_firma')->insert(['id_actividad_firma' => $id, 'id_actividad' => 1, 'id_firma' => $id, 'orden_firma' => $id]);
        }
        if ($duplicatePerson) {
            DB::table('gestion.persona_trabajador')->where('id_persona_trabajador', 11)->update(['id_persona' => 10]);
            $this->postJson('/api/activity-session/1/preparar-certificados', ['accion' => 'vista_previa', 'formato' => 'pdf', 'id_participacion' => 1])->assertConflict();
            $this->assertDatabaseCount('certificado.certificado', 0);

            return;
        }
        $response = $this->postJson('/api/activity-session/1/preparar-certificados', ['accion' => 'vista_previa', 'formato' => 'pdf', 'id_participacion' => 1])->assertOk();
        $html = $response->json('data.vista_previa_html');
        self::assertCount($signerCount, $response->json('data.firmantes_configurados'));
        self::assertSame($signerCount, substr_count($html, 'class="signature-space"'));
        self::assertSame($signerCount, substr_count($html, 'class="signature-name"'));
        $lastPosition = -1;
        foreach ($response->json('data.firmantes_configurados') as $signer) {
            $position = strpos($html, e($signer['nombre']));
            self::assertNotFalse($position);
            self::assertGreaterThan($lastPosition, $position);
            self::assertStringContainsString(e($signer['cargo']), $html);
            $lastPosition = $position;
        }
        $pdf = base64_decode($response->json('data.vista_previa_pdf.contenido_base64'), true);
        self::assertStringContainsString('/MediaBox [0.000 0.000 841.890 595.280]', $pdf);
        self::assertSame(1, preg_match_all('~/Type /Page\\b~', $pdf));
        self::assertStringContainsString('Responsable Autorizado', $html);
        self::assertStringContainsString('Responsable de capacitación', $html);
        self::assertStringNotContainsString('Responsable Inactivo', $html);
        self::assertStringNotContainsString('FIRMADO', $html);
        $this->assertDatabaseCount('certificado.certificado', 0);
    }

    public function test_an_explicit_local_lan_url_can_be_encoded_for_phone_verification(): void
    {
        $this->demonstration();
        config(['certification.documents.verification_base_url' => 'http://192.168.10.20:8000/api/certificados/verificar',
            'certification.documents.allow_http_lan' => true]);
        $issued = $this->postJson('/api/activity-session/1/preparar-certificados', $this->confirmation())->assertCreated()->json('data');
        self::assertSame('http://192.168.10.20:8000/api/certificados/verificar/'.$issued['codigo_verificacion'], $issued['url_verificacion']);
    }

    public function test_demo_cannot_be_relabelled_official_and_annulling_a_document_preserves_its_identity(): void
    {
        $this->demonstration();
        $this->authorize(permissions: ['actividades.ver', 'emision.ver', 'emision.crear', 'emision.editar', 'participantes.ver']);
        $issued = $this->postJson('/api/activity-session/1/preparar-certificados', $this->confirmation())->assertCreated()->json('data');
        $this->patchJson('/api/certificados/update/'.$issued['id_certificado'], ['estado' => 'EMITIDO'])->assertConflict();
        $this->patchJson('/api/certificados-documentos/update/'.$issued['id_certificado_documento'], ['codigo_verificacion' => str_repeat('f', 30)])->assertConflict();
        $this->patchJson('/api/certificados-documentos/update/'.$issued['id_certificado_documento'], ['estado' => 'Anulado'])->assertOk();
        $this->getJson('/api/certificados/verificar/'.$issued['codigo_verificacion'])->assertOk()
            ->assertJsonPath('data.demostracion', true)->assertJsonPath('data.estado_documento', 'Anulado')
            ->assertJsonPath('data.puede_ver_documento', false)->assertJsonPath('data.firma_verificada', false);
        $this->postJson('/api/activity-session/1/preparar-certificados', $this->confirmation())->assertConflict();
        $this->assertDatabaseCount('certificado.certificado', 1);
        $this->assertDatabaseCount('certificado.certificado_documento', 1);
    }

    public function test_an_uncertain_commit_preserves_the_private_file_for_reconciliation(): void
    {
        $this->demonstration();
        $path = 'certificados/demostracion/uncertain.pdf';
        Storage::disk('local')->put($path, '%PDF-1.4 isolated file fixture');
        $generator = app(CertificateDocumentGenerator::class);
        $generator->discardFailedArtifact($path, new \RuntimeException('Commit response unavailable'), true);
        Storage::disk('local')->assertExists($path);
        $generator->discardFailedArtifact($path, new \PDOException('server closed the connection unexpectedly'));
        Storage::disk('local')->assertExists($path);
        $generator->discardFailedArtifact($path, new \RuntimeException('Known rollback'));
        Storage::disk('local')->assertMissing($path);
        $this->assertDatabaseCount('certificado.certificado', 0);
    }

    public function test_generic_crud_cannot_create_a_demo_without_its_confirmed_session_flow(): void
    {
        $this->demonstration();
        $this->postJson('/api/certificados/create', [
            'id_persona' => 1, 'id_participacion' => 1, 'id_tipo_certificado' => 1,
            'fecha_emision' => '2026-11-10', 'estado' => 'Demostracion', 'notificado' => false,
        ])->assertUnprocessable();
        $this->assertDatabaseCount('certificado.certificado', 0);
        $this->assertDatabaseCount('certificado.certificado_documento', 0);
    }
}
