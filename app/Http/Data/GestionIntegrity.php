<?php

namespace App\Http\Data;

use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

class GestionIntegrity
{
    public static function assertOwnership(string $table, int $id, array $data): void
    {
        $key = 'id_'.substr($table, strlen('gestion.'));
        $record = DB::table($table)->where($key, $id)->lockForUpdate()->first();
        if (! $record) {
            return;
        }
        $field = $table === 'gestion.practica' ? 'id_persona_practicante' : 'id_persona';
        if (! array_key_exists($field, $data) || (string) $record->$field === (string) $data[$field]) {
            return;
        }
        if ($table === 'gestion.practica') {
            $person = DB::table('gestion.persona_practicante')->where('id_persona_practicante', $data[$field])->sharedLock()->first();
            if (! $person) {
                throw new DomainException('El practicante seleccionado no existe.', 422);
            }
            $certificates = DB::table('certificado.certificado')->where('id_practica', $id);
            $personId = $person->id_persona;
        } elseif ($table === 'gestion.persona_practicante') {
            $certificates = DB::table('certificado.certificado as c')->join('gestion.practica as p', 'p.id_practica', '=', 'c.id_practica')->where('p.id_persona_practicante', $id);
            $personId = $data[$field];
        } else {
            if (DB::table('certificado.firma')->where('id_persona_trabajador', $id)->exists()
                || DB::table('certificado.reconocimiento')->where('id_persona_trabajador', $id)->exists()) {
                throw new DomainException('El trabajador tiene firmas o reconocimientos y debe conservar su titularidad.', 409);
            }

            return;
        }
        $column = $table === 'gestion.practica' ? 'id_persona' : 'c.id_persona';
        if ($certificates->where($column, '<>', $personId)->exists()) {
            throw new DomainException('El cambio de titularidad afectaría certificados existentes.', 409);
        }
    }

    /**
     * Restrict writes to submitted, authorized columns whose values changed.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function changes(string $table, array $data, object $record): array
    {
        $fields = match ($table) {
            'gestion.persona' => ['nombres', 'apellido_paterno', 'apellido_materno', 'telefono', 'direccion', 'correo', 'fecha_registro'],
            'gestion.tipo_documento' => ['nombre', 'estado'],
            'gestion.persona_documento' => ['id_persona', 'id_tipo_documento', 'numero_documento', 'estado'],
            'gestion.area' => ['nombre'],
            'gestion.institucion' => ['nombre_institucion', 'pais', 'tipo'],
            'gestion.persona_trabajador' => ['id_persona', 'id_area', 'cargo', 'fecha_ingreso', 'fecha_fin', 'estado'],
            'gestion.persona_practicante' => ['id_persona', 'codigo', 'estado'],
            'gestion.practica' => ['id_persona_practicante', 'id_institucion', 'id_area', 'carrera', 'fecha_inicio', 'fecha_fin', 'estado'],
            'gestion.practica_supervisor' => ['id_practica', 'id_persona_trabajador', 'tipo_supervision', 'fecha_inicio', 'fecha_fin', 'estado'],
            'gestion.persona_ponente' => ['id_persona', 'id_institucion', 'cargo', 'especialidad'],
            default => [],
        };

        return array_filter(array_intersect_key($data, array_flip($fields)), function ($value, string $field) use ($record): bool {
            $previous = $record->$field ?? null;
            $previous = is_bool($previous) ? (int) $previous : $previous;
            $value = is_bool($value) ? (int) $value : $value;

            return $value === null ? $previous !== null : ($previous === null || (string) $value !== (string) $previous);
        }, ARRAY_FILTER_USE_BOTH);
    }

    /** @param array<string, mixed> $changes */
    public static function assertHistoricalUpdate(string $table, object $record, array $changes): void
    {
        if ($table === 'gestion.practica_supervisor'
            && array_intersect_key($changes, array_flip(['id_practica', 'id_persona_trabajador', 'tipo_supervision', 'fecha_inicio', 'fecha_fin'])) !== []) {
            $practiceIds = array_unique([$record->id_practica, $changes['id_practica'] ?? $record->id_practica]);
            DB::table('gestion.practica')->whereIn('id_practica', $practiceIds)->orderBy('id_practica')->lockForUpdate()->get();
            if (DB::table('certificado.certificado')->whereIn('id_practica', $practiceIds)->exists()) {
                throw new DomainException('La supervisión sustenta certificados y debe conservarse.', 409);
            }
        }
        if ($table === 'gestion.persona_documento'
            && array_intersect_key($changes, array_flip(['id_persona', 'id_tipo_documento', 'numero_documento'])) !== []) {
            DB::table('gestion.persona')->where('id_persona', $record->id_persona)->lockForUpdate()->first();
            if (DB::table('certificado.certificado')->where('id_persona', $record->id_persona)->exists()) {
                throw new DomainException('La identidad del beneficiario de certificados debe conservarse.', 409);
            }
        }
    }

    public static function assertCanDelete(string $table, int $id): void
    {
        $key = 'id_'.substr($table, strlen('gestion.'));
        $record = DB::table($table)->where($key, $id)->lockForUpdate()->first();
        if (! $record) {
            return;
        }
        $dependencies = match ($table) {
            'gestion.persona' => ['gestion.persona_documento', 'gestion.persona_trabajador', 'gestion.persona_practicante', 'gestion.persona_ponente', 'certificado.certificado', 'certificado.participacion'],
            'gestion.tipo_documento' => ['gestion.persona_documento'],
            'gestion.area' => ['gestion.persona_trabajador', 'gestion.practica', 'certificado.actividad'],
            'gestion.institucion' => ['gestion.practica', 'gestion.persona_ponente'],
            'gestion.persona_trabajador' => ['certificado.reconocimiento', 'certificado.firma', 'gestion.practica_supervisor'],
            'gestion.persona_practicante' => ['gestion.practica'],
            'gestion.practica' => ['certificado.certificado', 'gestion.practica_supervisor'],
            'gestion.persona_ponente' => ['certificado.actividad_ponente'],
            default => [],
        };
        foreach ($dependencies as $dependency) {
            if (DB::table($dependency)->where($key, $id)->exists()) {
                throw new DomainException('El registro tiene dependencias y debe conservarse.', 409);
            }
        }
        if ($table === 'gestion.practica_supervisor' && DB::table('certificado.certificado')->where('id_practica', $record->id_practica)->exists()) {
            throw new DomainException('La supervisión sustenta certificados y debe conservarse.', 409);
        }
        if ($table === 'gestion.persona_documento' && DB::table('certificado.certificado')->where('id_persona', $record->id_persona)->exists()) {
            throw new DomainException('La identidad del beneficiario de certificados debe conservarse.', 409);
        }
    }

    public static function failure(Throwable $exception): array
    {
        if ($exception instanceof DomainException && in_array($exception->getCode(), [409, 422], true)) {
            return ['success' => false, 'message' => $exception->getMessage(), 'data' => null, 'status' => $exception->getCode()];
        }
        if ($exception instanceof QueryException && (
            in_array($exception->errorInfo[0] ?? null, ['23503', '23505'], true)
            || (($exception->errorInfo[0] ?? null) === '23000' && (str_contains($exception->getMessage(), 'FOREIGN KEY constraint failed') || str_contains($exception->getMessage(), 'UNIQUE constraint failed')))
        )) {
            return ['success' => false, 'message' => 'Conflicto con registros relacionados o restricciones de unicidad.', 'data' => null, 'status' => 409];
        }

        return [];
    }
}
