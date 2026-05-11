<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * System-alert channel pivot — B5.
 *
 * Per-user choice of which of their alert channels receive the system-
 * emitted alerts (session expiring soon, consecutive scrape failures,
 * quiet subscription). Independent of the per-saved-query pivot from
 * B4 because system alerts are infrastructure-level and the operator
 * usually wants ONE high-signal channel for them (a dedicated
 * #bex-ops Slack channel, or a single email) rather than the broader
 * fanout they'd configure for log-content alerts.
 *
 * Composite primary key on (user_id, alert_channel_id) prevents the
 * same channel from getting duplicate system alerts if the operator
 * accidentally double-clicks the toggle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_alert_channels', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('alert_channel_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['user_id', 'alert_channel_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_alert_channels');
    }
};
