<?php

namespace App\Support;

use App\Models\Page as LogPage;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-side log analytics for the dashboard — scoped to pages the user
 * owns via organization membership (same chain as JobSummary::logs_total).
 */
class LogSummary
{
    /**
     * Daily log volume for the last N calendar days (UTC), zero-filled
     * so the dashboard line chart has a continuous series.
     *
     * @return array<int, array{day: string, count: int}>
     */
    public static function logsPerDayForUser(User $user, int $days = 30): array
    {
        $days = max(1, min($days, 90));
        $start = Carbon::now('UTC')->subDays($days - 1)->startOfDay();
        $startIso = $start->toIso8601String();

        $rows = DB::table('log_messages')
            ->whereIn(
                'page_id',
                LogPage::query()
                    ->whereExists(function ($q) use ($user) {
                        $q->from('organizations')
                            ->whereColumn('organizations.id', 'pages.organization_id')
                            ->where('organizations.user_id', $user->id);
                    })
                    ->select('id'),
            )
            ->where('timestamp', '>=', $startIso)
            ->selectRaw('SUBSTR(timestamp, 1, 10) as day, COUNT(*) as count')
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        /** @var Collection<string, int> $byDay */
        $byDay = $rows->mapWithKeys(fn ($row) => [(string) $row->day => (int) $row->count]);

        $series = [];
        for ($cursor = $start->copy(); $cursor->lte(Carbon::now('UTC')->startOfDay()); $cursor->addDay()) {
            $key = $cursor->format('Y-m-d');
            $series[] = [
                'day' => $key,
                'count' => $byDay->get($key, 0),
            ];
        }

        return $series;
    }

    /**
     * Top subscriptions by today's log volume, each with its top-N most
     * frequent log patterns (grouped by type + action).
     *
     * @return array<int, array{
     *   subscription_id: string,
     *   subscription_name: string,
     *   page_id: int,
     *   total_today: int,
     *   top_entries: array<int, array{type: string, action: string, count: int}>,
     * }>
     */
    public static function topSubscriptionsTodayForUser(
        User $user,
        int $subscriptionLimit = 10,
        int $entriesPerSubscription = 10,
    ): array {
        $todayStart = Carbon::now('UTC')->startOfDay()->toIso8601String();
        $todayEnd = Carbon::now('UTC')->endOfDay()->toIso8601String();

        $topSubs = DB::table('log_messages')
            ->join('pages', 'pages.id', '=', 'log_messages.page_id')
            ->join('subscriptions', 'subscriptions.id', '=', 'pages.subscription_id')
            ->join('applications', 'applications.id', '=', 'subscriptions.application_id')
            ->join('organizations', 'organizations.id', '=', 'applications.organization_id')
            ->where('organizations.user_id', $user->id)
            ->where('log_messages.timestamp', '>=', $todayStart)
            ->where('log_messages.timestamp', '<=', $todayEnd)
            ->groupBy('subscriptions.id', 'subscriptions.name', 'pages.id')
            ->selectRaw(
                'subscriptions.id as subscription_id, subscriptions.name as subscription_name, '
                .'pages.id as page_id, COUNT(*) as total_today',
            )
            ->orderByDesc('total_today')
            ->limit($subscriptionLimit)
            ->get();

        if ($topSubs->isEmpty()) {
            return [];
        }

        $subIds = $topSubs->pluck('subscription_id')->all();

        $patternRows = DB::table('log_messages')
            ->join('pages', 'pages.id', '=', 'log_messages.page_id')
            ->whereIn('pages.subscription_id', $subIds)
            ->where('log_messages.timestamp', '>=', $todayStart)
            ->where('log_messages.timestamp', '<=', $todayEnd)
            ->groupBy('pages.subscription_id', 'log_messages.type', 'log_messages.action')
            ->selectRaw(
                'pages.subscription_id as subscription_id, log_messages.type, log_messages.action, COUNT(*) as count',
            )
            ->orderByDesc('count')
            ->get();

        /** @var Collection<string, Collection<int, object>> $patternsBySub */
        $patternsBySub = $patternRows->groupBy('subscription_id');

        return $topSubs->map(function ($row) use ($patternsBySub, $entriesPerSubscription) {
            $patterns = $patternsBySub->get($row->subscription_id, collect())
                ->take($entriesPerSubscription)
                ->map(fn ($entry) => [
                    'type' => (string) $entry->type,
                    'action' => (string) $entry->action,
                    'count' => (int) $entry->count,
                ])
                ->values()
                ->all();

            return [
                'subscription_id' => (string) $row->subscription_id,
                'subscription_name' => (string) $row->subscription_name,
                'page_id' => (int) $row->page_id,
                'total_today' => (int) $row->total_today,
                'top_entries' => $patterns,
            ];
        })->all();
    }
}
