<?php

namespace App\Http\Controllers;

use App\Models\LogMessage;
use App\Models\Page;
use App\Models\Subscription;
use App\Services\Ai\LogQueryBuilder;
use App\Services\ColdLogReader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PageController extends Controller
{
    /**
     * List all (organization × application × subscription) tuples we have
     * scraped at least once, with a quick row count.
     */
    public function index(Request $request): Response
    {
        $userId = $request->user()->id;

        $rows = Page::query()
            ->select('pages.*')
            ->join('subscriptions', 'subscriptions.id', '=', 'pages.subscription_id')
            ->join('applications', 'applications.id', '=', 'pages.application_id')
            ->join('organizations', 'organizations.id', '=', 'pages.organization_id')
            ->where('organizations.user_id', $userId)
            ->with(['organization', 'application', 'subscription'])
            ->orderByDesc('pages.created_at')
            ->get()
            ->map(fn (Page $p) => [
                'id' => $p->id,
                'organization' => ['id' => $p->organization?->id, 'name' => $p->organization?->name],
                'application' => ['id' => $p->application?->id, 'name' => $p->application?->name],
                'subscription' => ['id' => $p->subscription?->id, 'name' => $p->subscription?->name],
                'log_count' => LogMessage::where('page_id', $p->id)->count(),
                'last_log_at' => LogMessage::where('page_id', $p->id)->max('timestamp'),
                'environment' => $p->subscription?->environment,
                'auto_scrape' => $p->subscription?->auto_scrape,
            ]);

        return Inertia::render('Logs/Index', [
            'pages' => $rows,
        ]);
    }

    /**
     * The big detail view — paginated logs with filters/sort/JSON modal.
     *
     * The query first runs against the hot Postgres `log_messages`
     * table, then — if the requested date range overlaps any rows
     * the {@see ColdLogReader} reports as archived — pulls the
     * matching rows out of the `cold-logs` disk, filters them
     * client-side, and merges the two streams before paginating.
     * The merge is transparent: the operator sees one paginated
     * list regardless of which tier each row currently lives in.
     */
    public function show(Request $request, Page $page, ColdLogReader $coldLogReader): Response
    {
        $this->authorizePageAccess($request, $page);

        $filters = $request->validate([
            'startDate' => 'nullable|string',
            'endDate' => 'nullable|string',
            'q' => 'nullable|string',
            'type' => 'nullable|string',
            'entity' => 'nullable|string',
            'action' => 'nullable|string',
            'method' => 'nullable|string',
            'status' => 'nullable|string',
            'sort' => 'nullable|in:timestamp,type,action,method,status',
            'direction' => 'nullable|in:asc,desc',
            'jsonFilters' => 'nullable|array',
            'jsonFilters.*.field' => 'required|string',
            'jsonFilters.*.value' => 'required|string',
        ]);

        $sortColumn = $filters['sort'] ?? 'timestamp';
        $sortDir = $filters['direction'] ?? 'desc';

        $query = LogMessage::query()->where('page_id', $page->id);
        app(LogQueryBuilder::class)->applyFilters($query, $filters);

        $hotTotal = (clone $query)->count();

        // Page 1 of the hot results is enough to feed the merge —
        // we paginate the merged stream below. Scaling the hot
        // pull to 100 keeps the same behaviour the controller had
        // before (`paginate(100)`) when there's no cold spillover.
        $hotRows = $query
            ->orderBy($sortColumn, $sortDir)
            ->orderByDesc('timestamp')
            ->orderByDesc('id')
            ->paginate(100)
            ->withQueryString();

        $coldRows = $coldLogReader->fetchRowsForRange(
            page: $page,
            fromIso: $filters['startDate'] ?? null,
            toIso: $filters['endDate'] ?? null,
            additionalFilters: array_intersect_key(
                $filters,
                array_flip(['q', 'type', 'entity', 'action', 'method', 'status']),
            ),
        );

        if ($coldRows->isNotEmpty()) {
            $logs = $this->mergeHotAndColdPagination(
                hot: $hotRows,
                cold: $coldRows,
                sortColumn: $sortColumn,
                sortDir: $sortDir,
                hotTotal: $hotTotal,
                request: $request,
            );
            $total = $hotTotal + $coldRows->count();
        } else {
            $logs = $hotRows;
            $total = $hotTotal;
        }

        $facets = [
            'types' => LogMessage::where('page_id', $page->id)->distinct()->pluck('type')->filter()->values(),
            'actions' => LogMessage::where('page_id', $page->id)->distinct()->pluck('action')->filter()->values(),
            // Entity is the first whitespace-separated token of the action title
            // (e.g. "Reservation updated" → "Reservation"). Surfacing it as a
            // first-class facet lets users slice by entity without typing the
            // verb suffix or knowing the exact action label.
            'entities' => LogMessage::where('page_id', $page->id)
                ->select(DB::raw("split_part(action, ' ', 1) as e"))
                ->distinct()
                ->pluck('e')
                ->filter()
                ->values(),
            'methods' => LogMessage::where('page_id', $page->id)
                ->select(DB::raw("substring(method from '^[A-Z]+') as m"))
                ->distinct()
                ->pluck('m')
                ->filter()
                ->values(),
            'statuses' => LogMessage::where('page_id', $page->id)->distinct()->pluck('status')->filter()->values(),
        ];

        return Inertia::render('Logs/Show', [
            'page' => [
                'id' => $page->id,
                'organization' => $page->organization,
                'application' => $page->application,
                'subscription' => $page->subscription,
            ],
            'logs' => $logs,
            'total' => $total,
            'facets' => $facets,
            'filters' => array_merge(['sort' => $sortColumn, 'direction' => $sortDir], $filters),
        ]);
    }

    public function destroyLogs(Request $request, Page $page): RedirectResponse
    {
        $this->authorizePageAccess($request, $page);

        LogMessage::where('page_id', $page->id)->delete();

        return back()->with('status', 'logs-deleted');
    }

    /**
     * Merge a Postgres-backed paginator's current page with the
     * filtered cold-tier rows the {@see ColdLogReader} returned.
     *
     * The merge is intentionally simple: concatenate, sort by the
     * same key the SQL ORDER BY used, and re-paginate. We do NOT
     * try to be clever about "page N already covered M cold rows
     * so subtract them" because a cold range can change between
     * requests (an archive run completing mid-pagination) and the
     * cost of re-merging is bounded — the hot paginator cap is 100
     * rows, the cold per-day blob caps at the archive command's
     * per-sub ceiling, and the typical merge is small.
     */
    private function mergeHotAndColdPagination(
        LengthAwarePaginator $hot,
        Collection $cold,
        string $sortColumn,
        string $sortDir,
        int $hotTotal,
        Request $request,
    ): LengthAwarePaginator {
        // Normalise hot rows to the JSONL-shaped associative array
        // the cold reader emits so the downstream merge can sort
        // both sides with the same key extractor.
        $hotItems = collect($hot->items())->map(fn ($model) => $this->logMessageToArray($model));

        $merged = $hotItems
            ->concat($cold)
            ->sortBy(
                fn (array $row) => (string) ($row[$sortColumn] ?? ''),
                SORT_REGULAR,
                $sortDir === 'desc',
            )
            ->values();

        $perPage = $hot->perPage();
        $currentPage = $hot->currentPage();
        $offset = ($currentPage - 1) * $perPage;
        $items = $merged->slice($offset, $perPage)->values()->all();

        $paginator = new LengthAwarePaginator(
            items: $items,
            total: $hotTotal + $cold->count(),
            perPage: $perPage,
            currentPage: $currentPage,
            options: [
                'path' => $request->url(),
                'query' => $request->query(),
            ],
        );

        return $paginator;
    }

    /**
     * Eloquent → array, mirroring the {@see App\Console\Commands\BexArchiveCold}
     * JSONL row shape. Hidden attributes (e.g. `content_hash`) are
     * preserved as their raw model attributes so the merge dedup
     * key (page_id + content_hash) lines up across both tiers.
     *
     * @return array<string, mixed>
     */
    private function logMessageToArray(LogMessage $row): array
    {
        $attrs = $row->attributesToArray();
        // `content_hash` is hidden by the model but the cold path
        // surfaces it for dedup and as an explicit field. Re-attach
        // a hex form when present so the two sides match shape.
        $hash = $row->getAttributes()['content_hash'] ?? null;
        if (is_string($hash) && $hash !== '') {
            $attrs['content_hash'] = ctype_xdigit($hash) ? $hash : bin2hex($hash);
        }

        return $attrs;
    }

    private function authorizePageAccess(Request $request, Page $page): void
    {
        $owns = Subscription::query()
            ->join('applications', 'applications.id', '=', 'subscriptions.application_id')
            ->join('organizations', 'organizations.id', '=', 'applications.organization_id')
            ->where('subscriptions.id', $page->subscription_id)
            ->where('organizations.user_id', $request->user()->id)
            ->exists();
        abort_unless($owns, 403);
    }
}
