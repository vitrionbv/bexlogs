import type { Environment } from './config.js';

/**
 * Why a scrape job stopped paginating. Reported back to Laravel via
 * /jobs/{id}/complete and persisted onto `scrape_jobs.stats.stop_reason`
 * so the Jobs UI can show why the worker exited (rather than asking the
 * operator to read scraper logs to tell a "natural end" from a runaway).
 *
 * Values must stay in lockstep with the enum-list in WorkerController and
 * the label map in `pages/Jobs/Index.vue`.
 */
export type StopReason =
    | 'natural_end'
    | 'duplicate_detection'
    | 'pagination_limit'
    | 'time_limit'
    | 'unparseable'
    | 'token_echo'
    | 'runaway_safety'
    | 'empty_window'
    | 'session_expired';

export interface BexCookie {
    name: string;
    value: string;
    domain?: string;
    path?: string;
    expirationDate?: number | null;
    httpOnly?: boolean;
    secure?: boolean;
    sameSite?: string;
}

export interface ScrapeJob {
    id: number;
    subscription: {
        id: string;
        name: string;
        environment: Environment;
        organization_id: string;
        application_id: string;
    };
    session: {
        id: number;
        environment: Environment;
        cookies: BexCookie[];
    };
    params: {
        start_time?: string;
        end_time?: string;
        max_pages?: number;
        max_duration_minutes?: number;
        early_stop_duplicate_pages?: number;
        early_stop_min_duplicates?: number;
    };
}

export interface ParsedLogMessage {
    timestamp: string;
    type: string;
    action: string;
    method: string;
    /**
     * Request path for API-call rows (e.g. `/v3/administrations/2555/todos/26205663`).
     * Webhook rows have no path; persisted as null.
     */
    path: string | null;
    status: string | null;
    parameters?: unknown;
    request?: unknown;
    response?: unknown;
}
