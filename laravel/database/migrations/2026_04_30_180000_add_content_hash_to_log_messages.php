<?php

use App\Support\LogMessageHasher;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        // 32 raw bytes = sha256. bytea is more compact than 64-char hex.
        // Created nullable so the backfill below can populate values before
        // the NOT NULL + UNIQUE constraint flips on.
        Schema::table('log_messages', function (Blueprint $table) {
            $table->binary('content_hash')->nullable()->after('response');
        });

        // Backfill existing rows with a deterministic hash so the new
        // unique constraint can be created. The canonicalization rules
        // live in PHP (LogMessageHasher) so they always match runtime.
        DB::table('log_messages')->orderBy('id')->chunkById(500, function ($rows) use ($driver) {
            foreach ($rows as $row) {
                $hash = LogMessageHasher::computeForRow((array) $row);

                $value = match ($driver) {
                    'pgsql' => DB::raw("decode('".bin2hex($hash)."', 'hex')"),
                    default => $hash,
                };

                DB::table('log_messages')->where('id', $row->id)->update([
                    'content_hash' => $value,
                ]);
            }
        });

        // Enforce NOT NULL via raw SQL — composer doesn't ship doctrine/dbal
        // in this project, so $table->change() isn't available. SQLite (only
        // used in tests) starts empty and stays nullable, which is harmless
        // because the test inserts always set a hash.
        match ($driver) {
            'pgsql' => DB::statement('ALTER TABLE log_messages ALTER COLUMN content_hash SET NOT NULL'),
            'mysql', 'mariadb' => DB::statement('ALTER TABLE log_messages MODIFY content_hash VARBINARY(32) NOT NULL'),
            default => null,
        };

        // Drop the old (page_id, timestamp, type, action, method, status)
        // tuple-based unique constraint; replace it with content-hash dedup.
        Schema::table('log_messages', function (Blueprint $table) {
            $table->dropUnique('log_messages_unique_idx');
            $table->unique(['page_id', 'content_hash'], 'log_messages_page_content_hash_idx');
        });
    }

    public function down(): void
    {
        Schema::table('log_messages', function (Blueprint $table) {
            $table->dropUnique('log_messages_page_content_hash_idx');
            $table->unique(
                ['page_id', 'timestamp', 'type', 'action', 'method', 'status'],
                'log_messages_unique_idx'
            );
            $table->dropColumn('content_hash');
        });
    }
};
