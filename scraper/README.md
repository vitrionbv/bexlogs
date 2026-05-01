# BexLogs Scraper Worker

Headless Playwright worker that scrapes BookingExperts logs and POSTs them
to the BexLogs Laravel API.

## How it works

1. Polls `GET /api/worker/jobs/next` on the BexLogs Laravel server.
2. For each job:
    - Launches a fresh Chromium context with the user's BookingExperts cookies
      (decrypted server-side and shipped down with the job).
    - Navigates to the logs page so any anti-bot JS / Rails CSRF tokens get
      handled by a real browser context.
    - Parses the rendered DOM for the first batch of rows + extracts the
      first `next_token`.
    - Loops `GET /load_more_logs.js?next_token=…` via Playwright's
      `APIRequestContext` (no clicks, no DOM-update waits).
    - Converts each row's inline detail HTML into a normalized
      `ParsedLogMessage` and POSTs in batches of `BATCH_SIZE` to Laravel.
3. Reports completion / failure / session expiry.

## Why XHR instead of clicking "Laad meer"

Click-based pagination is fragile: it depends on visual layout, requires
waiting for DOM updates, and breaks any time BookingExperts adjusts the
button. The XHR endpoint is what the page itself calls under the hood — by
hitting it directly with the same cookie jar (via Playwright's
`context.request`), we get the same anti-bot tokens but skip every fragile
DOM step.

## Local development

```bash
cp .env.example .env
# Edit .env: point LARAVEL_BASE_URL at your local Laravel and set
# WORKER_API_TOKEN to match the value in laravel/.env

pnpm install
pnpm exec playwright install --with-deps chromium

pnpm run dev          # runs the worker loop (Ctrl-C to stop)
pnpm run scrape:once  # pulls one job and runs it; useful for debugging
```

## Selector/parser drift

When BookingExperts updates the logs page DOM, two things may need touching:

- `src/extractors.ts` — `extractRowsFromMain()` tries multiple row selectors
  in order and falls back to inferring rows from the per-row "Details"
  button. It logs which selector matched in the diagnostics object so you
  can see which one to lock in.
- `src/extractors.ts` — `parseLoadMoreResponse()` tries known jQuery
  patterns (`.append("…")`, `.html("…")`) plus a fallback that picks the
  largest double-quoted string in the response. If both fail, the worker
  logs the raw response body's first 240 chars so you can see the new
  shape.

The HTTP body parser (`src/httpParser.ts`) and the row-to-message converter
(`src/rowParser.ts`) are direct ports of the Electron version's parsing
logic and don't depend on selectors.
