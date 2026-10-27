<?php

namespace App\Http\Controllers\ParticipationType;

use App\Http\Controllers\Controller;
use App\Http\Data\ParticipationType\ParticipationTypeData;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Throwable;

class ParticipationTypeController extends Controller
{
    use ApiResponse;

    public function list(): JsonResponse
    {
        $response = ParticipationTypeData::getParticipationTypes();

        if (! $response['success']) {
            return $this->error($response['message'], $response['status']);
        }

        return $this->ok($response['data'], $response['message']);
    }

    public function detail(int $idParticipationType): JsonResponse
    {
        $response = ParticipationTypeData::getParticipationTypeById($idParticipationType);

        if (! $response['success']) {
            return $this->error($response['message'], $response['status']);
        }

        return $this->ok($response['data'], $response['message']);
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'nombre' => ['required', 'string', 'max:50'],
                'estado' => ['required', 'boolean'],
            ]);

            if ($validator->fails()) {
                return $this->information('Invalid validation data.', 422, $validator->errors());
            }

            $response = ParticipationTypeData::createParticipationType($validator->validated());

            if (! $response['success']) {
                if ($response['status'] === 422) {
                    return $this->information($response['message'], 422, $response['errors']);
                }

                return $this->error($response['message'], $response['status']);
            }

            return $this->ok($response['data'], $response['message'], 201);
        } catch (Throwable $e) {
            report($e);

            return $this->error('Error al registrar el tipo de participación.', 500);
        }
    }

    public function update(Request $request, int $idParticipationType): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'nombre' => ['sometimes', 'required', 'string', 'max:50'],
                'estado' => ['sometimes', 'required', 'boolean'],
            ]);

            if ($validator->fails()) {
                return $this->information('Invalid validation data.', 422, $validator->errors());
            }

            $response = ParticipationTypeData::updateParticipationType($idParticipationType, $validator->validated());

            if (! $response['success']) {
                if ($response['status'] === 422) {
                    return $this->information($response['message'], 422, $response['errors']);
                }

                return $this->error($response['message'], $response['status']);
            }

            return $this->ok($response['data'], $response['message']);
        } catch (Throwable $e) {
            report($e);

            return $this->error('Error al actualizar el tipo de participación.', 500);
        }
    }

    public function destroy(int $idParticipationType): JsonResponse
    {
        $response = ParticipationTypeData::deleteParticipationType($idParticipationType);

        if (! $response['success']) {
            return $this->error($response['message'], $response['status']);
        }

        return $this->ok($response['data'], $response['message']);
    }
}
