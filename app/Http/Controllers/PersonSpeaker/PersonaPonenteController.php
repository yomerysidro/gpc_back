<?php

namespace App\Http\Controllers\PersonSpeaker;

use App\Http\Controllers\Controller;
use App\Http\Data\PersonSpeaker\PersonaPonenteData;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class PersonaPonenteController extends Controller
{
    use ApiResponse;

    public function list(): JsonResponse
    {
        $response = PersonaPonenteData::getAll();

        if (! $response['success']) {
            return $this->error($response['message'], $response['status']);
        }

        return $this->ok($response['data'], $response['message']);
    }

    public function detail(int $id): JsonResponse
    {
        $response = PersonaPonenteData::getById($id);

        if (! $response['success']) {
            return $this->error($response['message'], $response['status']);
        }

        return $this->ok($response['data'], $response['message']);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'id_persona' => ['required', 'integer', Rule::exists(DB::getDefaultConnection().'.gestion.persona', 'id_persona')],
            'id_institucion' => ['nullable', 'integer', Rule::exists(DB::getDefaultConnection().'.gestion.institucion', 'id_institucion')],
            'cargo' => ['nullable', 'string', 'max:150'],
            'especialidad' => ['nullable', 'string', 'max:100'],
        ]);

        if ($validator->fails()) {
            return $this->information('Datos de validación inválidos.', 422, $validator->errors());
        }

        $response = PersonaPonenteData::create($validator->validated());

        if (! $response['success']) {
            return $this->error($response['message'], $response['status']);
        }

        return $this->ok($response['data'], $response['message'], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'id_persona' => ['required', 'integer', Rule::exists(DB::getDefaultConnection().'.gestion.persona', 'id_persona')],
            'id_institucion' => ['nullable', 'integer', Rule::exists(DB::getDefaultConnection().'.gestion.institucion', 'id_institucion')],
            'cargo' => ['nullable', 'string', 'max:150'],
            'especialidad' => ['nullable', 'string', 'max:100'],
        ]);

        if ($validator->fails()) {
            return $this->information('Datos de validación inválidos.', 422, $validator->errors());
        }

        $response = PersonaPonenteData::update($id, $validator->validated());

        if (! $response['success']) {
            return $this->error($response['message'], $response['status']);
        }

        return $this->ok($response['data'], $response['message']);
    }

    public function destroy(int $id): JsonResponse
    {
        $response = PersonaPonenteData::delete($id);

        if (! $response['success']) {
            return $this->error($response['message'], $response['status']);
        }

        return $this->ok($response['data'], $response['message']);
    }
}
