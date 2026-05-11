<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Organization;
use App\Models\ScrapeJob;
use App\Models\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Cross-app fuzzy search endpoint that feeds the Cmd-K command palette
 * (F16). Returns scoped results grouped by surface so the palette
 * doesn't have to know about per-model column mappings.
 *
 * Scoping: every result is constrained to the authenticated user's
 * organisations (and transitively their applications + subscriptions
 * + scrape jobs). The single-tenant rule means cross-tenant leaks
 * aren't on the table today, but the per-user scope is still the
 * right default for when this app grows.
 *
 * Result shape (one entry per group, max 10 each):
 *
 *   {
 *     "subscriptions": [{ "id", "label", "sublabel", "href", "kind": "subscription" }],
 *     "scrape_jobs":   [{ "id", "label", "sublabel", "href", "kind": "scrape_job" }],
 *     "saved_queries": [...] | omitted if class doesn't exist,
 *   }
 *
 * The palette UI merges these into a single virtual list keyed by
 * `kind` for the group header.
 */
class SearchController extends Controller
{
    /**
     * Per-group result cap. The palette already debounces on the
     * client, so capping at 10 per group is plenty — more would
     * just bloat the dropdown without helping discovery.
     */
    private const LIMIT = 10;

    public function __invoke(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));

        // Empty query → empty result groups. The palette renders the
        // localStorage-backed recent list in this state, so the
        // server doesn't need to ship anything. Returning an empty
        // hash (instead of 400-ing) keeps the client code free of
        // branchy "if (q)" guards.
        if ($query === '') {
            return response()->json([
                'subscriptions' => [],
                'scrape_jobs' => [],
                'saved_queries' => [],
            ]);
        }

        $user = $request->user();
        $needle = Str::lower($query);
        $like = '%'.$needle.'%';

        $subs = Subscription::query()
            ->select(['subscriptions.id', 'subscriptions.name', 'subscriptions.environment'])
            ->join('applications', 'applications.id', '=', 'subscriptions.application_id')
            ->join('organizations', 'organizations.id', '=', 'applications.organization_id')
            ->where('organizations.user_id', $user->id)
            ->where(function ($q) use ($like) {
                $q->whereRaw('LOWER(subscriptions.name) LIKE ?', [$like])
                    ->orWhere('subscriptions.id', 'LIKE', $like)
                    ->orWhereRaw('LOWER(subscriptions.environment) LIKE ?', [$like]);
            })
            ->orderBy('subscriptions.name')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Subscription $s) => [
                'id' => (string) $s->id,
                'kind' => 'subscription',
                'label' => $s->name,
                'sublabel' => "{$s->id} · {$s->environment}",
                // Insights page (Agent 1) isn't merged yet — point at
                // Manage for now. When `/insights/{sub}` exists, the
                // palette consumer can swap this href without a server
                // round-trip (the palette can also be aware of feature
                // flags via the shared props if needed later).
                'href' => '/manage?q='.urlencode($s->id),
            ]);

        $jobs = ScrapeJob::query()
            ->select(['scrape_jobs.id', 'scrape_jobs.status', 'scrape_jobs.subscription_id'])
            ->join('subscriptions', 'subscriptions.id', '=', 'scrape_jobs.subscription_id')
            ->join('applications', 'applications.id', '=', 'subscriptions.application_id')
            ->join('organizations', 'organizations.id', '=', 'applications.organization_id')
            ->where('organizations.user_id', $user->id)
            ->where(function ($q) use ($needle, $like) {
                $q->whereRaw('CAST(scrape_jobs.id AS TEXT) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(scrape_jobs.status) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(subscriptions.name) LIKE ?', [$like]);
            })
            ->orderByDesc('scrape_jobs.id')
            ->limit(self::LIMIT)
            ->with(['subscription:id,name'])
            ->get()
            ->map(fn (ScrapeJob $j) => [
                'id' => (string) $j->id,
                'kind' => 'scrape_job',
                'label' => "Job #{$j->id} · {$j->status}",
                'sublabel' => $j->subscription?->name ?? "sub {$j->subscription_id}",
                'href' => '/jobs?job='.$j->id,
            ]);

        // Saved-query group is conditional on Agent 2's model
        // landing. We check the file's existence directly rather than
        // calling `class_exists()` — the latter triggers Composer's
        // PSR-4 autoloader which `include`s the would-be file, and
        // Laravel's error handler then promotes the `include` warning
        // to an `ErrorException`. Falling back to a filesystem probe
        // keeps the conditional zero-cost when the model is absent.
        $savedQueries = [];
        $savedQueryClass = 'App\\Models\\SavedQuery';
        $savedQueryFile = app_path('Models/SavedQuery.php');
        if (is_file($savedQueryFile) && class_exists($savedQueryClass)) {
            try {
                $rows = $savedQueryClass::query()
                    ->where('user_id', $user->id)
                    ->where(function ($q) use ($like) {
                        $q->whereRaw('LOWER(name) LIKE ?', [$like]);
                    })
                    ->orderBy('name')
                    ->limit(self::LIMIT)
                    ->get(['id', 'name']);

                $savedQueries = $rows->map(fn ($r) => [
                    'id' => (string) $r->id,
                    'kind' => 'saved_query',
                    'label' => $r->name,
                    'sublabel' => 'saved query',
                    'href' => '/logs?saved_query='.$r->id,
                ])->all();
            } catch (\Throwable) {
                // Schema may not be migrated yet (table missing on the
                // current branch). The palette degrades gracefully —
                // an empty group rather than a 500.
                $savedQueries = [];
            }
        }

        return response()->json([
            'subscriptions' => $subs,
            'scrape_jobs' => $jobs,
            'saved_queries' => $savedQueries,
        ]);
    }

    /**
     * Optional helper: list organisations/applications a user owns.
     * Currently unused by the palette but exposed for future palette
     * groups (e.g. an org switcher). Kept here so the surface stays
     * cohesive.
     *
     * @phpstan-ignore method.unused
     */
    public function orgs(Request $request): JsonResponse
    {
        $orgs = Organization::query()
            ->where('user_id', $request->user()->id)
            ->with('applications:id,name,organization_id')
            ->get(['id', 'name'])
            ->map(fn (Organization $o) => [
                'id' => $o->id,
                'name' => $o->name,
                'applications' => $o->applications->map(fn (Application $a) => [
                    'id' => $a->id,
                    'name' => $a->name,
                ]),
            ]);

        return response()->json(['organizations' => $orgs]);
    }
}
