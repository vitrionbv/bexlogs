<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $isPgsql = DB::connection()->getDriverName() === 'pgsql';

        Schema::create('log_messages', function (Blueprint $table) use ($isPgsql) {
            $table->id();
            $table->foreignId('page_id')->constrained()->cascadeOnDelete();
            $table->string('timestamp'); // stored as ISO8601 text for byte-exact dedup
            $table->string('type');
            $table->string('action');
            $table->string('method');
            $table->string('status')->nullable();
            // jsonb on pgsql so GIN jsonb_path_ops can index by key; json on
            // sqlite (used in tests) because sqlite has no native jsonb type.
            if ($isPgsql) {
                $table->jsonb('parameters')->nullable();
                $table->jsonb('request')->nullable();
                $table->jsonb('response')->nullable();
            } else {
                $table->json('parameters')->nullable();
                $table->json('request')->nullable();
                $table->json('response')->nullable();
            }
            $table->timestamps();

            $table->unique(
                ['page_id', 'timestamp', 'type', 'action', 'method', 'status'],
                'log_messages_unique_idx'
            );

            $table->index(['page_id', 'timestamp'], 'log_messages_page_ts_idx');
        });

        // GIN indexes for fast jsonb path/value filtering on parameters/request/response.
        // Postgres-only — sqlite doesn't support GIN or jsonb_path_ops.
        if ($isPgsql) {
            DB::statement('CREATE INDEX log_messages_parameters_gin ON log_messages USING GIN (parameters jsonb_path_ops)');
            DB::statement('CREATE INDEX log_messages_request_gin ON log_messages USING GIN (request jsonb_path_ops)');
            DB::statement('CREATE INDEX log_messages_response_gin ON log_messages USING GIN (response jsonb_path_ops)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('log_messages');
    }
};
