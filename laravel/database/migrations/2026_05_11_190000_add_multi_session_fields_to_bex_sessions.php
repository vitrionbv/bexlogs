<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-session per (user, environment) support — B6.
 *
 * Historically `User::activeBexSession($env)` returned the most-recently
 * captured non-expired row. That worked when there was implicitly one
 * session per env; the moment an operator paired two browsers (their
 * laptop and the office workstation, or two different BookingExperts
 * accounts) the picker became "whichever ran the last validator wins".
 * The scheduler then funneled every job through that single session,
 * so a single cookie expiry took out every queued scrape until the
 * operator re-paired.
 *
 * The new model:
 *   - `priority`       smallint, default 100. Lower number = higher
 *                      priority. The scheduler picks the lowest-priority
 *                      healthy session first; ties are broken by id.
 *   - `expires_at`     timestamptz nullable. Derived from the longest
 *                      auth-cookie expirationDate at insert/update time
 *                      (mirrors BexSession::cookieTtlSummary). NULL for
 *                      session-only auth cookies, which are treated as
 *                      "expiring soon" because their lifetime is bound
 *                      to the next browser quit.
 *   - `health_status`  text enum:
 *                        - healthy        ok to use
 *                        - expiring_soon  cookies < 48h to expiration
 *                                         (or session-only)
 *                        - expired        cookies past their Expires
 *                                         OR worker reported
 *                                         session_expired on this
 *                                         specific row
 *                        - disabled       operator-disabled from the UI
 *
 * `health_status` is denormalised on purpose: the scheduler runs every
 * minute and the value rarely changes, so a single index lookup beats
 * recomputing the cookie scan on every tick. The check command refreshes
 * it explicitly when the cookies pass / cross the 48h boundary.
 *
 * NOTE on `expired_at` (validator-flag) vs `expires_at` (cookie-derived):
 * they can disagree. Examples:
 *   - cookies say expired but BE still answers 200 (the auth cookie
 *     was rotated server-side, jar still bears the stale Expires) →
 *     `expired_at IS NULL`, `expires_at IN PAST`, health=expired
 *     until next refresh promotes it back to healthy.
 *   - cookies look fine but BE answers 401 (account disabled, IP
 *     change, MFA timeout) → `expired_at IS NOT NULL`,
 *     `expires_at IN FUTURE`, health=expired.
 * The scheduler treats `health_status = expired` (regardless of which
 * column drove it) as "skip me".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bex_sessions', function (Blueprint $table) {
            $table->unsignedSmallInteger('priority')
                ->default(100)
                ->after('environment');

            $table->timestampTz('expires_at')
                ->nullable()
                ->after('last_validated_at');

            // Plain string column instead of an enum: MySQL/Postgres ENUMs
            // are painful to ALTER (every value addition rewrites the
            // table definition), and the worker-side has zero need for a
            // database-level constraint here — application code is the
            // only writer. We add a CHECK on Postgres for production
            // safety; SQLite (test driver) skips the CHECK to keep the
            // migration runnable in-memory.
            $table->string('health_status', 16)
                ->default('healthy')
                ->after('expires_at');
        });

        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement(
                "ALTER TABLE bex_sessions ADD CONSTRAINT bex_sessions_health_status_check
                 CHECK (health_status IN ('healthy','expiring_soon','expired','disabled'))"
            );

            // Composite index used by the rotation picker:
            // SELECT ... WHERE user_id=? AND environment=? AND health_status='healthy'
            //  ORDER BY priority, id
            // Adding `priority, id` as the trailing keys lets PG serve
            // the ORDER BY straight from the index without a sort.
            DB::statement(
                'CREATE INDEX IF NOT EXISTS bex_sessions_rotation_idx
                 ON bex_sessions (user_id, environment, health_status, priority, id)'
            );
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS bex_sessions_rotation_idx');
            DB::statement('ALTER TABLE bex_sessions DROP CONSTRAINT IF EXISTS bex_sessions_health_status_check');
        }

        Schema::table('bex_sessions', function (Blueprint $table) {
            $table->dropColumn(['priority', 'expires_at', 'health_status']);
        });
    }
};
