<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-subscription rolling baselines for `duration_ms` and `rows_inserted`.
 * Recomputed nightly by `bex:compute-baselines` from the last 30 days of
 * completed scrape_jobs, and consulted by `AnomalyDetector` to flag drift
 * on each new completion.
 *
 * One row per subscription. The percentile values are floats because PG
 * `percentile_cont` returns double precision; we accept a tiny rounding
 * cost in exchange for a single canonical column type that survives
 * PG ↔ SQLite (test driver) without per-driver casts.
 *
 * `same_hour_avg_rows_inserted` stores up to 24 entries — one rolling
 * 7-day mean per hour-of-day — as a JSON map. A separate column rather
 * than a wide 24-column schema because the structure is opaque outside
 * `AnomalyDetector` and we'd rather migrate the JSON shape than the
 * table shape if the detector's appetite changes.
 *
 * `computed_at` keeps us honest about staleness: the Manage drift badge
 * and the Dashboard "Needs attention" panel both prefer to suppress
 * signals when the baselines are older than ~36 h (i.e., the nightly
 * command failed to run).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_baselines', function (Blueprint $table) {
            $table->id();

            $table->string('subscription_id');
            $table->foreign('subscription_id')
                ->references('id')
                ->on('subscriptions')
                ->cascadeOnDelete();

            $table->float('duration_p50')->nullable();
            $table->float('duration_p95')->nullable();
            $table->float('duration_p99')->nullable();

            $table->float('rows_inserted_p50')->nullable();
            $table->float('rows_inserted_p95')->nullable();
            $table->float('rows_inserted_p99')->nullable();

            // 24-entry map keyed by hour-of-day "00".."23", value is the
            // rolling 7-day mean of `stats.rows_inserted` for completed
            // jobs whose `completed_at` falls in that UTC hour. Used by
            // the "rows_inserted < 0.1× same-hour avg" drift rule.
            $table->json('same_hour_avg_rows_inserted')->nullable();

            // Diagnostic metadata so an operator (and the test suite) can
            // tell at a glance what the baseline was actually computed
            // from. `sample_size` is the count of completed scrape_jobs
            // the percentiles came from; the [from..to] window is the
            // raw 30-day slice.
            $table->unsignedInteger('sample_size')->default(0);
            $table->timestampTz('window_from')->nullable();
            $table->timestampTz('window_to')->nullable();

            $table->timestampTz('computed_at');
            $table->timestamps();

            $table->unique('subscription_id', 'subscription_baselines_sub_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_baselines');
    }
};
