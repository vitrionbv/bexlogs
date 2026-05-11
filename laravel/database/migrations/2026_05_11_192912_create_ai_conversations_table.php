<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ai_conversations — one row per "ask your logs" chat session, scoped
 * to a single (user, subscription) pair. A user can have many parallel
 * conversations against the same subscription (different threads of
 * inquiry); they cannot have a single conversation that spans multiple
 * subscriptions, because the agent's tool dispatcher hardcodes
 * `subscription_id` from this row into every WHERE clause.
 *
 * `subscription_id` is a `string` because Subscription primary keys are
 * the upstream BookingExperts ids, which are alphanumeric.
 *
 * `model` is the OpenRouter model slug for THIS conversation (so we can
 * A/B Sonnet vs GPT-5 without redeploys); when null the controller
 * falls back to `config('ai.default_model')`.
 *
 * Indexes:
 *   - (user_id, subscription_id) — the "list my chats for this sub"
 *     side panel query.
 *   - (user_id, created_at) — the global "recent chats" sidebar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('subscription_id');
            $table->foreign('subscription_id')->references('id')->on('subscriptions')->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->string('model')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'subscription_id']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_conversations');
    }
};
