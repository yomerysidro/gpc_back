<?php

namespace App\Http\Controllers\Participation;

use App\Http\Controllers\Controller;
use App\Http\Data\Participation\ParticipationData;
use App\Models\ActivitySession\ActivitySession;
use App\Models\Attendance\Attendance;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

class ParticipationController extends Controller
{
    use ApiResponse;

    public function list(Request $request): JsonResponse
    {
        $response = ParticipationData::getParticipations($request->attributes->get('activity_module_areas'));

        if (! $response['success']) {
            return $this->error($response['message'], $response['status']);
        }

        return $this->ok($response['data'], $response['message']);
    }

    public function detail(int $idParticipation): JsonResponse
    {
        $response = ParticipationData::getParticipationById($idParticipation);

        if (! $response['success']) {
            return $this->error($response['message'], $response['status']);
        }

        return $this->ok($response['data'], $response['message']);
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'id_persona' => ['bail', 'required', 'integer', Rule::exists(config('database.default').'.gestion.persona', 'id_persona')],
                'id_actividad' => ['bail', 'required', 'integer', Rule::exists(config('database.default').'.certificado.actividad', 'id_actividad')],
                'id_tipo_participacion' => ['bail', 'required', 'integer', Rule::exists(config('database.default').'.certificado.tipo_participacion', 'id_tipo_participacion')],
                'fecha_inscripcion' => ['required', 'date'],
                'estado' => ['required', 'string', 'max:30'],
                'id_actividad_sesion' => ['sometimes', 'required', 'integer', 'min:1'],
            ]);

            if ($validator->fails()) {
                return $this->information('Invalid validation data.', 422, $validator->errors());
            }

            $data = $validator->validated();
            if (isset($data['id_actividad_sesion'])) {
                $session = ActivitySession::query()->where('id_actividad', $data['id_actividad'])->find($data['id_actividad_sesion']);
                if (! $session) {
                    return $this->error('La sesión no pertenece a la actividad indicada.', 422);
                }
                if (Gate::denies('view', $session) || Gate::denies('create', Attendance::class)) {
                    return $this->error('No autorizado para inscribir participantes en esta sesión.', 403);
                }
            }

            $response = ParticipationData::createParticipation($data);

            if (! $response['success']) {
                if ($response['status'] === 422) {
                    return $this->information($response['message'], 422, $response['errors']);
                }

                return $this->error($response['message'], $response['status']);
            }

            return $this->ok($response['data'], $response['message'], 201);
        } catch (Throwable $e) {
            report($e);

            return $this->error('Error al registrar la participación.', 500);
        }
    }

    public function update(Request $request, int $idParticipation): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'id_persona' => ['sometimes', 'bail', 'required', 'integer', Rule::exists(config('database.default').'.gestion.persona', 'id_persona')],
                'id_actividad' => ['sometimes', 'bail', 'required', 'integer', Rule::exists(config('database.default').'.certificado.actividad', 'id_actividad')],
                'id_tipo_participacion' => ['sometimes', 'bail', 'required', 'integer', Rule::exists(config('database.default').'.certificado.tipo_participacion', 'id_tipo_participacion')],
                'fecha_inscripcion' => ['sometimes', 'required', 'date'],
                'estado' => ['sometimes', 'required', 'string', 'max:30'],
            ]);

            if ($validator->fails()) {
                return $this->information('Invalid validation data.', 422, $validator->errors());
            }

            $response = ParticipationData::updateParticipation($idParticipation, $validator->validated());

            if (! $response['success']) {
                if ($response['status'] === 422) {
                    return $this->information($response['message'], 422, $response['errors']);
                }

                return $this->error($response['message'], $response['status']);
            }

            return $this->ok($response['data'], $response['message']);
        } catch (Throwable $e) {
            report($e);

            return $this->error('Error al actualizar la participación.', 500);
        }
    }

    public function destroy(int $idParticipation): JsonResponse
    {
        $response = ParticipationData::deleteParticipation($idParticipation);

        if (! $response['success']) {
            return $this->error($response['message'], $response['status']);
        }

        return $this->ok($response['data'], $response['message']);
    }
}
