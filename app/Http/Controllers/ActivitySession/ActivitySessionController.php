<?php

namespace App\Http\Controllers\ActivitySession;

use App\Http\Controllers\Controller;
use App\Http\Data\ActivitySession\ActivitySessionData;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

class ActivitySessionController extends Controller
{
    use ApiResponse;

    public function list(): JsonResponse
    {
        $response = ActivitySessionData::getActivitySessions();

        if (! $response['success']) {
            return $this->error($response['message'], $response['status']);
        }

        return $this->ok($response['data'], $response['message']);
    }

    public function detail(int $idActivitySession): JsonResponse
    {
        $response = ActivitySessionData::getActivitySessionById($idActivitySession);

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
                'nombre' => ['required', 'string', 'max:150'],
                'fecha' => ['required', 'date'],
                'hora_inicio' => ['required', 'date_format:H:i,H:i:s'],
                'hora_fin' => ['required', 'date_format:H:i,H:i:s'],
                'lugar' => ['nullable', 'string', 'max:200'],
                'estado' => ['required', 'string', 'max:30'],
            ]);

            if ($validator->fails()) {
                return $this->information('Invalid validation data.', 422, $validator->errors());
            }

            $response = ActivitySessionData::createActivitySession($validator->validated());

            if (! $response['success']) {
                if ($response['status'] === 422) {
                    return $this->information($response['message'], 422, $response['errors']);
                }

                return $this->error($response['message'], $response['status']);
            }

            return $this->ok($response['data'], $response['message'], 201);
        } catch (Throwable $e) {
            report($e);

            return $this->error('Error al registrar la sesión de actividad.', 500);
        }
    }

    public function update(Request $request, int $idActivitySession): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'id_actividad' => ['sometimes', 'bail', 'required', 'integer', Rule::exists(config('database.default').'.certificado.actividad', 'id_actividad')],
                'nombre' => ['sometimes', 'required', 'string', 'max:150'],
                'fecha' => ['sometimes', 'required', 'date'],
                'hora_inicio' => ['sometimes', 'required', 'date_format:H:i,H:i:s'],
                'hora_fin' => ['sometimes', 'required', 'date_format:H:i,H:i:s'],
                'lugar' => ['sometimes', 'nullable', 'string', 'max:200'],
                'estado' => ['sometimes', 'required', 'string', 'max:30'],
            ]);

            if ($validator->fails()) {
                return $this->information('Invalid validation data.', 422, $validator->errors());
            }

            $response = ActivitySessionData::updateActivitySession($idActivitySession, $validator->validated());

            if (! $response['success']) {
                if ($response['status'] === 422) {
                    return $this->information($response['message'], 422, $response['errors']);
                }

                return $this->error($response['message'], $response['status']);
            }

            return $this->ok($response['data'], $response['message']);
        } catch (Throwable $e) {
            report($e);

            return $this->error('Error al actualizar la sesión de actividad.', 500);
        }
    }

    public function destroy(int $idActivitySession): JsonResponse
    {
        $response = ActivitySessionData::deleteActivitySession($idActivitySession);

        if (! $response['success']) {
            return $this->error($response['message'], $response['status']);
        }

        return $this->ok($response['data'], $response['message']);
    }
}
