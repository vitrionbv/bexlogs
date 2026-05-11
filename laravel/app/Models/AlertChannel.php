<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One named alert delivery destination (Slack webhook, generic webhook,
 * or email) owned by a single user. The actual delivery URL / mailbox
 * lives in `config_encrypted` and is only readable through the
 * `encrypted` cast — operators viewing the row through Inertia/JSON
 * see a redacted payload (see {@see AlertChannel::redactedConfig()}).
 *
 * Lifecycle:
 *   - operator creates a channel in the /alerts UI;
 *   - links it to one or more saved queries (see {@see savedQueries()});
 *   - optionally opts in to receive system alerts on it (see
 *     {@see User::systemAlertChannels()});
 *   - delivery attempts land in `alert_deliveries`, queryable per
 *     channel via {@see deliveries()}.
 */
#[Fillable([
    'user_id',
    'name',
    'kind',
    'config_encrypted',
    'enabled',
])]
class AlertChannel extends Model
{
    public const KIND_SLACK = 'slack';

    public const KIND_WEBHOOK = 'webhook';

    public const KIND_EMAIL = 'email';

    public const KINDS = [
        self::KIND_SLACK,
        self::KIND_WEBHOOK,
        self::KIND_EMAIL,
    ];

    /**
     * Hide the encrypted blob from any default `toArray()` /
     * Inertia-prop conversion. The /alerts UI fetches a redacted view
     * via {@see redactedConfig()} that strips secrets but keeps the
     * URL host visible (so operators can verify which webhook they
     * pointed at without exposing the secret).
     */
    protected $hidden = ['config_encrypted'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            // Encrypts/decrypts at the cast layer so the column on disk
            // is opaque; pg_dump output is safe to share with non-secret
            // collaborators. The shape is { url, secret?, to_address }
            // depending on `kind` — see the migration for details.
            'config_encrypted' => 'encrypted:array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function savedQueries(): BelongsToMany
    {
        return $this->belongsToMany(
            related: SavedQuery::class,
            table: 'saved_query_channel',
            foreignPivotKey: 'alert_channel_id',
            relatedPivotKey: 'saved_query_id',
        )->withPivot('dedupe_window_seconds')->withTimestamps();
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(AlertDelivery::class);
    }

    /**
     * Render a config view that's safe to ship to the UI: the URL host
     * stays visible (so operators can sanity-check "yep, this is the
     * #bex-prod-alerts Slack hook"), but the path/secret components
     * never leave the server.
     */
    public function redactedConfig(): array
    {
        $cfg = $this->config_encrypted ?? [];

        $masked = [];
        foreach ($cfg as $key => $value) {
            if (! is_string($value)) {
                $masked[$key] = $value;

                continue;
            }

            $masked[$key] = match ($key) {
                'url' => self::maskUrl($value),
                'secret' => str_repeat('•', max(8, strlen($value))),
                'to_address' => self::maskEmail($value),
                default => self::maskString($value),
            };
        }

        return $masked;
    }

    /**
     * Hostname-preserving URL mask: `https://hooks.slack.com/services/T0/B0/abc123def`
     * becomes `https://hooks.slack.com/services/****`. Keeps enough
     * context that an operator can tell two hooks apart at a glance
     * (host + first path segment) while never leaking the hash that
     * grants posting rights.
     */
    private static function maskUrl(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['host'])) {
            return self::maskString($url);
        }

        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'];
        $path = $parts['path'] ?? '/';
        $segments = array_values(array_filter(explode('/', $path), fn ($s) => $s !== ''));
        $head = $segments === [] ? '' : '/'.$segments[0];

        return "{$scheme}://{$host}{$head}/****";
    }

    /**
     * `name@example.com` → `n***@example.com`. Domain stays visible so
     * the operator can spot misconfigured "sent to ops@wrong-customer".
     */
    private static function maskEmail(string $email): string
    {
        $at = strrpos($email, '@');
        if ($at === false || $at === 0) {
            return self::maskString($email);
        }

        $local = substr($email, 0, $at);
        $domain = substr($email, $at + 1);

        return $local[0].str_repeat('*', max(2, strlen($local) - 1)).'@'.$domain;
    }

    private static function maskString(string $value): string
    {
        $len = strlen($value);
        if ($len <= 4) {
            return str_repeat('*', $len);
        }

        return substr($value, 0, 2).str_repeat('*', $len - 4).substr($value, -2);
    }
}
