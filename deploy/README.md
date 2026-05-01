# BexLogs — Production Deployment

Single-host deployment on Hetzner Cloud (Ubuntu 24.04 LTS, x86_64) with
auto-deploy on push-to-`main` via a self-hosted GitHub Actions runner.

```
                                       ┌─────────────────────────────────┐
   Internet ──────── :443/:80 ──────▶  │  caddy            (TLS, reverse │
                                       │                    proxy, ACME) │
                                       └────────┬────────────────────────┘
                                                │
                                                ▼
                                       ┌─────────────────────────────────┐
                                       │  app           Nginx + PHP-FPM  │
                                       │  reverb        WebSocket server │
                                       │  queue         Laravel worker   │
                                       │  scheduler     schedule:work    │
                                       │  scraper       Playwright       │
                                       └────────┬────────────────────────┘
                                                │
                                                ▼
                                       ┌─────────────────────────────────┐
                                       │  postgres   redis               │
                                       │  (named volumes — never exposed)│
                                       └─────────────────────────────────┘
```

All seven services live in `docker-compose.production.yml`. The single
`bexlogs/app` image (built from `docker/app/Dockerfile`) is reused as four
roles via `APP_ROLE`.

---

## Prerequisites

1. A fresh Hetzner Cloud server running **Ubuntu 24.04 LTS** (any size that
   has ≥ 2 GB RAM; CX21 is fine).
2. **DNS A record** for your chosen domain pointing at the server's public
   IPv4 *before* you run the bootstrap, so Caddy can issue the Let's Encrypt
   cert on first boot.
3. Your **GitHub repo** (this one) cloned somewhere you can copy a runner
   registration token from: GitHub → Settings → Actions → Runners → *New
   self-hosted runner* → copy the token.
4. (Optional) **GitHub Actions repository variables**:
   - `DEPLOY_DOMAIN` — your fqdn, e.g. `bexlogs.example.com`. The deploy
     workflow uses this to do an external `https://${DOMAIN}/up` smoke
     check after each push.
   - `NOTIFY_WEBHOOK` (secret) — Slack/Discord-compatible incoming webhook;
     posts on deploy failure.

That's it. PHP, Composer, Node, Postgres, Redis, Nginx — none of those
touch the host. Everything runs in containers.

---

## One-line bootstrap

