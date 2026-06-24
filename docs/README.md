# BexLogs documentation

Self-hosted log explorer for the BookingExperts developer **Logboeken bekijken** page. Replaces a previous Electron desktop app with a web stack: Laravel + Vue, a Playwright worker, and a browser extension for session capture.

## Documentation index

| Document | Contents |
|----------|----------|
| [Overview](./overview.md) | Purpose, architecture diagram, tech stack, repo layout |
| [Local development](./local-development.md) | First-time setup, daily dev workflow, running tests |
| [Architecture](./architecture.md) | Data flow, services, containers, real-time layer |
| [Scraping pipeline](./scraping-pipeline.md) | Jobs lifecycle, windows, stop reasons, scraper internals |
| [Sessions & pairing](./sessions-and-pairing.md) | Bex sessions, extension flow, rotation, expiry |
| [Frontend](./frontend.md) | Inertia/Vue pages, UI patterns, shadcn-vue choices |
| [Backend](./backend.md) | Laravel structure, auth, API routes, scheduler |
| [Database](./database.md) | Tables, relationships, deduplication, indexes |
| [Deployment](./deployment.md) | Production bootstrap, CI/CD, Docker production stack |
| [Operations](./operations.md) | Monitoring, troubleshooting, runbooks, incident patterns |
| [Design decisions](./design-decisions.md) | Why we chose X over Y — operational and product choices |
| [REST API](./api.md) | Sanctum-gated read API (API Platform) |

## Quick links

- **Production server:** `/opt/bexlogs` on the self-hosted runner
- **Compose (prod):** `docker compose -f docker-compose.production.yml --env-file laravel/.env`
- **Compose (dev infra):** `docker compose up -d postgres redis`
- **Dev all-in-one:** `cd laravel && composer run dev`
- **Deploy:** push to `main` → CI runs tests → self-hosted deploy job

## Who this is for

- **Operators** running production: start with [Deployment](./deployment.md) and [Operations](./operations.md)
- **Developers** adding features: [Local development](./local-development.md), [Frontend](./frontend.md), [Backend](./backend.md)
- **On-call / debugging scrape issues:** [Scraping pipeline](./scraping-pipeline.md), [Sessions & pairing](./sessions-and-pairing.md), [Operations](./operations.md)
