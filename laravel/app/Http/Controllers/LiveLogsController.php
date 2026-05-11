<?php

namespace App\Http\Controllers;

use App\Models\LogMessage;
use App\Models\Page;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Real-time log tail across every subscription owned by the current
 * user. Reads from the SAME `log_messages` rows the regular Logs page
 * shows — there's no extra browser, no extra scraping mode, no
 * separate ingestion path. Live updates ride the existing
 * `LogBatchInserted` Reverb broadcast on `private-user.{userId}` (the
 * same channel the sidebar already subscribes to for job lifecycle
 * events).
 *
 * Two endpoints:
 *
 *   - `index()`   Inertia page render: bootstrap the latest 200 rows
 *                 across the user's subscriptions and the dropdown
 *                 facets the page renders.
 *
 *   - `since()`   JSON endpoint: fetch rows newer than a given id for
 *                 a specific page_id. Called by the Vue page after a
 *                 LogBatchInserted broadcast lands. The event itself
 *                 only carries the page_id + count; we still need a
 *                 fetch round-trip to get the row payloads.
 *
 * The page-level "give me the new rows" queries are FAR cheaper than
 * a full reload because each LogBatchInserted event already carries
 * the `page_id` and `inserted` count. We just need to pull the new
 * rows for that page and prepend them on the client.
 */
class LiveLogsController extends Controller
{
    private const BOOTSTRAP_LIMIT = 200;

    private const SINCE_LIMIT = 500;

    public function index(Request $request): Response
    {
        $userId = (int) $request->user()->id;

        $subs = $this->subscriptionsForUser($userId);

        $bootstrapRows = LogMessage::query()
            ->select(['log_messages.*'])
            ->join('pages', 'pages.id', '=', 'log_messages.page_id')
            ->whereIn('pages.subscription_id', $subs->pluck('id'))
            ->orderByDesc('log_messages.id')
            ->limit(self::BOOTSTRAP_LIMIT)
            ->get();

        $pageSubMap = $this->pageSubMap($bootstrapRows);

        return Inertia::render('Logs/Live', [
            'rows' => $bootstrapRows->map(
                fn (LogMessage $m) => $this->serialize($m, $pageSubMap, $subs),
            )->values()->all(),
            'subscriptions' => $subs->map(fn (Subscription $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'environment' => $s->environment,
            ])->values()->all(),
        ]);
    }

    /**
     * Polled endpoint: rows for the given page_id that are newer than
     * `?since=<id>`. The Vue side receives a LogBatchInserted event and
     * uses the highest id it has on screen as the cursor.
     *
     * Authorisation: the page must belong to a subscription owned by
     * the current user. Defense-in-depth — the LogBatchInserted
     * broadcast is already gated by the `user.{id}` channel auth, but
     * a stale page_id from a freshly-deleted subscription shouldn't
     * leak rows.
     */
    public function since(Request $request, Page $page): JsonResponse
    {
        $userId = (int) $request->user()->id;

        $owns = Subscription::query()
            ->select('subscriptions.id')
            ->join('applications', 'applications.id', '=', 'subscriptions.application_id')
            ->join('organizations', 'organizations.id', '=', 'applications.organization_id')
            ->where('subscriptions.id', $page->subscription_id)
            ->where('organizations.user_id', $userId)
            ->exists();

        abort_unless($owns, 403);

        $since = (int) $request->query('since', 0);

        $rows = LogMessage::query()
            ->where('page_id', $page->id)
            ->where('id', '>', $since)
            ->orderByDesc('id')
            ->limit(self::SINCE_LIMIT)
            ->get(['id', 'page_id', 'timestamp', 'type', 'action', 'method', 'path', 'status']);

        $subs = $this->subscriptionsForUser($userId);
        $pageSubMap = [(int) $page->id => (string) $page->subscription_id];

        return response()->json([
            'rows' => $rows->map(
                fn (LogMessage $m) => $this->serialize($m, $pageSubMap, $subs),
            )->values()->all(),
        ]);
    }

    /**
     * @return EloquentCollection<int, Subscription>
     */
    private function subscriptionsForUser(int $userId): EloquentCollection
    {
        return Subscription::query()
            ->select(['subscriptions.id', 'subscriptions.name', 'subscriptions.environment', 'subscriptions.application_id'])
            ->join('applications', 'applications.id', '=', 'subscriptions.application_id')
            ->join('organizations', 'organizations.id', '=', 'applications.organization_id')
            ->where('organizations.user_id', $userId)
            ->orderBy('subscriptions.name')
            ->get();
    }

    /**
     * Build a (page_id -> subscription_id) lookup table for the rows
     * we're about to render. We fetch the Pages in a single SELECT
     * keyed by the distinct page_ids in the row set so the
     * serialisation pass stays loop-free of per-row queries.
     *
     * @param  EloquentCollection<int, LogMessage>  $rows
     * @return array<int, string>
     */
    private function pageSubMap(EloquentCollection $rows): array
    {
        $pageIds = $rows->pluck('page_id')->unique()->all();

        if (empty($pageIds)) {
            return [];
        }

        return Page::query()
            ->whereIn('id', $pageIds)
            ->pluck('subscription_id', 'id')
            ->all();
    }

    /**
     * Wide-shape row the Live tail page renders. We add the
     * subscription name + environment so the frontend can group by
     * either without re-querying.
     *
     * @param  array<int, string>  $pageSubMap
     * @param  EloquentCollection<int, Subscription>  $subs
     * @return array<string, mixed>
     */
    private function serialize(LogMessage $m, array $pageSubMap, EloquentCollection $subs): array
    {
        $subId = $pageSubMap[(int) $m->page_id] ?? null;
        $sub = $subId ? $subs->firstWhere('id', $subId) : null;

        return [
            'id' => $m->id,
            'page_id' => $m->page_id,
            'subscription_id' => $sub?->id,
            'subscription_name' => $sub?->name,
            'environment' => $sub?->environment,
            'timestamp' => $m->timestamp,
            'type' => $m->type,
            'action' => $m->action,
            'method' => $m->method,
            'path' => $m->path,
            'status' => $m->status,
        ];
    }
}
