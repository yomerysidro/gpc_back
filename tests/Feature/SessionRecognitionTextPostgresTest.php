<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('postgresql')]
class SessionRecognitionTextPostgresTest extends TestCase
{
    public function test_real_schema_and_historical_artifacts_support_text_persistence_without_changes(): void
    {
        if (getenv('SESSION_RECOGNITION_TEXT_NEON_READ_ONLY') !== '1') {
            $this->markTestSkipped('Requiere autorización de lectura real; no modifica documentos.');
        }
        config(['database.default' => 'pgsql', 'database.connections.pgsql.database' => 'certificados_morales', 'database.connections.pgsql.url' => null]);
        DB::purge('pgsql');
        $identity = DB::selectOne('SELECT current_database() AS database, current_user AS username, current_schema() AS schema');
        $this->assertSame('certificados_morales', $identity->database);
        $this->assertStringEndsWith('.neon.tech', DB::connection()->getConfig('host'));
        DB::beginTransaction();
        DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
        $before = $this->snapshot();
        $disk = Storage::disk('local');
        $files = collect($disk->allFiles('certificados'))->mapWithKeys(fn (string $path): array => [$path => hash_file('sha256', $disk->path($path))])->all();
        $this->beforeApplicationDestroyed(function () use ($before, $disk, $files): void {
            try {
                $this->assertSame($before, $this->snapshot());
                $this->assertSame($files, collect($disk->allFiles('certificados'))->mapWithKeys(fn (string $path): array => [$path => hash_file('sha256', $disk->path($path))])->all());
            } finally {
                while (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }
            }
        });
        $columns = DB::table('information_schema.columns')->where('table_schema', 'certificado')
            ->whereIn('table_name', ['reconocimiento', 'certificado_documento'])->orderBy('table_name')->orderBy('ordinal_position')
            ->get(['table_name', 'column_name', 'data_type', 'character_maximum_length', 'is_nullable']);
        fwrite(STDOUT, json_encode(compact('identity', 'columns'), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL);
        $reason = $columns->first(fn (object $column): bool => $column->table_name === 'reconocimiento' && $column->column_name === 'motivo');
        $this->assertNotNull($reason);
        $this->assertTrue($reason->data_type === 'text' || (int) $reason->character_maximum_length >= 500);
        $this->assertTrue($columns->contains('column_name', 'id_actividad_ponente'));
        $this->assertTrue($columns->contains('column_name', 'hash_certificado'));
        $this->assertSame('on', DB::selectOne('SHOW transaction_read_only')->transaction_read_only);
    }

    /** @return array<string, string> */
    private function snapshot(): array
    {
        $snapshots = [];
        foreach (['certificado.actividad_ponente', 'certificado.reconocimiento', 'certificado.certificado',
            'certificado.certificado_documento', 'certificado.certificado_firma'] as $table) {
            $snapshots[$table] = DB::selectOne('SELECT md5(COALESCE(jsonb_agg(to_jsonb(t) ORDER BY 1)::text, \'[]\')) AS fingerprint FROM (SELECT * FROM '.$table.' ORDER BY 1) t')->fingerprint;
        }

        return $snapshots;
    }
}
