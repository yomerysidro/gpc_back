<?php

namespace App\Http\Controllers\DocumentType;

use App\Http\Controllers\Controller;
use App\Http\Data\DocumentType\TipoDocumentoData;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TipoDocumentoController extends Controller
{
    use ApiResponse;

    public function list(): JsonResponse
    {
        $response = TipoDocumentoData::getAll();

        if (! $response['success']) {
            return $this->error($response['message'], $response['status']);
        }

        return $this->ok($response['data'], $response['message']);
    }

    public function detail(int $id): JsonResponse
    {
        $response = TipoDocumentoData::getById($id);

        if (! $response['success']) {
            return $this->error($response['message'], $response['status']);
        }

        return $this->ok($response['data'], $response['message']);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'nombre' => ['required', 'string', 'max:150'],
            'estado' => ['sometimes', 'boolean'],
        ]);

        if ($validator->fails()) {
            return $this->information('Datos de validación inválidos.', 422, $validator->errors());
        }

        $response = TipoDocumentoData::create($validator->validated());

        if (! $response['success']) {
            return $this->error($response['message'], $response['status']);
        }

        return $this->ok($response['data'], $response['message'], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'nombre' => ['required', 'string', 'max:150'],
            'estado' => ['sometimes', 'boolean'],
        ]);

        if ($validator->fails()) {
            return $this->information('Datos de validación inválidos.', 422, $validator->errors());
        }

        $response = TipoDocumentoData::update($id, $validator->validated());

        if (! $response['success']) {
            return $this->error($response['message'], $response['status']);
        }

        return $this->ok($response['data'], $response['message']);
    }

    public function destroy(int $id): JsonResponse
    {
        $response = TipoDocumentoData::delete($id);

        if (! $response['success']) {
            return $this->error($response['message'], $response['status']);
        }

        return $this->ok($response['data'], $response['message']);
    }
}
