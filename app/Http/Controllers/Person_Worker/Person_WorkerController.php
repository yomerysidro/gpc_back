<?php

namespace App\Http\Controllers\Person_Worker;

use App\Http\Controllers\Controller;
use App\Http\Data\Person_Worker\Person_WorkerData;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class Person_WorkerController extends Controller
{
    use ApiResponse;

    /**
     * Listar trabajadores.
     */
    public function list()
    {
        $response = Person_WorkerData::getPersonWorkers();

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
     * Obtener un trabajador por ID.
     */
    public function detail(int $idPersonWorker)
    {
        $response = Person_WorkerData::getPersonWorkerById(
            $idPersonWorker
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
     * Registrar un trabajador.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id_persona' => [
                'required',
                'integer',
                'exists:'.config('database.default').'.gestion.persona,id_persona',
            ],
            'id_area' => [
                'required',
                'integer',
                'exists:'.config('database.default').'.gestion.area,id_area',
            ],
            'cargo' => ['required', 'string', 'max:150'],
            'fecha_ingreso' => ['required', 'date'],
            'fecha_fin' => ['nullable', 'date', 'after_or_equal:fecha_ingreso'],
            'estado' => ['required', 'boolean'],
        ]);

        if ($validator->fails()) {
            return $this->information(
                'Datos de validación incorrectos.',
                422,
                $validator->errors()
            );
        }

        $response = Person_WorkerData::createPersonWorker(
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
     * Actualizar un trabajador.
     */
    public function update(Request $request, int $idPersonWorker)
    {
        $validator = Validator::make($request->all(), [
            'id_persona' => [
                'required',
                'integer',
                'exists:'.config('database.default').'.gestion.persona,id_persona',
            ],
            'id_area' => [
                'required',
                'integer',
                'exists:'.config('database.default').'.gestion.area,id_area',
            ],
            'cargo' => ['required', 'string', 'max:150'],
            'fecha_ingreso' => ['required', 'date'],
            'fecha_fin' => ['nullable', 'date', 'after_or_equal:fecha_ingreso'],
            'estado' => ['required', 'boolean'],
        ]);

        if ($validator->fails()) {
            return $this->information(
                'Datos de validación incorrectos.',
                422,
                $validator->errors()
            );
        }

        $response = Person_WorkerData::updatePersonWorker(
            $idPersonWorker,
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
     * Eliminar un trabajador.
     */
    public function destroy(int $idPersonWorker)
    {
        $response = Person_WorkerData::deletePersonWorker(
            $idPersonWorker
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
