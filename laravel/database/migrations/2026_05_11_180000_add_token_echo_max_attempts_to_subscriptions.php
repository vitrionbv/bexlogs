<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-subscription override for the token-echo retry helper's
     * `maxAttempts` knob (see `scraper/src/scrape.ts` →
     * `loadMoreWithTokenEchoRetry` and `loadInitialPageWithRetry`).
     *
     * Default 100 matches the historic env-wide `TOKEN_ECHO_MAX_ATTEMPTS`
     * default, so existing subscriptions behave identically until an
     * operator opts into a different value from the Manage page.
     *
     * 100 means "1 initial attempt + 99 retries at the flat
     * TOKEN_ECHO_RETRY_DELAY_MS schedule" — at the 3s default delay,
     * a fully-exhausted echo cluster costs ~5 min of sleep + 100 RTTs.
     * Range cap at 1000 (10× default) leaves room for the rare
     * stuck-tail subscription without letting an operator wedge a job
     * for hours.
     */
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->unsignedSmallInteger('token_echo_max_attempts')
                ->default(100)
                ->after('job_spacing_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('token_echo_max_attempts');
        });
    }
};
