<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->string('organization_id');
            $table->string('application_id');
            $table->string('subscription_id');
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('application_id')->references('id')->on('applications')->cascadeOnDelete();
            $table->foreign('subscription_id')->references('id')->on('subscriptions')->cascadeOnDelete();

            $table->unique(
                ['organization_id', 'application_id', 'subscription_id'],
                'pages_unique_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pages');
    }
};
