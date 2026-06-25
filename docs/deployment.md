# Deployment

## Production overview

| Item | Value |
|------|-------|
| Install path | `/opt/bexlogs` |
| Compose file | `docker-compose.production.yml` |
| Env file | `laravel/.env` (via `--env-file laravel/.env`) |
| Deploy trigger | Push to `main` or manual `workflow_dispatch` |
| Runner | Self-hosted: `[self-hosted, linux, bexlogs]` on the production server |
| TLS | Caddy + Cloudflare origin certificate at `/etc/bexlogs/origin/` |

---

## Initial server bootstrap

Script: `deploy/bootstrap.sh` (run as root on a fresh Ubuntu server).

Steps:

1. System updates, Docker Engine + Compose plugin
2. UFW: allow 22/80/443
3. Create `bexlogs` system user (docker group)
4. Clone repo to `/opt/bexlogs`
5. Generate `laravel/.env` from `laravel/.env.production.example`:
   - Random: `APP_KEY`, `DB_PASSWORD`, `WORKER_API_TOKEN`, `REVERB_APP_*`
   - Substitute: `APP_DOMAIN`, `APP_ACME_EMAIL`, `APP_URL`
6. Require Cloudflare origin cert files
7. `docker compose build && up -d`
8. Wait for app health → `migrate --force`, `storage:link`, cache warm
9. Extension zip built inside Docker image
10. Optional: register GitHub Actions self-hosted runner

Full runbook: `deploy/README.md`.

---

## Production services

| Service | Image / build | Notes |
|---------|---------------|-------|
| **caddy** | caddy:2 | Public HTTPS, proxies to app + Reverb WS |
| **app** | `docker/app` | `APP_ROLE=app`, Nginx + PHP-FPM |
| **queue** | same | `APP_ROLE=queue` |
| **scheduler** | same | `APP_ROLE=scheduler` |
| **reverb** | same | `APP_ROLE=reverb`, port 8080 internal |
| **scraper** | `scraper/Dockerfile` | Playwright worker |
| **postgres** | postgres:18-alpine | Volume `bexlogs_pg_data` |
| **redis** | redis:7-alpine | AOF persistence |

### Scraper production settings

```yaml
MAX_CONCURRENT_SCRAPES: ${MAX_CONCURRENT_SCRAPES:-4}
mem_limit: 3g
pids_limit: 1024
init: true   # tini reaps orphan Chromium processes
```

Tune via `laravel/.env`:

```env
MAX_CONCURRENT_SCRAPES=4
```

---

## CI/CD (`.github/workflows/ci.yml`)

### Job: `laravel`

- Ubuntu, PHP 8.5, Node 22
- Services: Postgres 18, Redis 7
- `composer test`, `npm run build`

### Job: `scraper`

- `npm run typecheck`, `npm run build`

### Job: `deploy` (main only)

1. Pull at `/opt/bexlogs` (no `actions/checkout` — uses server working tree)
2. **Conditionally rebuild scraper** — only if `scraper/**` changed or scraper compose hash changed
3. **Always rebuild app** image
4. `docker compose up -d --remove-orphans`
5. Migrate + cache warm
6. Restart **reverb, queue, scheduler** — **not scraper** (unless rebuilt)
7. Optional external health check + failure webhook

### Why scraper deploy is special

Scraper jobs run up to **hours** (backfills). Recreating the scraper container mid-job:

- Kills Playwright/Chromium
- Loses unpersisted pagination progress
- Job stays `running` until reaper marks it `worker_reaped`

**Rule:** Avoid scraper redeploys during active backfills. App-only deploys do not recycle the scraper container.

App/queue/scheduler/restart is safe — short-lived work units.

---

## Manual deploy commands

On the production server:

```bash
cd /opt/bexlogs
git pull origin main

docker compose -f docker-compose.production.yml \
  --env-file laravel/.env \
  build app

docker compose -f docker-compose.production.yml \
  --env-file laravel/.env \
  up -d --remove-orphans

docker exec bexlogs-app-1 php artisan migrate --force
docker exec bexlogs-app-1 php artisan config:cache
docker exec bexlogs-app-1 php artisan route:cache
docker exec bexlogs-app-1 php artisan view:cache
```

To rebuild scraper ( ⚠ kills in-flight scrapes):

```bash
docker compose -f docker-compose.production.yml \
  --env-file laravel/.env \
  build scraper

docker compose -f docker-compose.production.yml \
  --env-file laravel/.env \
  up -d scraper
```

---

## Required secrets (`laravel/.env`)

| Variable | Purpose |
|----------|---------|
| `APP_KEY` | Laravel encryption |
| `APP_DOMAIN` | Caddy TLS + public URL |
| `DB_PASSWORD` | Postgres |
| `WORKER_API_TOKEN` | Scraper ↔ Laravel auth |
| `REVERB_APP_ID/KEY/SECRET` | WebSocket |
| `VITE_REVERB_*` | Baked into frontend at build time |

Optional:

| Variable | Purpose |
|----------|---------|
| `HETZNER_S3_*` | Cold log archive |
| `OPENROUTER_API_KEY` | AI agent |
| `SENTRY_LARAVEL_DSN` | Error/performance monitoring (self-hosted Sentry) |
| `SENTRY_DSN` | Scraper worker errors (self-hosted Sentry; job failures still go to Laravel `/fail`) |
| `APP_IP_ALLOWLIST` | Restrict web/API by IP |
| `NOTIFY_WEBHOOK` | CI failure notifications |

---

## Health checks

```bash
# Container status
docker ps --format 'table {{.Names}}\t{{.Status}}'

# App HTTP
curl -sS -o /dev/null -w '%{http_code}' https://${APP_DOMAIN}/up

# Enqueue dry run
docker exec bexlogs-app-1 php artisan scrape:enqueue

# Scraper polling
docker logs --tail 20 bexlogs-scraper-1

# Sentry verify (requires SENTRY_DSN on the scraper container)
docker exec bexlogs-scraper-1 npm run sentry:verify

# Scheduler ticking
docker logs --tail 20 bexlogs-scheduler-1 | grep scrape
```

---

## Backup and restore

`deploy/backup.sh`:

- `pg_dump` of `bexlogs` database
- Tarball of `storage/` (exports, debug artifacts)

Schedule via cron on the host. See `deploy/README.md` for retention guidance.

---

## Extension distribution

Production Docker build produces `bexlogs-extension.zip` served from Laravel storage. Version read from `extension/manifest.json` → `EXTENSION_VERSION` in `.env`.

Operators install from **Authenticate** page download link.

---

## Common deploy pitfalls

| Mistake | Consequence |
|---------|-------------|
| Using root `.env` instead of `laravel/.env` | Compose fails or missing secrets |
| `docker compose up` without `--env-file laravel/.env` | `WORKER_API_TOKEN` missing |
| Rebuilding scraper during backfill | Lost progress, `worker_reaped` after 30 min |
| Forgetting `VITE_REVERB_*` at build time | Live updates broken until app rebuild |
