<?php

namespace App\Http\Controllers\Area;

use App\Http\Controllers\Controller;
use App\Http\Data\Area\AreaData;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AreaController extends Controller
{
    use ApiResponse;

    public function list(): JsonResponse
    {
        $response = AreaData::getAll();

        if (! $response['success']) {
            return $this->error($response['message'], $response['status']);
        }

        return $this->ok($response['data'], $response['message']);
    }

    public function detail(int $id): JsonResponse
    {
        $response = AreaData::getById($id);

        if (! $response['success']) {
            return $this->error($response['message'], $response['status']);
        }

        return $this->ok($response['data'], $response['message']);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'nombre' => ['required', 'string', 'max:100'],
        ]);

        if ($validator->fails()) {
            return $this->information('Datos de validación inválidos.', 422, $validator->errors());
        }

        $response = AreaData::create($validator->validated());

        if (! $response['success']) {
            return $this->error($response['message'], $response['status']);
        }

        return $this->ok($response['data'], $response['message'], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'nombre' => ['required', 'string', 'max:100'],
        ]);

        if ($validator->fails()) {
            return $this->information('Datos de validación inválidos.', 422, $validator->errors());
        }

        $response = AreaData::update($id, $validator->validated());

        if (! $response['success']) {
            return $this->error($response['message'], $response['status']);
        }

        return $this->ok($response['data'], $response['message']);
    }

    public function destroy(int $id): JsonResponse
    {
        $response = AreaData::delete($id);

        if (! $response['success']) {
            return $this->error($response['message'], $response['status']);
        }

        return $this->ok($response['data'], $response['message']);
    }
}
