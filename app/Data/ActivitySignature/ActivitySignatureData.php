<?php

namespace App\Data\ActivitySignature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class ActivitySignatureData
{
    private const TABLE = 'certificado.actividad_firma';

    private const KEY = 'id_actividad_firma';

    private const RULES = [
        'id_actividad' => 'integer',
        'id_firma' => 'integer',
        'orden_firma' => 'integer|min:1',
    ];

    private const FOREIGN_KEYS = [
        'id_actividad' => [
            0 => 'certificado.actividad',
            1 => 'id_actividad',
        ],
        'id_firma' => [
            0 => 'certificado.firma',
            1 => 'id_firma',
        ],
    ];

    private const UNIQUE_KEYS = [
        0 => [
            0 => 'id_actividad',
            1 => 'id_firma',
        ],
        1 => [
            0 => 'id_actividad',
            1 => 'orden_firma',
        ],
    ];

    private const DEPENDENCIES = [
    ];

    private const HIDDEN = [
    ];

    public static function list(array $filters = []): array
    {
        return self::execute(function () use ($filters) {
            $rules = ['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100'];
            foreach (self::RULES as $field => $rule) {
                if (! in_array($field, array_merge(self::HIDDEN, ['passphrase']), true)) {
                    $rules[$field] = 'sometimes|'.$rule;
                }
            }
            $filters = Validator::make($filters, $rules)->validate();
            $query = DB::table(self::TABLE)->select(self::columns());
            foreach ($filters as $field => $value) {
                if (array_key_exists($field, self::RULES)) {
                    $query->where($field, $value);
                }
            }

            return $query->orderBy(self::KEY, 'desc')->paginate($filters['per_page'] ?? 20, ['*'], 'page', $filters['page'] ?? 1);
        });
    }

    public static function detail($id): array
    {
        return self::execute(fn () => self::find($id));
    }

    public static function store(array $input): array
    {
        return self::execute(fn () => DB::transaction(function () use ($input) {
            $data = self::validated($input, false);
            self::checkRelations($data);
            self::businessRules($data, null);
            self::checkDuplicates($data);
            $id = DB::table(self::TABLE)->insertGetId($data, self::KEY);

            return self::find($id);
        }), 201);
    }

    public static function update($id, array $input, bool $partial = false): array
    {
        return self::execute(fn () => DB::transaction(function () use ($id, $input, $partial) {
            $record = self::find($id, true, true);
            $changes = self::validated($input, $partial);
            $data = array_replace((array) $record, $changes);
            unset($data[self::KEY]);
            self::checkRelations($data);
            self::businessRules($data, $record);
            self::checkDuplicates($data, $id);
            $updates = [];
            foreach (array_intersect_key($data, array_fill_keys(array_keys($changes), true)) as $field => $value) {
                $previous = $record->$field ?? null;
                $currentValue = is_bool($value) ? (int) $value : $value;
                $previous = is_bool($previous) ? (int) $previous : $previous;
                if ($currentValue === null ? $previous !== null : ($previous === null || (string) $currentValue !== (string) $previous)) {
                    $updates[$field] = $value;
                }
            }
            if ($updates !== []) {
                DB::table(self::TABLE)->where(self::KEY, $id)->update($updates);
            }

            return self::find($id);
        }));
    }

    public static function destroy($id): array
    {
        return self::execute(fn () => DB::transaction(function () use ($id) {
            self::find($id, true);
            $record = self::find($id, true);
            abort_unless(Schema::hasTable('certificado.certificado_firma'), 503, 'No se puede verificar el historial de firmas.');
            abort_if(DB::table('certificado.certificado_firma')->where('id_firma', $record->id_firma)->exists(), 409, 'La firma tiene aplicaciones históricas.');
            self::assertNoDependencies($id);
            DB::table(self::TABLE)->where(self::KEY, $id)->delete();

            return null;
        }));
    }

