# BexLogs

Self-hosted log explorer for the BookingExperts developer "Logboeken bekijken"
page. Replaces the previous Electron desktop app.

```
┌──────────────────────┐    pairing token + cookies     ┌────────────────────────┐
│ Browser extension    │  ───────────────────────────▶ │ Laravel + PostgreSQL    │
│ (MV3, Chrome/Firefox)│                                │ (Inertia + Vue 3 +      │
│  user logs in to BEX │                                │  shadcn-vue UI)         │
└──────────────────────┘                                └────────────────────────┘
                                                                  │
                                                       jobs ▼     │ ▲ batches/heartbeats
                                                                  │
                                                        ┌─────────────────────┐
                                                        │ Playwright worker    │
                                                        │ (Node + TypeScript)  │
                                                        │ XHR /load_more_logs.js│
                                                        └─────────────────────┘
```

## Layout

```
bexlogs/
├── laravel/      Laravel 13 app — UI, API, scheduler, Excel I/O
├── scraper/      Node Playwright worker — pulls jobs, scrapes, posts batches
├── extension/    MV3 browser extension — captures BookingExperts cookies
└── docker-compose.yml
```

## Quick start (local dev)

```bash
# 0. Bring up Postgres + Redis (host port 54323 / 63792)
docker compose up -d postgres redis

# 1. Laravel
cd laravel
composer install
npm install
php artisan migrate
php artisan key:generate     # only first time
# In .env, set WORKER_API_TOKEN to a long random string.
composer run dev             # starts php artisan serve + vite + queue + reverb (ws on :8080)

# 2. Scraper (in a separate terminal)
cd scraper
cp .env.example .env         # then edit LARAVEL_BASE_URL + WORKER_API_TOKEN
npm install
npx playwright install chromium
npm run dev

# 3. Extension
cd extension
./build.sh                   # produces build/bexlogs-extension.zip
                             # and copies it into laravel/storage/app/public/
```

Then visit `http://localhost:8000/authenticate`, install the extension as
unpacked, paste the pairing code, log into BookingExperts in the popup-opened
tab, and you're set.

## Running everything in containers

```bash
docker compose --profile full up -d --build
```

This adds the Playwright worker container.

## Where things live

- **`laravel/app/Http/Controllers/Api/BexSessionController.php`** — public
  POST endpoint the extension drops cookies into; pairing-token authed.
- **`laravel/app/Http/Controllers/Api/WorkerController.php`** — bearer-token
  authed endpoints the Playwright worker uses (`jobs/next`, `batch`,
  `complete`, `fail`, `sessions/{id}/expired`).
- **`scraper/src/scrape.ts`** — the actual scrape loop:
  - One Playwright context per job, preloaded with the user's BookingExperts
    cookies.
  - Initial page is GETed in a real browser so any anti-bot JS / CSRF tokens
    settle into the cookie jar.
  - `extractRowsFromMain()` runs in `page.evaluate()` to pull rows + the
    first `next_token`.
  - Pagination is XHR-only: `context.request.get('/load_more_logs.js?next_token=…')`,
    parsed with `parseLoadMoreResponse()`. No clicks, no DOM-update waits.
  - Rows → `ParsedLogMessage[]` via `rowToMessage()` (which delegates to the
    HTTP-body parser ported from the Electron app's `logger.js`).
  - Batches POSTed to `/api/worker/jobs/{id}/batch` (deduped server-side via
    a unique index).

## Environment variables

`laravel/.env`:

```
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=54323
DB_DATABASE=bexlogs
DB_USERNAME=bexlogs
DB_PASSWORD=bexlogs

REDIS_HOST=127.0.0.1
REDIS_PORT=63792
REDIS_CLIENT=predis

WORKER_API_TOKEN=  # any long random string; share with scraper/.env

# WebSocket broadcasting (Reverb). composer run dev starts it on :8080.
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=...        # any number
REVERB_APP_KEY=...       # any random string; the frontend uses VITE_REVERB_APP_KEY
REVERB_APP_SECRET=...    # any random string
REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=http
VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
```

Live updates flow over private channels:

- `private-user.{userId}` — sidebar job summary, Pages index, Jobs page (job lifecycle + log batches).
- `private-page.{pageId}` — Pages/Show live log feed; emits a `+N new entries` pill when the user is on page 1, otherwise queues a "Show N new" badge so we don't yank scroll position.

`scraper/.env`:

```
LARAVEL_BASE_URL=http://localhost:8000
WORKER_API_TOKEN=  # must match laravel/.env
```
