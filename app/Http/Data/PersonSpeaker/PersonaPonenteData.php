<?php

namespace App\Http\Data\PersonSpeaker;

use App\Http\Data\GestionIntegrity;
use Illuminate\Support\Facades\DB;
use Throwable;

class PersonaPonenteData
{
    /**
     * @return array{success: bool, message: string, data: mixed, status: int}
     */
    public static function getAll(): array
    {
        try {
            $records = DB::table('gestion.persona_ponente')->orderBy('id_persona_ponente', 'desc')->get();

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
            $record = DB::table('gestion.persona_ponente')->where('id_persona_ponente', $id)->first();

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
     * @param  array{id_persona: int, id_institucion?: int|null, cargo?: string|null, especialidad?: string|null}  $data
     * @return array{success: bool, message: string, data: mixed, status: int}
     */
    public static function create(array $data): array
    {
        try {
            return DB::transaction(function () use ($data): array {
                $id = DB::table('gestion.persona_ponente')->insertGetId($data, 'id_persona_ponente');
                $record = DB::table('gestion.persona_ponente')->where('id_persona_ponente', $id)->first();

                return [
                    'success' => true,
                    'message' => 'Registro creado correctamente.',
                    'data' => $record,
                    'status' => 201,
                ];
            });
        } catch (Throwable $exception) {
            if ($failure = GestionIntegrity::failure($exception)) {
                return $failure;
            }

            return [
                'success' => false,
                'message' => 'Error al crear el registro.',
                'data' => null,
                'status' => 500,
            ];
        }
    }

    /**
     * @param  array{id_persona: int, id_institucion?: int|null, cargo?: string|null, especialidad?: string|null}  $data
     * @return array{success: bool, message: string, data: mixed, status: int}
     */
    public static function update(int $id, array $data): array
    {
        try {
            return DB::transaction(function () use ($id, $data): array {
                $record = DB::table('gestion.persona_ponente')->where('id_persona_ponente', $id)->lockForUpdate()->first();

                if (! $record) {
                    return [
                        'success' => false,
                        'message' => 'Registro no encontrado.',
                        'data' => null,
                        'status' => 404,
                    ];
                }

                $changes = GestionIntegrity::changes('gestion.persona_ponente', $data, $record);
                if (array_key_exists('id_persona', $changes)
                    && DB::table('certificado.actividad_ponente')->where('id_persona_ponente', $id)->exists()) {
                    return ['success' => false, 'message' => 'El ponente vinculado debe conservar su identidad.', 'data' => null, 'status' => 409];
                }
                if ($changes !== []) {
                    DB::table('gestion.persona_ponente')->where('id_persona_ponente', $id)->update($changes);
                }
                $updatedRecord = DB::table('gestion.persona_ponente')->where('id_persona_ponente', $id)->first();

                return [
                    'success' => true,
                    'message' => 'Registro actualizado correctamente.',
                    'data' => $updatedRecord,
                    'status' => 200,
                ];
            });
        } catch (Throwable $exception) {
            if ($failure = GestionIntegrity::failure($exception)) {
                return $failure;
            }

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
                GestionIntegrity::assertCanDelete('gestion.persona_ponente', $id);

                $record = DB::table('gestion.persona_ponente')->where('id_persona_ponente', $id)->lockForUpdate()->first();

                if (! $record) {
                    return [
                        'success' => false,
                        'message' => 'Registro no encontrado.',
                        'data' => null,
                        'status' => 404,
                    ];
                }

                DB::table('gestion.persona_ponente')->where('id_persona_ponente', $id)->delete();

                return [
                    'success' => true,
                    'message' => 'Registro eliminado correctamente.',
                    'data' => null,
                    'status' => 200,
                ];
            });
        } catch (Throwable $exception) {
            if ($failure = GestionIntegrity::failure($exception)) {
                return $failure;
            }

            return [
                'success' => false,
                'message' => 'Error al eliminar el registro.',
                'data' => null,
                'status' => 500,
            ];
        }
    }
}
