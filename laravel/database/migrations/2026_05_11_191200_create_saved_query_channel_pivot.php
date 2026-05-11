<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saved-query → Alert-channel pivot — B4.
 *
 * Keyed by the (saved_query_id, alert_channel_id) pair so an operator
 * can wire one query into multiple channels (Slack + email +
 * PagerDuty webhook for the same critical event) without having to
 * duplicate the filter spec.
 *
 * `dedupe_window_seconds` is the per-link "how often will we deliver
 * to this channel for this query" knob. Default 60s gives the
 * AlertOnLogBatchListener a comfortable burst window: a
 * BookingExperts dashboard occasionally pings the logs endpoint with
 * 5-10 near-identical 422s in a sub-second tick, and we don't want to
 * spam Slack 10 times for a single operator-visible incident.
 *
 * The dedupe key is a hash of (saved_query_id, channel_id, payload
 * fingerprint); see `DeliverAlertJob`. Setting the window to 0 turns
 * dedup off entirely for one-row-per-delivery channels (e.g. a
 * webhook that already de-duplicates server-side).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_query_channel', function (Blueprint $table) {
            $table->id();
            $table->foreignId('saved_query_id')->constrained()->cascadeOnDelete();
            $table->foreignId('alert_channel_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('dedupe_window_seconds')->default(60);
            $table->timestamps();

            $table->unique(['saved_query_id', 'alert_channel_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_query_channel');
    }
};
