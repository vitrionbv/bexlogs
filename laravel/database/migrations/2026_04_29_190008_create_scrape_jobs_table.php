<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scrape_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('subscription_id');
            $table->foreign('subscription_id')->references('id')->on('subscriptions')->cascadeOnDelete();
            $table->foreignId('bex_session_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('queued'); // queued | running | completed | failed
            $table->unsignedInteger('attempts')->default(0);
            $table->json('params')->nullable(); // { start_time, end_time, max_pages }
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('last_heartbeat_at')->nullable();
            $table->text('error')->nullable();
            $table->json('stats')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['subscription_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scrape_jobs');
    }
};
