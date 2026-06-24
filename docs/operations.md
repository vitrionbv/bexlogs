# Operations

Runbook for production operators. Assumes install at `/opt/bexlogs` and compose via `laravel/.env`.

---

## Daily health check (2 minutes)

```bash
# 1. Containers healthy?
docker ps --filter name=bexlogs

# 2. Recent jobs?
docker exec bexlogs-postgres-1 psql -U bexlogs -d bexlogs -c \
  "SELECT id, subscription_id, status, completed_at, stats->>'stop_reason' AS reason
   FROM scrape_jobs ORDER BY id DESC LIMIT 5;"

# 3. Session OK?
docker exec bexlogs-postgres-1 psql -U bexlogs -d bexlogs -c \
  "SELECT id, health_status, account_email, last_validated_at, expired_at FROM bex_sessions;"

# 4. Enqueue working?
docker exec bexlogs-app-1 php artisan scrape:enqueue
```

Expect: `queued=N skipped=0` (or skips only for interval/spacing), session `healthy` or `expiring_soon`.

---

## Symptom → diagnosis

### "No jobs for days"

**Most likely: expired BEX session.**

```bash
docker exec bexlogs-app-1 php artisan scrape:enqueue
# → "no usable session for production, skipping" × N
```

**Fix:** Re-pair on `/authenticate`. See [Sessions and pairing](./sessions-and-pairing.md).

Other causes:

- All subscriptions `auto_scrape=false`
- Stuck `running` jobs blocking enqueue (check `ScrapeEnqueueGuard` cap)
- Scheduler container down

---

### Jobs show `worker_reaped`

Worker stopped sending heartbeats for 30+ minutes.

| Cause | Evidence |
|-------|----------|
| Scraper OOM-killed | `dmesg \| grep oom`, scraper restart, many concurrent jobs |
| Scraper container recreated | Deploy/restart during job; `docker inspect` StartedAt |
| Event loop starvation (legacy) | Pre-heartbeat-worker-thread; heartbeats stale while logs show pagination |
| Heartbeat `fetch failed` | App container restarted mid-job |

**Fix:** Investigate scraper logs around `last_heartbeat_at`. Re-enqueue if needed. For OOM → lower `MAX_CONCURRENT_SCRAPES` or raise `mem_limit`.

---

### Job "frozen" — heartbeats OK, no new batches

Usually **token-echo retry loop** with `token_echo_max_attempts: 999999`:

```
load_more next_token echoed — retrying … attempt 1200 … attemptsRemaining 998800
```

Job is alive but not progressing. **Fix:** Fail job manually; re-enqueue with `token_echo_max_attempts: 100`. See [Design decisions](./design-decisions.md).

---

### High `empty_window` rate

Jobs complete with 0 rows after 100 initial-page retries.

| Cause | Action |
|-------|--------|
| Session dying | Re-pair soon |
| Subscription genuinely quiet | Normal overnight |
| Cloudflare / BE outage | Wait, check BEX manually |

Rising empty_window count **before** `expired` status is an early warning.

---

### EuroParcs (5614) backfill strategy

Large subscription (~900k+ rows). Prefer **per-day windows** with ±2h overlap:

```
May 22 day: start 2026-05-21T22:00:00Z → end 2026-05-23T02:00:00Z
```

Not monolithic multi-day jobs. One backfill at a time on prod if possible.

**Overlap rule:** `end_time` = oldest event seen + 2–4h; next window `start_time` = day start − 2h.

---

### Scraper not picking up queued jobs

```bash
docker logs --tail 30 bexlogs-scraper-1
docker logs --since 5m bexlogs-app-1 2>&1 | grep 'worker/jobs'
```

Expect: `GET /api/worker/jobs/next` every ~5s, 200 with job or 204 empty.

If 401 → `WORKER_API_TOKEN` mismatch.

---

## Manual job management

### Fail a stuck job

```bash
docker exec bexlogs-app-1 php artisan tinker --execute="
\$j = App\Models\ScrapeJob::find(JOB_ID);
\$j->update(['status'=>'failed','completed_at'=>now(),'error'=>'Manual cancel','stats'=>array_merge(\$j->stats??[],['stop_reason'=>'operator_cancelled'])]);
"
```

Then restart scraper if the process still holds the job in memory:

```bash
cd /opt/bexlogs
docker compose -f docker-compose.production.yml --env-file laravel/.env restart scraper
```

⚠ Restart kills **all** in-flight scrapes.

---

## Resource monitoring

### Disk

```bash
df -h /
docker exec bexlogs-postgres-1 psql -U bexlogs -d bexlogs -c \
  "SELECT pg_size_pretty(pg_database_size('bexlogs'));"
```

150G disk; alert if >80%. DB ~14GB typical with full EuroParcs history.

### Scraper memory

```bash
docker stats bexlogs-scraper-1 --no-stream
```

Limit: 3g. Six concurrent Chromium jobs previously OOM'd at 2g.

### Scraper PIDs (inside container)

```bash
docker exec bexlogs-scraper-1 cat /sys/fs/cgroup/pids.current
docker exec bexlogs-scraper-1 cat /sys/fs/cgroup/pids.max
```

Cap: 1024. Creeping toward cap → Chromium orphan leak; `init: true` (tini) should prevent this.

---

## Operational rules (team conventions)

1. **Do not deploy scraper during active backfills** — kills in-flight jobs.
2. **One heavy migrate/backfill at a time** on prod (long DB locks).
3. **Use tmux** for long SSH commands on prod.
4. **Re-pair session** when `expiring_soon` or empty_window spikes — don't wait for full expiry.
5. **`token_echo_max_attempts: 100`** for historical backfills; 999999 only when intentionally waiting at live tip.
6. **Disable duplicate early-stop** only for forced backfills (`early_stop_*` → 999999).
7. **Compose env file** is always `laravel/.env`, not root `.env`.

---

## Incident timeline reference (May 2026)

Documented for learning — not exhaustive.

| Date | Issue | Root cause | Fix |
|------|-------|------------|-----|
| May 22 | Multiple `worker_reaped` | Event loop starvation + scheduler collision at :05 | Heartbeat worker thread; reaper 30min |
| May 22 | OOM kill at 18:05 UTC | 6 concurrent Playwright jobs, 2g limit | MAX_CONCURRENT=4, mem 3g |
| May 22 | May 22 backfills "frozen" | token_echo 999999 at log tip | Fail jobs; use token_echo 100 |
| May 30 – Jun 12 | No jobs ~13 days | BEX session expired | Re-pair session |

---

## Logs to collect for debugging

```bash
# Scraper
docker logs --since 2h bexlogs-scraper-1 2>&1 | grep -E 'JOB_ID|error|fatal|SESSION|OOM'

# App worker API
docker logs --since 2h bexlogs-app-1 2>&1 | grep worker

# Scheduler
docker logs --since 1h bexlogs-scheduler-1 2>&1 | grep scrape

# Job row
docker exec bexlogs-postgres-1 psql -U bexlogs -d bexlogs -c \
  "SELECT * FROM scrape_jobs WHERE id=JOB_ID \gx"
```

Debug HTML artifacts: scraper container `/app/debug/` (also persisted if volume mounted).

---

## Alerts (built-in)

| Check | Cadence | Signal |
|-------|---------|--------|
| `bex:check-sessions` | 6h | Session expires within 48h |
| `bex:check-failure-runs` | 15min | 3 consecutive same stop_reason |
| Quiet subscription | 15min | No success in 24h |

Delivered via configured alert channels (`/alerts`).
