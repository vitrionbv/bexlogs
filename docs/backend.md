# Backend

## Laravel application structure

```
laravel/app/
├── Console/Commands/     Artisan commands (scrape, bex, admin)
├── Events/               Broadcast events (ScrapeJobUpdated, …)
├── Http/
│   ├── Controllers/      Web + API controllers
│   └── Middleware/       Auth, IP allowlist, worker auth
├── Models/               Eloquent models
├── Observers/            ScrapeJobObserver, audit hooks
├── Services/             Domain logic (planner, rotator, BE client, …)
└── Support/              Helpers (LogSummary, SafeBroadcast, …)
```

---

## Routing

### Web (`routes/web.php`)

Inertia pages behind `auth` + `verified` middleware:

- Logs, Dashboard, Manage, Jobs, Authenticate, Alerts, Settings, Admin

### API (`routes/api.php`)

| Route group | Auth | Purpose |
|-------------|------|---------|
| `POST /api/bex-sessions` | Pairing token | Extension cookie upload |
| `/api/worker/*` | Bearer `WORKER_API_TOKEN` | Scraper worker |
| `/api/search` | Session | Command palette |
| API Platform resources | Sanctum | Read-only REST |

### Console (`routes/console.php`)

Scheduler definitions — see [Scraping pipeline](./scraping-pipeline.md) and [Architecture](./architecture.md).

### Channels (`routes/channels.php`)

Broadcast authorization — verifies user owns the resource chain (org → app → sub → page).

---

## Authentication and authorization

### Web operators (Fortify)

- Login, password reset, email verification, **2FA** (optional per user).
- **No public registration** — users created via `/admin/users` or `php artisan admin:make`.
- Session driver: **database** in production.

### Admin flag

`users.is_admin` → `admin` middleware for `/admin/*` routes (user CRUD, server stats channel).

### Worker API

`AuthenticateWorker` middleware:

```
Authorization: Bearer <WORKER_API_TOKEN>
```

Must match `config('bex.worker_api_token')` ← `WORKER_API_TOKEN` env.

Exempt from IP allowlist.

### Sanctum read API

Personal access tokens with `read` ability. API Platform resources scoped to user's organizations.

Documented in [api.md](./api.md).

### IP allowlist

`EnsureClientIpIsAllowed` on web + api groups.

- `APP_IP_ALLOWLIST` — comma-separated CIDRs; empty = allow all.
- Exempt: `/up`, `/api/worker/*`, loopback.

---

## Key controllers

| Controller | Responsibility |
|------------|----------------|
| `ManageController` | Subscription CRUD, bulk ops, manual/custom scrape enqueue |
| `AuthenticateController` | Pairing token generation, session list |
| `Api/BexSessionController` | Extension POST endpoint |
| `Api/WorkerController` | Job claim, batch, complete, fail, heartbeat |
| `LogsController` | Log feed browsing, export |
| `JobsController` | Job list, retry, cancel, purge |

---

## Scheduler commands

| Command | Schedule |
|---------|----------|
| `scrape:enqueue` | Every minute |
| `scrape:reap-stale --minutes=30` | Every minute |
| `bex:refresh-sessions` | Hourly |
| `server-stats:broadcast` | Every 5 seconds (admin dashboard) |
| `bex:apply-retention` | Daily 03:00 UTC |
| `bex:compute-baselines` | Daily 03:30 UTC |
| `bex:archive-cold` | Daily 04:00 UTC |
| `bex:check-sessions` | Every 6 hours |
| `bex:check-failure-runs` | Every 15 minutes |

---

## Queue

Production: `QUEUE_CONNECTION=redis`, dedicated `queue` container running `queue:work`.

Used for: exports, alert delivery, heavy async tasks — **not** scrape jobs (those use the Node worker + `scrape_jobs` table).

---

## Config highlights (`config/bex.php`)

| Key | Purpose |
|-----|---------|
| `worker_api_token` | Scraper bearer token |
| `pairing_token_ttl_minutes` | Pairing code lifetime (default 5) |

---

## Audit logging

`audit_logs` table records operator actions (scrape triggered, subscription changes, etc.) with subject type/id and JSON payload. Used for traceability on Manage actions.

---

## AI log agent (optional)

If `OPENROUTER_API_KEY` is set, an AI-assisted chat can query log context. Caps configured via `AI_*` env vars. Optional feature — not required for core scraping.

---

## Excel export

OpenSpout-based export for log data. Triggered from UI, processed via queue for large exports.

---

## Testing

- **Pest 4** feature tests in `laravel/tests/Feature/`.
- Key suites: scrape enqueue, worker batch stats, session rotator, manage enqueue, IP allowlist.
- Run: `composer test` (includes Pint lint check).

CI uses PHP 8.5, Postgres 18, Redis 7.
