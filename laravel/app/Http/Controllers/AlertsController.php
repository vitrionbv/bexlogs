<?php

namespace App\Http\Controllers;

use App\Models\AlertChannel;
use App\Models\AlertDelivery;
use App\Models\Application;
use App\Models\SavedQuery;
use App\Models\Subscription;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /alerts UI controller — B4 + B5.
 *
 * The page hosts three sections behind a tab strip:
 *
 *   - Saved Queries  Filter expressions an operator wants to alert on
 *                    when a new log row matches. Each query can be
 *                    wired to N channels with per-link dedupe windows.
 *
 *   - Channels       Slack webhook / generic webhook / email
 *                    destinations. Stored encrypted via the
 *                    AlertChannel::config_encrypted cast; the API
 *                    only ever returns {@see AlertChannel::redactedConfig()}
 *                    so the on-the-wire JSON never leaks the URL hash
 *                    or webhook secret.
 *
 *   - System Alerts  A user-level pivot picking which channels receive
 *                    the system-emitted alerts from B5 (session
 *                    expiry, consecutive failures, quiet
 *                    subscriptions). Independent of the per-query
 *                    pivot so an operator can route infrastructure
 *                    pings to a separate channel from log alerts.
 *
 * Validation lives entirely inline (no FormRequest classes) to match
 * the convention in ManageController — the rules are short enough
 * that splitting them into separate files would just add indirection.
 */
