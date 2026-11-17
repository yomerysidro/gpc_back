<?php

namespace Tests\Fixtures;

use InvalidArgumentException;

class PostgresModuleConnection
{
    /**
     * Build a dedicated test connection without inheriting institutional credentials.
     *
     * @return array<string, mixed>
     */
    public static function configuration(string $url, bool $exclusive): array
    {
        $parts = parse_url($url);
        parse_str($parts['query'] ?? '', $query);
        if (! $exclusive || $parts === false
            || ! in_array($parts['scheme'] ?? '', ['pgsql', 'postgres', 'postgresql'], true)
            || empty($parts['host']) || empty($parts['user'])
            || ($parts['path'] ?? '') !== '/certificados_morales_test'
            || isset($parts['fragment'])
            || array_diff(array_keys($query), ['sslmode']) !== []
            || (isset($query['sslmode']) && ! in_array($query['sslmode'], ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'], true))) {
            throw new InvalidArgumentException('Se requiere una URL PostgreSQL dedicada a certificados_morales_test y confirmación explícita de uso exclusivo.');
        }

        return [
            'driver' => 'pgsql',
            'url' => $url,
            'prefix' => '',
            'charset' => 'utf8',
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ];
    }
}
