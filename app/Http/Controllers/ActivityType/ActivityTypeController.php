<?php

namespace App\Http\Controllers\ActivityType;

use App\Http\Controllers\Controller;
use App\Http\Data\ActivityType\ActivityTypeData;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Throwable;

class ActivityTypeController extends Controller
{
    use ApiResponse;

    public function list(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'estado' => ['sometimes', 'required', 'boolean'],
        ]);
        if ($validator->fails()) {
            return $this->information('Datos de validación inválidos.', 422, $validator->errors());
        }

        $filters = $validator->validated();
        $response = ActivityTypeData::getActivityTypes(isset($filters['estado']) ? (bool) $filters['estado'] : null);

        if (! $response['success']) {
            return $this->error($response['message'], $response['status']);
        }

        return $this->ok($response['data'], $response['message']);
    }

    public function detail(int $idActivityType): JsonResponse
    {
        $response = ActivityTypeData::getActivityTypeById($idActivityType);

        if (! $response['success']) {
            return $this->error($response['message'], $response['status']);
        }

        return $this->ok($response['data'], $response['message']);
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'nombre' => ['required', 'string', 'max:100'],
                'descripcion' => ['nullable', 'string', 'max:255'],
                'estado' => ['required', 'boolean'],
            ]);

            if ($validator->fails()) {
                return $this->information('Invalid validation data.', 422, $validator->errors());
            }

            $response = ActivityTypeData::createActivityType($validator->validated());

            if (! $response['success']) {
                if ($response['status'] === 422) {
                    return $this->information($response['message'], 422, $response['errors']);
                }

                return $this->error($response['message'], $response['status']);
            }

            return $this->ok($response['data'], $response['message'], 201);
        } catch (Throwable $e) {
            report($e);

            return $this->error('Error al registrar el tipo de actividad.', 500);
        }
    }

    public function update(Request $request, int $idActivityType): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'nombre' => ['sometimes', 'required', 'string', 'max:100'],
                'descripcion' => ['sometimes', 'nullable', 'string', 'max:255'],
                'estado' => ['sometimes', 'required', 'boolean'],
            ]);

            if ($validator->fails()) {
                return $this->information('Invalid validation data.', 422, $validator->errors());
            }

            $response = ActivityTypeData::updateActivityType($idActivityType, $validator->validated());

            if (! $response['success']) {
                if ($response['status'] === 422) {
                    return $this->information($response['message'], 422, $response['errors']);
                }

                return $this->error($response['message'], $response['status']);
            }

            return $this->ok($response['data'], $response['message']);
        } catch (Throwable $e) {
            report($e);

            return $this->error('Error al actualizar el tipo de actividad.', 500);
        }
    }

    public function destroy(int $idActivityType): JsonResponse
    {
        $response = ActivityTypeData::deleteActivityType($idActivityType);

        if (! $response['success']) {
            return $this->error($response['message'], $response['status']);
        }

        return $this->ok($response['data'], $response['message']);
    }
}
