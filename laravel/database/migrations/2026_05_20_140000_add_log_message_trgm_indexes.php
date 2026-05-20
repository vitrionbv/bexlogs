<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Accelerate free-text log search — especially JSON body / ID lookups.
 *
 * LogQueryBuilder matches `q` and jsonFilters via ILIKE on
 * `parameters::text`, `request::text`, and `response::text`. Without
 * trigram GIN indexes those predicates devolve into parallel seq scans
 * over the entire page partition (observed ~670 ms / facet on ~935k
 * rows in prod). pg_trgm turns `%26205663%`-style needles into GIN
 * index lookups.
 *
 * Uses CREATE INDEX CONCURRENTLY on production so the ~1M-row /
 * ~4.7 GB table can be indexed without blocking writes. The migration
 * opts out of the default transaction wrapper (`$withinTransaction =
 * false`) because CONCURRENTLY is forbidden inside a transaction block.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    /** @var list<string> */
    private array $indexes = [
        'log_messages_parameters_text_trgm_idx' => '(parameters::text)',
        'log_messages_request_text_trgm_idx' => '(request::text)',
        'log_messages_response_text_trgm_idx' => '(response::text)',
        'log_messages_action_trgm_idx' => '(action)',
        'log_messages_path_trgm_idx' => '(path)',
        'log_messages_method_trgm_idx' => '(method)',
    ];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        $concurrently = app()->environment('production') ? ' CONCURRENTLY' : '';

        foreach ($this->indexes as $name => $expression) {
            DB::statement(
                "CREATE INDEX{$concurrently} IF NOT EXISTS {$name} ON log_messages USING gin ({$expression} gin_trgm_ops)",
            );
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $concurrently = app()->environment('production') ? ' CONCURRENTLY' : '';

        foreach (array_keys($this->indexes) as $name) {
            DB::statement("DROP INDEX{$concurrently} IF EXISTS {$name}");
        }
    }
};
