<?php

namespace App\Http\Controllers\PersonDocument;

use App\Http\Controllers\Controller;
use App\Http\Data\PersonDocument\PersonaDocumentoData;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class PersonaDocumentoController extends Controller
{
    use ApiResponse;

    public function list(): JsonResponse
    {
        $response = PersonaDocumentoData::getAll();

        if (! $response['success']) {
            return $this->error($response['message'], $response['status']);
        }

        return $this->ok($response['data'], $response['message']);
    }

    public function detail(int $id): JsonResponse
    {
        $response = PersonaDocumentoData::getById($id);

        if (! $response['success']) {
            return $this->error($response['message'], $response['status']);
        }

        return $this->ok($response['data'], $response['message']);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'id_persona' => ['required', 'integer', Rule::exists(DB::getDefaultConnection().'.gestion.persona', 'id_persona')],
            'id_tipo_documento' => ['required', 'integer', Rule::exists(DB::getDefaultConnection().'.gestion.tipo_documento', 'id_tipo_documento')],
            'numero_documento' => ['required', 'string', 'max:100'],
            'estado' => ['required', 'string', 'max:100'],
        ]);

        if ($validator->fails()) {
            return $this->information('Datos de validación inválidos.', 422, $validator->errors());
        }

        $response = PersonaDocumentoData::create($validator->validated());

        if (! $response['success']) {
            return $this->error($response['message'], $response['status']);
        }

        return $this->ok($response['data'], $response['message'], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'id_persona' => ['required', 'integer', Rule::exists(DB::getDefaultConnection().'.gestion.persona', 'id_persona')],
            'id_tipo_documento' => ['required', 'integer', Rule::exists(DB::getDefaultConnection().'.gestion.tipo_documento', 'id_tipo_documento')],
            'numero_documento' => ['required', 'string', 'max:100'],
            'estado' => ['required', 'string', 'max:100'],
        ]);

        if ($validator->fails()) {
            return $this->information('Datos de validación inválidos.', 422, $validator->errors());
        }

        $response = PersonaDocumentoData::update($id, $validator->validated());

        if (! $response['success']) {
            return $this->error($response['message'], $response['status']);
        }

        return $this->ok($response['data'], $response['message']);
    }

    public function destroy(int $id): JsonResponse
    {
        $response = PersonaDocumentoData::delete($id);

        if (! $response['success']) {
            return $this->error($response['message'], $response['status']);
        }

        return $this->ok($response['data'], $response['message']);
    }
}