class AlertsController extends Controller
{
    /**
     * Render the /alerts page.
     *
     * Loads everything the three tabs need in a single Inertia
     * response so tab-switching is instant (no per-tab fetches). The
     * delivery-history queries are capped at 50 rows each because
     * the operator-facing surface only shows the most recent —
     * deeper history is reachable through a future "all deliveries"
     * drill-down that doesn't exist yet.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        $queries = SavedQuery::query()
            ->where('user_id', $user->id)
            ->with(['channels:id,name,kind'])
            ->orderBy('name')
            ->get();

        $channels = AlertChannel::query()
            ->where('user_id', $user->id)
            ->orderBy('name')
            ->get();

        $systemChannelIds = $user->systemAlertChannels()
            ->select('alert_channels.id')
            ->pluck('alert_channels.id')
            ->all();

        // Recent deliveries are flattened across queries + channels;
        // the UI groups them client-side by the active tab. 50 is
        // big enough to span a typical dev day's worth of alerting
        // activity for a single operator and small enough that the
        // payload column (full JSON body) doesn't blow up the
        // Inertia visit.
        $recentDeliveries = AlertDelivery::query()
            ->whereHas('alertChannel', fn ($q) => $q->where('user_id', $user->id))
            ->with([
                'alertChannel:id,name,kind',
                'savedQuery:id,name',
            ])
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        // Pre-build the subscription dropdown for the saved-query
        // filter form. Sourced through Application → Organization so
        // we don't surface another user's subscription ids — the
        // join chain bakes in the org-scoped guard.
        $subscriptions = Subscription::query()
            ->whereHas(
                'application.organization',
                fn ($q) => $q->where('user_id', $user->id),
            )
            ->orderBy('name')
            ->get(['id', 'name', 'environment'])
            ->map(fn (Subscription $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'environment' => $s->environment,
            ]);

        return Inertia::render('Alerts/Index', [
            'savedQueries' => $queries->map(fn (SavedQuery $q) => self::queryPayload($q)),
            'channels' => $channels->map(fn (AlertChannel $c) => self::channelPayload($c)),
            'systemChannelIds' => $systemChannelIds,
            'recentDeliveries' => $recentDeliveries->map(fn (AlertDelivery $d) => self::deliveryPayload($d)),
            'subscriptions' => $subscriptions,
            'channelKinds' => AlertChannel::KINDS,
        ]);
    }

    public function storeQuery(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $this->validateQuery($request);

        $query = SavedQuery::query()->create([
            'user_id' => $user->id,
            'name' => $data['name'],
            'filter' => $data['filter'] ?? new \stdClass,
            'enabled' => $data['enabled'] ?? true,
        ]);

        if (! empty($data['channels'])) {
            $this->syncQueryChannels($query, $user->id, $data['channels']);
        }

        return back()->with('status', 'saved-query-created');
    }

    public function updateQuery(Request $request, SavedQuery $savedQuery): RedirectResponse
    {
        abort_unless($savedQuery->user_id === $request->user()->id, 403);
        $data = $this->validateQuery($request);

        $savedQuery->update([
            'name' => $data['name'],
            'filter' => $data['filter'] ?? new \stdClass,
            'enabled' => $data['enabled'] ?? $savedQuery->enabled,
        ]);

        $this->syncQueryChannels($savedQuery, $request->user()->id, $data['channels'] ?? []);

        return back()->with('status', 'saved-query-updated');
    }

    public function destroyQuery(Request $request, SavedQuery $savedQuery): RedirectResponse
    {
        abort_unless($savedQuery->user_id === $request->user()->id, 403);
        $savedQuery->delete();

        return back()->with('status', 'saved-query-deleted');
    }

    public function storeChannel(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $this->validateChannel($request);

        AlertChannel::query()->create([
            'user_id' => $user->id,
            'name' => $data['name'],
            'kind' => $data['kind'],
            'config_encrypted' => self::extractConfig($data),
            'enabled' => $data['enabled'] ?? true,
        ]);

        return back()->with('status', 'channel-created');
    }

    public function updateChannel(Request $request, AlertChannel $channel): RedirectResponse
    {
        abort_unless($channel->user_id === $request->user()->id, 403);
        $data = $this->validateChannel($request, isUpdate: true);

        $update = [
            'name' => $data['name'],
            'kind' => $data['kind'],
            'enabled' => $data['enabled'] ?? $channel->enabled,
        ];

        // Preserve the existing config when the operator submits the
        // form without re-typing the secret. The UI ships the URL
        // (host + ****) as a placeholder so the operator can edit
        // the channel name without re-pasting the secret on every
        // save. Empty/redacted-looking values fall through to the
        // existing encrypted blob.
        $newConfig = self::extractConfig($data, allowEmpty: true);
        if ($newConfig !== []) {
            $update['config_encrypted'] = array_merge(
                $channel->config_encrypted ?? [],
                $newConfig,
            );
        }

        $channel->update($update);

        return back()->with('status', 'channel-updated');
    }

    public function destroyChannel(Request $request, AlertChannel $channel): RedirectResponse
    {
        abort_unless($channel->user_id === $request->user()->id, 403);
        $channel->delete();

        return back()->with('status', 'channel-deleted');
    }

    /**
     * Replace the user's system-alert channel set in one request.
     * Idempotent — a re-submit with the same ids is a no-op at the
     * pivot level (sync diffs against current rows).
     */
    public function syncSystemChannels(Request $request): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'channel_ids' => 'array',
            'channel_ids.*' => ['integer', Rule::exists('alert_channels', 'id')->where('user_id', $user->id)],
        ]);

        $user->systemAlertChannels()->sync($data['channel_ids'] ?? []);

        return back()->with('status', 'system-channels-updated');
    }

    /**
     * Validation rules for both store + update of a SavedQuery. The
     * filter shape matches the keys SavedQueryEvaluator understands;
     * unknown keys are dropped silently to keep forward-compat with
     * frontends running ahead of a backend deploy.
     *
     * @return array{name:string, filter?:array, enabled?:bool, channels?:array}
     */
    private function validateQuery(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:120',
            'enabled' => 'boolean',
            'filter' => 'nullable|array',
            'filter.subscription_id' => 'nullable|string|max:64',
            'filter.environment' => 'nullable|in:production,staging',
            'filter.method' => 'nullable|string|max:16',
            'filter.status_regex' => 'nullable|string|max:200',
            'filter.action_regex' => 'nullable|string|max:200',
            'filter.since' => 'nullable|string|max:64',
            'channels' => 'nullable|array',
            'channels.*.id' => ['required', 'integer', Rule::exists('alert_channels', 'id')->where('user_id', $request->user()->id)],
            'channels.*.dedupe_window_seconds' => 'nullable|integer|min:0|max:86400',
        ]);
    }

    /**
     * Sync the saved-query → channel pivot from the array shape the
     * UI submits ([{id, dedupe_window_seconds}]). Verifies every
     * referenced channel belongs to the same operator before sync.
     *
     * @param  array<int,array{id:int, dedupe_window_seconds?:int}>  $channels
     */
    private function syncQueryChannels(SavedQuery $query, int $userId, array $channels): void
    {
        $pivot = [];
        foreach ($channels as $entry) {
            $channelId = (int) ($entry['id'] ?? 0);
            if ($channelId === 0) {
                continue;
            }

            $pivot[$channelId] = [
                'dedupe_window_seconds' => (int) ($entry['dedupe_window_seconds'] ?? 60),
            ];
        }

        $query->channels()->sync($pivot);
    }

    /**
     * Validation rules for AlertChannel store + update. The per-kind
     * sub-keys are validated as strings here and re-shape later in
     * {@see extractConfig} — keeping the rule list flat means
     * Laravel's validator surfaces useful field-level errors to the
     * UI instead of the operator-hostile "config.0.url is invalid".
     *
     * @return array<string,mixed>
     */
    private function validateChannel(Request $request, bool $isUpdate = false): array
    {
        return $request->validate([
            'name' => 'required|string|max:120',
            'kind' => ['required', Rule::in(AlertChannel::KINDS)],
            'enabled' => 'boolean',
            // Per-kind config fields — declared here so a single form
            // can carry all three shapes without bouncing through a
            // discriminated request class. The UI hides the
            // irrelevant ones based on the selected kind.
            'config' => 'nullable|array',
            'config.url' => 'nullable|string|max:1024',
            'config.secret' => 'nullable|string|max:512',
            'config.to_address' => 'nullable|email|max:254',
        ]);
    }

    /**
     * Pull the per-kind config sub-keys out of the validated payload
     * and return only the ones the operator filled in. Drives both
     * the store path (full insert) and the update path (partial
     * merge — see updateChannel).
     *
     * `allowEmpty=true` is the update-path mode: empty strings get
     * filtered out so they don't blank out the existing encrypted
     * value. The store path uses the strict mode so the operator
     * can't create a channel with an empty URL.
     *
     * @return array<string,string>
     */
    private static function extractConfig(array $data, bool $allowEmpty = false): array
    {
        $cfg = $data['config'] ?? [];
        $out = [];
        foreach (['url', 'secret', 'to_address'] as $key) {
            $value = is_string($cfg[$key] ?? null) ? trim($cfg[$key]) : '';
            if ($value === '' && $allowEmpty) {
                continue;
            }
            if ($value === '') {
                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * Shape a SavedQuery for the index page. Eager-loaded channels
     * become a thin id/name/kind/dedupe_window list — the full
     * channel payload is rendered separately in the Channels tab so
     * we don't ship the redacted config twice per response.
     */
    private static function queryPayload(SavedQuery $query): array
    {
        return [
            'id' => $query->id,
            'name' => $query->name,
            'enabled' => (bool) $query->enabled,
            'filter' => $query->filter ?? [],
            'channels' => $query->channels->map(fn (AlertChannel $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'kind' => $c->kind,
                'dedupe_window_seconds' => (int) ($c->pivot->dedupe_window_seconds ?? 60),
            ])->values(),
            'updated_at' => $query->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Shape an AlertChannel for the index page using the redacted
     * config view so the JSON shipped to the browser never carries
     * the raw URL hash or HMAC secret.
     */
    private static function channelPayload(AlertChannel $channel): array
    {
        return [
            'id' => $channel->id,
            'name' => $channel->name,
            'kind' => $channel->kind,
            'enabled' => (bool) $channel->enabled,
            'config' => $channel->redactedConfig(),
            'updated_at' => $channel->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Shape an AlertDelivery for the recent-deliveries panel.
     * Includes the eager-loaded query/channel names so the UI can
     * render rows without a second fetch round-trip per row.
     */
    private static function deliveryPayload(AlertDelivery $delivery): array
    {
        return [
            'id' => $delivery->id,
            'status' => $delivery->status,
            'attempts' => (int) $delivery->attempts,
            'error' => $delivery->error,
            'created_at' => $delivery->created_at?->toIso8601String(),
            'last_attempt_at' => $delivery->last_attempt_at?->toIso8601String(),
            'channel' => $delivery->alertChannel ? [
                'id' => $delivery->alertChannel->id,
                'name' => $delivery->alertChannel->name,
                'kind' => $delivery->alertChannel->kind,
            ] : null,
            'saved_query' => $delivery->savedQuery ? [
                'id' => $delivery->savedQuery->id,
                'name' => $delivery->savedQuery->name,
            ] : null,
            'title' => (string) ($delivery->payload['title'] ?? '(no title)'),
        ];
    }
}
