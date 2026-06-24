# Overview

## What BexLogs does

BexLogs continuously scrapes BookingExperts (BEX) developer log pages for configured **application subscriptions**, stores deduplicated log rows in PostgreSQL, and exposes them through a web UI with search, export, alerts, and an AI-assisted log agent.

The scrape path mirrors what the old Electron app did, but runs as a server-side Playwright worker driven by a job queue instead of a desktop client.

## Architecture

```
┌──────────────────────┐    pairing token + cookies     ┌────────────────────────┐
│ Browser extension    │  ───────────────────────────▶ │ Laravel + PostgreSQL    │
│ (MV3, Chrome/Firefox)│                                │ (Inertia + Vue 3 UI)    │
│  user logs in to BEX │                                │ scheduler · queue · API │
└──────────────────────┘                                └────────────────────────┘
                                                                  │
                                                       jobs ▼     │ ▲ batches / heartbeats
                                                                  │
                                                        ┌─────────────────────┐
                                                        │ Playwright worker    │
                                                        │ (Node + TypeScript)  │
                                                        │ XHR load_more_logs.js│
                                                        └─────────────────────┘
```

### Components

| Component | Role |
|-----------|------|
| **Extension** | Captures BookingExperts cookies after the operator completes SSO/MFA; posts them to Laravel via a short-lived pairing token |
| **Laravel app** | UI, subscription management, job enqueue, worker API, deduplication, retention, cold archive, alerts |
| **Scheduler** | Runs `scrape:enqueue` every minute, session refresh, reaper, retention, baselines |
| **Queue worker** | Processes async Laravel jobs (exports, alerts, etc.) |
| **Scraper** | Polls `/api/worker/jobs/next`, runs Playwright per job, POSTs batches |
| **Reverb** | WebSocket server for live job/log updates in the browser |
| **PostgreSQL** | Primary data store |
| **Redis** | Cache, queue, session rotation counters, Reverb scaling |

## Repository layout

```
bexlogs/
├── laravel/          Laravel 13 app — UI, API, scheduler, business logic
├── scraper/          Node/Playwright worker
├── extension/        MV3 browser extension (cookie capture)
├── docker/           Production Docker images (PHP app, Caddy config)
├── deploy/           bootstrap.sh, backup.sh, ops runbook
├── docs/             This documentation
├── docker-compose.yml              Dev: Postgres + Redis (+ optional scraper profile)
└── docker-compose.production.yml   Prod: full stack behind Caddy
```

## Tech stack

| Layer | Choice |
|-------|--------|
| Backend | PHP 8.3+, **Laravel 13** |
| Frontend | **Vue 3**, **Inertia 3**, Vite, **Tailwind 4**, **shadcn-vue** (reka-ui) |
| Auth | Laravel Fortify (login, email verification, 2FA) — **no public registration** |
| Read API | API Platform + Laravel Sanctum personal access tokens |
| Real-time | Laravel Reverb + laravel-echo |
| Database | PostgreSQL 18 |
| Cache / queue | Redis 7 |
| Scraper | Node 22, TypeScript, Playwright, zod, undici |
| Extension | Plain MV3 JavaScript (no bundler) |
| Production TLS | Caddy 2 with Cloudflare origin certificate |
| Testing | Pest 4 |

## Core concepts

### Subscription

A BookingExperts **application subscription** (string ID from BEX). Each subscription belongs to an application → organization → user. Subscriptions have scrape settings: interval, concurrency, retention, etc.

### Page (log feed)

In the UI, a **Page** is one log feed per subscription (`pages` table). The name "Page" is historical; operators browse logs per subscription.

### Scrape job

A unit of work: given a BEX session (cookies) and a time window, paginate the BEX log API and insert rows. Statuses: `queued` → `running` → `completed` | `failed`.

### Bex session

Encrypted BookingExperts cookies for a user + environment (`production` | `staging`). Without at least one **healthy** session, `scrape:enqueue` skips all subscriptions for that environment.

## Post-login home

Fortify `home` is **`/logs`** — operators land on the log browser after login.
