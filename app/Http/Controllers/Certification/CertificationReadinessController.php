<?php

namespace App\Http\Controllers\Certification;

use App\Http\Controllers\Controller;
use App\Http\Data\ActivityRequests\ActivityRequestData;
use App\Models\Activity\Activity;
use App\Models\ActivitySession\ActivitySession;
use App\Models\ActivitySpeaker\ActivitySpeaker;
use App\Models\ActivityType\ActivityType;
use App\Models\Certificate\Certificate;
use App\Models\CertificateDocument\CertificateDocument;
use App\Models\CertificateType\CertificateType;
use App\Models\Participation\Participation;
use App\Models\PersonSpeaker\PersonaPonente;
use App\Models\Practice\Practice;
use App\Models\Recognition\Recognition;
use App\Services\CertificateDocumentWorkflow;
use App\Services\CertificateManagement;
use App\Services\CertificateOrigins;
use App\Services\CertificationReadiness;
use App\Services\RequestReadMemo;
use App\Services\SessionCertificatePreparation;
use App\Services\SessionSpeakers;
use App\Traits\ApiResponse;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class CertificationReadinessController extends Controller
{
    use ApiResponse;

    public function __construct(private CertificationReadiness $readiness) {}

    public function originCatalogs(CertificateOrigins $origins): JsonResponse
    {
        return $this->respond(function () use ($origins): array {
            foreach ([Certificate::class, ActivityType::class, CertificateType::class] as $model) {
                abort_if(Gate::denies('viewAny', $model), 403, 'No autorizado para consultar los catálogos de certificación.');
            }

            return $origins->catalogs();
        });
    }

    public function manageSessionSpeakers(Request $request, int $activity, int $id, SessionSpeakers $speakers): JsonResponse
    {
        return $this->respond(function () use ($request, $activity, $id, $speakers): array {
            abort_if(Gate::denies('viewAny', ActivitySpeaker::class), 403, 'No autorizado para consultar ponentes.');
            if ($request->isMethod('POST')) {
                abort_if(Gate::denies('create', ActivitySpeaker::class), 403, 'No autorizado para vincular ponentes.');
            }
            $session = $this->session($request, $id, 'view');
            abort_unless((int) $session->id_actividad === $activity, 409, 'La sesión no pertenece a la actividad.');
            $speakers->assertSchema();
            if ($request->isMethod('GET')) {
                abort_if(Gate::denies('viewAny', PersonaPonente::class), 403, 'No autorizado para consultar personas ponentes.');

                return ['id_actividad' => $activity, 'id_sesion' => $id,
                    'ponentes_disponibles' => DB::table('gestion.persona_ponente as pp')
                        ->join('gestion.persona as p', 'p.id_persona', '=', 'pp.id_persona')->orderBy('pp.id_persona_ponente')
                        ->get(['pp.id_persona_ponente', 'pp.id_persona', 'p.nombres', 'p.apellido_paterno', 'p.apellido_materno']),
                    'vinculos' => $speakers->assigned($activity, $id)];
            }
            if ($request->isMethod('POST')) {
                abort_if(array_diff(array_keys($request->all()), ['id_persona_ponente']) !== [], 422, 'Campos no admitidos.');
                $input = Validator::make($request->all(), ['id_persona_ponente' => 'required|integer|min:1'])->validate();

                return ['vinculo' => $speakers->link($activity, $id, $input['id_persona_ponente'])];
            }
            abort_if(array_diff(array_keys($request->all()), ['id_actividad_ponente']) !== [], 422, 'Campos no admitidos.');
            $input = Validator::make($request->all(), ['id_actividad_ponente' => 'required|integer|min:1'])->validate();
            $assignment = ActivitySpeaker::find($input['id_actividad_ponente']);
            abort_unless($assignment, 404, 'Vínculo no encontrado.');
            abort_if(Gate::denies('delete', $assignment), 403, 'No autorizado para desvincular ponentes.');
            $speakers->unlink($activity, $id, $input['id_actividad_ponente']);

            return ['desvinculado' => true];
        });
    }

    public function sessionSpeakers(Request $request, int $id, CertificateOrigins $origins): JsonResponse
    {
        return $this->respond(function () use ($request, $id, $origins): array {
            abort_if(Gate::denies('viewAny', Certificate::class) || Gate::denies('viewAny', ActivitySpeaker::class),
                403, 'No autorizado para consultar ponentes de certificación.');
            $session = $this->session($request, $id, 'view');

            $data = $origins->sessionSpeakers($id, (int) $session->id_actividad);
            $data['modalidad_reconocimiento'] = 'sesion';
            foreach ($data['ponentes_sesion'] ?? [] as $speaker) {
                $speaker->reconocimiento = $this->activitySpeakerEvaluation($origins, (int) $speaker->id_actividad_ponente, true, $speaker->reconocimiento);
            }
            if ($data['ponentes_sesion'] !== null) {
                $data['puede_preparar'] = $data['ponentes_sesion']->contains(fn (object $speaker): bool => $speaker->reconocimiento['puede_preparar']);
                $data['puede_generar_pdf'] = $data['puede_preparar'];
            }
            foreach ($data['ponentes_actividad'] as $speaker) {
                $speaker->reconocimiento_por_actividad = $this->activitySpeakerEvaluation($origins, (int) $speaker->id_actividad_ponente);
            }

            return $data;
        });
    }

    public function activitySpeakers(Request $request, int $id, CertificateOrigins $origins): JsonResponse
    {
        return $this->respond(function () use ($request, $id, $origins): array {
            abort_if(Gate::denies('viewAny', Activity::class) || Gate::denies('viewAny', ActivitySpeaker::class)
                || Gate::denies('viewAny', Certificate::class), 403, 'No autorizado.');
            $activity = Activity::find($id);
            abort_unless($activity, 404, 'Actividad no encontrada.');
            abort_if(Gate::denies('view', $activity), 403, 'No autorizado.');
            $this->checkArea($request, (int) $activity->id_area);
            $rows = $origins->sessionSpeakers(null, $id)['ponentes_actividad'];

            return ['modalidad_reconocimiento' => 'actividad', 'id_actividad' => $id, 'id_sesion' => null,
                'ponentes' => $rows->map(fn (object $row): array => [...(array) $row,
                    'reconocimiento' => $this->activitySpeakerEvaluation($origins, (int) $row->id_actividad_ponente)])->all()];
        });
    }

    /** @return array<string, mixed> */
    private function activitySpeakerEvaluation(CertificateOrigins $origins, int $id, bool $bySession = false, ?array $evaluation = null): array
    {
        $data = $evaluation ?? $origins->activityRecognition($id, $bySession);
        $allowed = Gate::allows('create', Certificate::class) && Gate::allows('create', CertificateDocument::class)
            && Gate::allows('create', Recognition::class);
        if ($data['puede_preparar'] && ! $allowed) {
            $data['motivos_bloqueo'][] = 'Se requieren permisos efectivos para crear certificados, documentos y reconocimientos.';
        }
        $data['puede_preparar'] = $data['puede_preparar'] && $allowed;
        $data['puede_generar_pdf'] = $data['puede_preparar'];

        return $data;
    }

    public function practiceReadiness(Request $request, int $id, CertificateOrigins $origins): JsonResponse
    {
        return $this->respond(function () use ($request, $id, $origins): array {
            abort_if(Gate::denies('viewAny', Certificate::class) || Gate::denies('viewAny', Practice::class),
                403, 'No autorizado para consultar prácticas de certificación.');
            $practice = Practice::find($id);
            abort_unless($practice, 404, 'Práctica no encontrada.');
            abort_if(Gate::denies('view', $practice), 403, 'No autorizado para consultar esta práctica.');
            $this->checkArea($request, (int) $practice->id_area);

            $data = $origins->practice($id);
            $this->applyPreparationPermission($data);
            $data['puede_generar_pdf'] = $data['puede_preparar'] && Gate::allows('create', CertificateDocument::class);
            $data['puede_preparar'] = $data['puede_generar_pdf'];
            $data['texto_editable'] = $data['puede_preparar'];

            return $data;
        });
    }

    public function finalizePractice(Request $request, int $id, CertificateOrigins $origins): JsonResponse
    {
        return $this->respond(function () use ($request, $id, $origins): array {
            abort_if($request->all() !== [], 422, 'La finalización no admite cambios de identidad, fechas ni estado desde el cliente.');

            $data = $origins->finalizePractice($id, $request->user());
            $data['puede_preparar'] = $data['puede_preparar'] && Gate::allows('create', Certificate::class)
                && Gate::allows('create', CertificateDocument::class);
            $data['puede_generar_pdf'] = $data['puede_preparar'];
            $data['texto_editable'] = $data['puede_preparar'];

            return $data;
        });
    }

    public function practicesReadiness(Request $request, CertificateOrigins $origins): JsonResponse
    {
        return $this->respond(function () use ($request, $origins): array {
            abort_unless(Gate::allows('viewAny', Certificate::class) && Gate::allows('viewAny', Practice::class),
                403, 'No autorizado para consultar prácticas de certificación.');
            $areas = null;
            if (! $request->user()->isSystemAdministrator()) {
                $result = ActivityRequestData::authorizedAreas((int) $request->user()->id_persona);
                abort_unless($result['success'], $result['status'] ?? 500, 'No se pudo comprobar el ámbito.');
                $areas = $result['data']->pluck('id_area')->all();
            }
            $practices = Practice::query()->when($areas !== null, fn ($query) => $query->whereIn('id_area', $areas))
                ->orderBy('id_practica')->get();

            return ['practicas' => $practices->filter(fn (Practice $practice): bool => Gate::allows('view', $practice))
                ->map(function (Practice $practice) use ($origins): array {
                    $data = $origins->practice((int) $practice->id_practica);
                    $this->applyPreparationPermission($data);
                    $data['puede_preparar'] = $data['puede_preparar'] && Gate::allows('create', CertificateDocument::class);
                    $data['puede_generar_pdf'] = $data['puede_preparar'];
                    $data['texto_editable'] = $data['puede_preparar'];

                    return $data;
                })->values()];
        });
    }

    public function recognitionReadiness(Request $request, int $id, CertificateOrigins $origins): JsonResponse
    {
        return $this->respond(function () use ($request, $id, $origins): array {
            abort_if(Gate::denies('viewAny', Certificate::class), 403, 'No autorizado.');
            $origins->authorize('reconocimiento', $id, $request->user());
            $data = $origins->recognition($id);
            $this->applyPreparationPermission($data);
            $data['puede_generar_pdf'] = $data['puede_preparar'] && Gate::allows('create', CertificateDocument::class);
            $data['puede_preparar'] = $data['puede_generar_pdf'];
            $data['texto_editable'] = $data['puede_preparar'];

            return [...$data, ...app(CertificateDocumentWorkflow::class)->recognitionSummary($id, $request->user(), $data['contexto_origen_real'])];
        })->header('Cache-Control', 'private, no-store');
    }

    public function prepareOrigin(Request $request, string $origen, int $id, CertificateManagement $management): JsonResponse
    {
        return $this->respond(function () use ($request, $origen, $id, $management): array {
            if ($request->isMethod('GET')) {
                abort_unless($request->input('accion') === 'consultar_preparacion', 422, 'GET sólo permite consultar la preparación.');
            }
            $rules = ['accion' => 'required|in:consultar_preparacion,revisar_documento,confirmar_preparacion',
                'token_revision' => 'required_if:accion,confirmar_preparacion|prohibited_if:accion,revisar_documento|string|regex:/^[a-f0-9]{64}$/D',
                'confirmar' => $request->input('accion') === 'confirmar_preparacion' ? 'required|accepted' : 'prohibited'];
            if (in_array($origen, ['ponente_actividad', 'ponente_sesion'], true)) {
                $rules['modalidad_reconocimiento'] = 'required|in:actividad,sesion';
                $rules['id_sesion'] = 'required_if:modalidad_reconocimiento,sesion|prohibited_if:modalidad_reconocimiento,actividad|integer|min:1';
            }
            if (in_array($origen, ['ponente_sesion', 'practica', 'reconocimiento'], true)) {
                $hasText = array_key_exists('mensaje', $request->all()) || array_key_exists('motivo', $request->all());
                foreach ($origen === 'practica' ? ['mensaje' => 1200] : ['mensaje' => 1200, 'motivo' => 500] as $field => $limit) {
                    $rules[$field] = [$hasText && ! ($origen === 'reconocimiento' && $field === 'motivo') ? 'required' : 'sometimes', 'string', 'max:'.$limit,
                        function (string $attribute, mixed $value, \Closure $fail): void {
                            if (is_string($value) && (preg_match('/[^\p{Z}\s\p{Cf}]/u', $value) !== 1
                                || preg_match('/<[^>]*>|[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $value))) {
                                $fail('El texto debe ser texto plano no vacío, sin HTML ni caracteres de control.');
                            }
                        }];
                }
            }
            abort_if(array_diff(array_keys($request->all()), array_keys($rules)) !== [], 422, 'La acción contiene campos no admitidos.');
            $input = Validator::make($request->all(), $rules)->validate();
            if ($input['accion'] === 'consultar_preparacion') {
                if ($origen === 'reconocimiento') {
                    abort_if(isset($input['token_revision']) || isset($input['mensaje']) || isset($input['motivo']), 422, 'La consulta no admite revisión ni cambios.');

                    return $management->consultRecognition($id, $request->user());
                }
                if ($origen === 'practica') {
                    abort_if(isset($input['token_revision']) || isset($input['mensaje']), 422, 'La consulta no admite revisión ni cambios.');

                    return $management->consultPractice($id, $request->user());
                }
                abort_unless($origen === 'ponente_sesion' && $input['modalidad_reconocimiento'] === 'sesion', 422,
                    'La consulta corresponde a reconocimientos por sesión.');
                abort_if(isset($input['token_revision']) || isset($input['mensaje']) || isset($input['motivo']), 422,
                    'La consulta no admite revisión ni cambios de texto.');
                $origins = app(CertificateOrigins::class);
                $origins->authorize($origen, $id, $request->user());
                $assignment = $origins->assignment($id);
                $this->session($request, $input['id_sesion'], 'view');
                abort_unless((int) $assignment->id_actividad_sesion === (int) $input['id_sesion'], 409,
                    'El vínculo no acredita esta sesión.');

                return $management->consultSessionRecognition($id, $request->user());
            }
            if ($origen === 'ponente_sesion') {
                abort_unless(Gate::allows('create', Certificate::class) && Gate::allows('create', CertificateDocument::class)
                    && Gate::allows('create', Recognition::class), 403, 'No autorizado para preparar reconocimientos.');
                abort_unless($input['modalidad_reconocimiento'] === 'sesion', 422, 'Esta ruta exige modalidad sesion.');
                app(CertificateOrigins::class)->authorize($origen, $id, $request->user());
                $assignment = app(CertificateOrigins::class)->assignment($id);
                $this->session($request, $input['id_sesion'], 'view');
                abort_unless((int) $assignment->id_actividad_sesion === (int) $input['id_sesion'], 409, 'El vínculo no acredita esta sesión.');
            }
            if ($origen === 'ponente_actividad' && $input['modalidad_reconocimiento'] === 'sesion') {
                abort_unless(Gate::allows('create', Certificate::class) && Gate::allows('create', CertificateDocument::class)
                    && Gate::allows('create', Recognition::class), 403, 'No autorizado para preparar reconocimientos.');
                app(CertificateOrigins::class)->authorize($origen, $id, $request->user());
                $session = ActivitySession::find($input['id_sesion']);
                abort_unless($session, 404, 'Sesión no encontrada.');
                $assignment = app(CertificateOrigins::class)->assignment($id);
                abort_unless((int) $session->id_actividad === (int) $assignment->id_actividad, 409, 'La sesión no pertenece a la actividad del ponente.');
                abort(409, 'Falta una referencia persistente del ponente a actividad_sesion; no se convertirá el reconocimiento por sesión en uno por actividad.');
            }

            $text = in_array($origen, ['ponente_sesion', 'reconocimiento'], true) && isset($input['mensaje'])
                ? array_intersect_key($input, array_flip(['mensaje', 'motivo'])) : null;
            if ($origen === 'practica' && isset($input['mensaje'])) {
                $text = ['mensaje' => $input['mensaje']];
            }

            return $input['accion'] === 'revisar_documento'
                ? $management->reviewOrigin($origen, $id, $request->user(), $text)
                : $management->confirmOrigin($origen, $id, $input['token_revision'], $request->user(), $text);
        })->header('Cache-Control', 'private, no-store');
    }

    public function pending(Request $request): JsonResponse
    {
        return $this->respond(function () use ($request): array {
            abort_if(Gate::denies('viewAny', Certificate::class) || Gate::denies('viewAny', Activity::class), 403, 'No autorizado.');
            $filters = $this->filters($request);
            if (($filters['origen'] ?? 'sesion') === 'actividad' && isset($filters['id_sesion'])) {
                throw ValidationException::withMessages(['id_sesion' => 'El origen actividad no admite un filtro de sesión.']);
            }

            $data = $this->readiness->pending($filters, $this->areas($request));
            foreach ($data['data'] as &$row) {
                $this->applyPreparationPermission($row);
            }

            return $data;
        });
    }

    public function sessionBeneficiaries(Request $request, int $id): JsonResponse
    {
        return $this->respond(function () use ($request, $id): array {
            $session = $this->session($request, $id, 'view');
            $this->authorizeBeneficiaries();
            $filters = $this->filters($request);
            $this->validateContext($filters, (int) $session->id_actividad, $id, 'sesion');
            $activity = $session->activity;
            $context = (object) [
                'id_actividad' => (int) $activity->id_actividad, 'nombre_actividad' => $activity->nombre,
                'estado_actividad' => $activity->estado, 'id_sesion' => $id,
                'nombre_sesion' => $session->nombre, 'estado_sesion' => $session->estado,
            ];
            $data = $this->readiness->beneficiaries($context, 'sesion', $filters);
            $summary = (object) $session->getAttributes();
            $this->readiness->addAttendanceCounters([$summary]);
            $data['contadores'] = $summary->contadores;
            $blockers = $this->readiness->closureBlockers((object) $session->getAttributes());
            $data['puede_finalizarse'] = $session->estado !== CertificationReadiness::FINAL_SESSION_STATE
                && Gate::allows('update', $session) && $blockers === [];
            $data['motivos_bloqueo_finalizacion'] = $blockers;
            $data['puede_consultar_beneficiarios'] = true;
            $this->applyPreparationPermission($data);

            return $data;
        });
    }

    public function activityBeneficiaries(Request $request, int $id): JsonResponse
    {
        return $this->respond(function () use ($request, $id): array {
            abort_if(Gate::denies('viewAny', Activity::class), 403, 'No autorizado.');
            $activity = Activity::find($id);
            abort_unless($activity, 404, 'Actividad no encontrada.');
            abort_if(Gate::denies('view', $activity), 403, 'No autorizado.');
            $this->checkArea($request, (int) $activity->id_area);
            $this->authorizeBeneficiaries();
            $filters = $this->filters($request);
            $this->validateContext($filters, $id, null, 'actividad');

            return $this->readiness->beneficiaries((object) [
                'id_actividad' => $id, 'nombre_actividad' => $activity->nombre, 'estado_actividad' => $activity->estado,
            ], 'actividad', $filters);
        });
    }

    public function finalize(Request $request, int $id): JsonResponse
    {
        return $this->respond(function () use ($request, $id): array {
            $session = $this->session($request, $id, 'update');
            Validator::make($request->all(), [
                'estado' => 'prohibited', 'id_usuario' => 'prohibited', 'id_persona' => 'prohibited',
                'id_actividad' => 'sometimes|integer|min:1', 'id_sesion' => 'sometimes|integer|min:1',
                'origen' => 'sometimes|in:sesion',
            ])->validate();
            $this->validateContext($request->all(), (int) $session->id_actividad, $id, 'sesion');

            return $this->readiness->finalize($id, (int) $request->user()->getAuthIdentifier(), $this->areas($request));
        }, true);
    }

    public function prepare(Request $request, int $id): JsonResponse
    {
        $executionLimit = (int) ini_get('max_execution_time');
        $preparationLimit = max(30, min(300, (int) config('certification.preparation.execution_timeout_seconds', 120)));
        if ($executionLimit > 0 && $executionLimit < $preparationLimit) {
            set_time_limit($preparationLimit);
        }

        return $this->respond(function () use ($request, $id): array {
            $session = $this->session($request, $id, 'view');
            $this->authorizeBeneficiaries();
            abort_if(Gate::denies('create', Certificate::class), 403, 'No autorizado para preparar certificados.');
            if (in_array($request->input('accion'), ['revisar_documento', 'confirmar_preparacion'], true)
                || ($request->input('accion') === 'enviar_gerencia' && ($request->has('token_revision') || $request->has('id_certificado_documento')))) {
                return $this->administrativePreparation($request, $id);
            }
            if ($request->has('accion')) {
                $input = Validator::make($request->all(), [
                    'accion' => 'required|in:vista_previa,emitir_demostracion,enviar_gerencia',
                    'id_participacion' => 'required|integer|min:1',
                    'id_tipo_certificado' => 'sometimes|integer|min:1',
                    'id_actividad' => 'sometimes|integer|min:1', 'id_sesion' => 'sometimes|integer|min:1',
                    'confirmar' => in_array($request->input('accion'), ['emitir_demostracion', 'enviar_gerencia'], true) ? 'required|accepted' : 'prohibited',
                    'huella_composicion' => $request->input('accion') === 'enviar_gerencia' ? 'required|string|regex:/^[a-f0-9]{64}$/D' : 'prohibited',
                    'formato' => $request->input('accion') === 'vista_previa' ? 'sometimes|in:html,pdf' : 'prohibited',
                    'mensaje' => in_array($request->input('accion'), ['vista_previa', 'enviar_gerencia'], true) ? [
                        $request->input('accion') === 'vista_previa' ? 'sometimes' : 'required', 'required', 'string', 'max:1200',
                        function (string $attribute, mixed $value, \Closure $fail): void {
                            if (is_string($value) && (trim($value) === '' || preg_match('/<[^>]*>|[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $value))) {
                                $fail('El mensaje debe ser texto plano, sin HTML ni caracteres de control.');
                            }
                        },
                    ] : 'prohibited',
                ])->validate();
                $unexpected = array_diff(array_keys($request->all()), ['accion', 'id_participacion', 'id_tipo_certificado', 'id_actividad', 'id_sesion', 'confirmar', 'mensaje', 'formato', 'huella_composicion']);
                abort_if($unexpected !== [], 422, 'La operación solo acepta selección y confirmación; los datos del certificado los obtiene el servidor.');
                $this->validateContext($input, (int) $session->id_actividad, $id, 'sesion');
                $service = app(SessionCertificatePreparation::class);
                $typeId = $service->resolveType(isset($input['id_tipo_certificado']) ? (int) $input['id_tipo_certificado'] : null);
                if (in_array($input['accion'], ['vista_previa', 'enviar_gerencia'], true)
                    && Gate::allows('create', CertificateDocument::class)) {
                    $management = app(CertificateManagement::class);
                    $existing = $management->recoverPreparation($id, (int) $input['id_participacion'], $typeId, $request->user());
                    if ($existing !== null) {
                        return ['modo' => 'administrativo', 'accion' => $input['accion'], ...($input['accion'] === 'enviar_gerencia'
                            ? $management->remit((int) $existing['id_certificado_documento'], $request->user(), $id) : $existing)];
                    }
                }
                if ($input['accion'] === 'vista_previa') {
                    $data = $service->preview($id, (int) $input['id_participacion'], $typeId, $this->areas($request), $input['mensaje'] ?? null, ($input['formato'] ?? 'html') === 'pdf');
                    $data['puede_emitir_demostracion'] = $data['puede_emitir_demostracion'] && Gate::allows('create', CertificateDocument::class);
                    if (Gate::denies('create', CertificateDocument::class)) {
                        $data['puede_revisar_documento_para_firma'] = false;
                        $data['siguiente_accion_preparacion'] = null;
                        $data['motivos_bloqueo_preparacion_persistente'][] = 'No dispone de permiso para registrar documentos.';
                    }

                    return $data;
                }
                abort_if(Gate::denies('create', CertificateDocument::class), 403, 'No autorizado para registrar documentos de demostración.');
                if ($input['accion'] === 'enviar_gerencia') {
                    return $service->sendToManagement($id, (int) $input['id_participacion'], $typeId, $this->areas($request), $input['mensaje'], $input['huella_composicion']);
                }

                return $service->issueDemonstration($id, (int) $input['id_participacion'], $typeId, $request->user(), $this->areas($request));
            }
            $input = Validator::make($request->all(), [
                'id_tipo_certificado' => 'required|integer|min:1',
                'id_actividad' => 'sometimes|integer|min:1', 'id_sesion' => 'sometimes|integer|min:1',
                'origen' => 'sometimes|in:sesion',
                'estado' => 'prohibited', 'fecha_emision' => 'prohibited',
                'id_usuario' => 'prohibited', 'id_persona' => 'prohibited',
                'id_preparacion' => 'prohibited', 'id_certificado' => 'prohibited', 'id_participacion' => 'prohibited',
                'codigo_verificacion' => 'prohibited', 'hash_certificado' => 'prohibited', 'ruta_archivo' => 'prohibited',
                'mensaje' => 'prohibited', 'formato' => 'prohibited',
            ])->validate();
            $this->validateContext($input, (int) $session->id_actividad, $id, 'sesion');

            if ($this->readiness->usesDevelopmentPolicy()) {
                $blockers = $this->readiness->preparationBlockers('sesion');
                if ($blockers !== []) {
                    return [
                        'origen' => 'sesion', 'modo' => 'desarrollo', 'oficial' => false,
                        'id_actividad' => (int) $session->id_actividad, 'id_sesion' => $id,
                        'id_tipo_certificado' => (int) $input['id_tipo_certificado'],
                        'estado_preparacion' => 'Bloqueada', 'preparaciones' => [],
                        'codigo_bloqueo' => null,
                        'puede_preparar' => false, 'puede_generar_pdf' => false, 'puede_emitir' => false, 'puede_firmar' => false,
                        'motivos_bloqueo' => $blockers,
                        'motivos_bloqueo_emision' => [CertificationReadiness::OFFICIAL_ISSUANCE_BLOCKER],
                    ];
                }

                return app(SessionCertificatePreparation::class)->prepare($id, (int) $input['id_tipo_certificado'],
                    (int) $request->user()->getAuthIdentifier(), $this->areas($request), (int) $session->id_actividad);
            }

            return [
                'origen' => 'sesion', 'id_actividad' => (int) $session->id_actividad,
                'id_sesion' => $id, 'id_tipo_certificado' => $input['id_tipo_certificado'],
                'puede_preparar' => false, 'puede_emitir' => false, 'puede_firmar' => false,
                'estado_preparacion' => 'Bloqueada', 'certificado_sesion' => null,
                'motivos_bloqueo' => $this->readiness->preparationBlockers('sesion'),
            ];
        }, preparing: true)->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }

    private function session(Request $request, int $id, string $ability): ActivitySession
    {
        abort_if(Gate::denies('viewAny', ActivitySession::class), 403, 'No autorizado.');
        $session = RequestReadMemo::remember(ActivitySession::class.'.'.$id, fn (): ?ActivitySession => ActivitySession::find($id));
        $session?->setRelation('activity', RequestReadMemo::remember(Activity::class.'.'.$session->id_actividad,
            fn (): ?Activity => Activity::find($session->id_actividad)));
        abort_unless($session, 404, 'Sesión no encontrada.');
        abort_if(Gate::denies($ability, $session), 403, 'No autorizado.');
        $this->checkArea($request, (int) $session->activity?->id_area);

        return $session;
    }

    /** @return array<string, mixed> */
    private function administrativePreparation(Request $request, int $sessionId): array
    {
        $reviewing = $request->input('accion') === 'revisar_documento';
        $byDocument = $request->input('accion') === 'enviar_gerencia' && $request->has('id_certificado_documento');
        $rules = $reviewing ? [
            'accion' => 'required|in:revisar_documento', 'id_participacion' => 'required|integer|min:1',
            'id_tipo_certificado' => 'required|integer|min:1',
            'firmantes' => 'sometimes|array|min:1|max:8',
            'firmantes.*' => 'required|array:id_persona_trabajador,orden_firma',
            'firmantes.*.id_persona_trabajador' => 'required|integer|min:1|distinct',
            'firmantes.*.orden_firma' => 'required|integer|min:1|distinct',
            'modalidad_firma' => (Schema::hasColumn('certificado.certificado_documento', 'estado_flujo') ? 'required' : 'sometimes').'|in:Paralela,Secuencial',
            'mensaje' => ['required', 'string', 'max:1200', function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && (trim($value) === '' || preg_match('/<[^>]*>|[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $value))) {
                    $fail('El mensaje debe ser texto plano, sin HTML ni caracteres de control.');
                }
            }],
        ] : [
            'accion' => 'required|in:confirmar_preparacion,enviar_gerencia', 'confirmar' => 'required|accepted',
            ...($byDocument ? ['id_certificado_documento' => 'required|integer|min:1'] : ['token_revision' => 'required|string|regex:/^[a-f0-9]{64}$/D']),
        ];
        abort_if(array_diff(array_keys($request->all()), array_keys($rules)) !== [], 422, 'La acción contiene campos no admitidos.');
        $input = Validator::make($request->all(), $rules)->validate();
        $service = app(CertificateManagement::class);
        if ($reviewing) {
            return $service->review($sessionId, (int) $input['id_participacion'], (int) $input['id_tipo_certificado'],
                $input['mensaje'], $input['modalidad_firma'] ?? null, $request->user(), $input['firmantes'] ?? null);
        }
        $result = $byDocument ? $service->remit((int) $input['id_certificado_documento'], $request->user(), $sessionId)
            : $service->confirm($sessionId, $input['token_revision'], $request->user(), $input['accion'] === 'enviar_gerencia');

        return ['modo' => 'administrativo', 'accion' => $input['accion'], ...$result];
    }

    private function authorizeBeneficiaries(): void
    {
        abort_if(Gate::denies('viewAny', Certificate::class) || Gate::denies('viewAny', Participation::class), 403, 'No autorizado para consultar beneficiarios de certificación.');
    }

    /** @param array<string, mixed> $data */
    private function applyPreparationPermission(array &$data): void
    {
        if (($data['puede_emitir_demostracion'] ?? false)
            && (Gate::denies('create', Certificate::class) || Gate::denies('create', CertificateDocument::class))) {
            $data['puede_emitir_demostracion'] = false;
        }
        if (($data['puede_preparar'] ?? false) && Gate::denies('create', Certificate::class)) {
            $data['puede_preparar'] = false;
            $data['motivos_bloqueo'][] = 'No dispone del permiso efectivo para preparar certificados.';
        }
    }

    /** @return list<int>|null */
    private function areas(Request $request): ?array
    {
        if ($request->user()->isSystemAdministrator()) {
            return null;
        }
        $result = ActivityRequestData::authorizedAreas((int) $request->user()->id_persona);
        abort_unless($result['success'], $result['status'] ?? 500, $result['message']);

        return $result['data']->pluck('id_area')->map(fn (mixed $id): int => (int) $id)->all();
    }

    private function checkArea(Request $request, int $areaId): void
    {
        $areas = $this->areas($request);
        abort_if($areas !== null && ! in_array($areaId, $areas, true), 403, 'Actividad fuera del ámbito autorizado.');
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        return Validator::make($request->query(), [
            'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100',
            'origen' => 'sometimes|in:sesion,actividad', 'id_actividad' => 'sometimes|integer|min:1',
            'id_sesion' => 'sometimes|integer|min:1', 'id_tipo_certificado' => 'sometimes|integer|min:1',
            'estado_certificacion' => 'sometimes|in:Bloqueada',
        ])->validate();
    }

    /** @param array<string, mixed> $filters */
    private function validateContext(array $filters, int $activityId, ?int $sessionId, string $origin): void
    {
        if ((isset($filters['id_actividad']) && (int) $filters['id_actividad'] !== $activityId)
            || (isset($filters['id_sesion']) && (int) $filters['id_sesion'] !== $sessionId)
            || (isset($filters['origen']) && $filters['origen'] !== $origin)) {
            throw ValidationException::withMessages(['contexto' => 'Los identificadores y el origen no corresponden al recurso solicitado.']);
        }
    }

    private function respond(callable $operation, bool $finalizing = false, bool $preparing = false): JsonResponse
    {
        try {
            $data = $operation();
            if (array_key_exists('practica_finalizada', $data)) {
                return $data['practica_finalizada']
                    ? $this->ok($data, 'Práctica finalizada; no se ha generado ninguna constancia.')
                    : $this->information('No se puede finalizar la práctica.', 409, $data);
            }
            if (($data['modo'] ?? null) === 'administrativo') {
                return $this->ok($data, ($data['enviado'] ?? false) ? 'Documento remitido a Gerencia; pendiente de firma.'
                    : 'Documento revisado o preparado; no emitido ni firmado.', ($data['creado'] ?? false) ? 201 : 200);
            }
            if (($data['accion'] ?? null) === 'enviar_gerencia') {
                return $this->information('La vista previa no es un documento registrado. Confirme la preparación y use revisar-envio y enviar-gerencia.', 409, $data);
            }
            if ($preparing && ($data['modo'] ?? null) !== 'demostracion' && (($data['modo'] ?? null) !== 'desarrollo' || ! ($data['puede_preparar'] ?? false))) {
                return $this->information(($data['modo'] ?? null) === 'desarrollo'
                    ? 'No se puede evaluar la preparación por sesión.' : 'La preparación por sesión requiere soporte institucional oficial.', 409, $data);
            }
            if ($finalizing && ! $data['finalizada']) {
                return $this->information($data['motivos_bloqueo'][0] ?? 'No se puede finalizar la sesión.', 409, $data);
            }

            $message = $finalizing ? 'Sesión finalizada; la actividad conserva su estado.'
                : ($preparing ? (($data['modo'] ?? null) === 'demostracion'
                    ? (($data['emitida'] ?? false) ? 'Certificado de demostración almacenado o recuperado; sin firma institucional.' : 'Vista previa obtenida; no se ha emitido ni registrado un certificado.')
                    : 'Candidatos evaluados dinámicamente; preparación sin persistencia ni emisión.') : 'Evaluación de certificación obtenida correctamente.');

            return $this->ok($data, $message, $preparing && (($data['creado'] ?? false) || ($data['preparaciones_creadas'] ?? 0) > 0) ? 201 : 200);
        } catch (ValidationException $exception) {
            return $this->information('Datos de validación incorrectos.', 422, $exception->errors());
        } catch (HttpException $exception) {
            return $this->error($exception->getMessage(), $exception->getStatusCode());
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() === '55P03') {
                return $this->error('El origen está ocupado por otra operación; vuelva a consultar.', 409);
            }
            if (str_starts_with((string) $exception->getCode(), '08') || (string) $exception->getCode() === '57014') {
                return $this->error('La base de datos no está disponible temporalmente; vuelva a intentar.', 503);
            }
            $conflict = in_array(substr((string) $exception->getCode(), 0, 2), ['23', '40'], true);
            if (! $conflict) {
                report($exception);
            }

            return $this->error($conflict ? 'Conflicto de integridad o concurrencia; vuelva a consultar el recurso.' : 'No se pudo consultar el módulo de certificación.', $conflict ? 409 : 500);
        } catch (Throwable $exception) {
            report($exception);

            return $this->error('No se pudo completar la operación de certificación.', 500);
        }
    }
}