    private static function validated(array $input, bool $partial): array
    {
        $rules = [];
        foreach (self::RULES as $field => $rule) {
            $rules[$field] = ($partial || str_contains($rule, 'nullable') ? 'sometimes|' : 'required|').$rule;
        }
        $rules[self::KEY] = 'prohibited';
        $rules['hash_certificado'] = 'prohibited';
        $rules['passphrase_cifrada'] = 'prohibited';

        return array_intersect_key(Validator::make($input, $rules)->validate(), self::RULES);
    }

    private static function checkRelations(array $data): void
    {
        foreach (self::FOREIGN_KEYS as $field => [$table, $key]) {
            if (($data[$field] ?? null) === null) {
                continue;
            }
            abort_unless(Schema::hasTable($table), 503, 'Dependencia del módulo no disponible.');
            if (! DB::table($table)->where($key, $data[$field])->lockForUpdate()->first()) {
                throw ValidationException::withMessages([$field => 'El registro relacionado no existe.']);
            }
        }
    }

    private static function checkDuplicates(array $data, $id = null): void
    {
        foreach (self::UNIQUE_KEYS as $fields) {
            $query = DB::table(self::TABLE);
            foreach ($fields as $field) {
                $query->where($field, $data[$field] ?? null);
            }
            if ($id !== null) {
                $query->where(self::KEY, '<>', $id);
            }
            abort_if($query->lockForUpdate()->exists(), 409, 'El registro o su orden ya está asignado.');
        }
    }

    private static function assertNoDependencies($id): void
    {
        foreach (self::DEPENDENCIES as [$table, $key]) {
            // Fail closed if the schema is incomplete: history must not be lost.
            abort_unless(Schema::hasTable($table), 503, 'No se pueden comprobar las dependencias del registro.');
            abort_if(DB::table($table)->where($key, $id)->lockForUpdate()->exists(), 409, 'El registro tiene dependencias históricas y no puede eliminarse ni alterarse.');
        }
    }

    private static function find($id, bool $lock = false, bool $sensitive = false): object
    {
        abort_unless(ctype_digit((string) $id) && (int) $id > 0, 404, 'Registro no encontrado.');
        $query = DB::table(self::TABLE)->where(self::KEY, $id);
        if (! $sensitive) {
            $query->select(self::columns());
        }
        $record = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_unless($record, 404, 'Registro no encontrado.');

        return $record;
    }

    private static function columns(): array
    {
        $columns = array_merge([self::KEY], array_keys(self::RULES));
        if (self::TABLE === 'certificado.certificado_documento') {
            $columns[] = 'hash_certificado';
        }

        return array_values(array_diff($columns, self::HIDDEN, ['passphrase']));
    }

    private static function execute(callable $operation, int $status = 200): array
    {
        try {
            abort_unless(Schema::hasTable(self::TABLE), 503, 'La tabla del módulo no está disponible.');

            return ['success' => true, 'message' => 'Operación realizada correctamente.', 'data' => $operation(), 'status' => $status];
        } catch (ValidationException $e) {
            return ['success' => false, 'message' => 'Invalid validation data.', 'data' => $e->errors(), 'status' => 422];
        } catch (HttpException $e) {
            return ['success' => false, 'message' => $e->getMessage(), 'status' => $e->getStatusCode()];
        } catch (QueryException $e) {
            $conflict = in_array(substr((string) $e->getCode(), 0, 2), ['23'], true);

            return ['success' => false, 'message' => $conflict ? 'Conflicto de integridad o dependencias.' : 'No se pudo completar la operación.', 'status' => $conflict ? 409 : 500];
        } catch (Throwable) {
            // Never log request data, credentials, document contents or SQL bindings.
            return ['success' => false, 'message' => 'No se pudo completar la operación.', 'status' => 500];
        }
    }

    private static function businessRules(array &$data, ?object $record): void
    {
        // A signature already used by an issued document cannot be reassigned retroactively.
        abort_unless(Schema::hasTable('certificado.certificado_firma'), 503, 'No se puede verificar el historial de firmas.');
        if ($record) {
            abort_if(DB::table('certificado.certificado_firma')->where('id_firma', $record->id_firma)->exists(), 409, 'La asignación tiene una firma aplicada y debe conservarse.');
        }
    }
}