SSH into the fresh server as root (or use Hetzner's console), then:

```bash
curl -sSL https://raw.githubusercontent.com/<owner>/bexlogs/main/deploy/bootstrap.sh \
  | sudo bash -s -- \
      --domain bexlogs.example.com \
      --acme-email you@example.com \
      --repo https://github.com/<owner>/bexlogs.git \
      --github-runner-url https://github.com/<owner>/bexlogs \
      --github-runner-token AAAA...XXXX
```

The script is **idempotent** — re-running it does the right thing (won't
regenerate `APP_KEY`, won't reconfigure UFW twice, won't re-register the
runner). Skip the runner flags if you want to register it later by hand,
or skip `--repo` to use whatever's already at `/opt/bexlogs`.

What it does:

1. `apt update && upgrade`
2. Install `ca-certificates curl git ufw fail2ban unattended-upgrades jq openssl`
3. Install Docker Engine + Compose plugin from Docker's official APT repo
4. UFW: deny all in, allow `22/80/443`
5. Enable `unattended-upgrades` for security patches
6. Create system user `bexlogs` (no shell, in `docker` group)
7. `git clone` into `/opt/bexlogs`
8. Generate `/opt/bexlogs/laravel/.env` from `.env.production.example` —
   random `APP_KEY`, `DB_PASSWORD`, `WORKER_API_TOKEN`, `REVERB_APP_*`
9. `docker compose build && up -d`
10. Wait for `app` health, then `php artisan migrate --force` + cache warm
11. The browser-extension zip is built inside the docker image (no host
    `zip` install needed)
12. Register the GitHub Actions runner as a systemd service if a token was
    given

Total time on a CX21: about **6–10 minutes** the first time (most of it is
the docker image build).

---

## Order of operations

1. **DNS first.** Point `bexlogs.example.com` at the server's IPv4.
2. **Bootstrap.** Run the curl|bash one-liner above.
3. **First deploy.** Bootstrap *itself* does the first deploy (steps 9–10).
4. **Runner registers.** Step 12 of bootstrap registers the GH runner. From
   here on out, every push to `main` deploys.
5. **Push to main.** `git push origin main` → runner picks it up →
   `.github/workflows/deploy.yml` runs → site updated in ~60–120 s.

---

## GitHub repository configuration

Set these in GitHub → Settings → Secrets and variables → Actions:

| kind     | name             | required? | what for                                              |
|----------|------------------|-----------|-------------------------------------------------------|
| variable | `DEPLOY_DOMAIN`  | optional  | external `/up` health probe in the deploy workflow    |
| secret   | `NOTIFY_WEBHOOK` | optional  | Slack/Discord webhook URL — pings on deploy failure   |

That's it. **No** SSH keys, **no** registry tokens, **no** server
credentials live in GitHub — the self-hosted runner has direct shell access
to `/opt/bexlogs` and the docker socket. That's the entire deploy story.

---

## Where logs live

```bash
# Per-service logs (follow)
docker compose -f docker-compose.production.yml logs -f app
docker compose -f docker-compose.production.yml logs -f reverb
docker compose -f docker-compose.production.yml logs -f queue
docker compose -f docker-compose.production.yml logs -f scheduler
docker compose -f docker-compose.production.yml logs -f scraper
docker compose -f docker-compose.production.yml logs -f caddy

# Just the last hour
docker compose -f docker-compose.production.yml logs --since 1h app

# Laravel-side application logs (single file driver)
docker compose -f docker-compose.production.yml exec app \
    tail -f storage/logs/laravel.log

# GitHub Actions runner — systemd-managed
sudo systemctl status 'actions.runner.*'
sudo journalctl -u 'actions.runner.*' -f

# UFW
sudo ufw status verbose

# Caddy access log: included in `logs -f caddy` (stdout JSON / console)
```

---

## Updating

**Just push to `main`.** The runner does the rest. If you need to redeploy
a specific commit by hand, use the workflow_dispatch:

```bash
gh workflow run deploy.yml -f ref=<sha-or-branch>
```

---

## Rolling back

Cleanest is to revert via git and let the workflow redeploy:

```bash
# On your dev box
git revert <bad-sha>
git push origin main
```

For an emergency rollback on the server itself:

```bash
ssh root@bexlogs-server
cd /opt/bexlogs
git checkout <last-good-sha>
docker compose -f docker-compose.production.yml --env-file laravel/.env up -d --build
docker compose -f docker-compose.production.yml --env-file laravel/.env exec -T app \
    php artisan migrate --force   # if the bad commit added migrations
```

Or via workflow_dispatch with the rollback ref:

```bash
gh workflow run deploy.yml -f ref=<last-good-sha>
```

---

## Backups

A rotating daily backup is at `deploy/backup.sh`. It dumps Postgres and
tarballs the storage volume into `/var/backups/bexlogs/`. To wire it up:

```bash
sudo crontab -e
# Add:
0 3 * * * /opt/bexlogs/deploy/backup.sh >> /var/log/bexlogs-backup.log 2>&1
```

Override retention with `KEEP=30 /opt/bexlogs/deploy/backup.sh` (default 14
of each kind).

To restore:

```bash
# Postgres
gunzip -c /var/backups/bexlogs/bexlogs-pg-2026-04-30.sql.gz | \
  docker compose -f docker-compose.production.yml --env-file laravel/.env exec -T postgres \
    psql -U bexlogs -d bexlogs

# Storage
docker run --rm \
  -v bexlogs_storage:/storage \
  -v /var/backups/bexlogs:/in:ro \
  alpine:3.20 \
  sh -c 'cd /storage && tar -xzf /in/bexlogs-storage-2026-04-30.tar.gz'
```

If you want off-host backups, point a cronjob at rclone / restic / scp
pulling from `/var/backups/bexlogs/`. Out of scope here.

---

## IP allowlist

The Laravel app supports an optional, application-level IP allowlist via
`App\Http\Middleware\EnsureClientIpIsAllowed`. It is wired into both the
`web` and `api` middleware groups, so it covers `/login`, the dashboard,
`/admin/*`, the BookingExperts authenticate flow, the `/broadcasting/auth`
endpoint, etc. The `/up` healthcheck and `/api/worker/*` (scraper, gated
by `WORKER_API_TOKEN`) are always exempt; loopback (127.0.0.1, ::1) is
always allowed implicitly.

Empty allowlist == open. A fresh deploy without `APP_IP_ALLOWLIST` set
behaves exactly like before — nothing gets locked out.

To turn it on:

```bash
ssh root@<server>
cd /opt/bexlogs

# Set / update the env var. Idempotent — replaces the value if the key
# already exists, appends otherwise.
KEY=APP_IP_ALLOWLIST
VAL='203.0.113.10,198.51.100.0/24'   # ← your IP(s) / CIDR(s)
ENV_FILE=/opt/bexlogs/laravel/.env

if grep -qE "^${KEY}=" "$ENV_FILE"; then
  sed -i "s|^${KEY}=.*|${KEY}=${VAL}|" "$ENV_FILE"
else
  printf '\n%s=%s\n' "$KEY" "$VAL" >> "$ENV_FILE"
fi

# Re-cache config so the new value takes effect (the deploy workflow
# already runs config:cache, but only as part of a deploy — for a bare
# .env edit you do it by hand).
COMPOSE='docker compose -f docker-compose.production.yml --env-file laravel/.env'
for svc in app queue scheduler reverb; do
  $COMPOSE exec -T "$svc" php artisan config:cache
done
```

Rejected requests get a 403 and a one-line `notice` log entry with IP,
method, URL, and User-Agent. Garbage entries (e.g. a hostname instead of
an IP) are silently skipped — the middleware logs a single warning per
worker process so you notice in the logs but don't drown in repeats.

### Defense in depth — independent of the middleware

The application-level allowlist works at the PHP layer, which means an
attacker still touches Caddy and PHP-FPM before getting blocked. For
stronger isolation use **either or both** of these:

**1. Cloudflare WAF rule.** Stops attackers at Cloudflare's edge so
nothing hits the origin at all. From the Cloudflare dashboard →
Security → WAF → Custom rules, add a rule with action `Block` and the
expression (replace the IP with your own, comma-separate for multiple):

```
(http.host eq "bexlogs.vitrion.dev") and not (ip.src in {203.0.113.10})
```

**2. UFW + Cloudflare's edge IPs.** Force all `:80` / `:443` traffic to
come through Cloudflare so even bypass attempts via the server's bare
IPv4 fail before reaching Caddy. Cloudflare publishes its current edge
ranges at <https://www.cloudflare.com/ips/>. Pseudocode (regenerate
when the published list changes — typically a few times a year):

