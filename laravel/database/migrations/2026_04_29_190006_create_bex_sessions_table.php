<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bex_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('environment'); // production | staging
            $table->text('cookies_encrypted'); // Crypt::encryptString of JSON cookie array
            $table->string('account_email')->nullable(); // resolved BookingExperts user email
            $table->string('account_name')->nullable(); // human-friendly label
            $table->timestampTz('captured_at');
            $table->timestampTz('last_validated_at')->nullable();
            $table->timestampTz('expired_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'environment']);
            $table->index('expired_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bex_sessions');
    }
};
