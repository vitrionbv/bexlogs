<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-subscription data lifecycle knobs.
     *
     * `retention_days` — how long log_messages rows live in the hot
     * Postgres table. NULL = "keep forever" (the historical default,
     * which is why no `default(...)` is set on the column). The
     * `bex:apply-retention` cron deletes rows older than
     * `now() - retention_days days` once a night.
     *
     * `archive_after_days` — how long rows live in Postgres before
     * they're moved to the cold-tier `cold-logs` filesystem disk
     * (Hetzner Object Storage in prod, the local fake in tests). NULL
     * = "never archive". The `bex:archive-cold` cron tarpits batches
     * older than this window into compressed JSONL on object storage
     * and removes the source rows once the upload is durable.
     *
     * Range caps: 1..3650 (10 years) for both columns. The 10-year
     * ceiling is symbolic — anyone setting a 9-year archive window
     * almost certainly means "never" — but it stops a typo of
     * `30000` (≈ 82 years, well past the unsignedSmallInteger range)
     * from silently coercing into something nonsensical. The 1-day
     * floor lets an operator effectively pause hot-tier writes for a
     * specific subscription by setting retention=1; that's a niche
     * but legitimate "I'm debugging a noisy webhook" workflow.
     *
     * Both columns are nullable smallint (max 65535), so the row size
     * grows by 2 + 2 = 4 bytes per subscription — negligible. We
     * intentionally do NOT seed a value via the model `$attributes`
     * default: NULL is the meaningful "feature off" state, and a
     * non-null default would silently opt every existing subscription
     * into a retention window without operator consent.
     */
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->unsignedSmallInteger('retention_days')
                ->nullable()
                ->default(null)
                ->after('token_echo_max_attempts');

            $table->unsignedSmallInteger('archive_after_days')
                ->nullable()
                ->default(null)
                ->after('retention_days');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['retention_days', 'archive_after_days']);
        });
    }
};