```bash
sudo ufw allow from <each-CF-range> to any port 443 proto tcp
sudo ufw allow from <each-CF-range> to any port 80  proto tcp
sudo ufw deny  80,443/tcp
```

This is strictly stricter than the application-level allowlist: even
a leaked origin IP can no longer talk to the box on `:80`/`:443` unless
the request comes through Cloudflare. The application-level allowlist
then layers on top to gate which Cloudflare-fronted requests are allowed
to log in. You can use either, both, or neither.

---

## SSL / TLS

Caddy auto-provisions a Let's Encrypt cert the first time it sees a
request for your domain. Requirements:

- Port `80` reachable from the public internet (UFW already allows it)
- DNS A record resolves to the server before the first `docker compose up`
- `APP_DOMAIN` and `APP_ACME_EMAIL` set in `laravel/.env` (the bootstrap
  fills these in for you)

Renewals are automatic. Certs and account state live in the
`bexlogs_caddy_data` named volume — back it up with the storage volume if
you care about not re-running ACME after a disaster.

---

## Service map

| service     | image / role                                     | port (host) | port (docker net) |
|-------------|--------------------------------------------------|-------------|-------------------|
| caddy       | `caddy:2-alpine`                                 | 80, 443     | —                 |
| app         | `bexlogs/app:latest` `APP_ROLE=app`              | —           | 80                |
| reverb      | `bexlogs/app:latest` `APP_ROLE=reverb`           | —           | 8080              |
| queue       | `bexlogs/app:latest` `APP_ROLE=queue`            | —           | —                 |
| scheduler   | `bexlogs/app:latest` `APP_ROLE=scheduler`        | —           | —                 |
| scraper     | `bexlogs/scraper:latest`                         | —           | —                 |
| postgres    | `postgres:18-alpine`                             | —           | 5432              |
| redis       | `redis:7-alpine`                                 | —           | 6379              |

