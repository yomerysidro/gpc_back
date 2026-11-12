<?php

namespace App\Http\Controllers\Certification;

use App\Http\Controllers\Controller;
use App\Http\Data\ActivityRequests\ActivityRequestData;
use App\Models\Activity\Activity;
use App\Models\ActivitySession\ActivitySession;
use App\Models\Certificate\Certificate;
use App\Models\CertificateDocument\CertificateDocument;
use App\Models\Participation\Participation;
use App\Services\CertificateManagement;
use App\Services\CertificationReadiness;
use App\Services\SessionCertificatePreparation;
use App\Traits\ApiResponse;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        $session = ActivitySession::with('activity')->find($id);
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
                return $this->information('No se puede finalizar la sesión.', 409, $data);
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
            $conflict = in_array(substr((string) $exception->getCode(), 0, 2), ['23', '40'], true);

            return $this->error($conflict ? 'Conflicto de integridad o concurrencia; vuelva a consultar el recurso.' : 'No se pudo consultar el módulo de certificación.', $conflict ? 409 : 500);
        } catch (Throwable) {
            return $this->error('No se pudo completar la operación de certificación.', 500);
        }
    }
}
