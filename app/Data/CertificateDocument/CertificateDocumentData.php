<?php

namespace App\Data\CertificateDocument;

use App\Services\CertificateDocumentGenerator;
use App\Services\SessionCertificatePreparation;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class CertificateDocumentData
{
    private const TABLE = 'certificado.certificado_documento';

    private const KEY = 'id_certificado_documento';

    private const RULES = [
        'id_certificado' => 'integer',
        'codigo_verificacion' => 'string|max:30',
        'ruta_archivo' => 'string|max:255',
        'motivo_reemision' => 'nullable|string|max:255',
        'estado' => 'in:Vigente,Anulado',
        'fecha_generacion' => 'date_format:Y-m-d',
    ];

    private const FOREIGN_KEYS = [
        'id_certificado' => [
            0 => 'certificado.certificado',
            1 => 'id_certificado',
        ],
    ];

    private const UNIQUE_KEYS = [
        0 => [
            0 => 'codigo_verificacion',
        ],
    ];

    private const DEPENDENCIES = [
        0 => [
            0 => 'certificado.certificado_firma',
            1 => 'id_certificado_documento',
        ],
        1 => [
            0 => 'certificado.consulta_certificado',
            1 => 'id_certificado_documento',
        ],
    ];

    private const HIDDEN = [
        0 => 'ruta_archivo',
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
            $query->whereNotExists(function (Builder $certificate): void {
                $certificate->selectRaw('1')->from('certificado.certificado as reserved')
                    ->whereColumn('reserved.id_certificado', self::TABLE.'.id_certificado')->whereIn('reserved.estado', ['PENDIENTE_FIRMA', 'FIRMADO']);
            });
            if (Schema::hasColumn(self::TABLE, 'estado_flujo')) {
                $query->whereNull('estado_flujo');
            }
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
            abort_if(($record->estado_flujo ?? null) !== null, 409, 'El documento preparado y su historial son inmutables; use el circuito administrativo.');
            $changes = self::validated($input, $partial);
            $data = array_replace((array) $record, $changes);
            unset($data[self::KEY]);
            self::checkRelations($data);
            self::businessRules($data, $record);
            self::checkDuplicates($data, $id);
            $updates = [];
            foreach (array_intersect_key($data, array_fill_keys(array_merge(array_keys($changes), ['hash_certificado']), true)) as $field => $value) {
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
            abort(409, 'La evidencia institucional y el historial deben conservarse.');
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
        if (Schema::hasColumn('certificado.certificado', 'id_actividad_sesion')) {
            abort_if(DB::table('certificado.certificado as c')->whereIn('c.id_certificado', array_values(array_filter([
                $data['id_certificado'] ?? null, $record?->id_certificado,
            ])))->whereNotNull('c.id_actividad_sesion')->whereNull('c.fecha_emision')
                ->whereExists(fn (Builder $document) => $document->selectRaw('1')->from(self::TABLE.' as approved')
                    ->whereColumn('approved.id_certificado', 'c.id_certificado'))->exists(),
                409, 'El PDF aprobado de sesión se conserva mediante el circuito documental.');
        }
        abort_if(DB::table('certificado.certificado')->whereIn('id_certificado', array_values(array_filter([
            $data['id_certificado'] ?? null, $record?->id_certificado,
        ])))->whereIn('estado', ['PENDIENTE_FIRMA', 'FIRMADO'])->exists(), 409,
            'El documento remitido no puede sustituirse o modificarse mediante el CRUD.');
        if (! $record && Schema::hasColumn(self::TABLE, 'estado_flujo')) {
            abort_if(DB::table('certificado.certificado')->where('id_certificado', $data['id_certificado'])->value('estado') === 'PREPARADO'
                || DB::table(self::TABLE)->where('id_certificado', $data['id_certificado'])->whereNotNull('estado_flujo')->exists(),
                409, 'Los documentos preparados se registran exclusivamente mediante confirmación de revisión.');
        }
        if ($record && DB::table('certificado.certificado')->where('id_certificado', $record->id_certificado)->value('estado') === SessionCertificatePreparation::DEMONSTRATION_STATE) {
            foreach (['id_certificado', 'codigo_verificacion', 'ruta_archivo', 'fecha_generacion'] as $field) {
                abort_if(($data[$field] ?? null) != $record->$field, 409, 'La identidad y el contenido del documento generado son inmutables.');
            }
            app(CertificateDocumentGenerator::class)->assertArtifact($record);
            abort_if($record->estado === 'Anulado' && $data['estado'] !== 'Anulado', 409, 'Un documento anulado no se reactiva mediante el CRUD genérico.');
        }
        abort_if($record && str_starts_with($record->ruta_archivo, 'certificados/desarrollo/'), 409,
            'Un documento generado debe conservar su código, contenido e identidad; requiere un procedimiento de reemisión.');
        self::checkDuplicates($data, $record ? $record->{self::KEY} : null);
        if ($record) {
            self::assertNoDependencies($record->{self::KEY});
        }
        $file = self::privateFile($data['ruta_archivo'], 'certificados');
        $stream = fopen($file, 'rb');
        abort_unless($stream !== false, 409, 'No se puede leer el documento.');
        try {
            if (fread($stream, 5) !== '%PDF-') {
                throw ValidationException::withMessages(['ruta_archivo' => 'El archivo debe ser un documento PDF real.']);
            }
            rewind($stream);
            $context = hash_init('sha256');
            hash_update_stream($context, $stream);
            $data['hash_certificado'] = hash_final($context);
        } finally {
            fclose($stream);
        }
        $previous = DB::table(self::TABLE)->where('id_certificado', $data['id_certificado']);
        if ($record) {
            $previous->where(self::KEY, '<>', $record->{self::KEY});
        }
        if ($previous->lockForUpdate()->exists() && empty($data['motivo_reemision'])) {
            throw ValidationException::withMessages(['motivo_reemision' => 'Indique el motivo de reemisión.']);
        }
        $duplicate = DB::table(self::TABLE)->where('id_certificado', $data['id_certificado'])->where('hash_certificado', $data['hash_certificado']);
        if ($record) {
            $duplicate->where(self::KEY, '<>', $record->{self::KEY});
        }
        abort_if($duplicate->exists(), 409, 'El contenido del documento ya está registrado para este certificado.');
    }

    private static function privateFile(string $path, string $directory): string
    {
        // Only pre-existing files beneath the private local storage root are accepted.
        $root = realpath(Storage::disk('local')->path($directory));
        $file = realpath(Storage::disk('local')->path($path));
        foreach ([public_path(), storage_path('app/public')] as $publicDirectory) {
            $publicRoot = realpath($publicDirectory);
            abort_if($file && $publicRoot && str_starts_with(strtolower($file), strtolower($publicRoot.DIRECTORY_SEPARATOR)), 422, 'El archivo debe permanecer en almacenamiento privado.');
        }
        if (! str_starts_with($path, $directory.'/') || str_contains($path, '..') || str_contains($path, '\\') || ! $root || ! $file || ! is_file($file) || ! str_starts_with(strtolower($file), strtolower($root.DIRECTORY_SEPARATOR))) {
            throw ValidationException::withMessages(['ruta_archivo' => 'Se requiere un archivo existente en el almacenamiento privado autorizado.']);
        }

        return $file;
    }
}
