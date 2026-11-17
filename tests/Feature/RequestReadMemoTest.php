<?php

namespace Tests\Feature;

use App\Services\RequestReadMemo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RequestReadMemoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('memo_probe', function (Blueprint $table): void {
            $table->id();
            $table->string('value');
        });
        DB::table('memo_probe')->insert(['id' => 1, 'value' => 'original']);
    }

    public function test_repeated_reads_and_null_results_run_once_per_request(): void
    {
        Route::get('/memo-probe', function (): array {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $resolve = fn (): ?object => DB::table('memo_probe')->find(1);
            $first = RequestReadMemo::remember('record', $resolve);
            $first->value = 'changed by caller';
            $second = RequestReadMemo::remember('record', $resolve);
            $missing = fn (): ?object => DB::table('memo_probe')->find(99);
            RequestReadMemo::remember('missing', $missing);

            return ['value' => $second->value, 'missing' => RequestReadMemo::remember('missing', $missing),
                'queries' => count(DB::getQueryLog())];
        });

        $this->getJson('/memo-probe')->assertOk()->assertExactJson(['value' => 'original', 'missing' => null, 'queries' => 2]);
        DB::table('memo_probe')->update(['value' => 'next request']);
        $this->getJson('/memo-probe')->assertOk()->assertExactJson(['value' => 'next request', 'missing' => null, 'queries' => 2]);
    }

    public function test_a_write_invalidates_reads_within_the_same_request(): void
    {
        Route::get('/memo-probe', function (): array {
            $read = fn (): ?string => DB::table('memo_probe')->where('id', 1)->value('value');
            $before = RequestReadMemo::remember('record', $read);
            DB::table('memo_probe')->where('id', 1)->update(['value' => 'updated']);

            return ['before' => $before, 'after' => RequestReadMemo::remember('record', $read)];
        });

        $this->getJson('/memo-probe')->assertOk()->assertExactJson(['before' => 'original', 'after' => 'updated']);
    }

    public function test_transactions_do_not_reuse_reads_or_keep_rolled_back_values(): void
    {
        Route::get('/memo-probe', function (): array {
            $read = fn (): ?string => DB::table('memo_probe')->where('id', 1)->value('value');
            RequestReadMemo::remember('record', $read);
            DB::beginTransaction();
            try {
                DB::table('memo_probe')->where('id', 1)->update(['value' => 'uncommitted']);
                DB::enableQueryLog();
                DB::flushQueryLog();
                $inside = RequestReadMemo::remember('record', $read);
                RequestReadMemo::remember('record', $read);
                $queries = count(DB::getQueryLog());
            } finally {
                DB::rollBack();
            }

            return ['inside' => $inside, 'queries' => $queries, 'after' => RequestReadMemo::remember('record', $read)];
        });

        $this->getJson('/memo-probe')->assertOk()->assertExactJson(['inside' => 'uncommitted', 'queries' => 2, 'after' => 'original']);
    }

    public function test_a_resolver_that_writes_cannot_store_an_obsolete_value(): void
    {
        Route::get('/memo-probe', function (): array {
            $before = RequestReadMemo::remember('record', function (): ?string {
                $value = DB::table('memo_probe')->where('id', 1)->value('value');
                DB::table('memo_probe')->where('id', 1)->update(['value' => 'updated']);

                return $value;
            });

            return ['before' => $before, 'after' => RequestReadMemo::remember('record',
                fn (): ?string => DB::table('memo_probe')->where('id', 1)->value('value'))];
        });

        $this->getJson('/memo-probe')->assertOk()->assertExactJson(['before' => 'original', 'after' => 'updated']);
    }

    public function test_unrouted_reads_are_not_memoized(): void
    {
        $read = fn (): ?string => DB::table('memo_probe')->where('id', 1)->value('value');
        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->assertSame('original', RequestReadMemo::remember('record', $read));
        $this->assertSame('original', RequestReadMemo::remember('record', $read));
        $this->assertCount(2, DB::getQueryLog());
    }

    public function test_connection_results_are_isolated(): void
    {
        config(['database.connections.memo_other' => config('database.connections.sqlite')]);
        Schema::connection('memo_other')->create('memo_probe', function (Blueprint $table): void {
            $table->id();
            $table->string('value');
        });
        DB::connection('memo_other')->table('memo_probe')->insert(['id' => 1, 'value' => 'other database']);
        Route::get('/memo-probe', fn (): array => [
            'first' => RequestReadMemo::remember('record', fn (): ?string => DB::table('memo_probe')->value('value')),
            'second' => RequestReadMemo::remember('record', fn (): ?string => DB::connection('memo_other')->table('memo_probe')->value('value'), 'memo_other'),
        ]);

        $this->getJson('/memo-probe')->assertOk()->assertExactJson(['first' => 'original', 'second' => 'other database']);
    }
}
