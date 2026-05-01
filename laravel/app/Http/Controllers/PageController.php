<?php

namespace App\Http\Controllers;

use App\Models\LogMessage;
use App\Models\Page;
use App\Models\Subscription;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
     */
    public function show(Request $request, Page $page): Response
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
        $this->applyFilters($query, $filters);

        $total = (clone $query)->count();

        $logs = $query
            ->orderBy($sortColumn, $sortDir)
            ->orderByDesc('timestamp')
            ->orderByDesc('id')
            ->paginate(100)
            ->withQueryString();

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

    private function applyFilters($query, array $filters): void
    {
        if (! empty($filters['startDate'])) {
            $query->where('timestamp', '>=', $filters['startDate']);
        }
        if (! empty($filters['endDate'])) {
            $query->where('timestamp', '<=', $filters['endDate']);
        }
        foreach (['type', 'action', 'method', 'status'] as $col) {
            if (! empty($filters[$col])) {
                $query->where($col, $filters[$col]);
            }
        }

        // Entity = first whitespace-separated token of action. Postgres ILIKE
        // gives case-insensitive prefix match without rebuilding an index.
        if (! empty($filters['entity'])) {
            $entity = $filters['entity'];
            $query->where(function ($w) use ($entity) {
                $w->where('action', 'ILIKE', $entity.' %')
                    ->orWhere('action', 'ILIKE', $entity);
            });
        }

        // Free-text search across action / path / method / json bodies. The
        // user's needle has its SQL LIKE wildcards (`%` and `_`) escaped so
        // a search for an entity id like `26205663` doesn't get reinterpreted
        // as a wildcard pattern.
        if (! empty($filters['q'])) {
            $needle = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $filters['q']).'%';
            $query->where(function ($w) use ($needle) {
                $w->where('action', 'ILIKE', $needle)
                    ->orWhere('path', 'ILIKE', $needle)
                    ->orWhere('method', 'ILIKE', $needle)
                    ->orWhereRaw('parameters::text ILIKE ?', [$needle])
                    ->orWhereRaw('request::text ILIKE ?', [$needle])
                    ->orWhereRaw('response::text ILIKE ?', [$needle]);
            });
        }

        if (! empty($filters['jsonFilters'])) {
            foreach ($filters['jsonFilters'] as $jf) {
                $field = $jf['field'];
                $value = $jf['value'];
                $query->where(function ($q) use ($field, $value) {
                    foreach (['parameters', 'request', 'response'] as $col) {
                        $q->orWhereRaw("$col::text ILIKE ?", ["%\"$field\":%$value%"]);
                    }
                });
            }
        }
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
