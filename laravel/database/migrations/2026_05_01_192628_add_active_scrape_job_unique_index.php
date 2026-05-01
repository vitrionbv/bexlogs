<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DB-level guard that exactly one scrape_job per subscription can be in
 * `queued` or `running` state at any moment. The app already does an
 * `exists()` check in `ManageController::enqueueScrape` and `ScrapeEnqueue`
 * before inserting, but the gap between SELECT and INSERT is wide enough
 * that two concurrent requests (double-click "Scrape now", or scheduler
 * + manual click within the same second) can both pass the check and
 * insert duplicate `queued` rows. The Node worker would then run the
 * same scrape twice back-to-back.
 *
 * Postgres partial unique index gives us the airtight version: the
 * second insert raises `unique_violation` and the `QueryException`
 * try/catch in both call sites surfaces it as the same
 * "scrape-already-queued" UX path the existing app-level check uses.
 *
 * SQLite (test driver) doesn't support partial indexes via Schema
 * builder the same way, so we skip the constraint there. The existing
 * test fixtures don't create overlapping jobs for the same subscription,
 * and the Pest race test that does exercise the constraint is gated
 * to pgsql with `markTestSkipped` on sqlite.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("CREATE UNIQUE INDEX scrape_jobs_active_unique_idx ON scrape_jobs (subscription_id) WHERE status IN ('queued','running')");
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS scrape_jobs_active_unique_idx');
        }
    }
};
