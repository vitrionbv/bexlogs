<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * audit_logs — append-only activity stream for the operator-facing
 * "Settings → Activity" page (F18). Every meaningful state change in
 * the app emits a row here so an operator can answer the perennial
 * "who paused this subscription / when did this session expire?"
 * question without grepping production logs.
 *
 * Design notes:
 *
 *   - `user_id` is nullable: system-emitted events (the scheduler
 *     auto-expiring a session, the worker recording a captured
 *     session, etc.) don't have a user attribution.
 *
 *   - `action` is a free-form string with a stable namespaced shape
 *     (`subscription.created`, `scrape.manual_triggered`, …). We avoid
 *     a Postgres enum because adding a new action shouldn't require a
 *     DDL change, and we want SQLite + Postgres parity for tests.
 *
 *   - `subject_type` / `subject_id` mirror Laravel's morphTo
 *     convention. `subject_id` is a string because some of our
 *     primary keys (Subscription, Organization, Application) are
 *     stringly-typed.
 *
 *   - `payload` carries before/after deltas for updates (`old` + `new`
 *     keys), or arbitrary context for non-update actions. jsonb on
 *     Postgres for index/query support, json on SQLite (tests).
 *
 *   - We deliberately do NOT add an `updated_at` column. Audit rows
 *     are immutable; only `created_at` is meaningful. Using the
 *     manual `timestampTz('created_at')` keeps the column shape close
 *     to Postgres conventions while leaving SQLite's TEXT-style
 *     datetimes working unchanged.
 *
 *   - Indexes target the three filters the page exposes: user,
 *     action, subject. The composite `(subject_type, subject_id,
 *     created_at)` is the hot path for "show me everything that
 *     happened to subscription X".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');
            $table->string('subject_type');
            $table->string('subject_id');
            $table->json('payload')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index(['action', 'created_at']);
            $table->index(['subject_type', 'subject_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
