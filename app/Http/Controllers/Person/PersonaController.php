<?php

namespace App\Http\Controllers\Person;

use App\Http\Controllers\Controller;
use App\Http\Data\Person\PersonaData;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PersonaController extends Controller
{
    use ApiResponse;

    public function list(): JsonResponse
    {
        $response = PersonaData::getAll();

        if (! $response['success']) {
            return $this->error($response['message'], $response['status']);
        }

        return $this->ok($response['data'], $response['message']);
    }

    public function detail(int $id): JsonResponse
    {
        $response = PersonaData::getById($id);

        if (! $response['success']) {
            return $this->error($response['message'], $response['status']);
        }

        return $this->ok($response['data'], $response['message']);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'nombres' => ['required', 'string', 'max:150'],
            'apellido_paterno' => ['required', 'string', 'max:150'],
            'apellido_materno' => ['nullable', 'string', 'max:150'],
            'telefono' => ['nullable', 'string', 'max:20'],
            'direccion' => ['nullable', 'string', 'max:200'],
            'correo' => ['nullable', 'string', 'max:255'],
            'fecha_registro' => ['required', 'date'],
        ]);

        if ($validator->fails()) {
            return $this->information('Datos de validación inválidos.', 422, $validator->errors());
        }

        $response = PersonaData::create($validator->validated());

        if (! $response['success']) {
            return $this->error($response['message'], $response['status']);
        }

        return $this->ok($response['data'], $response['message'], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'nombres' => ['required', 'string', 'max:150'],
            'apellido_paterno' => ['required', 'string', 'max:150'],
            'apellido_materno' => ['nullable', 'string', 'max:150'],
            'telefono' => ['nullable', 'string', 'max:20'],
            'direccion' => ['nullable', 'string', 'max:200'],
            'correo' => ['nullable', 'string', 'max:255'],
            'fecha_registro' => ['required', 'date'],
        ]);

        if ($validator->fails()) {
            return $this->information('Datos de validación inválidos.', 422, $validator->errors());
        }

        $response = PersonaData::update($id, $validator->validated());

        if (! $response['success']) {
            return $this->error($response['message'], $response['status']);
        }

        return $this->ok($response['data'], $response['message']);
    }

    public function destroy(int $id): JsonResponse
    {
        $response = PersonaData::delete($id);

        if (! $response['success']) {
            return $this->error($response['message'], $response['status']);
        }

        return $this->ok($response['data'], $response['message']);
    }
}
