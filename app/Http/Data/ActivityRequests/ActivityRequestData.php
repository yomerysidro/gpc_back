<?php

namespace App\Http\Data\ActivityRequests;

use App\Models\ActivityRequest;
use App\Models\ActivityRequestDecision;
use App\Models\User\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class ActivityRequestData
{
    private const REQUESTS = 'certificado.solicitud_actividad';

    private const DECISIONS = 'certificado.solicitud_decision';

    private const STATES = [
        'Borrador',
        'Enviada',
        'En revisión',
        'Observada',
        'Pendiente de aprobación',
        'Aprobada',
        'Rechazada',
    ];

    /** @return array{success: bool, message: string, data: mixed, status: int} */
    public static function authorizedRequestAreas(User $user): array
    {
        if (! $user->canRepresentAllRequestAreas()) {
            return self::authorizedAreas((int) $user->id_persona);
        }

        return self::execute(fn (): object => DB::table('gestion.area')->select('id_area', 'nombre')
            ->orderBy('nombre')->orderBy('id_area')->get(), message: 'Áreas autorizadas para solicitudes consultadas correctamente.');
    }

    /** @return array{success: bool, message: string, data: mixed, status: int} */
    public static function authorizedAreas(int $personId): array
    {
        return self::execute(fn (): object => DB::table('gestion.area as area')
            ->select('area.id_area', 'area.nombre')
            ->whereExists(function (Builder $workers) use ($personId): void {
                $workers->selectRaw('1')->from('gestion.persona_trabajador as worker')
                    ->whereColumn('worker.id_area', 'area.id_area')->where('worker.id_persona', $personId)
                    ->where('worker.estado', true)->whereDate('worker.fecha_ingreso', '<=', today())
                    ->where(fn (Builder $query): Builder => $query
                        ->whereNull('worker.fecha_fin')->orWhereDate('worker.fecha_fin', '>=', today()));
            })
            ->orderBy('area.nombre')->orderBy('area.id_area')->get(),
            message: 'Áreas autorizadas para solicitudes consultadas correctamente.',
        );
    }

    /** @param array<string, mixed> $input */
    public static function createDraft(int $userId, array $input): array
    {
        return self::execute(fn (): object => DB::transaction(function () use ($userId, $input): object {
            $data = self::validate($input, false);
            $id = DB::table(self::REQUESTS)->insertGetId([
                ...$data,
                'id_usuario_solicitante' => $userId,
                'estado' => 'Borrador',
                'fecha_solicitud' => now(),
            ], 'id_solicitud_actividad');

            return self::request($id);
        }), 201);
    }

    /** @param array<string, mixed> $input */
    public static function updateDraft(int $userId, int $requestId, array $input, ?array $areaIds = null): array
    {
        return self::execute(fn (): object => DB::transaction(function () use ($userId, $requestId, $input, $areaIds): object {
            $request = self::lockedRequest($requestId, $areaIds);
            abort_unless((int) $request->id_usuario_solicitante === $userId, 404, 'Solicitud no encontrada.');
            abort_unless(in_array($request->estado, ['Borrador', 'Observada'], true), 409, 'Solo se pueden modificar solicitudes en borrador u observadas.');
            $data = self::validate(array_replace((array) $request, $input), false);
            $data = array_intersect_key($data, $input);
            if ($data !== []) {
                DB::table(self::REQUESTS)->where('id_solicitud_actividad', $requestId)->update($data);
            }

            return self::request($requestId);
        }));
    }

    public static function submit(int $userId, int $requestId, ?array $areaIds = null): array
    {
        return self::transition($userId, $requestId, ['Borrador', 'Observada'], 'Enviada', 'ENVIAR', null, true, $areaIds);
    }

    /** @param array<string, mixed> $filters */
    public static function ownRequests(int $userId, array $filters = [], ?array $areaIds = null): array
    {
        return self::list($filters, $userId, $areaIds);
    }

    /** @param array<string, mixed> $filters */
    public static function list(array $filters = [], ?int $ownerId = null, ?array $areaIds = null, ?array $states = null): array
    {
        $validated = Validator::make($filters, [
            'estado' => ['sometimes', 'string', 'in:'.implode(',', self::STATES)],
            'id_area' => ['sometimes', 'integer', 'min:1'],
            'fecha_desde' => ['sometimes', 'date_format:Y-m-d'],
            'fecha_hasta' => ['sometimes', 'date_format:Y-m-d', Rule::when(isset($filters['fecha_desde']), ['after_or_equal:fecha_desde'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ])->validate();

        $query = DB::table(self::REQUESTS)->orderByDesc('fecha_solicitud')->orderByDesc('id_solicitud_actividad');
        if ($areaIds !== null) {
            $query->whereIn('id_area', $areaIds);
        }
        if ($states !== null) {
            $query->where(function (Builder $query) use ($states): void {
                $query->whereIn('estado', $states)->orWhereExists(function (Builder $history): void {
                    $history->selectRaw('1')->from(self::DECISIONS.' as routing')
                        ->whereColumn('routing.id_solicitud_actividad', self::REQUESTS.'.id_solicitud_actividad')
                        ->where('routing.tipo_decision', 'DERIVAR_GERENCIA');
                });
            });
        }
        if ($ownerId !== null) {
            $query->where('id_usuario_solicitante', $ownerId);
        }
        foreach (['estado', 'id_area'] as $field) {
            if (isset($validated[$field])) {
                $query->where($field, $validated[$field]);
            }
        }
        if (isset($validated['fecha_desde'])) {
            $query->whereDate('fecha_solicitud', '>=', $validated['fecha_desde']);
        }
        if (isset($validated['fecha_hasta'])) {
            $query->whereDate('fecha_solicitud', '<=', $validated['fecha_hasta']);
        }

        return self::execute(
            function () use ($query, $validated): object {
                $page = $query->paginate($validated['per_page'] ?? 20, ['*'], 'page', $validated['page'] ?? 1);
                self::addRequestNames($page->items());

                return $page;
            },
            message: 'Solicitudes consultadas correctamente.',
        );
    }

    public static function detail(int $userId, int $requestId): array
    {
        return self::execute(function () use ($userId, $requestId): object {
            $request = DB::table(self::REQUESTS)->where('id_solicitud_actividad', $requestId)
                ->where('id_usuario_solicitante', $userId)->first();
            abort_unless($request, 404, 'Solicitud no encontrada.');
            self::addRequestNames([$request]);

            return $request;
        });
    }

    public static function reviewDetail(int $requestId): array
    {
        return self::execute(function () use ($requestId): object {
            $request = self::request($requestId);
            self::addRequestNames([$request]);

            return $request;
        });
    }

    /** @return array{success: bool, message?: string, data?: mixed, status?: int} */
    public static function history(int $requestId, ?int $ownerId = null): array
    {
        return self::execute(function () use ($requestId, $ownerId): object|array {
            $request = DB::table(self::REQUESTS)->where('id_solicitud_actividad', $requestId);
            if ($ownerId !== null) {
                $request->where('id_usuario_solicitante', $ownerId);
            }
            abort_unless($request->exists(), 404, 'Solicitud no encontrada.');

            $decisions = DB::table(self::DECISIONS)
                ->where('id_solicitud_actividad', $requestId)
                ->orderBy('fecha_decision')
                ->orderBy('id_solicitud_decision')
                ->get()
                ->all();
            self::addDecisionNames($decisions);

            return $decisions;
        });
    }

    /** @param array<string, mixed> $filters
     * @return array{success: bool, message: string, data: mixed, status: int}
     */
    public static function decisionHistory(array $filters = [], ?array $areaIds = null, ?array $states = null): array
    {
        $validated = Validator::make($filters, [
            'id_area' => ['sometimes', 'integer', 'min:1'],
            'id_solicitud_actividad' => ['sometimes', 'integer', 'min:1'],
            'id_usuario_responsable' => ['sometimes', 'integer', 'min:1'],
            'tipo_decision' => ['sometimes', 'string', 'in:REVISION_CONFORME,OBSERVAR,DERIVAR_GERENCIA,APROBAR,RECHAZAR'],
            'estado_nuevo' => ['sometimes', 'string', Rule::in(self::STATES)],
            'fecha_desde' => ['sometimes', 'date_format:Y-m-d'],
            'fecha_hasta' => ['sometimes', 'date_format:Y-m-d', Rule::when(isset($filters['fecha_desde']), ['after_or_equal:fecha_desde'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ])->validate();

        $query = DB::table(self::DECISIONS.' as decision')
            ->join(self::REQUESTS.' as request', 'request.id_solicitud_actividad', '=', 'decision.id_solicitud_actividad')
            ->select([
                'decision.id_solicitud_decision',
                'decision.id_solicitud_actividad',
                'decision.id_usuario_responsable',
                'decision.tipo_decision',
                'decision.estado_anterior',
                'decision.estado_nuevo',
                'decision.observacion',
                'decision.fecha_decision',
                'request.id_usuario_solicitante',
                'request.id_area',
                'request.nombre as solicitud_nombre',
            ])
            ->orderByDesc('decision.fecha_decision')
            ->orderByDesc('decision.id_solicitud_decision');

        if (isset($validated['id_area'])) {
            $query->where('request.id_area', $validated['id_area']);
        }
        if ($areaIds !== null) {
            $query->whereIn('request.id_area', $areaIds);
        }
        if ($states !== null) {
            $query->where(function (Builder $query) use ($states): void {
                $query->whereIn('request.estado', $states)->orWhereExists(function (Builder $history): void {
                    $history->selectRaw('1')->from(self::DECISIONS.' as routing')
                        ->whereColumn('routing.id_solicitud_actividad', 'request.id_solicitud_actividad')
                        ->where('routing.tipo_decision', 'DERIVAR_GERENCIA');
                });
            });
        }

        foreach (['id_solicitud_actividad', 'id_usuario_responsable', 'tipo_decision', 'estado_nuevo'] as $field) {
            if (isset($validated[$field])) {
                $query->where('decision.'.$field, $validated[$field]);
            }
        }
        if (isset($validated['fecha_desde'])) {
            $query->whereDate('decision.fecha_decision', '>=', $validated['fecha_desde']);
        }
        if (isset($validated['fecha_hasta'])) {
            $query->whereDate('decision.fecha_decision', '<=', $validated['fecha_hasta']);
        }

        return self::execute(
            function () use ($query, $validated): object {
                $page = $query->paginate($validated['per_page'] ?? 20, ['*'], 'page', $validated['page'] ?? 1);
                self::addDecisionNames($page->items(), true);

                return $page;
            },
            message: 'Historial de decisiones consultado correctamente.',
        );
    }

    /** @return array{success: bool, data?: object, message?: string, status?: int} */
    public static function decide(int $responsibleUserId, int $requestId, string $decision, ?string $observation = null, ?array $areaIds = null): array
    {
        $transitions = [
            'REVISION_CONFORME' => ['Enviada', 'En revisión'],
            'OBSERVAR' => [['Enviada', 'En revisión', 'Pendiente de aprobación'], 'Observada'],
            'DERIVAR_GERENCIA' => ['En revisión', 'Pendiente de aprobación'],
            'APROBAR' => ['Pendiente de aprobación', 'Aprobada'],
            'RECHAZAR' => ['Pendiente de aprobación', 'Rechazada'],
        ];
        if (! isset($transitions[$decision])) {
            return ['success' => false, 'message' => 'Decisión no válida.', 'status' => 422];
        }

        [$from, $to] = $transitions[$decision];

        return self::transition($responsibleUserId, $requestId, (array) $from, $to, $decision, $observation, false, $areaIds);
    }

    /** @param list<string> $expectedStates */
    private static function transition(int $actorId, int $requestId, array $expectedStates, string $nextState, string $decision, ?string $observation, bool $ownerAction, ?array $areaIds = null): array
    {
        return self::execute(fn (): object => DB::transaction(function () use ($actorId, $requestId, $expectedStates, $nextState, $decision, $observation, $ownerAction, $areaIds): object {
            $request = self::lockedRequest($requestId, $areaIds);
            if (! $ownerAction && in_array($decision, ['APROBAR', 'RECHAZAR'], true)) {
                $institutionalAuthority = User::find($actorId)?->hasInstitutionalRequestAuthority() ?? false;
                abort_if((int) $request->id_usuario_solicitante === $actorId && ! $institutionalAuthority, 403, 'No puede decidir su propia solicitud.');
            }
            if ($ownerAction) {
                abort_unless((int) $request->id_usuario_solicitante === $actorId, 404, 'Solicitud no encontrada.');
            }
            abort_if(! in_array($request->estado, $expectedStates, true), 409, 'La solicitud ya no admite esta transición.');
            if (in_array($decision, ['APROBAR', 'RECHAZAR'], true)) {
                $recorded = DB::table(self::DECISIONS)->where('id_solicitud_actividad', $requestId)
                    ->whereIn('tipo_decision', ['REVISION_CONFORME', 'DERIVAR_GERENCIA'])->pluck('tipo_decision')->all();
                abort_unless(in_array('REVISION_CONFORME', $recorded, true) && in_array('DERIVAR_GERENCIA', $recorded, true),
                    409, 'La solicitud requiere revisión conforme y derivación registradas antes de una decisión formal.');
            }

            if ($ownerAction || $decision === 'APROBAR') {
                self::validate((array) $request, false);
            }
            if (! $ownerAction) {
                $validated = Validator::make(['observacion' => $observation], [
                    'observacion' => [in_array($decision, ['OBSERVAR', 'RECHAZAR'], true) ? 'required' : 'nullable', 'string'],
                ])->validate();
                $observation = $validated['observacion'];

                DB::table(self::DECISIONS)->insert([
                    'id_solicitud_actividad' => $requestId,
                    'id_usuario_responsable' => $actorId,
                    'tipo_decision' => $decision,
                    'estado_anterior' => $request->estado,
                    'estado_nuevo' => $nextState,
                    'observacion' => $observation,
                    'fecha_decision' => now(),
                ]);
            }

            $changes = ['estado' => $nextState];
            if ($decision === 'APROBAR') {
                $changes['id_actividad'] = self::approve($request);
            }

            $affected = DB::table(self::REQUESTS)->where('id_solicitud_actividad', $requestId)
                ->where('estado', $request->estado)->update($changes);
            abort_unless($affected === 1, 409, 'La solicitud cambió durante la operación.');

            return self::request($requestId);
        }));
    }

    private static function approve(object $request): int
    {
        abort_if($request->id_actividad !== null, 409, 'La solicitud ya tiene una actividad vinculada.');
        $activityId = DB::table('certificado.actividad')->insertGetId([
            'id_area' => $request->id_area,
            'id_tipo_actividad' => $request->id_tipo_actividad,
            'nombre' => $request->nombre,
            'descripcion' => $request->descripcion,
            'fecha_inicio' => $request->fecha_inicio_propuesta,
            'fecha_fin' => $request->fecha_fin_propuesta,
            'sede' => $request->sede_propuesta,
            'estado' => 'Activa',
        ], 'id_actividad');

        return $activityId;
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private static function validate(array $input, bool $partial): array
    {
        $presence = $partial ? ['sometimes', 'required'] : ['required'];

        return Validator::make($input, [
            'id_area' => [...$presence, 'integer', Rule::exists(config('database.default').'.gestion.area', 'id_area')],
            'id_tipo_actividad' => [...$presence, 'integer', Rule::exists(config('database.default').'.certificado.tipo_actividad', 'id_tipo_actividad')],
            'nombre' => [...$presence, 'string', 'max:100'],
            'descripcion' => ['sometimes', 'nullable', 'string', 'max:255'],
            'justificacion' => [...$presence, 'string'],
            'fecha_inicio_propuesta' => [...$presence, 'date_format:Y-m-d'],
            'fecha_fin_propuesta' => [...$presence, 'date_format:Y-m-d', 'after_or_equal:fecha_inicio_propuesta'],
            'sede_propuesta' => ['sometimes', 'nullable', 'string', 'max:100'],
        ])->validate();
    }

    /** @param list<object> $requests */
    private static function addRequestNames(array $requests): void
    {
        $models = ActivityRequest::hydrate($requests)->load([
            'area:id_area,nombre',
            'activityType:id_tipo_actividad,nombre',
            'applicant:id_usuario,id_persona',
            'applicant.person:id_persona,nombres,apellido_paterno,apellido_materno',
        ]);
        foreach ($requests as $index => $request) {
            $model = $models[$index];
            $request->area_nombre = $model->area?->nombre;
            $request->tipo_actividad_nombre = $model->activityType?->nombre;
            $request->solicitante_nombre = self::personName($model->applicant);
        }
    }

    /** @param list<object> $decisions */
    private static function addDecisionNames(array $decisions, bool $includeRequest = false): void
    {
        $relations = ['responsibleUser:id_usuario,id_persona', 'responsibleUser.person:id_persona,nombres,apellido_paterno,apellido_materno'];
        if ($includeRequest) {
            $relations = [...$relations, 'request:id_solicitud_actividad,id_area,id_usuario_solicitante',
                'request.area:id_area,nombre', 'request.applicant:id_usuario,id_persona',
                'request.applicant.person:id_persona,nombres,apellido_paterno,apellido_materno'];
        }
        $models = ActivityRequestDecision::hydrate($decisions)->load($relations);
        foreach ($decisions as $index => $decision) {
            $model = $models[$index];
            $decision->responsable_nombre = self::personName($model->responsibleUser);
            if ($includeRequest) {
                $decision->area_nombre = $model->request?->area?->nombre;
                $decision->solicitante_nombre = self::personName($model->request?->applicant);
            }
        }
    }

    private static function personName(?User $user): ?string
    {
        $person = $user?->person;
        if ($person === null) {
            return null;
        }

        $name = trim(implode(' ', array_filter([$person->nombres, $person->apellido_paterno, $person->apellido_materno],
            fn (?string $part): bool => $part !== null && trim($part) !== '')));

        return $name === '' ? null : $name;
    }

    private static function request(int $requestId): object
    {
        $request = DB::table(self::REQUESTS)->where('id_solicitud_actividad', $requestId)->first();
        abort_unless($request, 404, 'Solicitud no encontrada.');

        return $request;
    }

    private static function lockedRequest(int $requestId, ?array $areaIds = null): object
    {
        $request = DB::table(self::REQUESTS)->where('id_solicitud_actividad', $requestId)->lockForUpdate()->first();
        abort_unless($request, 404, 'Solicitud no encontrada.');

        if ($areaIds !== null) {
            abort_unless(in_array((int) $request->id_area, $areaIds, true), 403, 'Solicitud fuera del ambito autorizado.');
        }

        return $request;
    }

    private static function execute(callable $operation, int $status = 200, string $message = 'Operación realizada correctamente.'): array
    {
        try {
            return ['success' => true, 'message' => $message, 'data' => $operation(), 'status' => $status];
        } catch (ValidationException $exception) {
            return ['success' => false, 'message' => 'Datos de validación incorrectos.', 'data' => $exception->errors(), 'status' => 422];
        } catch (HttpException $exception) {
            return ['success' => false, 'message' => $exception->getMessage(), 'data' => null, 'status' => $exception->getStatusCode()];
        } catch (QueryException $exception) {
            $conflict = str_starts_with((string) $exception->getCode(), '23');

            return ['success' => false, 'message' => $conflict ? 'Conflicto de integridad.' : 'No se pudo completar la operación.', 'data' => null, 'status' => $conflict ? 409 : 500];
        } catch (Throwable) {
            return ['success' => false, 'message' => 'No se pudo completar la operación.', 'data' => null, 'status' => 500];
        }
    }
}
