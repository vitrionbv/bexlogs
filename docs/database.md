# Database

PostgreSQL 18. All timestamps stored with timezone where applicable.

## Entity relationship (simplified)

```
users
  └── organizations
        └── applications
              └── subscriptions (PK = BEX subscription id string)
                    └── pages (one log feed per subscription)
                          └── log_messages

users
  └── bex_sessions (encrypted cookies)

subscriptions
  └── scrape_jobs (→ bex_sessions)
```

---

## Core tables

### `subscriptions`

BookingExperts application subscription.

| Column | Notes |
|--------|-------|
| `id` | **String** — BEX subscription ID (not auto-increment) |
| `application_id` | FK |
| `name`, `environment` | Display; `production` \| `staging` |
| `auto_scrape` | Enable scheduler enqueue |
| `scrape_interval_minutes` | Default **5** |
| `last_scraped_at` | Updated on job completion |
| `max_pages_per_scrape` | Default **200** |
| `lookback_days_first_scrape` | Default **30** |
| `max_duration_minutes` | Default **10** (scheduled); backfills use overrides |
| `max_concurrent_jobs` | Default **1**; EuroParcs uses **2** |
| `job_spacing_minutes` | Default **10** |
| `token_echo_max_attempts` | Default **100** |
| `retention_days` | NULL = keep forever |
| `archive_after_days` | NULL = no cold archive |

### `scrape_jobs`

| Column | Notes |
|--------|-------|
| `subscription_id`, `bex_session_id` | FKs |
| `status` | `queued`, `running`, `completed`, `failed` |
| `params` | JSON — scrape window + limits |
| `stats` | JSON — counters, stop_reason, event timestamps |
| `last_heartbeat_at` | Reaper liveness |
| `attempts` | Incremented on each claim |
| `error` | Failure message |

Partial index on active jobs per subscription (for guard queries).

### `bex_sessions`

See [Sessions and pairing](./sessions-and-pairing.md).

### `pages`

One row per `(organization_id, application_id, subscription_id)`.

Unique index `pages_unique_idx` — all concurrent jobs for a subscription share one `page_id` for dedup.

### `log_messages`

| Column | Notes |
|--------|-------|
| `page_id` | FK → pages |
| `timestamp` | BEX event time |
| `type`, `action`, `method`, `status` | Log dimensions |
| `parameters`, `request`, `response` | JSONB |
| `content_hash` | Dedup hash |

**Unique constraints:**

- Row identity: `(page_id, timestamp, type, action, method, status)`
- Content dedup: `(page_id, content_hash)` — primary scrape dedup mechanism

GIN indexes on JSONB columns; pg_trgm indexes for text search (added via migrations).

---

## Supporting tables

| Table | Purpose |
|-------|---------|
| `organizations`, `applications` | BEX catalog hierarchy |
| `pairing_tokens` | Short-lived extension pairing |
| `audit_logs` | Operator action audit trail |
| `saved_queries` | User-saved log filters |
| `alert_channels`, alert state | Alerting (B5) |
| `ai_conversations` | AI chat history |
| `log_archive_manifest` | Cold tier day index (S3) |
| `personal_access_tokens` | Sanctum API tokens |

---

## Migrations

Located in `laravel/database/migrations/`. Notable:

- `2026_05_04_205500` — dropped partial unique index on active scrape jobs (replaced by application-level guard).
- B6 migration — multi-session fields on `bex_sessions` (`priority`, `health_status`).

Run in production:

```bash
docker exec bexlogs-app-1 php artisan migrate --force
```

Long-running index migrations (`CREATE INDEX CONCURRENTLY`) can take 45–60 minutes on large `log_messages` tables — CI deploy timeout is **90 minutes**.

---

## Retention and archive

| Feature | Column | Command |
|---------|--------|---------|
| Hot retention | `subscriptions.retention_days` | `bex:apply-retention` (03:00 UTC) |
| Cold archive | `subscriptions.archive_after_days` | `bex:archive-cold` (04:00 UTC) → Hetzner S3 |

NULL = feature disabled for that subscription.

---

## Useful queries (operations)

### Jobs stuck running

```sql
SELECT id, subscription_id, started_at, last_heartbeat_at,
       now() - last_heartbeat_at AS silent_for
FROM scrape_jobs
WHERE status = 'running'
ORDER BY id;
```

### Logs per day for a subscription

```sql
SELECT date_trunc('day', timestamp::timestamptz) AS day, COUNT(*)
FROM log_messages lm
JOIN pages p ON lm.page_id = p.id
WHERE p.subscription_id = '5614'
  AND timestamp::timestamptz >= '2026-06-01'
GROUP BY 1 ORDER BY 1;
```

### Session health

```sql
SELECT id, environment, health_status, account_email,
       captured_at, last_validated_at, expired_at
FROM bex_sessions;
```

---

## Backup

`deploy/backup.sh` — daily Postgres dump + storage tarball. See [Deployment](./deployment.md).
