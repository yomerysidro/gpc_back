<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('postgresql')]
class CertificatePublicationSchemaPostgresTest extends TestCase
{
    public function test_publication_storage_inventory_is_read_only_in_the_real_database(): void
    {
        if (getenv('CERTIFICATE_PUBLICATION_SCHEMA_READ_ONLY') !== '1') {
            $this->markTestSkipped('Requires authorized read-only publication schema inspection.');
        }
        config(['database.default' => 'pgsql', 'database.connections.pgsql.database' => 'certificados_morales',
            'database.connections.pgsql.url' => null]);
        DB::purge('pgsql');
        DB::beginTransaction();
        DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
        DB::statement("SET LOCAL statement_timeout = '10s'");
        try {
            self::assertSame('certificados_morales', DB::selectOne('select current_database() as name')->name);
            $tables = DB::select("select table_schema,table_name from information_schema.tables
                where table_type='BASE TABLE' and table_schema not in ('pg_catalog','information_schema')
                order by table_schema,table_name");
            $columns = DB::select("select table_schema,table_name,column_name,data_type,is_nullable,column_default
                from information_schema.columns where table_schema not in ('pg_catalog','information_schema')
                and (table_schema='certificado' or table_name ~* 'public|autoriz|consent|auditor|historial|evento'
                or column_name ~* 'public|revoc|autoriz|consent|gestion_entrega')
                order by table_schema,table_name,ordinal_position");
            $constraints = DB::select("select n.nspname as schema,c.relname as table_name,k.conname,pg_get_constraintdef(k.oid) as definition
                from pg_constraint k join pg_class c on c.oid=k.conrelid join pg_namespace n on n.oid=c.relnamespace
                where n.nspname='certificado' order by c.relname,k.conname");
            $permissions = DB::table('acceso.permiso')->where(function ($query): void {
                $query->where('clave', 'ilike', '%public%')->orWhere('clave', 'ilike', '%rrhh%')->orWhere('clave', 'ilike', '%emision%');
            })->get(['clave']);
            $inventory = [
                'responsible_key' => DB::table('information_schema.columns')->where('table_schema', 'acceso')
                    ->where('table_name', 'usuario')->where('column_name', 'id_usuario')->first(['column_name', 'data_type']),
                'tables' => array_map(fn (object $table): string => $table->table_schema.'.'.$table->table_name, $tables),
                'document_columns' => array_values(array_filter($columns, fn (object $column): bool => in_array($column->table_name, ['certificado_documento', 'consulta_certificado'], true))),
                'publication_candidates' => array_values(array_filter($columns, fn (object $column): bool => preg_match('/public|revoc|autoriz|consent|gestion_entrega/i', $column->column_name.' '.$column->table_name) === 1)),
                'document_constraints' => array_values(array_filter($constraints, fn (object $constraint): bool => $constraint->table_name === 'certificado_documento')),
                'permissions' => $permissions,
            ];
            fwrite(STDOUT, json_encode($inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL);
            self::assertNotEmpty($tables);
            self::assertSame('on', DB::selectOne('show transaction_read_only')->transaction_read_only);
        } finally {
            DB::rollBack();
        }
    }
}
