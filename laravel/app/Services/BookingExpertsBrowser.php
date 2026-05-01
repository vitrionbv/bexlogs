<?php

namespace App\Services;

use App\Models\BexSession;
use DOMDocument;
use DOMXPath;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Lists applications, organizations, and subscriptions that the
 * authenticated user can see on app.bookingexperts.com. Powers the
 * "Browse" tab of the Add Subscription dialog.
 *
 * Strategy: BookingExperts' admin UI doesn't expose a JSON endpoint
 * for any of these lists (we verified `.json` 404s/406s), so we scrape
 * the HTML pages and parse with DOMDocument + XPath. Per-session results
 * are cached for a few minutes — these lists rarely change, but a
 * manual refresh stays cheap.
 *
 * URL hierarchy (verified against app.bookingexperts.com on 2026-04-30):
 *   - /parks                                                       → org-switcher; one
 *     <li class='js-search-park--result' data-organization-id='ID'>
 *     per dev-org the user belongs to (note single quotes; libxml normalises).
 *   - /organizations/{org}/apps/developer/applications             → app list;
 *     each app is a <tr id="app_store_application_{id}"> with a name link.
 *   - /organizations/{org}/apps/developer/applications/{app}       → app detail;
 *     the "Installaties" section embeds the full subscriber list inside
 *     a <template data-modal-trigger-target="template"> block ("Bekijk
 *     alle Installaties"). Every subscriber row is
 *     <tr data-search-list-target="item" class="table__row"> with the
 *     customer name in td[1] and a logs link
 *     /application_subscriptions/{sub_id}/logs.
 *
 * Pagination: at the time of writing none of these pages paginate (all
 * fit on one page), but we *defensively* honour `rel="next"` links and
 * `?page=N` query params with a hard cap of 50 pages — should BE start
 * paginating in the future, the parser keeps walking. Counts per page
 * are logged so silent regressions surface in `storage/logs/laravel.log`.
 */
class BookingExpertsBrowser
{
    /** Hard cap on pages walked per listing — guards against infinite loops. */
    private const PAGINATION_HARD_CAP = 50;

    /** Cache TTL for browse results (seconds). 60s feels right for a cascading picker. */
    private const CACHE_TTL_SECONDS = 60;

    public function __construct(private readonly BexSession $session) {}

    // ─── Public API ──────────────────────────────────────────────────────

    /**
     * All applications the user can see across every dev-org they belong to.
     *
     * @return array<int, array{id:string, name:string, organization_id:string, organization_name:string}>
     */
    public function listApplications(): array
    {
        return Cache::remember(
            $this->cacheKey('apps-flat'),
            self::CACHE_TTL_SECONDS,
            fn () => $this->fetchApplicationsForAllOrgs(),
        );
    }

    /**
     * All subscribers (rows in the "Installaties" section) of a single app.
     * Each entry includes a synthetic `organization_id` derived from the
     * customer name so the cascading picker can group / dedupe by org.
     * The `developer_organization_id` / `developer_organization_name`
     * fields point at the org that *owns* the application — that's what
     * gets persisted server-side as `organizations.id` when the user saves.
     *
     * @return array<int, array{id:string, name:string, organization_id:string, organization_name:string, developer_organization_id:string, developer_organization_name:string}>
     */
    public function listSubscriptionsForApplication(string $applicationId): array
    {
        return Cache::remember(
            $this->cacheKey("subs-for-app:{$applicationId}"),
            self::CACHE_TTL_SECONDS,
            fn () => $this->fetchSubscriptionsForApplication($applicationId),
        );
    }

    /**
     * Customer-orgs that have at least one subscription to the given app,
     * each with the underlying subscription objects nested. Derived from
     * `listSubscriptionsForApplication` — kept as a public helper because
     * the controller wants both shapes.
     *
     * @return array<int, array{organization_id:string, organization_name:string, subscription_count:int, subscriptions: array<int, array<string, string>>}>
     */
    public function listOrganizationsWithSubscriptionsForApplication(string $applicationId): array
    {
        $subs = $this->listSubscriptionsForApplication($applicationId);
        $byOrg = [];
        foreach ($subs as $sub) {
            $orgId = $sub['organization_id'];
            if (! isset($byOrg[$orgId])) {
                $byOrg[$orgId] = [
                    'organization_id' => $orgId,
                    'organization_name' => $sub['organization_name'],
                    'subscription_count' => 0,
                    'subscriptions' => [],
                ];
            }
            $byOrg[$orgId]['subscriptions'][] = $sub;
            $byOrg[$orgId]['subscription_count']++;
        }

        $list = array_values($byOrg);
        usort($list, fn ($a, $b) => strcasecmp($a['organization_name'], $b['organization_name']));

        return $list;
    }

    // ─── Application listing ─────────────────────────────────────────────

    /**
     * Walk the org-switcher, then for each dev-org fetch its app list.
     * Returns a flat list with `organization_id` / `organization_name`
     * embedded so the UI can display "Verbleif (Verbleif Apps)".
     *
     * @return array<int, array{id:string, name:string, organization_id:string, organization_name:string}>
     */
    private function fetchApplicationsForAllOrgs(): array
    {
        $orgs = $this->fetchDeveloperOrganizations();

        $apps = [];
        foreach ($orgs as $org) {
            foreach ($this->fetchApplicationsForOrg($org['id']) as $app) {
                $apps[] = [
                    'id' => $app['id'],
                    'name' => $app['name'],
                    'organization_id' => $org['id'],
                    'organization_name' => $org['name'],
                ];
            }
        }

        usort($apps, function (array $a, array $b): int {
            $byOrg = strcasecmp($a['organization_name'], $b['organization_name']);
            if ($byOrg !== 0) {
                return $byOrg;
            }

            return strcasecmp($a['name'], $b['name']);
        });

        Log::info('bex.browse: aggregated application list', [
            'session_id' => $this->session->id,
            'orgs_seen' => count($orgs),
            'apps_total' => count($apps),
        ]);

        return $apps;
    }

    /**
     * Org switcher: every org the user has any role on — both ones they
     * happen to admin and ones they only have read access to. We rely on
     * caller code (fetchApplicationsForOrg) to gracefully drop orgs that
     * 401/403 the dev sub-tree.
     *
     * @return array<int, array{id:string, name:string}>
     */
    private function fetchDeveloperOrganizations(): array
    {
        $rows = [];
        $seen = [];

        foreach ($this->paginatedHtml('/parks') as $page) {
            $xpath = $page['xpath'];
            $items = $xpath->query('//li[@data-organization-id and not(@data-park)]');
            $foundThisPage = 0;

            foreach ($items as $node) {
                /** @var \DOMElement $node */
                $id = $node->getAttribute('data-organization-id');
                if (! $id || isset($seen[$id])) {
                    continue;
                }

                $name = $this->cleanText($node->textContent);
                if ($name === '') {
                    $name = "#{$id}";
                }

                $rows[] = ['id' => $id, 'name' => $name];
                $seen[$id] = true;
                $foundThisPage++;
            }

            $this->logPage('organizations', $page['url'], $foundThisPage);

            if ($items->length === 0) {
                Log::warning('bex.browse: parser found 0 org rows on page', [
                    'session_id' => $this->session->id,
                    'url' => $page['url'],
                    'body_length' => $page['body_length'],
                ]);
            }
        }

        usort($rows, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return $rows;
    }

    /**
     * Apps listed under a single dev-org. Returns [] (and logs) on 401/403,
     * which is the normal "user is in this org but isn't a developer there"
     * case. Other failures also degrade to [].
     *
     * @return array<int, array{id:string, name:string}>
     */
    private function fetchApplicationsForOrg(string $organizationId): array
    {
        $rows = [];
        $seen = [];
        $url = "/organizations/{$organizationId}/apps/developer/applications";

        foreach ($this->paginatedHtml($url) as $page) {
            if ($page['skipped_reason'] !== null) {
                if ($page['skipped_reason'] === 'unauthorized') {
                    Log::info('bex.browse: org has no developer access', [
                        'session_id' => $this->session->id,
                        'organization_id' => $organizationId,
                        'status' => $page['status'],
                    ]);
                }

                return [];
            }

            $xpath = $page['xpath'];
            // Each app row is a <tr id="app_store_application_{id}">.
            // The visible name lives in a child <a class="table__clickable link"
            // href="…/applications/{id}"> that has non-whitespace text and no
            // nested <img>. We anchor on the row so duplicate hrefs from
            // popovers and "/copy" actions don't pollute the list.
            $appRows = $xpath->query("//tr[contains(@id, 'app_store_application_')]");
            $foundThisPage = 0;

            foreach ($appRows as $row) {
                /** @var \DOMElement $row */
                if (! preg_match('~app_store_application_(\d+)~', $row->getAttribute('id'), $m)) {
                    continue;
                }
                $id = $m[1];
                if (isset($seen[$id])) {
                    continue;
                }

                $nameLink = $xpath
                    ->query(
                        ".//a[contains(@href, '/apps/developer/applications/')"
                        ." and not(contains(@href, '/copy'))"
                        .' and not(.//img)'
                        .' and string-length(normalize-space(.)) > 0]',
                        $row,
                    )
                    ->item(0);

                $name = $nameLink ? $this->cleanText($nameLink->textContent) : '';
                if ($name === '') {
                    $name = "#{$id}";
                }

                $rows[] = ['id' => $id, 'name' => $name];
                $seen[$id] = true;
                $foundThisPage++;
            }

            $this->logPage('applications', $page['url'], $foundThisPage);

            if ($appRows->length === 0) {
                Log::warning('bex.browse: parser found 0 application rows on page', [
                    'session_id' => $this->session->id,
                    'organization_id' => $organizationId,
                    'url' => $page['url'],
                    'body_length' => $page['body_length'],
                ]);
            }
        }

        usort($rows, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return $rows;
    }

    // ─── Subscription listing ─────────────────────────────────────────────

    /**
     * Subscribers of a single app. Returns the developer-org info too so
     * the UI / store can persist the right `organizations.id`. Customer
     * orgs are synthetically id'd by name slug since the BE admin page
     * doesn't expose a numeric customer-org id on the row.
     *
     * @return array<int, array{id:string, name:string, organization_id:string, organization_name:string, developer_organization_id:string, developer_organization_name:string}>
     */
    private function fetchSubscriptionsForApplication(string $applicationId): array
    {
        $apps = $this->listApplications();
        $owner = collect($apps)->firstWhere('id', $applicationId);

        if (! $owner) {
            Log::warning('bex.browse: subscriptions requested for unknown app', [
                'session_id' => $this->session->id,
                'application_id' => $applicationId,
            ]);

            return [];
        }

        $devOrgId = $owner['organization_id'];
        $devOrgName = $owner['organization_name'];
        $appName = $owner['name'];

        $rows = [];
        $seen = [];

        $detailUrl = "/organizations/{$devOrgId}/apps/developer/applications/{$applicationId}";

        foreach ($this->paginatedHtml($detailUrl) as $page) {
            if ($page['skipped_reason'] !== null) {
                Log::warning('bex.browse: app detail unreachable', [
                    'session_id' => $this->session->id,
                    'application_id' => $applicationId,
                    'reason' => $page['skipped_reason'],
                    'status' => $page['status'],
                ]);

                return [];
            }

            $xpath = $page['xpath'];

            // Every subscriber row — visible-table rows AND modal-template
            // rows — is a `<tr data-search-list-target="item">` with a
            // logs link in the popover template. We anchor on the row so
            // we can pull the customer name out of td[1] without confusion.
            $subRows = $xpath->query(
                "//tr[@data-search-list-target='item']"
                ." [.//a[contains(@href, '/application_subscriptions/') and contains(@href, '/logs')]]"
            );
            $foundThisPage = 0;

            foreach ($subRows as $row) {
                /** @var \DOMElement $row */
                $id = null;
                $links = $xpath->query(".//a[contains(@href, '/application_subscriptions/')]", $row);
                foreach ($links as $link) {
                    /** @var \DOMElement $link */
                    if (preg_match('~/application_subscriptions/(\d+)(?:[/?#]|$)~', $link->getAttribute('href'), $m)) {
                        $id = $m[1];
                        break;
                    }
                }
                if (! $id || isset($seen[$id])) {
                    continue;
                }

                $name = '';
                $firstCell = $xpath->query('./td[1]', $row)->item(0);
                if ($firstCell) {
                    $name = $this->cleanText($firstCell->textContent);
                }
                if ($name === '') {
                    $name = "#{$id}";
                }

                $orgSlug = Str::slug($name) ?: ('sub-'.$id);
                $orgId = "name:{$orgSlug}";

                $rows[] = [
                    'id' => $id,
                    'name' => $name,
                    'organization_id' => $orgId,
                    'organization_name' => $name,
                    'developer_organization_id' => $devOrgId,
                    'developer_organization_name' => $devOrgName,
                ];
                $seen[$id] = true;
                $foundThisPage++;
            }

            $this->logPage('subscriptions', $page['url'], $foundThisPage);

            if ($subRows->length === 0) {
                Log::warning('bex.browse: parser found 0 subscription rows on page', [
                    'session_id' => $this->session->id,
                    'application_id' => $applicationId,
                    'app_name' => $appName,
                    'url' => $page['url'],
                    'body_length' => $page['body_length'],
                ]);
            }
        }

        usort($rows, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return $rows;
    }

    // ─── HTTP & pagination ───────────────────────────────────────────────

    /**
     * Yield each page's parsed XPath in order, following `rel="next"`
     * links or auto-incrementing `?page=N`. Stops at:
     *   - a non-2xx response (returned with `skipped_reason` set so callers
     *     can short-circuit on 401/403),
     *   - a page that has no successor link AND we've exhausted the
     *     numeric sequence,
     *   - the hard cap of self::PAGINATION_HARD_CAP pages.
     *
     * @return iterable<int, array{xpath: DOMXPath, url: string, body_length: int, status: int, skipped_reason: ?string}>
     */
    private function paginatedHtml(string $startPath): iterable
    {
        $client = $this->client();
        $visited = [];
        $next = $startPath;
        $pageNo = 1;

        while ($next !== null && $pageNo <= self::PAGINATION_HARD_CAP) {
            // Avoid loops if a page somehow links back to itself.
            if (isset($visited[$next])) {
                Log::info('bex.browse: stopped pagination loop', [
                    'session_id' => $this->session->id,
                    'url' => $next,
                    'page_no' => $pageNo,
                ]);
                break;
            }
            $visited[$next] = true;

            $resp = $client->get($next);
            $status = $resp->status();
            $body = $resp->body();

            if ($status === 401 || $status === 403) {
                yield [
                    'xpath' => new DOMXPath(new DOMDocument),
                    'url' => $next,
                    'body_length' => strlen($body),
                    'status' => $status,
                    'skipped_reason' => 'unauthorized',
                ];

                return;
            }
            if (! $resp->ok()) {
                Log::warning('bex.browse: paginated request failed', [
                    'session_id' => $this->session->id,
                    'url' => $next,
                    'status' => $status,
                    'page_no' => $pageNo,
                ]);
                yield [
                    'xpath' => new DOMXPath(new DOMDocument),
                    'url' => $next,
                    'body_length' => strlen($body),
                    'status' => $status,
                    'skipped_reason' => 'http_error',
                ];

                return;
            }

            $xpath = $this->loadHtml($body);

            yield [
                'xpath' => $xpath,
                'url' => $next,
                'body_length' => strlen($body),
                'status' => $status,
                'skipped_reason' => null,
            ];

            $next = $this->resolveNextPage($xpath, $next, $pageNo);
            $pageNo++;
        }

        if ($pageNo > self::PAGINATION_HARD_CAP) {
            Log::warning('bex.browse: hit pagination hard cap', [
                'session_id' => $this->session->id,
                'start_path' => $startPath,
                'cap' => self::PAGINATION_HARD_CAP,
            ]);
        }
    }

    /**
     * Pick the next URL to fetch, in priority order:
     *   1. an `<a rel="next" href="…">` anywhere in the document,
     *   2. a `?page={N+1}` swapped into the previous URL — only honoured
     *      if the document also contains `?page={N+1}` somewhere as a
     *      hint that a next page exists. This avoids infinite loops on
     *      pages that don't paginate at all.
     */
    private function resolveNextPage(DOMXPath $xpath, string $currentPath, int $currentPageNo): ?string
    {
        $relNext = $xpath->query("//a[@rel='next' and @href]")->item(0);
        if ($relNext instanceof \DOMElement) {
            $href = $relNext->getAttribute('href');
            if ($href !== '' && $href !== '#') {
                return $href;
            }
        }

        // Heuristic: if the page references "?page={N+1}" as a query
        // param somewhere in its HTML, assume there's a next page and
        // construct it deterministically.
        $nextNo = $currentPageNo + 1;
        $needle = "page={$nextNo}";
        // The XPath text() above won't catch attribute values, so we
        // fall back to a plain string scan on the serialised body. This
        // is much cheaper than another XPath traversal.
        $serialized = $xpath->document->saveHTML() ?: '';
        if (str_contains($serialized, $needle)) {
            return $this->withQueryParam($currentPath, 'page', (string) $nextNo);
        }

        return null;
    }

    /**
     * Add or replace a single query param on a path-only URL like
     * "/foo/bar?baz=1". Path remains relative; we don't try to be a
     * fully-fledged URL builder — the BE admin paths are simple.
     */
    private function withQueryParam(string $path, string $key, string $value): string
    {
        [$base, $query] = array_pad(explode('?', $path, 2), 2, '');
        parse_str($query, $params);
        $params[$key] = $value;

        return $base.'?'.http_build_query($params);
    }

    private function client(): PendingRequest
    {
        return (new BookingExpertsClient($this->session->environment))
            ->authed($this->session)
            ->withOptions(['allow_redirects' => true]);
    }

    /**
     * Build an XPath query against an HTML payload. We swallow libxml's
     * pedantic complaints about HTML5 — BE markup is real-world and we
     * only care about structure.
     */
    private function loadHtml(string $html): DOMXPath
    {
        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        // Force UTF-8: BE pages are UTF-8 but the meta tag may not appear
        // before any non-ASCII byte; without this prefix libxml falls
        // back to ISO-8859-1 and produces mojibake.
        $doc->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($doc);
    }

    private function cleanText(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', html_entity_decode($text)) ?? '');
    }

    private function cacheKey(string $suffix): string
    {
        return "bex.browse.{$this->session->id}.{$suffix}";
    }

    private function logPage(string $list, string $url, int $foundThisPage): void
    {
        Log::info('bex.browse: page parsed', [
            'session_id' => $this->session->id,
            'list' => $list,
            'url' => $url,
            'rows' => $foundThisPage,
        ]);
    }
}
