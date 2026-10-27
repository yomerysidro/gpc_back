<?php

namespace App\Http\Data\PersonDocument;

use App\Http\Data\GestionIntegrity;
use Illuminate\Support\Facades\DB;
use Throwable;

class PersonaDocumentoData
{
    /**
     * @return array{success: bool, message: string, data: mixed, status: int}
     */
    public static function getAll(): array
    {
        try {
            $records = DB::table('gestion.persona_documento')->orderBy('id_persona_documento', 'desc')->get();

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
            $record = DB::table('gestion.persona_documento')->where('id_persona_documento', $id)->first();

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
     * @param  array{id_persona: int, id_tipo_documento: int, numero_documento: string, estado: string}  $data
     * @return array{success: bool, message: string, data: mixed, status: int}
     */
    public static function create(array $data): array
    {
        try {
            return DB::transaction(function () use ($data): array {
                $id = DB::table('gestion.persona_documento')->insertGetId($data, 'id_persona_documento');
                $record = DB::table('gestion.persona_documento')->where('id_persona_documento', $id)->first();

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
     * @param  array{id_persona: int, id_tipo_documento: int, numero_documento: string, estado: string}  $data
     * @return array{success: bool, message: string, data: mixed, status: int}
     */
    public static function update(int $id, array $data): array
    {
        try {
            return DB::transaction(function () use ($id, $data): array {
                $record = DB::table('gestion.persona_documento')->where('id_persona_documento', $id)->lockForUpdate()->first();

                if (! $record) {
                    return [
                        'success' => false,
                        'message' => 'Registro no encontrado.',
                        'data' => null,
                        'status' => 404,
                    ];
                }

                $changes = GestionIntegrity::changes('gestion.persona_documento', $data, $record);
                GestionIntegrity::assertHistoricalUpdate('gestion.persona_documento', $record, $changes);
                if ($changes !== []) {
                    DB::table('gestion.persona_documento')->where('id_persona_documento', $id)->update($changes);
                }
                $updatedRecord = DB::table('gestion.persona_documento')->where('id_persona_documento', $id)->first();

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
                GestionIntegrity::assertCanDelete('gestion.persona_documento', $id);

                $record = DB::table('gestion.persona_documento')->where('id_persona_documento', $id)->lockForUpdate()->first();

                if (! $record) {
                    return [
                        'success' => false,
                        'message' => 'Registro no encontrado.',
                        'data' => null,
                        'status' => 404,
                    ];
                }

                DB::table('gestion.persona_documento')->where('id_persona_documento', $id)->delete();

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
