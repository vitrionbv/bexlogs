# Local development

## Prerequisites

- **PHP 8.3+** with extensions: pdo_pgsql, redis, mbstring, xml, curl, zip, bcmath
- **Composer 2**
- **Node.js 22+** and npm
- **Docker** (for Postgres + Redis locally)
- **Git**

Optional: Chromium via Playwright for the scraper (`npx playwright install chromium`).

---

## First-time setup

### 1. Clone and start infrastructure

```bash
git clone <repo-url> bexlogs
cd bexlogs

# Postgres on host port 54323, Redis on 63792
docker compose up -d postgres redis
```

### 2. Laravel application

```bash
cd laravel
composer install
cp .env.example .env   # if .env does not exist
php artisan key:generate
```

Edit `laravel/.env`:

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=54323
DB_DATABASE=bexlogs
DB_USERNAME=bexlogs
DB_PASSWORD=bexlogs

REDIS_HOST=127.0.0.1
REDIS_PORT=63792
REDIS_CLIENT=predis

# Shared secret — must match scraper/.env
WORKER_API_TOKEN=<long-random-string>

# Real-time (required for live job/log updates in dev)
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=local
REVERB_APP_KEY=local-key
REVERB_APP_SECRET=local-secret
REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=http
VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
```

```bash
php artisan migrate
npm install
```

### 3. Create an admin user

There is no public `/register`. Create the first user:

```bash
php artisan admin:make
```

Follow prompts for email and password.

### 4. Scraper worker

In a **second terminal**:

```bash
cd scraper
cp .env.example .env
npm install
npx playwright install chromium
```

Edit `scraper/.env`:

```env
LARAVEL_BASE_URL=http://localhost:8000
WORKER_API_TOKEN=<same-as-laravel>
HEADLESS=true
```

### 5. Browser extension

```bash
cd extension
./build.sh
```

This produces `extension/build/bexlogs-extension.zip` and copies it to `laravel/storage/app/public/` for download from the Authenticate page.

**Load unpacked** in Chrome/Firefox: point at the `extension/` directory (not the zip).

### 6. Pair BookingExperts session

1. Start the dev stack (see below).
2. Visit `http://localhost:8000/authenticate`.
3. Generate a pairing code.
4. Open the extension popup → paste code → choose environment → complete BEX login in the opened tab.
5. Confirm session shows as linked on the Authenticate page.

### 7. Add subscriptions

Use **Manage** (`/manage`) to browse the BookingExperts catalog and enable subscriptions for scraping, or seed via tinker if you have test data.

---

## Daily dev workflow

### All-in-one (recommended)

From `laravel/`:

```bash
composer run dev
```

This starts concurrently:

| Process | Purpose |
|---------|---------|
| `php artisan serve` | HTTP on `:8000` |
| `php artisan queue:listen` | Queue worker |
| `php artisan pail` | Log tail |
| `php artisan reverb:start` | WebSocket on `:8080` |
| `php artisan schedule:work` | Scheduler (enqueue, reaper, etc.) |
| `npm run dev` | Vite HMR |

Start the **scraper** separately in another terminal (`cd scraper && npm run dev`).

### Docker scraper profile

Run Postgres, Redis, and scraper in containers:

```bash
docker compose --profile full up -d --build
```

Set `LARAVEL_BASE_URL=http://host.docker.internal:8000` in scraper env when Laravel runs on the host.

---

## Running tests

### Laravel (CI-equivalent)

```bash
cd laravel
composer test
```

CI uses Postgres + Redis service containers, `cp .env.example .env`, migrate, `npm run build`, then Pest.

### Scraper

```bash
cd scraper
npm run typecheck
npm run build
```

### Lint

```bash
cd laravel
composer run lint          # fix
composer run lint:check    # check only
npm run lint:check
npm run types:check
```

---

## Useful artisan commands (dev)

| Command | Purpose |
|---------|---------|
| `php artisan scrape:enqueue` | Manually enqueue due subscriptions |
| `php artisan scrape:enqueue --force` | Enqueue all auto-scrape subs regardless of interval |
| `php artisan scrape:enqueue --subscription=5614` | Enqueue one subscription |
| `php artisan scrape:reap-stale --minutes=30` | Mark stale running jobs failed |
| `php artisan bex:refresh-sessions` | Re-validate BEX sessions |
| `php artisan tinker` | REPL for debugging |

---

## Common local issues

| Symptom | Fix |
|---------|-----|
| `queued=0 skipped=N no usable session` | Re-pair extension on `/authenticate` |
| Scraper gets 401 on worker API | `WORKER_API_TOKEN` mismatch between laravel and scraper `.env` |
| No live job updates in UI | Ensure Reverb is running and `BROADCAST_CONNECTION=reverb` |
| Playwright browser missing | `npx playwright install chromium` |
| DB connection refused | `docker compose up -d postgres redis`, check port `54323` |

---

## Environment files reference

| File | Purpose |
|------|---------|
| `laravel/.env` | Local dev (from `.env.example`) |
| `laravel/.env.production.example` | Template for production; bootstrap fills secrets |
| `scraper/.env` | Worker URL + token (from `.env.example`) |

Production uses **`laravel/.env`** as the single source of truth passed to Docker Compose via `--env-file laravel/.env`. Do not rely on a root-level `.env` on the server.
