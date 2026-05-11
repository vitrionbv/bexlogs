<?php

namespace App\Http\Controllers;

use App\Events\ScrapeJobUpdated;
use App\Models\Application;
use App\Models\BexSession;
use App\Models\Organization;
use App\Models\ScrapeJob;
use App\Models\Subscription;
use App\Services\AuditLogger;
use App\Services\BookingExpertsBrowser;
use App\Services\MayEnqueueResult;
use App\Services\ScrapeEnqueueGuard;
use App\Services\ScrapeWindowPlanner;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ManageController extends Controller
{
    /**
     * Allow-list of sort modes the index page exposes via `?sort=…`. Any
     * value outside this set falls back to `name`. Keeping the list
     * tight means the controller never has to guard against
     * SQL-injection or unknown column names — the sort key is mapped
     * to a hard-coded ORDER BY fragment below in `applySubscriptionSort`.
     *
     * Default is `name` because that's what an alphabetically-curated
     * UI most often wants; `id` is offered as the "stop the page from
     * jumping when I edit a row" escape hatch (numeric subscription
     * IDs never change after creation, so the order is bulletproof).
     */
    private const SORT_MODES = ['name', 'id', 'last_scraped', 'environment'];

    public function index(Request $request): Response
    {
        $user = $request->user();
        $sort = $this->normalizeSort($request->query('sort'));
        $search = trim((string) $request->query('q', ''));

        // Eager-load with an explicit ORDER BY at every nesting level.
        // The previous code only ordered `organizations` by name and
        // left `applications` + `subscriptions` un-ordered, so Postgres
        // was free to return them in heap order — which an UPDATE on
        // any subscription row (toggling auto_scrape, bumping an
        // interval, etc.) reshuffles, making the just-edited row
        // visibly "jump" after the Inertia partial reload.
        //
        // Two-key sort everywhere: the primary human-friendly key, then
        // `id` as a deterministic tiebreaker. The `id` tiebreaker is
        // what guarantees the order is identical run-to-run even when
        // two rows share the same primary value (two apps with the
        // same name, two subs with the same `last_scraped_at`, etc.).
        $orgs = $user->organizations()
            ->with([
                'applications' => fn ($q) => $q->orderBy('name')->orderBy('id'),
                'applications.subscriptions' => fn ($q) => $this->applySubscriptionSort($q, $sort),
            ])
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        $organizations = $orgs->map(fn (Organization $o) => [
            'id' => $o->id,
            'name' => $o->name,
            'applications' => $o->applications->map(fn (Application $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'subscriptions' => $a->subscriptions->map(fn (Subscription $s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'environment' => $s->environment,
                    'auto_scrape' => $s->auto_scrape,
                    'scrape_interval_minutes' => $s->scrape_interval_minutes,
                    'max_pages_per_scrape' => $s->max_pages_per_scrape,
                    'lookback_days_first_scrape' => $s->lookback_days_first_scrape,
                    'max_duration_minutes' => $s->max_duration_minutes,
                    'max_concurrent_jobs' => $s->max_concurrent_jobs,
                    'job_spacing_minutes' => $s->job_spacing_minutes,
                    'token_echo_max_attempts' => $s->token_echo_max_attempts,
                    'last_scraped_at' => $s->last_scraped_at?->toIso8601String(),
                ]),
            ]),
        ]);

        // Server-side text filter. Matches across org name, app name,
        // sub name, and sub id — case-insensitive. Done in PHP rather
        // than SQL because the cascade is already in memory and the
        // dataset is small (per-user, capped by how many real
        // BookingExperts subscriptions one operator manages — typically
        // dozens, never thousands). Performing the filter post-load
        // also keeps the SQL stable and the ordering deterministic.
        if ($search !== '') {
            $needle = Str::lower($search);
            $organizations = $organizations
                ->map(function (array $org) use ($needle) {
                    $org['applications'] = collect($org['applications'])
                        ->map(function (array $app) use ($needle) {
                            $app['subscriptions'] = collect($app['subscriptions'])
                                ->filter(fn (array $sub) => self::matchesQuery($sub, $needle))
                                ->values()
                                ->all();

                            return $app;
                        })
                        // Hide apps with no matching subs UNLESS the
                        // app name itself matches the needle (so
                        // searching "verbleif" still surfaces the app
                        // card even if no individual sub matches).
                        ->filter(fn (array $app) => count($app['subscriptions']) > 0
                            || Str::contains(Str::lower($app['name']), $needle)
                            || Str::contains(Str::lower((string) $app['id']), $needle))
                        ->values()
                        ->all();

                    return $org;
                })
                // Same rule one level up: keep the org card if any of
                // its apps survived OR the org name/id matches.
                ->filter(fn (array $org) => count($org['applications']) > 0
                    || Str::contains(Str::lower($org['name']), $needle)
                    || Str::contains(Str::lower((string) $org['id']), $needle))
                ->values();
        }

        // Counts reflect the unfiltered dataset so the summary chip is
        // a stable "your account holds N subscriptions" rather than a
        // shifting "currently visible" number. The frontend renders
        // both: total + visible.
        $totals = $this->buildTotals($orgs);

        return Inertia::render('Manage/Index', [
            'organizations' => $organizations,
            'sessionsActive' => $user->bexSessions()
                ->whereNull('expired_at')
                ->count(),
            'filters' => [
                'sort' => $sort,
                'q' => $search,
            ],
            'totals' => $totals,
        ]);
    }

    /**
     * Map a raw `?sort=` query value to one of `SORT_MODES`. Anything
     * unrecognized (typo, omitted, hostile) falls back to `name`.
     */
    private function normalizeSort(mixed $sort): string
    {
        $candidate = is_string($sort) ? $sort : '';

        return in_array($candidate, self::SORT_MODES, true) ? $candidate : 'name';
    }

    /**
     * Apply the selected sort mode to a Subscriptions query builder
     * with a deterministic tiebreaker.
     *
     * The four modes:
     *   - `name`         primary key for an alphabetically-curated UI.
     *                    Default; what most operators expect.
     *   - `id`           numeric-string sort via `LENGTH(id), id`,
     *                    portable to both Postgres (prod) and SQLite
     *                    (tests). Subscription IDs never change after
     *                    creation, so this is the order-stability
     *                    escape hatch operators can pick when an edit
     *                    on a name- or last_scraped-sorted view would
     *                    otherwise shuffle the row.
     *   - `last_scraped` most-recently-scraped first; NULLS LAST so
     *                    fresh subscriptions (never scraped yet) go
     *                    to the bottom rather than masquerading as
     *                    "ancient". Handled via a CASE expression
     *                    because SQLite lacks NULLS LAST.
     *   - `environment`  groups production / staging together — useful
     *                    when an account spans both.
     *
     * Every mode appends `LENGTH(id), id` so two subscriptions sharing
     * the primary value stay in a deterministic order; this is what
     * stops the "jump" the user reported.
     */
    private function applySubscriptionSort(mixed $query, string $sort): mixed
    {
        // The closure passed to `with(['…' => fn($q) => …])` is invoked
        // with a `Relations\HasMany` (or whichever relation type the
        // eager-load resolves to), NOT a bare `Builder` — that's why
        // the type here is `mixed`. Both classes forward
        // `orderBy*` to the underlying query builder, so the body
        // below works against either.
        //
        // Numeric-string sort that works on both Postgres and SQLite
        // without a CAST — shorter strings come first, then alpha.
        // For all-numeric IDs (today's reality) this matches natural
        // numeric ordering: ['1', '10', '100', '2'] becomes
        // ['1', '2', '10', '100'].
        $idOrder = fn ($q) => $q->orderByRaw('LENGTH(id), id');

        return match ($sort) {
            'id' => $idOrder($query),

            'last_scraped' => $idOrder(
                $query->orderByRaw(
                    'CASE WHEN last_scraped_at IS NULL THEN 1 ELSE 0 END, last_scraped_at DESC',
                ),
            ),

            'environment' => $idOrder(
                $query->orderBy('environment')->orderBy('name'),
            ),

            // `name` and any fallthrough.
            default => $idOrder($query->orderBy('name')),
        };
    }

    /**
     * Substring match across the fields an operator most often types
     * into the search box: subscription name, subscription id (so
     * pasting an id from another tab Just Works), and the wrapping
     * app/org names indirectly (handled in the caller — see
     * `index()`).
     */
    private static function matchesQuery(array $sub, string $needle): bool
    {
        return Str::contains(Str::lower((string) $sub['name']), $needle)
            || Str::contains(Str::lower((string) $sub['id']), $needle)
            || Str::contains(Str::lower((string) $sub['environment']), $needle);
    }

    /**
     * Compute the summary chip the index page renders under the
     * header: total orgs / apps / subscriptions, plus how many subs
     * have `auto_scrape = true`. Totals are over the un-filtered
     * dataset so the chip stays stable while the user types in the
     * search box.
     *
     * @param  Collection<int, Organization>  $orgs
     * @return array{organizations:int, applications:int, subscriptions:int, auto_scrape_on:int}
     */
    private function buildTotals($orgs): array
    {
        $allApps = $orgs->flatMap(fn (Organization $o) => $o->applications);
        $allSubs = $allApps->flatMap(fn (Application $a) => $a->subscriptions);

        return [
            'organizations' => $orgs->count(),
            'applications' => $allApps->count(),
            'subscriptions' => $allSubs->count(),
            'auto_scrape_on' => $allSubs->where('auto_scrape', true)->count(),
        ];
    }

    public function storeSubscription(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'url' => 'nullable|string',
            'organization_id' => 'nullable|string',
            'application_id' => 'nullable|string',
            'subscription_id' => 'nullable|string',
            'organization_name' => 'nullable|string|max:255',
            'application_name' => 'nullable|string|max:255',
            'subscription_name' => 'required|string|max:255',
            'environment' => 'required|in:production,staging',
        ]);

        $ids = $this->resolveIds($data);

        // Hold a reference to the just-persisted Subscription so the
        // post-transaction audit write has a populated model to record
        // against (the observer's `created` hook also fires inside the
        // transaction, but we want the controller's row to take
        // precedence with a richer payload).
        $created = DB::transaction(function () use ($ids, $data, $request, $audit) {
            Organization::query()->updateOrCreate(
                ['id' => $ids['organization_id']],
                [
                    'user_id' => $request->user()->id,
                    'name' => $data['organization_name'] ?? "Organization {$ids['organization_id']}",
                ],
            );
            Application::query()->updateOrCreate(
                ['id' => $ids['application_id']],
                [
                    'organization_id' => $ids['organization_id'],
                    'name' => $data['application_name'] ?? "Application {$ids['application_id']}",
                ],
            );

            // Suppress the observer's matching write — the controller
            // already records this action with a richer payload below.
            $sub = Subscription::query()->firstOrNew(['id' => $ids['subscription_id']]);
            $audit->suppressNext('subscription.created', $sub);

            return Subscription::query()->updateOrCreate(
                ['id' => $ids['subscription_id']],
                [
                    'application_id' => $ids['application_id'],
                    'name' => $data['subscription_name'],
                    'environment' => $data['environment'],
                ],
            );
        });

        $audit->record('subscription.created', $created, [
            'new' => [
                'id' => $created->id,
                'name' => $created->name,
                'environment' => $created->environment,
            ],
        ], deduplicate: false);

        return back()->with('status', 'subscription-added');
    }

    public function updateSubscription(Request $request, Subscription $subscription, AuditLogger $audit): RedirectResponse
    {
        $this->authorize($request, $subscription);
        $data = $request->validate(self::updateValidationRules());

        $diff = AuditLogger::diff($subscription, $data);
        $subscription->update($data);

        // Choose the action name based on the columns that actually
        // changed — `auto_scrape` flips and budget-only edits are
        // first-class on the Activity page; mixed edits fall back to
        // `subscription.updated`. Empty diff (nothing changed) emits
        // nothing.
        if ($diff['new'] !== []) {
            $action = self::actionFor(array_keys($diff['new']));
            // Observer fires alongside us on the same request; mark
            // the (action, subject) pair as already-recorded so its
            // write becomes a no-op.
            $audit->suppressNext($action, $subscription);
            $audit->record($action, $subscription, $diff, deduplicate: false);
        }

        return back()->with('status', 'subscription-updated');
    }

    public function destroySubscription(Request $request, Subscription $subscription, AuditLogger $audit): RedirectResponse
    {
        $this->authorize($request, $subscription);

        // Audit must be recorded BEFORE the delete: the morph relation
        // needs a populated row to derive subject_id from, and the
        // operator-friendly payload (name, environment) would otherwise
        // be lost. Suppress the observer's matching write so the
        // explicit controller row is the only one persisted.
        $audit->suppressNext('subscription.deleted', $subscription);
        $audit->record('subscription.deleted', $subscription, [
            'old' => [
                'id' => $subscription->id,
                'name' => $subscription->name,
                'environment' => $subscription->environment,
            ],
        ], deduplicate: false);

        $subscription->delete();

        return back()->with('status', 'subscription-deleted');
    }

    /**
     * Shared validation rules used by both the per-sub update endpoint
     * and the bulk-update endpoint. Pulled into a constant-shaped
     * helper so the two paths stay in lock-step.
     *
     * @return array<string, string>
     */
    public static function updateValidationRules(): array
    {
        return [
            'name' => 'sometimes|string|max:255',
            'auto_scrape' => 'sometimes|boolean',
            'scrape_interval_minutes' => 'sometimes|integer|min:1|max:1440',
            'max_pages_per_scrape' => 'sometimes|integer|min:1|max:5000',
            'lookback_days_first_scrape' => 'sometimes|integer|min:1|max:365',
            'max_duration_minutes' => 'sometimes|integer|min:1|max:120',
            'max_concurrent_jobs' => 'sometimes|integer|min:1|max:10',
            'job_spacing_minutes' => 'sometimes|integer|min:1|max:120',
            // 1000 ceiling = 10× the default, which at the 3s flat
            // schedule already costs ~50 min of sleep on full
            // exhaust — well past any sensible per-job budget. The
            // unsignedSmallInteger column type also caps at 65535,
            // but 1000 is the operator-facing ceiling so a typo can't
            // wedge a worker for hours waiting on a quiet sub.
            'token_echo_max_attempts' => 'sometimes|integer|min:1|max:1000',
            'environment' => 'sometimes|in:production,staging',
        ];
    }

    /**
     * Choose the audit action for a subscription update based on which
     * columns changed. Mirrors {@see AuditSubscriptionObserver::updated}
     * so the controller and the observer agree on action naming and
     * the dedup map can suppress the duplicate cleanly.
     *
     * @param  array<int, string>  $changedKeys
     */
    private static function actionFor(array $changedKeys): string
    {
        if ($changedKeys === ['auto_scrape']) {
            return 'subscription.auto_scrape_toggled';
        }

        $budgetKeys = [
            'scrape_interval_minutes',
            'max_pages_per_scrape',
            'lookback_days_first_scrape',
            'max_duration_minutes',
            'max_concurrent_jobs',
            'job_spacing_minutes',
            'token_echo_max_attempts',
        ];

        return array_diff($changedKeys, $budgetKeys) === []
            ? 'subscription.budget_updated'
            : 'subscription.updated';
    }

    public function enqueueScrape(
        Request $request,
        Subscription $subscription,
        ScrapeEnqueueGuard $guard,
        ScrapeWindowPlanner $planner,
        AuditLogger $audit,
    ): RedirectResponse {
        $this->authorize($request, $subscription);

        $session = $request->user()->activeBexSession($subscription->environment);
        if (! $session) {
            throw ValidationException::withMessages([
                'session' => "No active BookingExperts session for {$subscription->environment}. Authenticate first.",
            ]);
        }

        $overrides = $request->validate([
            'start_time' => 'nullable|string',
            'end_time' => 'nullable|string',
            'max_pages' => 'nullable|integer|min:1|max:5000',
            'max_duration_minutes' => 'nullable|integer|min:1|max:120',
        ]);

        // Application-level concurrency gate. The Postgres partial unique
        // index that previously made "exactly one queued/running job per
        // subscription" a hard rule was dropped in 2026_05_04_205500 in
        // favour of the configurable (max_concurrent_jobs, job_spacing_minutes)
        // pair. The guard inspects the same scrape_jobs query the index
        // used to back, plus a spacing-window check so a double-click or
        // a scheduler tick that overlaps a manual click doesn't pile on.
        $decision = $guard->mayEnqueue($subscription);
        if (! $decision->allowed) {
            $audit->record('scrape.denied', $subscription, [
                'reason' => $decision->reason,
                'message' => $decision->message,
            ], deduplicate: false);

            return $this->scrapeDeniedResponse($decision);
        }

        $job = ScrapeJob::create([
            'subscription_id' => $subscription->id,
            'bex_session_id' => $session->id,
            'status' => ScrapeJob::STATUS_QUEUED,
            'params' => $planner->buildParamsWithOverrides($subscription, $overrides),
        ]);

        // Audit row is keyed on the subscription (the operator-facing
        // entity), with the job id recorded in the payload for
        // traceability. The ScrapeJobObserver's matching `scrape.enqueued`
        // write also fires but with `subject_type=ScrapeJob`, so the
        // two rows describe the same event at different granularities
        // and we leave both intact.
        $audit->record('scrape.manual_triggered', $subscription, [
            'scrape_job_id' => $job->id,
            'overrides' => $overrides,
        ], deduplicate: false);

        broadcast(new ScrapeJobUpdated(
            userId: (int) $request->user()->id,
            jobId: $job->id,
            subscriptionId: (string) $subscription->id,
            status: $job->status,
        ));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Scrape queued for {$subscription->name}.",
        ]);

        return back()->with('status', 'scrape-enqueued');
    }

    // ─── Bulk operations (F17) ──────────────────────────────────────────────
    //
    // The Manage page selection toolbar fans a single click out to N
    // subscriptions. Three endpoints, all mirroring the per-sub
    // counterparts:
    //
    //   - bulkUpdate         → applies a partial set of fields to each
    //                          authorised sub. Caller passes only the
    //                          fields they want to touch; everything
    //                          else is left untouched per-row. Run
    //                          inside a transaction so a validation
    //                          failure aborts the whole batch (the
    //                          alternative — partial application — was
    //                          tested with operators and rejected as
    //                          too confusing).
    //
    //   - bulkDelete         → cascading delete; identical authz to
    //                          destroySubscription. Audit row per sub.
    //
    //   - bulkEnqueueScrape  → runs each sub through ScrapeEnqueueGuard
    //                          independently. Returns a tally of queued
    //                          vs skipped so the toolbar can flash a
    //                          summary toast ("5 queued, 2 skipped").
    //
    // Authorization happens up front in a single query against the
    // organizations table — anything the user doesn't own is silently
    // dropped from the request set (NOT a 403, so a partially-stale
    // selection doesn't fail the whole batch). Each endpoint then
    // operates only on the authorised slice.

    /**
     * Apply a partial field update to every authorised subscription in
     * the request set. Same validation rules as `updateSubscription`
     * — anything that would 422 a single-row update 422s the batch.
     */
    public function bulkUpdate(Request $request, AuditLogger $audit): RedirectResponse
    {
        $payload = $request->validate(array_merge(
            ['subscription_ids' => 'required|array|min:1', 'subscription_ids.*' => 'string'],
            self::updateValidationRules(),
        ));

        $ids = $payload['subscription_ids'];
        unset($payload['subscription_ids']);

        if ($payload === []) {
            // No-op update (caller didn't tick any "Apply to all"
            // checkboxes). Bail with a friendly status rather than
            // 422-ing — the UI guards against this too but the server
            // is the source of truth.
            return back()->with('status', 'bulk-update-noop');
        }

        $subs = $this->authorisedSubscriptions($request, $ids);
        $updated = 0;

        DB::transaction(function () use ($subs, $payload, $audit, &$updated) {
            foreach ($subs as $sub) {
                $diff = AuditLogger::diff($sub, $payload);
                if ($diff['new'] === []) {
                    continue;
                }

                $sub->update($payload);
                $action = self::actionFor(array_keys($diff['new']));
                $audit->suppressNext($action, $sub);
                $audit->record($action, $sub, $diff, deduplicate: false);
                $updated++;
            }
        });

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $updated === 1
                ? '1 subscription updated.'
                : "{$updated} subscriptions updated.",
        ]);

        return back()->with('status', 'bulk-update-applied');
    }

    /**
     * Delete every authorised subscription in the request set. The
     * deletion cascade on the Subscription model is unchanged — this
     * just batches the per-row delete loop with audit + a summary
     * toast.
     */
    public function bulkDelete(Request $request, AuditLogger $audit): RedirectResponse
    {
        $payload = $request->validate([
            'subscription_ids' => 'required|array|min:1',
            'subscription_ids.*' => 'string',
        ]);

        $subs = $this->authorisedSubscriptions($request, $payload['subscription_ids']);
        $deleted = 0;

        DB::transaction(function () use ($subs, $audit, &$deleted) {
            foreach ($subs as $sub) {
                $audit->suppressNext('subscription.deleted', $sub);
                $audit->record('subscription.deleted', $sub, [
                    'old' => [
                        'id' => $sub->id,
                        'name' => $sub->name,
                        'environment' => $sub->environment,
                    ],
                ], deduplicate: false);
                $sub->delete();
                $deleted++;
            }
        });

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $deleted === 1
                ? '1 subscription deleted.'
                : "{$deleted} subscriptions deleted.",
        ]);

        return back()->with('status', 'bulk-delete-applied');
    }

    /**
     * Enqueue a manual scrape for every authorised subscription. Each
     * sub goes through {@see ScrapeEnqueueGuard} independently so the
     * spacing/concurrency gates that apply to single clicks still
     * apply per-row. Returns a structured tally for the toast — the
     * UI surfaces "X queued, Y skipped (within spacing window)".
     */
    public function bulkEnqueueScrape(
        Request $request,
        ScrapeEnqueueGuard $guard,
        ScrapeWindowPlanner $planner,
        AuditLogger $audit,
    ): RedirectResponse {
        $payload = $request->validate([
            'subscription_ids' => 'required|array|min:1',
            'subscription_ids.*' => 'string',
        ]);

        $subs = $this->authorisedSubscriptions($request, $payload['subscription_ids']);

        $queued = 0;
        $skipped = 0;
        $noSession = 0;
        // Track which denial reasons fired so the toast can mention
        // the dominant one rather than a generic "skipped" count.
        $reasonCounts = [];

        foreach ($subs as $sub) {
            $session = $request->user()->activeBexSession($sub->environment);
            if (! $session) {
                $noSession++;
                $skipped++;
                $reasonCounts['no_session'] = ($reasonCounts['no_session'] ?? 0) + 1;
                $audit->record('scrape.denied', $sub, [
                    'reason' => 'no_session',
                    'message' => "No active session for {$sub->environment}.",
                ], deduplicate: false);

                continue;
            }

            $decision = $guard->mayEnqueue($sub);
            if (! $decision->allowed) {
                $skipped++;
                $reasonKey = $decision->reason ?? 'denied';
                $reasonCounts[$reasonKey] = ($reasonCounts[$reasonKey] ?? 0) + 1;
                $audit->record('scrape.denied', $sub, [
                    'reason' => $decision->reason,
                    'message' => $decision->message,
                ], deduplicate: false);

                continue;
            }

            $job = ScrapeJob::create([
                'subscription_id' => $sub->id,
                'bex_session_id' => $session->id,
                'status' => ScrapeJob::STATUS_QUEUED,
                'params' => $planner->buildParamsWithOverrides($sub, []),
            ]);

            $audit->record('scrape.manual_triggered', $sub, [
                'scrape_job_id' => $job->id,
                'via' => 'bulk',
            ], deduplicate: false);

            broadcast(new ScrapeJobUpdated(
                userId: (int) $request->user()->id,
                jobId: $job->id,
                subscriptionId: (string) $sub->id,
                status: $job->status,
            ));

            $queued++;
        }

        $message = match (true) {
            $queued === 0 && $skipped === 0 => 'No matching subscriptions.',
            $skipped === 0 => "{$queued} queued.",
            $queued === 0 => "{$skipped} skipped.",
            default => "{$queued} queued, {$skipped} skipped.",
        };

        Inertia::flash('toast', [
            'type' => $queued === 0 ? 'warning' : 'success',
            'message' => $message,
        ]);

        return back()
            ->with('status', 'bulk-scrape-applied')
            ->with('bulk_scrape_queued', $queued)
            ->with('bulk_scrape_skipped', $skipped)
            ->with('bulk_scrape_no_session', $noSession)
            ->with('bulk_scrape_reasons', $reasonCounts);
    }

    /**
     * Resolve the subset of `$ids` the authenticated user actually
     * owns — anything outside their organizations is silently
     * dropped. Returning a Collection (rather than a paginator or
     * raw rows) keeps the bulk callers' iteration code identical to
     * what a per-row controller would do.
     *
     * @param  array<int, string>  $ids
     * @return Collection<int, Subscription>
     */
    private function authorisedSubscriptions(Request $request, array $ids): Collection
    {
        return Subscription::query()
            ->whereIn('id', $ids)
            ->whereExists(fn ($q) => $q
                ->from('applications')
                ->whereColumn('applications.id', 'subscriptions.application_id')
                ->whereExists(fn ($qq) => $qq
                    ->from('organizations')
                    ->whereColumn('organizations.id', 'applications.organization_id')
                    ->where('organizations.user_id', $request->user()->id),
                ),
            )
            ->get();
    }

    /**
     * Map a guard denial onto a redirect with a flash status keyed on the
     * reason. The Vue side flashes a toast keyed off `status`.
     */
    private function scrapeDeniedResponse(MayEnqueueResult $decision): RedirectResponse
    {
        $statusByReason = [
            'concurrency_cap_reached' => 'scrape-concurrency-cap',
            'within_spacing_window' => 'scrape-spacing-window',
            'prior_job_not_yet_started' => 'scrape-queued-not-started',
        ];

        $statusKey = $statusByReason[$decision->reason ?? ''] ?? 'scrape-already-queued';

        Inertia::flash('toast', [
            'type' => 'warning',
            'message' => $decision->message ?? 'A scrape is already running for this subscription.',
            'retry_after_seconds' => $decision->retryAfterSeconds,
        ]);

        return back()
            ->with('status', $statusKey)
            ->with('scrape_denied_reason', $decision->reason)
            ->with('scrape_denied_message', $decision->message);
    }

    // ─── Browse endpoints (Add Subscription → Browse tab, app-first cascade) ────────
    //
    // The browse cascade was inverted in 2026-04-30: instead of
    // organization → application → subscription, it now goes
    // application → organization (customer) → subscription. The flat
    // applications endpoint scrapes every dev-org the user belongs to;
    // organizations and subscriptions are derived from the per-app
    // subscriber list. Each level returns 200 with `requires_session_for`
    // set when no BE session is active for the env, so the dialog can
    // render an inline "Authenticate now" CTA without the Inertia HTML
    // 500 handler kicking in.

    /**
     * Flat list of applications across every dev-org the user can see.
     */
    public function browseApplications(Request $request): JsonResponse
    {
        [$session, $environment] = $this->browseSession($request);

        if (! $session) {
            return $this->noSessionResponse('applications', $environment);
        }

        $applications = Cache::remember(
            "manage.browse.apps.{$session->id}",
            60,
            fn () => (new BookingExpertsBrowser($session))->listApplications(),
        );

        return response()->json([
            'applications' => $applications,
            'requires_session_for' => null,
            'message' => null,
        ]);
    }

    /**
     * Customer-orgs that subscribe to {application}, with subscription
     * counts. Sorted alphabetically by org name. Used as Step 2 of the
     * browse cascade.
     */
    public function browseOrganizationsForApplication(Request $request, string $application): JsonResponse
    {
        [$session, $environment] = $this->browseSession($request);

        if (! $session) {
            return $this->noSessionResponse('organizations', $environment);
        }

        $organizations = Cache::remember(
            "manage.browse.orgs-for-app.{$session->id}.{$application}",
            60,
            fn () => (new BookingExpertsBrowser($session))->listOrganizationsWithSubscriptionsForApplication($application),
        );

        return response()->json([
            'organizations' => $organizations,
            'requires_session_for' => null,
            'message' => null,
        ]);
    }

    /**
     * Subscriptions for {application}, optionally filtered to a single
     * customer-org via `?organization_id=name:slug`. Used as Step 3 of
     * the browse cascade — when the response has exactly one entry the
     * UI auto-selects it.
     */
    public function browseSubscriptionsForApplication(Request $request, string $application): JsonResponse
    {
        [$session, $environment] = $this->browseSession($request);

        if (! $session) {
            return $this->noSessionResponse('subscriptions', $environment);
        }

        $organizationId = $request->query('organization_id');

        $cacheKey = "manage.browse.subs-for-app.{$session->id}.{$application}.".sha1((string) $organizationId);
        $subscriptions = Cache::remember($cacheKey, 60, function () use ($session, $application, $organizationId) {
            $all = (new BookingExpertsBrowser($session))->listSubscriptionsForApplication($application);
            if ($organizationId === null || $organizationId === '') {
                return $all;
            }

            return array_values(array_filter(
                $all,
                fn (array $sub) => ($sub['organization_id'] ?? null) === $organizationId,
            ));
        });

        return response()->json([
            'subscriptions' => $subscriptions,
            'requires_session_for' => null,
            'message' => null,
        ]);
    }

    /**
     * Resolve the BookingExperts session the browse endpoints should use.
     *
     * Returns a 2-tuple [session, environment]. The session is null when
     * the user has no active session for the requested environment —
     * callers surface that as a structured 200 JSON payload rather than
     * an HTTP error so the frontend can render an inline "authenticate"
     * CTA without triggering Inertia's HTML 500 handler.
     *
     * @return array{0: ?BexSession, 1: string}
     */
    private function browseSession(Request $request): array
    {
        $environment = $request->query('environment', 'production');
        if (! in_array($environment, ['production', 'staging'], true)) {
            $environment = 'production';
        }

        return [$request->user()->activeBexSession($environment), $environment];
    }

    /**
     * Empty-but-structured browse payload used when the user has no
     * active BookingExperts session for the requested environment. The
     * frontend keys off `requires_session_for` to render an inline
     * "Authenticate now" link.
     */
    private function noSessionResponse(string $listKey, string $environment): JsonResponse
    {
        return response()->json([
            $listKey => [],
            'requires_session_for' => $environment,
            'message' => "No active BookingExperts session for {$environment}. Authenticate first.",
        ], 200);
    }

    private function authorize(Request $request, Subscription $sub): void
    {
        $owns = Organization::query()
            ->where('user_id', $request->user()->id)
            ->whereExists(fn ($q) => $q
                ->from('applications')
                ->whereColumn('applications.organization_id', 'organizations.id')
                ->where('applications.id', $sub->application_id),
            )
            ->exists();
        abort_unless($owns, 403);
    }

    /**
     * Either accept three explicit IDs or extract them from a pasted
     * BookingExperts logs URL.
     *
     * @return array{organization_id:string, application_id:string, subscription_id:string}
     */
    private function resolveIds(array $data): array
    {
        if (! empty($data['organization_id']) && ! empty($data['application_id']) && ! empty($data['subscription_id'])) {
            return [
                'organization_id' => $data['organization_id'],
                'application_id' => $data['application_id'],
                'subscription_id' => $data['subscription_id'],
            ];
        }

        if (empty($data['url'])) {
            throw ValidationException::withMessages([
                'url' => 'Either paste a logs URL or supply organization_id + application_id + subscription_id.',
            ]);
        }

        // Match both old and new URL shapes:
        //   /organizations/{o}/applications/{a}/application_subscriptions/{s}
        //   /organizations/{o}/apps/developer/applications/{a}/application_subscriptions/{s}
        $pattern = '#/organizations/(\d+)/(?:apps/[^/]+/)?applications/(\d+)/application_subscriptions/(\d+)#';
        if (! preg_match($pattern, $data['url'], $m)) {
            throw ValidationException::withMessages([
                'url' => 'Could not parse organization/application/subscription IDs out of that URL.',
            ]);
        }

        return [
            'organization_id' => $m[1],
            'application_id' => $m[2],
            'subscription_id' => $m[3],
        ];
    }
}
