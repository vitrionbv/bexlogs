# Architecture

## End-to-end data flow

### 1. Session capture (one-time / periodic)

```
Operator → /authenticate → pairing token
Extension → BEX login → POST /api/bex-sessions { token, cookies[] }
Laravel → validate cookies → store bex_sessions row (encrypted)
```

### 2. Scheduled scrape

```
scheduler: scrape:enqueue (every minute)
  → for each due subscription:
      SessionRotator.pickSession(user, env)
      ScrapeEnqueueGuard.mayEnqueue(subscription)
      ScrapeWindowPlanner.buildParams(subscription)
      INSERT scrape_jobs (status=queued)

scraper poll loop:
  GET /api/worker/jobs/next → claim oldest queued job (status=running)
  Playwright scrape loop → POST /api/worker/jobs/{id}/batch
  POST heartbeat every 30s (dedicated worker thread)
  POST /complete or /fail
```

### 3. Log ingestion

```
Worker batch → WorkerController::batch
  → resolve Page (subscription → page row)
  → INSERT log_messages with ON CONFLICT dedup
  → update scrape_jobs.stats (rows_received, rows_inserted, pages_processed, …)
  → broadcast ScrapeJobUpdated, LogBatchInserted
```

### 4. UI consumption

```
Browser → Inertia pages (Logs, Jobs, Dashboard, Manage)
Echo → private-user.{userId} for job sidebar + Jobs table
Echo → private-page.{pageId} for live log feed on Logs/Show
```

---

## Production container topology

```
                    Internet
                        │
                   ┌────▼────┐
                   │  Caddy  │  TLS (Cloudflare origin cert)
                   └────┬────┘
                        │
         ┌──────────────┼──────────────┐
         │              │              │
    ┌────▼────┐   ┌─────▼─────┐  ┌────▼────┐
    │   app   │   │  reverb   │  │ (static)│
    │ Nginx+  │   │  :8080    │  │         │
    │ PHP-FPM │   └───────────┘  └─────────┘
    └────┬────┘
         │
    ┌────┴────┬──────────┬──────────┐
    │         │          │          │
┌───▼───┐ ┌───▼───┐ ┌────▼────┐ ┌───▼────┐
│postgres│ │ redis │ │ queue   │ │scheduler│
└────────┘ └───────┘ └─────────┘ └─────────┘

┌─────────────┐
│   scraper   │  Playwright worker (no HTTP port)
│  mem 3g     │  polls app:80 internally
│  max 4 jobs │
└─────────────┘
```

All services share the `bexlogs` Docker network. Postgres and Redis are not exposed publicly.

### APP_ROLE split

The same Laravel Docker image runs with different `APP_ROLE` values:

| Role | Entrypoint behavior |
|------|---------------------|
| `app` | Nginx + PHP-FPM, serves HTTP |
| `queue` | `php artisan queue:work` |
| `scheduler` | `php artisan schedule:work` |
| `reverb` | `php artisan reverb:start` |

This keeps long-running processes isolated without separate codebases.

---

## Key Laravel services

| Service | Responsibility |
|---------|----------------|
| `ScrapeWindowPlanner` | Computes `start_time`, `end_time`, `max_pages`, `max_duration_minutes`, `token_echo_max_attempts` per job |
| `ScrapeEnqueueGuard` | Per-subscription concurrency cap + job spacing |
| `SessionRotator` | Round-robin across multiple healthy BEX sessions |
| `BookingExpertsClient` | Validates sessions, browses org/app/sub catalog |
| `ColdLogReader` | Serves archived days from Hetzner S3 |
| `SafeBroadcast` | Wraps broadcasts from worker path so WS failures don't fail writes |

---

## Scraper process model

```
index.ts
  └── poll loop (POLL_INTERVAL_MS=5000)
        └── inflight Set<Promise> capped at MAX_CONCURRENT_SCRAPES
              └── runScrapeJob(job) per job
                    ├── launchBrowserWithRetry()
                    ├── buildLogsUrl() → initial page GET
                    ├── load_more loop via XHR
                    ├── heartbeat.ts → heartbeat-worker.ts (worker thread)
                    └── complete / fail API calls
```

**Why a heartbeat worker thread:** Playwright can block the Node main thread for minutes on slow page loads. `setInterval` on the main thread would stop firing, `last_heartbeat_at` goes stale, and the reaper marks live jobs as `worker_reaped`. Timers in a dedicated worker thread keep heartbeats independent of Playwright blocking.

---

## Real-time broadcasting

### Server

- Production: `BROADCAST_CONNECTION=reverb`
- PHP containers broadcast to **`reverb:8080`** over the internal Docker network (not the public hostname — avoids WAN round-trip).
- Caddy proxies browser WebSocket connections on `/app/*` to Reverb.

### Channels

| Channel | Audience | Typical events |
|---------|----------|----------------|
| `private-user.{userId}` | Logged-in user | `ScrapeJobUpdated`, log batch summaries, session relink |
| `private-page.{pageId}` | User viewing a log feed | `LogBatchInserted` |
| `private-job.{jobId}` | Job detail dialogs | Job-specific updates |
| `private-server-stats` | Admins | CPU/memory/disk vitals |

### Client

`laravel/resources/js/echo.ts` initializes Laravel Echo with Reverb. Composable `useRealtime.ts` / `useUserChannel` subscribe in Vue pages.

---

## Deduplication strategy

Two layers:

1. **In-batch dedup** (scraper): collapses duplicate rows within one POST body.
2. **Server unique index** `(page_id, content_hash)` on `log_messages`: rejects rows already stored for that page.

Concurrent scrape jobs for the same subscription share one `Page.id`, so cross-job dedup is correct. The scraper's **`duplicate_detection`** early-stop fires when several consecutive pages are 100% duplicates — the normal "caught up" signal for scheduled scrapes.

---

## Security boundaries

| Surface | Auth mechanism |
|---------|----------------|
| Web UI | Fortify session cookie |
| Worker API `/api/worker/*` | Bearer `WORKER_API_TOKEN` |
| Extension `POST /api/bex-sessions` | 48-char pairing token (TTL ~5 min, single-use) |
| Read API `/api/*` | Sanctum personal access token (`read` ability) |
| IP allowlist | Optional `APP_IP_ALLOWLIST` on web + api (worker exempt) |

See [Backend](./backend.md) for details.

---

## External dependencies

| System | Usage |
|--------|-------|
| BookingExperts | Log pages, `load_more_logs.js` pagination, SSO cookies |
| Cloudflare | Origin TLS certificate on production server |
| Hetzner Object Storage | Optional cold-tier log archive (`HETZNER_S3_*`) |
| OpenRouter | Optional AI log agent (`OPENROUTER_API_KEY`) |
