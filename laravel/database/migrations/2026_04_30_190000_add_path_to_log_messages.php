<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add a `path` column for the request URL component of API-call rows.
     *
     * Webhook rows have no path (the action title carries the entity + verb,
     * e.g. "Reservation updated"); only API-call rows fill it in
     * (e.g. "/v3/administrations/2555/todos/26205663"). The column is
     * nullable so existing rows backfill cleanly to NULL.
     *
     * Existing log_messages keep their content_hash unchanged — the hasher's
     * new path component appends after status, but pre-existing rows treated
     * path as the empty string implicitly. We do NOT re-hash old rows here
     * (re-hashing would risk breaking the unique (page_id, content_hash)
     * index for any same-second collisions). Newly inserted rows hash with
     * path included, so dedup remains correct going forward.
     */
    public function up(): void
    {
        Schema::table('log_messages', function (Blueprint $table) {
            $table->text('path')->nullable()->after('method');
        });
    }

    public function down(): void
    {
        Schema::table('log_messages', function (Blueprint $table) {
            $table->dropColumn('path');
        });
    }
};
