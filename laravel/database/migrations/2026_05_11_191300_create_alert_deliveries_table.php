<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alert deliveries audit log — B4.
 *
 * One row per attempted delivery to a channel. The UI surfaces the
 * latest 50 per query and per channel so an operator can answer
 * "did Slack get the alert?" without grepping container logs.
 *
 * `log_message_id` is nullable on purpose: B5's system-emitted alerts
 * (session expiry, consecutive failures, quiet subscriptions) are not
 * tied to a specific log row. The payload column carries the full
 * rendered notification for both kinds — easier than maintaining two
 * audit tables.
 *
 * `attempts` + `last_attempt_at` + `error` mirror the queue-job
 * retry pattern. The DeliverAlertJob's exception path bumps
 * `attempts`, captures the throwable's message in `error`, and lets
 * Laravel's queue runner re-dispatch with backoff. Once attempts hit
 * the configured ceiling the row stays as `failed` and the operator
 * gets a follow-up notification through the system-alert channel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('saved_query_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->foreignId('alert_channel_id')
                ->constrained()
                ->cascadeOnDelete();
            // `log_messages.id` is bigint unsigned but NOT a foreign key:
            // log rows are aggressively pruned (retention/cold storage in
            // Agent 5's scope) and we want delivery audit rows to outlive
            // the log row that triggered them. Cascading-delete would
            // erase the audit trail every time the retention sweep runs.
            $table->unsignedBigInteger('log_message_id')->nullable();
            $table->json('payload');
            $table->string('status', 16)->default('queued');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('last_attempt_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['saved_query_id', 'created_at']);
            $table->index(['alert_channel_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_deliveries');
    }
};
