<?php

namespace App\Services;

use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

class RequestReadMemo
{
    /**
     * Reuse explicit reads only inside a routed request, outside transactions.
     * Objects are cloned so callers cannot mutate the stored snapshot.
     */
    public static function remember(string $key, Closure $resolve, ?string $connectionName = null): mixed
    {
        $request = request();
        $connection = DB::connection($connectionName);
        if ($request->route() === null || $connection->transactionLevel() > 0) {
            return $resolve();
        }
        $revision = $request->attributes->get(self::class.'.revision', 0);
        $key = hash('sha256', serialize([$key, spl_object_id($connection), spl_object_id($connection->getPdo()),
            $connection->getDatabaseName(), $revision]));
        $values = $request->attributes->get(self::class, []);
        if (array_key_exists($key, $values)) {
            return self::copy($values[$key]);
        }
        $value = $resolve();
        if ($revision === $request->attributes->get(self::class.'.revision', 0)) {
            $values = $request->attributes->get(self::class, []);
            $values[$key] = self::copy($value);
            $request->attributes->set(self::class, $values);
        }

        return $value;
    }

    public static function invalidateAfterWrite(QueryExecuted $query): void
    {
        if (preg_match('/^\s*\(*\s*(select|show|explain)\b/i', $query->sql) === 1) {
            return;
        }
        $request = request();
        $request->attributes->remove(self::class);
        $request->attributes->set(self::class.'.revision', $request->attributes->get(self::class.'.revision', 0) + 1);
    }

    private static function copy(mixed $value): mixed
    {
        return is_object($value) ? clone $value : $value;
    }
}