Postgres and Redis are **never** exposed to the host or the internet — only
the docker bridge network can reach them.

---

## Troubleshooting

### Site is up but `https://…` URL helpers generate `http://`

Laravel doesn't trust the proxy by default. Add to
`laravel/bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->trustProxies(at: '*');
    // ... rest of your middleware config
})
```

(Out of scope for this deployment to modify, but it's a one-line fix.)

### `docker compose exec -T app …` hangs or returns blank

Most often the `app` container is restart-looping. Check logs:

```bash
docker compose -f docker-compose.production.yml ps
docker compose -f docker-compose.production.yml logs --tail=100 app
```

### Caddy keeps issuing certs / "ACME failure"

- Confirm DNS resolves: `dig +short A bexlogs.example.com`
- Confirm `:80` is reachable from outside: `curl -v http://bexlogs.example.com/up`
- Hetzner doesn't block ACME, but UFW could — re-run `ufw status` and
  ensure `80/tcp` shows ALLOW.

### Reverb isn't receiving WebSocket connections

```bash
# 1. Is the WS service even up?
docker compose -f docker-compose.production.yml ps reverb

# 2. Does Caddy think /app/* is a Reverb path? curl through it:
curl -i https://bexlogs.example.com/app/$(docker compose exec -T app printenv REVERB_APP_KEY)
# Expect HTTP/1.1 101 Switching Protocols (well, you need a WS client; this
# just proves the route reaches Reverb — a 4xx from Reverb is good enough)

# 3. Reverb logs
docker compose -f docker-compose.production.yml logs -f reverb
```

The Echo client connects to `wss://${REVERB_HOST}:${REVERB_PORT}/app/${REVERB_APP_KEY}`.
In our setup `REVERB_HOST=$APP_DOMAIN`, `REVERB_PORT=443`, `REVERB_SCHEME=https`,
and Caddy proxies `/app/*` to `reverb:8080`.

### Restart a single service

```bash
docker compose -f docker-compose.production.yml restart queue
docker compose -f docker-compose.production.yml restart scheduler
docker compose -f docker-compose.production.yml restart reverb
docker compose -f docker-compose.production.yml restart scraper
```

### Get a shell inside a service

```bash
docker compose -f docker-compose.production.yml exec app  bash
docker compose -f docker-compose.production.yml exec scraper sh
docker compose -f docker-compose.production.yml exec postgres psql -U bexlogs
docker compose -f docker-compose.production.yml exec redis    redis-cli
```

### The deploy workflow can't reach `git pull`

The runner runs as the `bexlogs` user. If `git pull` fails with a 403, the
repo is private and the runner needs a deploy key or a PAT. Easiest fix:
make the repo public, or add an SSH deploy key:

```bash
sudo -u bexlogs ssh-keygen -t ed25519 -N '' -f /home/bexlogs/.ssh/id_ed25519
cat /home/bexlogs/.ssh/id_ed25519.pub
# Paste into GitHub → Settings → Deploy keys (read-only is fine)

# Then point the local clone at the SSH remote:
sudo -u bexlogs git -C /opt/bexlogs remote set-url origin git@github.com:<owner>/bexlogs.git
```

### Check what version of the extension is being served

```bash
curl -sSI https://bexlogs.example.com/extension/download | grep -i content-disposition
# → attachment; filename="bexlogs-extension-1.1.0.zip"
```

The extension version is pinned in `extension/manifest.json` and propagated
into `EXTENSION_VERSION` by the bootstrap script (and via subsequent deploys
the runner can re-run `bootstrap.sh --no-deploy` to refresh it, or you can
just bump it in `.env` directly).
