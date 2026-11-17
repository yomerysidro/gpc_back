<?php

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RequestSchema
{
    /** @return list<array<string, mixed>> */
    public static function getColumns(string $table): array
    {
        return self::remember('columns', [$table], fn (): array => Schema::getColumns($table));
    }

    /** @return list<string> */
    public static function getColumnListing(string $table): array
    {
        return array_column(self::getColumns($table), 'name');
    }

    public static function hasColumn(string $table, string $column): bool
    {
        return in_array(strtolower($column), array_map(strtolower(...), self::getColumnListing($table)), true);
    }

    /** @param list<string> $columns */
    public static function hasColumns(string $table, array $columns): bool
    {
        $available = array_map(strtolower(...), self::getColumnListing($table));

        return array_all($columns, fn (string $column): bool => in_array(strtolower($column), $available, true));
    }

    public static function hasTable(string $table): bool
    {
        return self::remember('table', [$table], fn (): bool => Schema::hasTable($table));
    }

    /** @return list<array<string, mixed>> */
    public static function getSchemas(): array
    {
        return self::remember('schemas', [], fn (): array => Schema::getSchemas());
    }

    /** @return list<array<string, mixed>> */
    public static function getIndexes(string $table): array
    {
        return self::remember('indexes', [$table], fn (): array => Schema::getIndexes($table));
    }

    /** @return list<array<string, mixed>> */
    public static function getForeignKeys(string $table): array
    {
        return self::remember('foreignKeys', [$table], fn (): array => Schema::getForeignKeys($table));
    }

    /** @param string|list<string> $index */
    public static function hasIndex(string $table, string|array $index, ?string $type = null): bool
    {
        $type = $type === null ? null : strtolower($type);
        foreach (self::getIndexes($table) as $value) {
            $typeMatches = $type === null
                || ($type === 'primary' && $value['primary'])
                || ($type === 'unique' && $value['unique'])
                || $type === $value['type'];
            if (($value['name'] === $index || $value['columns'] === $index) && $typeMatches) {
                return true;
            }
        }

        return false;
    }

    /**
     * Schema metadata is shared only inside the current routed request, never across requests or connections.
     *
     * @param  list<mixed>  $arguments
     */
    private static function remember(string $operation, array $arguments, Closure $resolve): mixed
    {
        $request = request();
        if ($request->route() === null) {
            return $resolve();
        }
        $connection = DB::connection();
        $key = self::class.'.'.spl_object_id($connection).'.'.hash('sha256', serialize([$connection->getDatabaseName(), $operation, $arguments]));
        if (! $request->attributes->has($key)) {
            $request->attributes->set($key, $resolve());
        }

        return $request->attributes->get($key);
    }
}
