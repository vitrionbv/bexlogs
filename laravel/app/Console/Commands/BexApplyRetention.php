<?php

namespace App\Console\Commands;

use App\Events\LogMessageRetentionApplied;
use App\Models\Subscription;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Per-subscription log_messages retention sweep.
 *
 * For every subscription with a non-null `retention_days` value, this
 * command deletes `log_messages` rows whose `timestamp` predates
 * `now() - retention_days days`. NULL `retention_days` means "keep
 * forever" (the historical default, untouched here).
 *
 * The hot table backing the Logs UI is the only thing this command
 * mutates — archived (`log_archive_manifest`) rows are immutable as
 * far as retention is concerned. If an operator wants archived data
 * gone too they delete the subscription, which cascades through both
 * tables via the FK on `log_archive_manifest.subscription_id`.
 *
 * Chunking strategy:
 *   - Each subscription is processed in fixed-size delete chunks
 *     (`CHUNK_SIZE = 10_000`). The Postgres `(page_id, timestamp)`
 *     composite index satisfies the WHERE clause without a sort, so
 *     each chunk is a cheap bounded scan.
 *   - There's a hard upper bound of `MAX_PER_SUB = 1_000_000` rows
 *     deleted per subscription per command run. Without it, the
 *     first cron tick after enabling retention on a subscription
 *     with millions of historical rows could run for hours and
 *     contend with live INSERTs from the worker. The leftover
 *     backlog gets picked up the next night at 03:00 — we'd rather
 *     take a week to drain a one-time backlog than lock the table
 *     for the duration.
 *
 * Why no transaction wrapping the whole sub:
 *   - A single 1M-row DELETE in one transaction would build up
 *     enormous WAL pressure on Postgres. Breaking into committed
 *     chunks releases dead tuples to autovacuum incrementally.
 *
 * Why a single timestamp string compare instead of `Carbon::parse`:
 *   - `log_messages.timestamp` is stored as ISO8601 text (see the
 *     original create migration), and ISO8601 strings sort
 *     lexicographically the same way they sort chronologically.
 *     Comparing as strings is cheaper than coercing per row.
 */
class BexApplyRetention extends Command
{
    /**
     * Per-pass DELETE batch size. Tuned so a single chunk on a
     * cold-cache row set still completes in a few hundred ms — large
     * enough that the per-statement overhead amortises, small enough
     * that autovacuum can keep up between chunks.
     */
    private const CHUNK_SIZE = 10_000;

    /**
     * Hard upper bound per subscription per run. See class docblock
     * for rationale. Enabling retention on a noisy sub with years of
     * history will take multiple cron ticks to fully prune; that's
     * the desired contract.
     */
    private const MAX_PER_SUB = 1_000_000;

    protected $signature = 'bex:apply-retention
                            {--subscription= : Limit the run to a single subscription_id (debugging).}
                            {--dry-run : Compute the deletion plan without actually deleting.}';

    protected $description = 'Delete log_messages rows older than each subscription retention_days window.';

    public function handle(): int
    {
        $singleSub = $this->option('subscription');
        $dryRun = (bool) $this->option('dry-run');

        $query = Subscription::query()->whereNotNull('retention_days');
        if ($singleSub !== null) {
            $query->where('id', (string) $singleSub);
        }

        $subscriptions = $query->get();

        if ($subscriptions->isEmpty()) {
            $this->info('No subscriptions have retention_days set; nothing to prune.');

            return self::SUCCESS;
        }

        $totalDeleted = 0;

        foreach ($subscriptions as $sub) {
            $deleted = $this->pruneSubscription($sub, $dryRun);
            $totalDeleted += $deleted;
        }

        $this->info(sprintf(
            '%s %d row(s) across %d subscription(s).',
            $dryRun ? 'Would delete' : 'Deleted',
            $totalDeleted,
            $subscriptions->count(),
        ));

        return self::SUCCESS;
    }

    /**
     * Drain log_messages older than the subscription's retention
     * window in fixed-size chunks until either the data is exhausted
     * or the per-run budget is hit. Returns the genuine deleted-row
     * count (zero in dry-run mode — we just compute the would-be
     * count for visibility).
     */
    private function pruneSubscription(Subscription $sub, bool $dryRun): int
    {
        $retentionDays = (int) $sub->retention_days;
        $cutoff = Carbon::now()->subDays($retentionDays);

        // log_messages.timestamp is ISO8601 text; compare as strings.
        // The sortable property of ISO8601 means a text compare
        // returns the same partition a Carbon compare would.
        $cutoffString = $cutoff->toIso8601String();

        // The page_id → subscription_id mapping is denormalised: we
        // run a sub-select that resolves the page_ids belonging to
        // this subscription once, then DELETE in chunks against that
        // set. Postgres handles the IN (sub-query) plan as a
        // hash-semi-join on the (page_id, timestamp) composite index.
        $pageIds = DB::table('pages')
            ->where('subscription_id', $sub->id)
            ->pluck('id');

        if ($pageIds->isEmpty()) {
            return 0;
        }

        if ($dryRun) {
            return (int) DB::table('log_messages')
                ->whereIn('page_id', $pageIds)
                ->where('timestamp', '<', $cutoffString)
                ->count();
        }

        $deleted = 0;
        $remaining = self::MAX_PER_SUB;

        while ($remaining > 0) {
            $chunk = min(self::CHUNK_SIZE, $remaining);

            // SQLite does not support `LIMIT` directly on `DELETE`,
            // so we always select the candidate ids first and delete
            // by primary key. This works identically on Postgres and
            // keeps the query plan predictable (always a single
            // index lookup per chunk).
            $ids = DB::table('log_messages')
                ->whereIn('page_id', $pageIds)
                ->where('timestamp', '<', $cutoffString)
                ->orderBy('id')
                ->limit($chunk)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $rows = DB::table('log_messages')
                ->whereIn('id', $ids)
                ->delete();

            $deleted += (int) $rows;
            $remaining -= (int) $rows;

            // If the chunk was smaller than the requested chunk size
            // the candidate set is exhausted; stop early to avoid an
            // empty round-trip.
            if ((int) $rows < $chunk) {
                break;
            }
        }

        Log::info('bex:apply-retention pruned subscription', [
            'subscription_id' => $sub->id,
            'retention_days' => $retentionDays,
            'cutoff' => $cutoffString,
            'deleted' => $deleted,
            'hit_budget_ceiling' => $deleted >= self::MAX_PER_SUB,
        ]);

        if ($deleted > 0) {
            $userId = $this->resolveUserId($sub);
            if ($userId !== null) {
                broadcast(new LogMessageRetentionApplied(
                    userId: $userId,
                    subscriptionId: (string) $sub->id,
                    deletedCount: $deleted,
                    retentionDays: $retentionDays,
                ));
            }
        }

        $this->line(sprintf(
            ' • subscription=%s retention_days=%d deleted=%d',
            $sub->id,
            $retentionDays,
            $deleted,
        ));

        return $deleted;
    }

    /**
     * Walk the application → organization chain to find the owning
     * user, which is the broadcast channel target. Done as a single
     * join rather than eager-loading the relations so we don't pay
     * for hydration cost just to extract one integer.
     */
    private function resolveUserId(Subscription $sub): ?int
    {
        $row = DB::table('subscriptions')
            ->join('applications', 'applications.id', '=', 'subscriptions.application_id')
            ->join('organizations', 'organizations.id', '=', 'applications.organization_id')
            ->where('subscriptions.id', $sub->id)
            ->select('organizations.user_id')
            ->first();

        return $row !== null ? (int) $row->user_id : null;
    }
}
