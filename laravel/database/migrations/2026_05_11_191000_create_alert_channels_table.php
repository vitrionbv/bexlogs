<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alert delivery channels — B4.
 *
 * One row per (operator, kind, named-config) tuple. The same operator
 * can wire multiple Slack webhooks (`#bex-prod-alerts`, `#bex-staging`,
 * a personal DM channel for after-hours incidents), multiple email
 * recipients (themselves + a shared on-call mailbox), and a webhook
 * sink that forwards into PagerDuty's Events V2 API. Saved queries
 * (B4) and the system-alert pivot (B5) reference channels by id.
 *
 * `config_encrypted` is a `jsonb` blob cast via Laravel's `encrypted`
 * cast on the model. Shapes per kind:
 *   - slack:    { url: string }                    — Incoming-webhook URL
 *   - webhook:  { url: string, secret?: string }   — POST + HMAC-SHA256 signature
 *   - email:    { to_address: string }             — RFC 5321 mailbox
 *
 * Encrypting at the cast layer (rather than the column) means a `pg_dump`
 * is safe to share for debugging without leaking webhook secrets, and
 * the decrypted value never lives in the DB or in slow-query logs.
 *
 * `enabled` is a soft-disable toggle: an operator pausing a noisy
 * channel during incident response shouldn't have to edit pivots —
 * we just stop dispatching deliveries when the column is false.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('kind', 16);
            $table->text('config_encrypted');
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['user_id', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_channels');
    }
};
