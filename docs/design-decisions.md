# Design decisions

Documented choices that affect how we build, deploy, and operate BexLogs. When in doubt, prefer consistency with these decisions.

---

## Architecture

### XHR-only pagination (no DOM clicking)

**Choice:** Scrape via `load_more_logs.js?next_token=…` XHR, not Playwright clicks on "Load more".

**Why:** Faster, deterministic, no DOM race conditions. Matches the old Electron app's HTTP approach. Initial page still uses a real browser GET for CSRF/anti-bot cookie settle.

### One Playwright context per job

**Choice:** Each scrape job gets its own browser context with that job's cookies.

**Why:** Isolation between concurrent jobs; clean teardown on completion. Trade-off: memory — capped by `MAX_CONCURRENT_SCRAPES`.

### Server-side dedup, not client-side trust

**Choice:** Unique index `(page_id, content_hash)` on `log_messages`; scraper may POST duplicates freely.

**Why:** Concurrent jobs for the same subscription share one `Page.id`. Idempotent inserts simplify worker logic.

### Node worker outside Laravel queue

**Choice:** Scrape jobs live in `scrape_jobs` table; Node polls `/jobs/next`, not Redis queue.

**Why:** Playwright needs a long-lived Node process with Chromium — poor fit for PHP queue workers. Laravel queue handles short async tasks (exports, alerts).

---

## Scraping behavior

### Duplicate detection as normal completion

**Choice:** `duplicate_detection` stop reason is **success**, not failure.

**Why:** Scheduled scrapes expect to re-walk recent history until inserts drop to zero. Early-stop after 3 consecutive all-duplicate pages avoids wasting pages.

### Token echo = "at log tip"

**Choice:** When `next_token === sentToken`, retry then complete as `caught_up`.

**Why:** BookingExperts uses AWS CloudWatch Logs-style pagination. Echo means no new data at this cursor.

**Corollary:** `token_echo_max_attempts: 999999` is **not** for scanning history — it waits for **new** logs at the tip. Historical backfills should use default **100** attempts.

### Reaper at 30 minutes (not 3)

**Choice:** `scrape:reap-stale --minutes=30` with 30s heartbeats.

**Why:** ~60× slack over heartbeat interval. Tolerates slow pages, brief app restarts, and pre-worker-thread starvation. False reaps were worse than delayed recovery.

**Note:** Root `README.md` still mentions 3 minutes in one place — **30 minutes is authoritative** (`routes/console.php`).

### Heartbeat on dedicated worker thread

**Choice:** `heartbeat-worker.ts` runs timers off the main thread.

**Why:** Playwright blocks the main thread for minutes on slow `page.goto` / load_more. Main-thread `setInterval` caused false `worker_reaped` failures with live jobs.

### Application-level concurrency guard (not DB unique index)

**Choice:** Dropped partial unique index on active jobs; use `max_concurrent_jobs` + `job_spacing_minutes` on subscription.

**Why:** Large subscriptions (EuroParcs) need `max_concurrent_jobs=2` with controlled overlap. DB hard cap of 1 was too rigid.

### Scrape window: 30-minute lookback on catch-up

**Choice:** `start_time = last_scraped_at - 30 minutes` (not exact last timestamp).

**Why:** Tolerates clock skew and late-arriving BEX log entries.

---

## Production resource limits

### MAX_CONCURRENT_SCRAPES = 4 (production)

**Choice:** Reduced from 8 after OOM incident (6 jobs × ~350MB Chromium > 2g cgroup).

**Why:** Two EuroParcs backfills + two scheduled jobs must coexist without killing the worker.

Dev compose still defaults to 8 — acceptable on developer machines with more headroom.

### mem_limit = 3g (production scraper)

**Choice:** Raised from 2g after OOM kill at ~962MB RSS + child processes.

### init: true (tini) on scraper

**Choice:** Docker injects tini as PID 1.

**Why:** Node doesn't reap orphaned Chromium children. Accumulated zombies hit `pids.max` → `spawn EAGAIN` on every new browser launch.

### Conditional scraper deploy in CI

**Choice:** Rebuild/recreate scraper container only when scraper source or compose hash changes.

**Why:** Deploy recycled scraper mid-backfill and lost hours of work. App/queue/scheduler restarts are safe; scraper restart is not during long jobs.

