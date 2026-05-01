<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('application_id');
            $table->foreign('application_id')->references('id')->on('applications')->cascadeOnDelete();
            $table->string('name');
            $table->string('environment')->default('production'); // production | staging
            $table->boolean('auto_scrape')->default(true);
            $table->unsignedInteger('scrape_interval_minutes')->default(5);
            $table->timestampTz('last_scraped_at')->nullable();
            $table->timestamps();

            $table->index('application_id');
            $table->index(['auto_scrape', 'last_scraped_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
