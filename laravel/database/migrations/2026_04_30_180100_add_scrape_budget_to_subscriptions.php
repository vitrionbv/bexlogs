<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            // Hard cap on pages fetched per scrape — protects against
            // runaway pagination on noisy subscriptions. ~50 entries/page.
            $table->unsignedInteger('max_pages_per_scrape')
                ->default(200)
                ->after('scrape_interval_minutes');

            // Only used the very first time a subscription is scraped
            // (when last_scraped_at is null). Bounds the catch-up window.
            $table->unsignedInteger('lookback_days_first_scrape')
                ->default(30)
                ->after('max_pages_per_scrape');

            // Wall-clock budget; jobs abort cleanly when reached.
            // 30 min is generous enough that the scraper can walk through
            // multi-hour quiet windows (BE paginates in 5–15 min slices, so
            // an overnight gap can take many pages of zero rows to cross).
            $table->unsignedInteger('max_duration_minutes')
                ->default(30)
                ->after('lookback_days_first_scrape');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn([
                'max_pages_per_scrape',
                'lookback_days_first_scrape',
                'max_duration_minutes',
            ]);
        });
    }
};
