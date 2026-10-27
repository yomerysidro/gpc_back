<?php

namespace Tests\Feature;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\PostgresModuleConnection;
use Tests\TestCase;

class PostgresModuleSafetyTest extends TestCase
{
    public static function unsafeConnections(): array
    {
        return [
            'missing explicit confirmation' => ['postgresql://fixture@localhost/certificados_morales_test', false],
            'institutional database' => ['postgresql://fixture@localhost/certificados_morales', true],
            'another project database' => ['postgresql://fixture@localhost/other_test', true],
            'wrong driver' => ['mysql://fixture@localhost/certificados_morales_test', true],
            'missing host and user' => ['pgsql:///certificados_morales_test', true],
            'database query override' => ['postgresql://fixture@localhost/certificados_morales_test?database=certificados_morales', true],
            'url query override' => ['postgresql://fixture@localhost/certificados_morales_test?url=other', true],
            'invalid ssl mode' => ['postgresql://fixture@localhost/certificados_morales_test?sslmode[]=require', true],
        ];
    }

    #[DataProvider('unsafeConnections')]
    public function test_rejects_unsafe_configuration_before_connecting(string $url, bool $exclusive): void
    {
        $this->expectException(InvalidArgumentException::class);
        PostgresModuleConnection::configuration($url, $exclusive);
    }

    public function test_dedicated_configuration_does_not_inherit_the_application_connection(): void
    {
        config(['database.connections.pgsql.host' => 'institutional.invalid', 'database.connections.pgsql.database' => 'certificados_morales']);
        $configuration = PostgresModuleConnection::configuration('postgresql://fixture@localhost/certificados_morales_test?sslmode=require', true);
        $this->assertSame('pgsql', $configuration['driver']);
        $this->assertSame('public', $configuration['search_path']);
        $this->assertArrayNotHasKey('password', $configuration);
        $this->assertArrayNotHasKey('host', $configuration);
        $this->assertSame('institutional.invalid', config('database.connections.pgsql.host'));
    }
}
