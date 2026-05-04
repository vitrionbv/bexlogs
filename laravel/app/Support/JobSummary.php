<?php

namespace App\Support;

use App\Models\ScrapeJob;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Read-side helpers that the sidebar / jobs page share.
 * Keeps the same shape so partial Inertia reloads stay cheap.
 */
class JobSummary
{
    /**
     * Counts of jobs by status for a given user (single-tenant: filters via
     * subscription → application → organization → user).
     *
     * @return array{queued:int, running:int, completed_24h:int, failed:int}
     */
    public static function countsForUser(User $user): array
    {
        $base = self::baseQuery($user);

        $queued = (clone $base)->where('scrape_jobs.status', ScrapeJob::STATUS_QUEUED)->count();
        $running = (clone $base)->where('scrape_jobs.status', ScrapeJob::STATUS_RUNNING)->count();
        $completed24h = (clone $base)
            ->where('scrape_jobs.status', ScrapeJob::STATUS_COMPLETED)
            ->where('scrape_jobs.completed_at', '>=', Carbon::now()->subDay())
            ->count();
        $failed = (clone $base)->where('scrape_jobs.status', ScrapeJob::STATUS_FAILED)->count();

        return [
            'queued' => $queued,
            'running' => $running,
            'completed_24h' => $completed24h,
            'failed' => $failed,
        ];
    }

    /**
     * Most recent jobs for the sidebar mini-list.
     *
     * The sidebar surfaces `rows_inserted` (genuinely-new rows that
     * survived the (page_id, content_hash) unique index) as the
     * primary "useful work" count, with `rows_received` (post in-batch
     * dedup, pre Postgres dedup) tagging along so SidebarJobs.vue can
     * render an `inserted/received` ratio when the two diverge during
     * a duplicate-heavy run. The legacy `stats.rows` (pre-`35f3948`,
     * empirically `pages_processed × BATCH_SIZE`) is no longer read —
     * see SidebarJobs.vue for the rendering side.
     *
     * @return array<int, array{
     *   id:int,
     *   subscription_name:string,
     *   subscription_id:string,
     *   status:string,
     *   created_at:string,
     *   completed_at:?string,
     *   error:?string,
     *   rows_inserted:?int,
     *   rows_received:?int,
     * }>
     */
    public static function recentForUser(User $user, int $limit = 5): array
    {
        return self::baseQuery($user)
            ->with('subscription:id,name')
            ->orderByDesc('scrape_jobs.id')
            ->limit($limit)
            ->get(['scrape_jobs.*'])
            ->map(function (ScrapeJob $j) {
                $st = $j->stats;

                return [
                    'id' => $j->id,
                    'subscription_id' => $j->subscription_id,
                    'subscription_name' => $j->subscription?->name ?? $j->subscription_id,
                    'status' => $j->status,
                    'created_at' => $j->created_at?->toIso8601String() ?? '',
                    'completed_at' => $j->completed_at?->toIso8601String(),
                    'error' => $j->error,
                    'rows_inserted' => isset($st['rows_inserted'])
                        ? (int) $st['rows_inserted']
                        : null,
                    'rows_received' => isset($st['rows_received'])
                        ? (int) $st['rows_received']
                        : null,
                ];
            })
            ->all();
    }

    private static function baseQuery(User $user): Builder
    {
        return ScrapeJob::query()
            ->join('subscriptions', 'subscriptions.id', '=', 'scrape_jobs.subscription_id')
            ->join('applications', 'applications.id', '=', 'subscriptions.application_id')
            ->join('organizations', 'organizations.id', '=', 'applications.organization_id')
            ->where('organizations.user_id', $user->id);
    }
}
