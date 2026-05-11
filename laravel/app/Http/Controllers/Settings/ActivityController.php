<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BexSession;
use App\Models\ScrapeJob;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Activity (F18).
 *
 * Read-only feed over the `audit_logs` table with the four filters the
 * spec calls out: user, action, date range, and subject. The page is a
 * standard paginated Inertia surface (50 per page) and the filters
 * round-trip through the query string so a deep-linked filtered view
 * survives a refresh.
 *
 * Scoping: this is a single-tenant operator tool. Every user can see
 * every audit row — there's no per-user partition. If that ever
 * changes (multi-tenant pivot), this is where the scope clause would
 * live.
 */
class ActivityController extends Controller
{
    /**
     * Page-size cap. Spec says 50; we expose it as a public constant
     * so the matching test asserts against the same number rather
     * than duplicating it.
     */
    public const PER_PAGE = 50;

    /**
     * Allow-list of subject types the filter dropdown can target.
     * Keyed by the human label the page renders; value is the morph
     * class. Wide-open `subject_type` from the query string would
     * let a hostile caller probe arbitrary class names; this gate
     * keeps the surface tight.
     */
    private const SUBJECT_TYPES = [
        'Subscription' => Subscription::class,
        'BexSession' => BexSession::class,
        'ScrapeJob' => ScrapeJob::class,
    ];

    public function index(Request $request): Response
    {
        $filters = $this->extractFilters($request);

        $page = AuditLog::query()
            ->with(['user:id,name,email'])
            ->filter($filters)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        // Reshape the paginator's items so the page doesn't need to
        // know about Laravel's internal AuditLog cast quirks. The
        // `subject_label` is a best-effort human handle for the morph
        // target — looking it up here keeps the page free of N+1
        // worries (we fetch all referenced subjects in one batch
        // below).
        $subjectLabels = $this->buildSubjectLabels($page->getCollection());

        $rows = $page->getCollection()->map(fn (AuditLog $log) => [
            'id' => $log->id,
            'user' => $log->user
                ? ['id' => $log->user->id, 'name' => $log->user->name, 'email' => $log->user->email]
                : null,
            'action' => $log->action,
            'subject_type' => $log->subject_type,
            'subject_id' => $log->subject_id,
            'subject_label' => $subjectLabels["{$log->subject_type}|{$log->subject_id}"] ?? null,
            'payload' => $log->payload,
            'ip_address' => $log->ip_address,
            'user_agent' => $log->user_agent,
            'created_at' => $log->created_at?->toIso8601String(),
        ])->values();

        return Inertia::render('settings/Activity', [
            'logs' => [
                'data' => $rows,
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'per_page' => $page->perPage(),
                    'total' => $page->total(),
                    'from' => $page->firstItem(),
                    'to' => $page->lastItem(),
                ],
            ],
            'filters' => [
                'user_id' => $filters['user_id'] ?? null,
                'actions' => $filters['actions'] ?? [],
                'subject_type' => $filters['subject_type'] ?? null,
                'subject_id' => $filters['subject_id'] ?? null,
                'from' => $request->query('from'),
                'to' => $request->query('to'),
            ],
            'facets' => [
                'users' => User::query()
                    ->select(['id', 'name', 'email'])
                    ->orderBy('name')
                    ->get(),
                // Available action names are the union of "actions we
                // emit anywhere in the codebase" — listed explicitly
                // here so the dropdown shows them even when no rows
                // have been emitted yet (otherwise the first-ever load
                // would show an empty multi-select). Keep in sync with
                // AuditLogger's docblock action vocabulary.
                'actions' => self::knownActions(),
                'subject_types' => array_keys(self::SUBJECT_TYPES),
            ],
        ]);
    }

    /**
     * Pull the filter shape out of the query string. Date filters
     * are coerced to CarbonImmutable so the comparison clause in
     * the model scope sees a consistent type regardless of input
     * format.
     *
     * @return array<string, mixed>
     */
    private function extractFilters(Request $request): array
    {
        $filters = [];

        if ($userId = $request->query('user_id')) {
            $filters['user_id'] = (int) $userId;
        }

        $actions = $request->query('actions');
        if (is_array($actions) && $actions !== []) {
            $filters['actions'] = array_values(array_filter(
                $actions,
                fn ($v) => is_string($v) && $v !== '',
            ));
        }

        $subjectType = $request->query('subject_type');
        if (is_string($subjectType) && isset(self::SUBJECT_TYPES[$subjectType])) {
            $filters['subject_type'] = self::SUBJECT_TYPES[$subjectType];
        }

        $subjectId = $request->query('subject_id');
        if (is_string($subjectId) && $subjectId !== '' && isset($filters['subject_type'])) {
            $filters['subject_id'] = $subjectId;
        }

        try {
            if ($from = $request->query('from')) {
                $filters['from'] = CarbonImmutable::parse((string) $from);
            }
            if ($to = $request->query('to')) {
                $filters['to'] = CarbonImmutable::parse((string) $to);
            }
        } catch (\Throwable) {
            // Malformed dates are silently ignored — the dropdown
            // shouldn't bring the whole page down with a 500.
        }

        return $filters;
    }

    /**
     * Resolve a human-friendly label for each (subject_type,
     * subject_id) tuple in one query per morph class.
     *
     * @param  Collection<int, AuditLog>  $logs
     * @return array<string, string>
     */
    private function buildSubjectLabels($logs): array
    {
        $byType = $logs
            ->filter(fn (AuditLog $l) => $l->subject_type && $l->subject_id)
            ->groupBy('subject_type');

        $labels = [];

        foreach ($byType as $type => $rows) {
            $ids = $rows->pluck('subject_id')->unique()->all();

            if ($type === Subscription::class) {
                Subscription::query()
                    ->whereIn('id', $ids)
                    ->get(['id', 'name', 'environment'])
                    ->each(function (Subscription $s) use (&$labels, $type) {
                        $labels["{$type}|{$s->id}"] = "{$s->name} ({$s->environment})";
                    });
            } elseif ($type === BexSession::class) {
                BexSession::query()
                    ->whereIn('id', $ids)
                    ->get(['id', 'environment', 'account_email'])
                    ->each(function (BexSession $s) use (&$labels, $type) {
                        $email = $s->account_email ?: 'no-email';
                        $labels["{$type}|{$s->id}"] = "{$email} · {$s->environment}";
                    });
            } elseif ($type === ScrapeJob::class) {
                ScrapeJob::query()
                    ->whereIn('id', $ids)
                    ->get(['id', 'subscription_id'])
                    ->each(function (ScrapeJob $j) use (&$labels, $type) {
                        $labels["{$type}|{$j->id}"] = "job #{$j->id} (sub {$j->subscription_id})";
                    });
            }
        }

        return $labels;
    }

    /**
     * Canonical list of action names the Activity page can filter on.
     * Hand-maintained vocabulary — the alternative (SELECT DISTINCT
     * action FROM audit_logs) would only show actions that have ever
     * fired, which makes a fresh install's dropdown empty.
     *
     * @return array<int, string>
     */
    public static function knownActions(): array
    {
        return [
            'subscription.created',
            'subscription.updated',
            'subscription.budget_updated',
            'subscription.auto_scrape_toggled',
            'subscription.deleted',
            'scrape.manual_triggered',
            'scrape.enqueued',
            'scrape.denied',
            'session.captured',
            'session.expired',
            'session.relinked',
            'session.disabled',
        ];
    }
}
