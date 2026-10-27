<?php

namespace App\Http\Controllers\Practicing_Individual;

use App\Http\Controllers\Controller;
use App\Http\Data\Practicing_Individual\Practicing_IndividualData;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class Practicing_IndividualController extends Controller
{
    use ApiResponse;

    /**
     * Listar personas practicantes.
     */
    public function list()
    {
        $response = Practicing_IndividualData::getPracticingIndividuals();

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
     * Obtener una persona practicante por ID.
     */
    public function detail(int $idPracticingIndividual)
    {
        $response = Practicing_IndividualData::getPracticingIndividualById(
            $idPracticingIndividual
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
     * Registrar una persona practicante.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id_persona' => [
                'required',
                'integer',
                'exists:'.config('database.default').'.gestion.persona,id_persona',
            ],
            'codigo' => ['required', 'string', 'max:50'],
            'estado' => ['required', 'string', 'max:30'],
        ]);

        if ($validator->fails()) {
            return $this->information(
                'Datos de validación incorrectos.',
                422,
                $validator->errors()
            );
        }

        $response = Practicing_IndividualData::createPracticingIndividual(
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
     * Actualizar una persona practicante.
     */
    public function update(Request $request, int $idPracticingIndividual)
    {
        $validator = Validator::make($request->all(), [
            'id_persona' => [
                'required',
                'integer',
                'exists:'.config('database.default').'.gestion.persona,id_persona',
            ],
            'codigo' => ['required', 'string', 'max:50'],
            'estado' => ['required', 'string', 'max:30'],
        ]);

        if ($validator->fails()) {
            return $this->information(
                'Datos de validación incorrectos.',
                422,
                $validator->errors()
            );
        }

        $response = Practicing_IndividualData::updatePracticingIndividual(
            $idPracticingIndividual,
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
     * Eliminar una persona practicante.
     */
    public function destroy(int $idPracticingIndividual)
    {
        $response = Practicing_IndividualData::deletePracticingIndividual(
            $idPracticingIndividual
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
}
