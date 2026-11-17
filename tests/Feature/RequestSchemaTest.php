<?php

namespace Tests\Feature;

use App\Services\RequestSchema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RequestSchemaTest extends TestCase
{
    public function test_column_checks_share_metadata_and_preserve_missing_column_validation(): void
    {
        Schema::create('schema_probe', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        $this->routedRequest();
        DB::enableQueryLog();

        $this->assertTrue(RequestSchema::hasColumn('schema_probe', 'NAME'));
        $metadataQueries = count(DB::getQueryLog());
        $this->assertTrue(RequestSchema::hasColumns('schema_probe', ['id', 'name']));
        $this->assertFalse(RequestSchema::hasColumns('schema_probe', ['id', 'missing']));
        $this->assertFalse(RequestSchema::hasColumn('schema_probe', 'missing'));
        $this->assertGreaterThan(0, $metadataQueries);
        $this->assertCount($metadataQueries, DB::getQueryLog());
    }

    public function test_index_checks_share_metadata_and_preserve_type_and_column_order(): void
    {
        Schema::create('schema_probe', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unique(['id', 'name'], 'probe_unique');
        });
        $this->routedRequest();
        DB::enableQueryLog();
        RequestSchema::getIndexes('schema_probe');
        $metadataQueries = count(DB::getQueryLog());

        $this->assertTrue(RequestSchema::hasIndex('schema_probe', 'probe_unique', 'UNIQUE'));
        $this->assertTrue(RequestSchema::hasIndex('schema_probe', ['id', 'name'], 'unique'));
        $this->assertFalse(RequestSchema::hasIndex('schema_probe', ['name', 'id'], 'unique'));
        $this->assertFalse(RequestSchema::hasIndex('schema_probe', 'probe_unique', 'primary'));
        $this->assertFalse(RequestSchema::hasIndex('schema_probe', 'missing'));
        $this->assertCount($metadataQueries, DB::getQueryLog());
    }

    public function test_metadata_is_not_reused_between_requests(): void
    {
        Schema::create('schema_probe', fn (Blueprint $table) => $table->id());
        $this->routedRequest();
        $this->assertFalse(RequestSchema::hasColumn('schema_probe', 'added'));
        Schema::table('schema_probe', fn (Blueprint $table) => $table->string('added'));

        $this->routedRequest();

        $this->assertTrue(RequestSchema::hasColumn('schema_probe', 'added'));
    }

    public function test_metadata_is_not_reused_for_a_different_connection(): void
    {
        Schema::create('schema_probe', fn (Blueprint $table) => $table->id());
        $this->routedRequest();
        $this->assertFalse(RequestSchema::hasColumn('schema_probe', 'added'));
        config(['database.connections.schema_probe' => config('database.connections.sqlite')]);
        Schema::connection('schema_probe')->create('schema_probe', function (Blueprint $table): void {
            $table->id();
            $table->string('added');
        });

        DB::setDefaultConnection('schema_probe');

        $this->assertTrue(RequestSchema::hasColumn('schema_probe', 'added'));
    }

    public function test_unrouted_execution_does_not_keep_stale_metadata(): void
    {
        Schema::create('schema_probe', fn (Blueprint $table) => $table->id());
        $this->assertFalse(RequestSchema::hasColumn('schema_probe', 'added'));

        Schema::table('schema_probe', fn (Blueprint $table) => $table->string('added'));

        $this->assertTrue(RequestSchema::hasColumn('schema_probe', 'added'));
    }

    private function routedRequest(): void
    {
        $request = Request::create('/schema-probe');
        $request->setRouteResolver(fn (): Route => new Route('GET', 'schema-probe', fn (): string => 'ok'));
        $this->app->instance('request', $request);
    }
}
