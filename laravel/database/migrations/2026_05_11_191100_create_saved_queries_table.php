<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saved queries — B4.
 *
 * Operator-named filter expressions that fire alerts when a new
 * `log_messages` row matches. Stored as a jsonb blob rather than as
 * separate columns because the operator-facing filter spec is
 * deliberately open-ended (today: subscription_id, environment,
 * status_regex, action_regex, method, since — tomorrow: response_size
 * thresholds, lat/lng buckets, etc.) and migrations to add filter
 * keys for every new dimension would be prohibitively chatty.
 *
 * The current spec — see `App\Services\AlertDelivery\SavedQueryEvaluator`
 * — accepts:
 *   {
 *     subscription_id?: string,
 *     environment?:    'production' | 'staging',
 *     status_regex?:   string (PCRE),
 *     action_regex?:   string (PCRE),
 *     method?:         'GET' | 'POST' | 'PATCH' | 'PUT' | 'DELETE',
 *     since?:          ISO 8601 timestamp
 *   }
 * Unknown keys are ignored. Empty `{}` matches every log row, which
 * is intentionally permitted (operator wanting a "new log written"
 * heartbeat into a low-noise Slack channel).
 *
 * `enabled` lets the operator pause an alert without losing the
 * config; the listener checks the column on every evaluation tick.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_queries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->json('filter');
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['user_id', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_queries');
    }
};
