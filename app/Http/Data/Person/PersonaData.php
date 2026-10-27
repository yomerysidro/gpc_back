<?php

namespace App\Http\Data\Person;

use Illuminate\Support\Facades\DB;
use Throwable;

class PersonaData
{
    /**
     * @return array{success: bool, message: string, data: mixed, status: int}
     */
    public static function getAll(): array
    {
        try {
            $records = DB::table('gestion.persona')->orderBy('id_persona', 'desc')->get();

            return [
                'success' => true,
                'message' => 'Registros obtenidos correctamente.',
                'data' => $records,
                'status' => 200,
            ];
        } catch (Throwable $exception) {
            return [
                'success' => false,
                'message' => 'Error al obtener los registros.',
                'data' => null,
                'status' => 500,
            ];
        }
    }

    /**
     * @return array{success: bool, message: string, data: mixed, status: int}
     */
    public static function getById(int $id): array
    {
        try {
            $record = DB::table('gestion.persona')->where('id_persona', $id)->first();

            if (! $record) {
                return [
                    'success' => false,
                    'message' => 'Registro no encontrado.',
                    'data' => null,
                    'status' => 404,
                ];
            }

            return [
                'success' => true,
                'message' => 'Registro obtenido correctamente.',
                'data' => $record,
                'status' => 200,
            ];
        } catch (Throwable $exception) {
            return [
                'success' => false,
                'message' => 'Error al obtener el registro.',
                'data' => null,
                'status' => 500,
            ];
        }
    }

    /**
     * @param  array{nombres: string, apellido_paterno: string, apellido_materno?: string|null, telefono?: string|null, direccion?: string|null, correo?: string|null, fecha_registro: string}  $data
     * @return array{success: bool, message: string, data: mixed, status: int}
     */
    public static function create(array $data): array
    {
        try {
            return DB::transaction(function () use ($data): array {
                $id = DB::table('gestion.persona')->insertGetId($data, 'id_persona');
                $record = DB::table('gestion.persona')->where('id_persona', $id)->first();

                return [
                    'success' => true,
                    'message' => 'Registro creado correctamente.',
                    'data' => $record,
                    'status' => 201,
                ];
            });
        } catch (Throwable $exception) {
            return [
                'success' => false,
                'message' => 'Error al crear el registro.',
                'data' => null,
                'status' => 500,
            ];
        }
    }

    /**
     * @param  array{nombres: string, apellido_paterno: string, apellido_materno?: string|null, telefono?: string|null, direccion?: string|null, correo?: string|null, fecha_registro: string}  $data
     * @return array{success: bool, message: string, data: mixed, status: int}
     */
    public static function update(int $id, array $data): array
    {
        try {
            return DB::transaction(function () use ($id, $data): array {
                $record = DB::table('gestion.persona')->where('id_persona', $id)->lockForUpdate()->first();

                if (! $record) {
                    return [
                        'success' => false,
                        'message' => 'Registro no encontrado.',
                        'data' => null,
                        'status' => 404,
                    ];
                }

                DB::table('gestion.persona')->where('id_persona', $id)->update($data);
                $updatedRecord = DB::table('gestion.persona')->where('id_persona', $id)->first();

                return [
                    'success' => true,
                    'message' => 'Registro actualizado correctamente.',
                    'data' => $updatedRecord,
                    'status' => 200,
                ];
            });
        } catch (Throwable $exception) {
            return [
                'success' => false,
                'message' => 'Error al actualizar el registro.',
                'data' => null,
                'status' => 500,
            ];
        }
    }

    /**
     * @return array{success: bool, message: string, data: mixed, status: int}
     */
    public static function delete(int $id): array
    {
        try {
            return DB::transaction(function () use ($id): array {
                $record = DB::table('gestion.persona')->where('id_persona', $id)->lockForUpdate()->first();

                if (! $record) {
                    return [
                        'success' => false,
                        'message' => 'Registro no encontrado.',
                        'data' => null,
                        'status' => 404,
                    ];
                }

                DB::table('gestion.persona')->where('id_persona', $id)->delete();

                return [
                    'success' => true,
                    'message' => 'Registro eliminado correctamente.',
                    'data' => null,
                    'status' => 200,
                ];
            });
        } catch (Throwable $exception) {
            return [
                'success' => false,
                'message' => 'Error al eliminar el registro.',
                'data' => null,
                'status' => 500,
            ];
        }
    }
}
