<?php

use App\Services\ColdLogReader;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Source-of-truth index for log_messages rows that have been
     * relocated out of the hot Postgres table into compressed JSONL
     * objects on the `cold-logs` filesystem disk.
     *
     * The manifest is what {@see ColdLogReader} consults
     * to decide WHICH `(YYYY/MM/DD)` objects to fetch back when a
     * Logs UI query covers an archived date range. Without a manifest
     * we'd have to do a `Storage::files(...)` LIST call against
     * Hetzner per page-load — that's an extra round-trip and (more
     * importantly) costs ~$0.005 per 1000 LIST calls on Hetzner's
     * pricing page. A small Postgres table is free and instant.
     *
     * Schema choices:
     *   - `subscription_id` is the canonical scope (one row per
     *     subscription per archived day) and is FK'd with cascade so
     *     deleting a subscription cleans up its manifest entries
     *     alongside its log_messages.
     *   - `date` (Y-M-D) is stored as a real DATE column so range
     *     queries (`BETWEEN ... AND ...`) compile to a single index
     *     scan rather than a string compare.
     *   - `s3_key` is the object key the archive command wrote to
     *     (e.g. `logs/123/2026/05/12.jsonl.gz`). Stored explicitly
     *     instead of being recomputed at read time so the convention
     *     can change without breaking historical fall-through reads.
     *   - `row_count` lets the Manage page show a hint
     *     ("12,345 rows archived") without a list/decompress cycle.
     *   - `archived_at` is informational; pair with `archive_after_days`
     *     on the subscription to reason about how aged-out the row
     *     was when it got archived.
     *
     * The (subscription_id, date) unique index is the merge key the
     * archive command's "object already exists, read+merge+rewrite"
     * branch keys off of — there can only ever be one manifest row
     * per (sub, day), and a partial-archive retry must update that
     * single row in-place.
     */
    public function up(): void
    {
        Schema::create('log_archive_manifest', function (Blueprint $table) {
            $table->id();
            // FK to subscriptions.id (string PK). cascadeOnDelete so a
            // subscription teardown nukes its manifest pointers along
            // with its log_messages rows.
            $table->string('subscription_id');
            $table->foreign('subscription_id')
                ->references('id')
                ->on('subscriptions')
                ->cascadeOnDelete();

            // The day this manifest row covers in UTC, stored as a
            // 10-char `YYYY-MM-DD` string rather than a native DATE
            // type. Two reasons:
            //   1. SQLite (used in tests) stores DATE columns as
            //      whatever string the binding produces, which means
            //      a `Carbon` cast round-trip serialises as
            //      `YYYY-MM-DD HH:MM:SS` and a literal `YYYY-MM-DD`
            //      comparison in WHERE clauses no longer matches.
            //      Pinning the column to a fixed `YYYY-MM-DD` string
            //      sidesteps the casting layer entirely.
            //   2. ISO date strings sort lexicographically the same
            //      way they sort chronologically, so range queries
            //      `WHERE date BETWEEN ? AND ?` still hit the
            //      composite index correctly.
            $table->char('date', 10);

            // The object key the archive command wrote to on the
            // `cold-logs` disk. We never reconstruct this from
            // (subscription_id, date) at read time — always read it
            // from the manifest so the keying convention can evolve.
            $table->string('s3_key');

            $table->unsignedInteger('row_count')->default(0);
            $table->timestamp('archived_at');

            $table->timestamps();

            // The merge key. Re-archiving a partially-uploaded day
            // updates the existing manifest row in-place rather than
            // creating a duplicate, and the cold reader looks up
            // exactly one row per (sub, date) tuple. Postgres can
            // satisfy `WHERE subscription_id = ? AND date BETWEEN ? AND ?`
            // with this same composite (subscription_id is the
            // leading column), so no separate range index is needed.
            $table->unique(['subscription_id', 'date'], 'log_archive_manifest_sub_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('log_archive_manifest');
    }
};
