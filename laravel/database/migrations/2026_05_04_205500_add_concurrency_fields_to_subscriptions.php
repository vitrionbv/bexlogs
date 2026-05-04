<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-subscription concurrency controls. Replaces the
 * "exactly one queued/running job per subscription" hard rule
 * (enforced by the partial unique index `scrape_jobs_active_unique_idx`)
 * with two configurable knobs:
 *
 *   - `max_concurrent_jobs`    (1..10, default 1)
 *   - `job_spacing_minutes`    (1..120, default 10)
 *
 * Defaults preserve today's behaviour bit-for-bit: cap=1 means at most
 * one job, spacing=10 reserves the slot long enough that a follow-up
 * scheduler tick won't pile on. Operators raise these per-subscription
 * from the Manage UI when a single run can't keep up.
 *
 * The DB-level unique index is replaced with a non-unique partial
 * supporting index so the new active-count query
 * (`WHERE subscription_id=? AND status IN ('queued','running')`)
 * stays cheap. Application-level enforcement moves to
 * `App\Services\ScrapeEnqueueGuard`.
 *
 * Postgres-gated, mirroring the original 2026_05_01_192628 migration.
 * SQLite (test driver) keeps the column adds and skips the partial
 * index swap entirely.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->unsignedTinyInteger('max_concurrent_jobs')
                ->default(1)
                ->after('max_duration_minutes');

            $table->unsignedSmallInteger('job_spacing_minutes')
                ->default(10)
                ->after('max_concurrent_jobs');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS scrape_jobs_active_unique_idx');
            DB::statement(
                "CREATE INDEX IF NOT EXISTS scrape_jobs_subscription_active_idx ON scrape_jobs (subscription_id, status) WHERE status IN ('queued','running')"
            );
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS scrape_jobs_subscription_active_idx');
            DB::statement(
                "CREATE UNIQUE INDEX IF NOT EXISTS scrape_jobs_active_unique_idx ON scrape_jobs (subscription_id) WHERE status IN ('queued','running')"
            );
        }

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn([
                'max_concurrent_jobs',
                'job_spacing_minutes',
            ]);
        });
    }
};
