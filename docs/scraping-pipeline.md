# Scraping pipeline

## Job lifecycle

```
queued ──(worker picks up)──▶ running ──(success)──▶ completed
                                │
                                └──(error / reaper / operator)──▶ failed
```

| Timestamp | Set when |
|-----------|----------|
| `created_at` | Job enqueued |
| `started_at` | Worker claims via `/jobs/next` |
| `last_heartbeat_at` | Every heartbeat POST + on claim |
| `completed_at` | Terminal state |

### Worker API endpoints

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/worker/jobs/next` | Claim oldest queued job (204 if none) |
| POST | `/api/worker/jobs/{id}/heartbeat` | Liveness ping |
| POST | `/api/worker/jobs/{id}/batch` | Insert log rows + update stats |
| POST | `/api/worker/jobs/{id}/complete` | Mark completed with final stats |
| POST | `/api/worker/jobs/{id}/fail` | Mark failed with error |
| POST | `/api/worker/sessions/{id}/expired` | Mark BEX session expired |

All worker routes use `AuthenticateWorker` middleware (Bearer token).

---

## Enqueue: `scrape:enqueue`

Runs **every minute** via scheduler (`withoutOverlapping(5)`).

For each subscription with `auto_scrape=true`:

1. Skip if scrape interval has not elapsed since `last_scraped_at`.
2. **`SessionRotator::pickSession()`** — returns null → skip with `no usable session`.
3. **`ScrapeEnqueueGuard::mayEnqueue()`** — concurrency/spacing gate.
4. **`ScrapeWindowPlanner::buildParams()`** — compute window + limits.
5. `ScrapeJob::create()` with `status=queued`.

Flags:

- `--force` — ignore interval check
- `--subscription=ID` — single subscription

---

## Scrape window planner

Two modes depending on whether the subscription already has a queued/running sibling:

### Catch-up (no active sibling)

- **Subsequent scrapes:** `start_time = last_scraped_at - 30 minutes` (clock skew tolerance)
- **First scrape ever:** `start_time = now - lookback_days_first_scrape` (default **30 days**)
- `end_time = now`

### Slim concurrent (active sibling exists)

When `max_concurrent_jobs > 1` and a job is already running:

- `start_time = now - 2 × job_spacing_minutes`
- `end_time = now`

The 2× spacing overlap lets the newer job catch up to the older job's inserts; duplicate early-stop then fires quickly.

### Params always included

```json
{
  "start_time": "ISO8601",
  "end_time": "ISO8601",
  "max_pages": 200,
  "max_duration_minutes": 10,
  "token_echo_max_attempts": 100
}
```

Per-subscription columns override defaults (`max_pages_per_scrape`, `max_duration_minutes`, `token_echo_max_attempts`).

### Manual overrides (Manage → custom scrape)

`ManageController::enqueueScrape` accepts optional overrides:

- `start_time`, `end_time`
- `max_pages`, `max_duration_minutes`
- `token_echo_max_attempts`
- `early_stop_duplicate_pages`, `early_stop_min_duplicates`

Used for backfills. See [Design decisions](./design-decisions.md) for guidance on override values.

---

## Concurrency guard

`ScrapeEnqueueGuard` replaces a dropped DB unique index on active jobs per subscription.

| Knob | Default | Effect |
|------|---------|--------|
| `max_concurrent_jobs` | 1 | Max simultaneous queued+running jobs |
| `job_spacing_minutes` | 10 | Min gap between job **starts** |

Denial reasons: `concurrency_cap_reached`, `prior_job_not_yet_started`, `spacing_window`.

**Note:** The scraper's global `MAX_CONCURRENT_SCRAPES` is separate — it limits Playwright browsers across *all* subscriptions.

---

## Scraper execution (`scrape.ts`)

### Initial page

1. Launch Chromium with user's BEX cookies.
2. GET logs URL with `start_time` / `end_time` query params:

   ```
   /organizations/{org}/apps/developer/applications/{app}/application_subscriptions/{sub}/logs?start_time=…&end_time=…
   ```

3. Wait for anti-bot / CSRF settle.
4. `extractRowsFromMain()` via `page.evaluate()` → rows + first `next_token`.
5. Retry up to 100 times if initial page is empty (transient BE/Cloudflare).

### Pagination

- XHR-only: `GET /load_more_logs.js?next_token=…`
- **No** `start_time`/`end_time` on load_more URLs — pagination is token-driven; the Referer carries the filtered logs URL context.
- Parse response with `parseLoadMoreResponse()` (Rails-UJS / HTML fragments).
- POST batches to Laravel when `BATCH_SIZE` (default 100) rows accumulate.

### Stop reasons

| `stop_reason` | Meaning |
|---------------|---------|
| `duplicate_detection` | Healthy catch-up — consecutive all-duplicate pages |
| `caught_up` | Token-echo retries exhausted at log tip |
| `empty_window` | Initial page empty after all retries |
| `pagination_limit` | Hit `max_pages` |
| `time_limit` | Hit `max_duration_minutes` budget |
| `session_expired` | HTTP 401/403 from BEX |
| `pagination_error` | Persistent HTTP 422 (rate limit) |
| `runaway_safety` | Too many consecutive zero-row pages |
| `worker_reaped` | Laravel reaper — heartbeat stale |

---

## Token echo retries

When BEX returns `next_token === sentToken`, the scraper treats it as "at the live log tip" (AWS CloudWatch Logs semantics).

- Retries with `TOKEN_ECHO_RETRY_DELAY_MS` (default **3000ms**) up to `token_echo_max_attempts` (default **100**).
- On exhaustion → **`caught_up`** (successful completion).
- If time budget would be exceeded → **`time_limit`**.

**Important:** High `token_echo_max_attempts` (e.g. 999999) is for waiting at the **live tip** while new logs appear — not for historical backfills. Misuse causes jobs to spin for hours/days at page N with zero new rows. See [Operations](./operations.md).

---

## Duplicate early-stop

Defaults (overridable per job):

| Setting | Default |
|---------|---------|
| `EARLY_STOP_DUPLICATE_PAGES` | 3 consecutive pages |
| `EARLY_STOP_MIN_DUPLICATES` | 10 total duplicate rows observed |

When both thresholds met → stop with `duplicate_detection`.

Disable for forced backfills by setting very high values in custom scrape params.

---

## Heartbeats and reaper

| Setting | Value |
|---------|-------|
| `HEARTBEAT_INTERVAL_MS` | 30000 (30s) |
| Reaper schedule | `scrape:reap-stale --minutes=30` every minute |

Reaper marks `running` jobs as `failed` when:

- `last_heartbeat_at` older than threshold, **or**
- No heartbeat ever and `started_at` older than threshold

Sets `stats.stop_reason = worker_reaped`.

**Why 30 minutes:** ~60× slack over heartbeat interval; tolerates slow token-echo pages, brief app restarts, and main-thread contention before the heartbeat worker thread was added.

---

## Stats blob (`scrape_jobs.stats`)

Updated on each batch and at completion:

| Key | Meaning |
|-----|---------|
| `rows_received` | Rows POSTed (after in-batch dedup) |
| `rows_inserted` | Rows that passed DB unique index |
| `total_duplicates` | received − inserted |
| `pages_processed` | Pagination pages completed |
| `batches` | Batch POST count |
| `last_batch_at` | ISO timestamp of last batch |
| `oldest_event_at` / `newest_event_at` | Min/max BEX event timestamps seen |
| `stop_reason` | Terminal reason |
| `token_echo_retries` | Diagnostic counter |

### UI: "Requested window" vs "Events seen"

- **Requested window** — from `params.start_time` / `params.end_time`.
- **Events seen** — from `stats.oldest_event_at` / `stats.newest_event_at`.

These diverge when pagination walks beyond the requested filter window (BEX token pagination is not strictly clipped to the URL window). High duplicate counts with a wide "Events seen" range on a narrow window usually means re-walking already-ingested history.

---

## Production scraper limits

| Setting | Production | Dev compose |
|---------|------------|-------------|
| `MAX_CONCURRENT_SCRAPES` | **4** | 8 |
| `mem_limit` | **3g** | 2g |
| `pids_limit` | 1024 | 1024 |
| `init: true` (tini) | yes | yes |

Six concurrent Chromium instances previously OOM-killed the 2g cgroup. See [Design decisions](./design-decisions.md).

When `SENTRY_DSN` is set, the scraper reports worker-loop and fatal errors to self-hosted Sentry (`src/instrument.ts`). Scrape job failures are still persisted via `POST /api/worker/jobs/{id}/fail` — they are not double-reported to Sentry. Verify with `npm run sentry:verify` inside the scraper container.

---

## Manual backfill (production tinker pattern)

```php
$sub = App\Models\Subscription::find('5614');
$planner = app(App\Services\ScrapeWindowPlanner::class);
$overrides = [
    'start_time' => '2026-05-21T22:00:00Z',  // day start − 2h (CEST overlap)
    'end_time'   => '2026-05-23T02:00:00Z',  // day end + 2h
    'max_pages' => 999999,
    'max_duration_minutes' => 720,
    'token_echo_max_attempts' => 100,        // NOT 999999 for historical windows
    'early_stop_duplicate_pages' => 999999,
    'early_stop_min_duplicates' => 999999999,
];
$job = App\Models\ScrapeJob::create([
    'subscription_id' => $sub->id,
    'bex_session_id' => 3,  // valid session id
    'status' => App\Models\ScrapeJob::STATUS_QUEUED,
    'params' => $planner->buildParamsWithOverrides($sub, $overrides),
]);
```

Per-day windows with ±2h overlap avoid monolithic multi-day jobs that run for hours.
