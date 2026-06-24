# Sessions and pairing

## Why sessions matter

The Playwright worker scrapes BookingExperts **as the logged-in operator**. It uses encrypted cookies stored in `bex_sessions`. Without at least one **usable** session for an environment (`production` / `staging`), `scrape:enqueue` skips every subscription in that environment:

```
subscription 5614: no usable session for production, skipping
queued=0 skipped=7
```

The scraper keeps polling (204 No Content) but no jobs are created. **This is the most common cause of "scraping stopped" outages.**

---

## Pairing flow

```
1. Operator visits /authenticate
2. Clicks "Generate pairing code"
   → POST /authenticate/start
   → creates PairingToken (48 chars, TTL ~5 min)

3. Extension popup:
   - Server URL (e.g. https://bexlogs.example.com)
   - Pairing code
   - Environment: production | staging

4. Extension opens BookingExperts tab
   → user completes SSO / MFA

5. Extension captures cookies
   → POST /api/bex-sessions { token, cookies[] }

6. Laravel validates via BookingExpertsClient::validateSession
   → INSERT or UPDATE bex_sessions

7. UI polls GET /authenticate/status?token=…
   → status: ready
```

### Extension states (popup)

| State | Meaning |
|-------|---------|
| **linked** | Current tab origin already paired |
| **other-instances** | Other server URLs linked |
| **pair** | Fresh install — needs pairing code |

Extension version is baked into the production Docker image and exposed for download on the Authenticate page.

---

## `bex_sessions` table

| Column | Purpose |
|--------|---------|
| `user_id` | Owning operator |
| `environment` | `production` or `staging` |
| `cookies_encrypted` | Encrypted cookie jar |
| `account_email`, `account_name` | From BEX profile |
| `captured_at` | When cookies were captured |
| `last_validated_at` | Last successful validation |
| `expired_at` | When marked expired |
| `health_status` | `healthy`, `expiring_soon`, `expired`, `disabled` |
| `priority` | Lower = preferred in rotation tie-break |
| `expires_at` | Optional explicit expiry hint |

---

## Session health lifecycle

| Command | Schedule | Purpose |
|---------|----------|---------|
| `bex:refresh-sessions` | Hourly | Re-validate cookies; keeps Rails session warm |
| `bex:check-sessions` | Every 6h | Alert if session expires within 48h |

When validation fails, session moves to `expired` and worker calls `/api/worker/sessions/{id}/expired` on auth errors during scrape.

### Warning signs before expiry

Jobs finishing with `empty_window` (zero rows, 100 initial-page retries) often indicate a **dying session** days before `health_status` flips to `expired`. Watch empty_window rate on the Jobs page.

---

## Session rotation (multi-session)

`SessionRotator` round-robins across all healthy sessions for `(user_id, environment)`.

**Why:** Previously, always picking the lowest-priority session meant one cookie expiry stopped **all** scrapes. Rotation distributes load and limits blast radius.

- Counter stored in Redis: `bex:session-rotation:{userId}:{environment}`
- Atomic increment → modulo session count
- Single session → fast path, no cache

---

## Re-pairing procedure (production)

1. Log into BexLogs web UI.
2. Go to **Authenticate** (`/authenticate`).
3. Generate new pairing code.
4. Open extension → paste code → complete BEX login.
5. Verify session shows **healthy** (not `expired`).
6. Confirm enqueue works:

   ```bash
   docker exec bexlogs-app-1 php artisan scrape:enqueue
   # expect: queued=N skipped=0 (or partial skips for interval/spacing)
   ```

7. Watch Jobs page or `docker logs -f bexlogs-scraper-1` for activity.

No server restart required after re-pairing.

---

## Staging vs production

Subscriptions have an `environment` column. Sessions are environment-specific. A production session does not work for staging subscriptions and vice versa.

---

## Security notes

- Pairing tokens are **single-use**, short TTL (~5 minutes).
- `POST /api/bex-sessions` has no session cookie auth — the pairing token is the credential.
- Cookies are encrypted at rest.
- Extension stores linked server URLs in `chrome.storage.local` (per-browser).
