# Frontend

## Stack

| Technology | Role |
|------------|------|
| **Vue 3** | Component framework (Composition API) |
| **Inertia.js** | Server-driven SPA — Laravel controllers return `Inertia::render()` |
| **Vite** | Build tool + HMR |
| **Tailwind CSS 4** | Utility styling |
| **shadcn-vue** (reka-ui) | Accessible component primitives |
| **Chart.js** | Dashboard charts |
| **TanStack Table** | Jobs table sorting/filtering |
| **vue-sonner** | Toast notifications |
| **Laravel Wayfinder** | Type-safe route helpers |

Entry: `laravel/resources/js/app.ts` → Inertia app mount.

Layouts: `AppSidebarLayout.vue` — persistent sidebar navigation.

---

## Main pages

| Route | Page | Purpose |
|-------|------|---------|
| `/logs` | `Logs/Index.vue`, `Show.vue` | Browse log feeds (Pages) and live event stream |
| `/dashboard` | `Dashboard.vue` | Summary metrics, charts, server vitals (admin) |
| `/manage` | `Manage/Index.vue` | Org/app/subscription tree, scrape settings, bulk ops |
| `/jobs` | `Jobs/Index.vue` | Scrape job history, stats dialog, retry/cancel |
| `/authenticate` | `Authenticate/Index.vue` | Pairing codes, session list, validate/relink |
| `/alerts` | Alerts pages | Alert channel configuration |
| `/admin/*` | Admin pages | User management (`is_admin` only) |
| `/settings/*` | Settings pages | Profile, 2FA, API tokens |

Post-login home: **`/logs`** (Fortify `home` config).

---

## Design choices

### Inertia over separate SPA API

We chose **Inertia** so Laravel controllers remain the source of truth for authorization and props. No parallel REST layer for the main UI — reduces auth duplication and keeps forms as standard Laravel requests with CSRF.

Trade-off: page transitions need a round-trip; mitigated by partial reloads and Echo for live data.

### shadcn-vue over a component library bundle

**shadcn-vue** copies components into `resources/js/components/ui/` — we own the code, customize freely, no version lock-in with a monolithic UI kit. Matches Tailwind 4 workflow.

### Server-driven props + Echo for live slices

Static page data comes from Inertia props. **Live** updates (job status, new log rows) use Reverb/Echo so we don't poll.

Pattern:

```typescript
// useRealtime.ts / useUserChannel
useUserChannel(userId, (event) => { /* refresh job row */ });
```

### Jobs stats dialog: two time ranges

The Jobs detail dialog shows:

- **Requested window** — from `job.params` (what we asked BEX to fetch).
- **Events seen** — from `job.stats.oldest_event_at` / `newest_event_at` (what pagination actually processed).

These intentionally differ; see [Scraping pipeline](./scraping-pipeline.md).

### Manage: custom scrape dialog

"Scrape now" has a basic path and an advanced **custom scrape** dialog (sliders icon) with overrides:

- Time window
- `max_pages`, `max_duration_minutes`
- `token_echo_max_attempts`
- `early_stop_duplicate_pages`, `early_stop_min_duplicates`

Backfill presets in the UI default duplicate-stop to disabled (999999) — operators must understand token-echo implications (documented in [Design decisions](./design-decisions.md)).

### Logs live feed scroll behavior

On `Logs/Show`:

- User on page 1 → new rows prepend with a subtle "+N new entries" pill.
- User scrolled down → queue a "Show N new" badge instead of yanking scroll position.

Channel: `private-page.{pageId}`.

### Dashboard charts

- 30-day line chart of total log volume.
- "Today by subscription" horizontal bar chart (one bar per sub, total rows today).

Removed per-subscription "top 10 patterns" list in favor of the bar chart for at-a-glance comparison.

### Dark mode / theming

Follows shadcn-vue CSS variables in `resources/css/app.css`. Sidebar + content use semantic tokens (`background`, `muted`, `destructive`, etc.).

### Toast flash pattern

Laravel `Inertia::flash('toast', …)` → `flashToast.ts` on page load. Used for scrape enqueue success/denial, bulk ops, etc.

### Cmd-K search

`GET /api/search` powers a command palette for quick navigation to subscriptions/pages.

---

## Real-time composables

| File | Purpose |
|------|---------|
| `echo.ts` | Echo/Reverb client init |
| `composables/useRealtime.ts` | Channel subscription helpers |
| `composables/useUserChannel.ts` | `private-user.{userId}` |

Vite env vars for Reverb (build-time):

```
VITE_REVERB_APP_KEY
VITE_REVERB_HOST
VITE_REVERB_PORT
VITE_REVERB_SCHEME
```

Production: `VITE_REVERB_HOST` = public `APP_DOMAIN`, port 443, scheme https.

---

## Key UI badges (Jobs)

Stop reason badges map to `stats.stop_reason`:

| Badge | Color semantics |
|-------|-----------------|
| `duplicate_detection` | Success — caught up |
| `caught_up` | Success — at log tip |
| `empty_window` | Warning — no data (may be session issue) |
| `worker_reaped` | Error — worker died or heartbeat stale |
| `session_expired` | Error — re-pair needed |
| `time_limit` / `pagination_limit` | Warning — hit cap |

---

## Building frontend assets

```bash
cd laravel
npm run dev      # HMR
npm run build    # production bundle → public/build/
```

Production Docker image runs `npm run build` at image build time with `VITE_REVERB_*` build args from `laravel/.env`.

---

## Testing frontend

```bash
npm run types:check    # vue-tsc
npm run lint:check     # ESLint
npm run format:check   # Prettier
```

Included in `composer run ci:check` and CI `laravel` job.
