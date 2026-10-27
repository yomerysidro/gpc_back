<?php

namespace App\Http\Controllers\Institution;

use App\Http\Controllers\Controller;
use App\Http\Data\Institution\InstitutionData;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class InstitutionController extends Controller
{
    use ApiResponse;

    /**
     * Listar instituciones.
     */
    public function list()
    {
        $response = InstitutionData::getInstitutions();

        if (! $response['success']) {
            return $this->error(
                $response['message'],
                $response['status']
            );
        }

        return $this->ok(
            $response['data'],
            $response['message']
        );
    }

    /**
     * Obtener una institución por ID.
     */
    public function detail(int $idInstitution)
    {
        $response = InstitutionData::getInstitutionById($idInstitution);

        if (! $response['success']) {
            return $this->error(
                $response['message'],
                $response['status']
            );
        }

        return $this->ok(
            $response['data'],
            $response['message']
        );
    }

    /**
     * Registrar una institución.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'nombre_institucion' => ['required', 'string', 'max:150'],
            'pais' => ['required', 'string', 'max:50'],
            'tipo' => ['required', 'string', 'max:30'],
        ]);

        if ($validator->fails()) {
            return $this->information(
                'Datos de validación incorrectos.',
                422,
                $validator->errors()
            );
        }

        $response = InstitutionData::createInstitution(
            $validator->validated()
        );

        if (! $response['success']) {
            return $this->error(
                $response['message'],
                $response['status']
            );
        }

        return $this->ok(
            $response['data'],
            $response['message'],
            201
        );
    }

    /**
     * Actualizar una institución.
     */
    public function update(Request $request, int $idInstitution)
    {
        $validator = Validator::make($request->all(), [
            'nombre_institucion' => ['required', 'string', 'max:150'],
            'pais' => ['required', 'string', 'max:50'],
            'tipo' => ['required', 'string', 'max:30'],
        ]);

        if ($validator->fails()) {
            return $this->information(
                'Datos de validación incorrectos.',
                422,
                $validator->errors()
            );
        }

        $response = InstitutionData::updateInstitution(
            $idInstitution,
            $validator->validated()
        );

        if (! $response['success']) {
            return $this->error(
                $response['message'],
                $response['status']
            );
        }

        return $this->ok(
            $response['data'],
            $response['message']
        );
    }

    /**
     * Eliminar una institución.
     */
    public function destroy(int $idInstitution)
    {
        $response = InstitutionData::deleteInstitution($idInstitution);

        if (! $response['success']) {
            return $this->error(
                $response['message'],
                $response['status']
            );
        }

        return $this->ok(
            $response['data'],
            $response['message']
        );
    }
}
