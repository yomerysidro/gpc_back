<?php

namespace App\Http\Controllers\Practice;

use App\Http\Controllers\Controller;
use App\Http\Data\Practice\PracticeData;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PracticeController extends Controller
{
    use ApiResponse;

    /**
     * Listar prácticas.
     */
    public function list()
    {
        $response = PracticeData::getPractices();

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
     * Obtener una práctica por ID.
     */
    public function detail(int $idPractice)
    {
        $response = PracticeData::getPracticeById($idPractice);

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
     * Registrar una práctica.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id_persona_practicante' => [
                'required',
                'integer',
                'exists:'.config('database.default').'.gestion.persona_practicante,id_persona_practicante',
            ],
            'id_institucion' => [
                'required',
                'integer',
                'exists:'.config('database.default').'.gestion.institucion,id_institucion',
            ],
            'id_area' => [
                'required',
                'integer',
                'exists:'.config('database.default').'.gestion.area,id_area',
            ],
            'carrera' => ['required', 'string', 'max:55'],
            'fecha_inicio' => ['required', 'date'],
            'fecha_fin' => ['nullable', 'date', 'after_or_equal:fecha_inicio'],
            'estado' => ['required', 'string', 'max:100'],
        ]);

        if ($validator->fails()) {
            return $this->information(
                'Datos de validación incorrectos.',
                422,
                $validator->errors()
            );
        }

        $response = PracticeData::createPractice(
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
     * Actualizar una práctica.
     */
    public function update(Request $request, int $idPractice)
    {
        $validator = Validator::make($request->all(), [
            'id_persona_practicante' => [
                'required',
                'integer',
                'exists:'.config('database.default').'.gestion.persona_practicante,id_persona_practicante',
            ],
            'id_institucion' => [
                'required',
                'integer',
                'exists:'.config('database.default').'.gestion.institucion,id_institucion',
            ],
            'id_area' => [
                'required',
                'integer',
                'exists:'.config('database.default').'.gestion.area,id_area',
            ],
            'carrera' => ['required', 'string', 'max:55'],
            'fecha_inicio' => ['required', 'date'],
            'fecha_fin' => ['nullable', 'date', 'after_or_equal:fecha_inicio'],
            'estado' => ['required', 'string', 'max:100'],
        ]);

        if ($validator->fails()) {
            return $this->information(
                'Datos de validación incorrectos.',
                422,
                $validator->errors()
            );
        }

        $response = PracticeData::updatePractice(
            $idPractice,
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
     * Eliminar una práctica.
     */
    public function destroy(int $idPractice)
    {
        $response = PracticeData::deletePractice($idPractice);

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
