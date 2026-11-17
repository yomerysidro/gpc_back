<?php

namespace App\Http\Controllers\Activity;

use App\Http\Controllers\Controller;
use App\Http\Data\Activity\ActivityData;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

class ActivityController extends Controller
{
    use ApiResponse;

    public function list(Request $request): JsonResponse
    {
        $response = ActivityData::getActivities($request->attributes->get('activity_module_areas'));

        if (! $response['success']) {
            return $this->error($response['message'], $response['status']);
        }

        return $this->ok($response['data'], $response['message']);
    }

    public function detail(Request $request, int $idActivity): JsonResponse
    {
        $response = ActivityData::getActivityById($idActivity, $request->attributes->get('activity_module_areas'));

        if (! $response['success']) {
            return $this->error($response['message'], $response['status']);
        }

        return $this->ok($response['data'], $response['message']);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->error(ActivityData::DIRECT_CREATION_DISABLED, 403);
    }

    public function update(Request $request, int $idActivity): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'id_area' => ['sometimes', 'bail', 'required', 'integer', Rule::exists(config('database.default').'.gestion.area', 'id_area')],
                'id_tipo_actividad' => ['sometimes', 'bail', 'required', 'integer', Rule::exists(config('database.default').'.certificado.tipo_actividad', 'id_tipo_actividad')],
                'nombre' => ['sometimes', 'required', 'string', 'max:100'],
                'descripcion' => ['sometimes', 'nullable', 'string', 'max:255'],
                'fecha_inicio' => ['sometimes', 'required', 'date'],
                'fecha_fin' => ['sometimes', 'required', 'date'],
                'sede' => ['sometimes', 'nullable', 'string', 'max:100'],
                'estado' => ['sometimes', 'required', Rule::in(['Activa', 'Cerrada'])],
            ]);

            if ($validator->fails()) {
                return $this->information('Invalid validation data.', 422, $validator->errors());
            }

            $response = ActivityData::updateActivity($idActivity, $validator->validated(), $request->attributes->get('activity_module_areas'));

            if (! $response['success']) {
                if ($response['status'] === 422) {
                    return $this->information($response['message'], 422, $response['errors']);
                }

                return $this->error($response['message'], $response['status']);
            }

            return $this->ok($response['data'], $response['message']);
        } catch (Throwable $e) {
            report($e);

            return $this->error('Error al actualizar la actividad.', 500);
        }
    }

    public function destroy(int $idActivity): JsonResponse
    {
        $response = ActivityData::deleteActivity($idActivity);

        if (! $response['success']) {
            return $this->error($response['message'], $response['status']);
        }

        return $this->ok($response['data'], $response['message']);
    }
}