---

## Sessions

### No public registration

**Choice:** Fortify without `Features::registration()`. Admins create users.

**Why:** Single-tenant operator tool — open registration is unnecessary attack surface.

### Pairing token for extension (not OAuth)

**Choice:** Short-lived 48-char token bridges extension → Laravel → cookie store.

**Why:** MV3 extension can't easily do OAuth with BEX; operator-in-the-loop login is required anyway for MFA.

### Session rotation across multiple cookies

**Choice:** `SessionRotator` round-robin when N healthy sessions exist.

**Why:** One expiry no longer stops all scrapes. Operators can add a second paired browser before the first expires.

---

## Frontend

### Inertia + Vue (not separate React SPA)

**Choice:** Laravel renders Inertia pages; Vue components in `resources/js/pages/`.

**Why:** Single auth model, CSRF for forms, Wayfinder typed routes. Real-time via Echo where needed.

### shadcn-vue (owned components)

**Choice:** Copy-in components vs npm UI library.

**Why:** Full customization, no black-box upgrades, Tailwind-native.

### Live logs: don't steal scroll position

**Choice:** On Logs/Show, only auto-prepend when user is on page 1; otherwise show "N new" badge.

**Why:** Operators reading historical rows shouldn't get jumped to top on every batch.

### Manage custom scrape dialog

**Choice:** Advanced overrides exposed in UI (sliders) next to "Scrape now".

**Why:** Operators need backfill control without SSH/tinker — but must understand token-echo and duplicate-stop semantics (see Operations).

---

## Backend

### SafeBroadcast from worker path

**Choice:** Wrap broadcasts in try/catch so Reverb failure doesn't fail batch writes.

**Why:** Data correctness > live UI. Worker POST must succeed even if WS is down.

### IP allowlist optional, worker exempt

**Choice:** `APP_IP_ALLOWLIST` gates web/API; `/api/worker/*` always allowed.

**Why:** Scraper connects from internal Docker network; allowlist would break worker without careful CIDR rules.

### Read API read-only (Sanctum + API Platform)

**Choice:** No write API for external integrations.

**Why:** Scraping and config are operator UI concerns; API is for metrics/export consumers.

---

## Data lifecycle

### Retention opt-in per subscription

**Choice:** `retention_days` NULL = keep forever.

**Why:** EuroParcs historical data is valuable; don't delete unless operator opts in.

### Cold archive to Hetzner S3

**Choice:** Optional `archive_after_days` → gzip JSONL per day on S3, manifest table for lookups.

**Why:** Cheaper long-term storage; hot Postgres stays queryable for recent data.

### Pages = one feed per subscription

**Choice:** `pages` table name is historical; 1:1 with subscription for log storage.

**Why:** Dedup index is per-page; concurrent jobs must share page_id.

---

## Testing philosophy

### Pest feature tests over unit mocks for scrape path

**Choice:** Feature tests hit enqueue, worker batch, guard, rotator with real DB.

**Why:** Scrape correctness is integration-heavy; mocks hide SQL/JSON edge cases.

### Scraper typecheck in CI, not full E2E against BEX

**Choice:** `npm run typecheck` + build in CI; manual/offline scripts for load-more/token-echo tests.

**Why:** BEX requires live cookies; E2E in CI would be flaky.

---

## Known trade-offs (accepted)

| Trade-off | Consequence |
|-----------|-------------|
| load_more ignores URL time window | "Events seen" can exceed "Requested window" on backfills |
| Single BEX session in prod today | Rotation helps when multiple sessions exist; one expiry still blocks all |
| Scheduler + manual enqueue can collide | Multiple jobs at `:05`; manage with concurrency limits |
| Long backfills block scraper slot | Plan overlap windows; don't run 7 concurrent heavy jobs |
| Re-pair is manual | No automatic BEX OAuth refresh — monitor `expiring_soon` |

---

## When to revisit these decisions

- **BEX changes pagination API** → scraper parser + docs
- **Postgres > 50GB** → partition `log_messages` or aggressive retention defaults
- **Multiple operators / tenants** → session model, user isolation review
- **Repeated OOM at 4 concurrent** → per-sub memory budgets or separate scraper pools
