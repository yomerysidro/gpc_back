<?php

namespace App\Http\Controllers\ActivitySpeaker;

use App\Http\Controllers\Controller;
use App\Http\Data\ActivitySpeaker\ActivitySpeakerData;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

class ActivitySpeakerController extends Controller
{
    use ApiResponse;

    public function list(): JsonResponse
    {
        $response = ActivitySpeakerData::getActivitySpeakers();

        if (! $response['success']) {
            return $this->error($response['message'], $response['status']);
        }

        return $this->ok($response['data'], $response['message']);
    }

    public function detail(int $idActivitySpeaker): JsonResponse
    {
        $response = ActivitySpeakerData::getActivitySpeakerById($idActivitySpeaker);

        if (! $response['success']) {
            return $this->error($response['message'], $response['status']);
        }

        return $this->ok($response['data'], $response['message']);
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'id_actividad' => ['bail', 'required', 'integer', Rule::exists(config('database.default').'.certificado.actividad', 'id_actividad')],
                'id_persona_ponente' => ['bail', 'required', 'integer', Rule::exists(config('database.default').'.gestion.persona_ponente', 'id_persona_ponente')],
            ]);

            if ($validator->fails()) {
                return $this->information('Invalid validation data.', 422, $validator->errors());
            }

            $response = ActivitySpeakerData::createActivitySpeaker($validator->validated());

            if (! $response['success']) {
                if ($response['status'] === 422) {
                    return $this->information($response['message'], 422, $response['errors']);
                }

                return $this->error($response['message'], $response['status']);
            }

            return $this->ok($response['data'], $response['message'], 201);
        } catch (Throwable $e) {
            report($e);

            return $this->error('Error al registrar el ponente de actividad.', 500);
        }
    }

    public function update(Request $request, int $idActivitySpeaker): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'id_actividad' => ['sometimes', 'bail', 'required', 'integer', Rule::exists(config('database.default').'.certificado.actividad', 'id_actividad')],
                'id_persona_ponente' => ['sometimes', 'bail', 'required', 'integer', Rule::exists(config('database.default').'.gestion.persona_ponente', 'id_persona_ponente')],
            ]);

            if ($validator->fails()) {
                return $this->information('Invalid validation data.', 422, $validator->errors());
            }

            $response = ActivitySpeakerData::updateActivitySpeaker($idActivitySpeaker, $validator->validated());

            if (! $response['success']) {
                if ($response['status'] === 422) {
                    return $this->information($response['message'], 422, $response['errors']);
                }

                return $this->error($response['message'], $response['status']);
            }

            return $this->ok($response['data'], $response['message']);
        } catch (Throwable $e) {
            report($e);

            return $this->error('Error al actualizar el ponente de actividad.', 500);
        }
    }

    public function destroy(int $idActivitySpeaker): JsonResponse
    {
        $response = ActivitySpeakerData::deleteActivitySpeaker($idActivitySpeaker);

        if (! $response['success']) {
            return $this->error($response['message'], $response['status']);
        }

        return $this->ok($response['data'], $response['message']);
    }
}
