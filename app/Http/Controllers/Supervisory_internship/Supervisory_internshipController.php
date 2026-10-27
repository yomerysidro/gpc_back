<?php

namespace App\Http\Controllers\Supervisory_internship;

use App\Http\Controllers\Controller;
use App\Http\Data\Supervisory_internship\Supervisory_internshipData;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class Supervisory_internshipController extends Controller
{
    use ApiResponse;

    /**
     * Listar registros.
     */
    public function list()
    {
        $response = Supervisory_internshipData::getSupervisoryInternships();

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
     * Obtener un registro por ID.
     */
    public function detail(int $idSupervisoryInternship)
    {
        $response = Supervisory_internshipData::getSupervisoryInternshipById(
            $idSupervisoryInternship
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
     * Crear un registro.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id_practica' => [
                'required',
                'integer',
                'exists:pgsql.gestion.practica,id_practica',
            ],
            'id_persona_trabajador' => [
                'required',
                'integer',
                'exists:pgsql.gestion.persona_trabajador,id_persona_trabajador',
            ],
            'tipo_supervision' => ['required', 'string', 'max:150'],
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

        $response = Supervisory_internshipData::createSupervisoryInternship(
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
     * Actualizar un registro.
     */
    public function update(Request $request, int $idSupervisoryInternship)
    {
        $validator = Validator::make($request->all(), [
            'id_practica' => [
                'required',
                'integer',
                'exists:pgsql.gestion.practica,id_practica',
            ],
            'id_persona_trabajador' => [
                'required',
                'integer',
                'exists:pgsql.gestion.persona_trabajador,id_persona_trabajador',
            ],
            'tipo_supervision' => ['required', 'string', 'max:150'],
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

        $response = Supervisory_internshipData::updateSupervisoryInternship(
            $idSupervisoryInternship,
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
     * Eliminar un registro.
     */
    public function destroy(int $idSupervisoryInternship)
    {
        $response = Supervisory_internshipData::deleteSupervisoryInternship(
            $idSupervisoryInternship
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
