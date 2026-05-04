import type { Environment } from './config.js';

/**
 * Why a scrape job stopped paginating. Reported back to Laravel via
 * /jobs/{id}/complete + /jobs/{id}/fail and persisted onto
 * `scrape_jobs.stats.stop_reason` so the Jobs UI can show why the worker
 * exited.
 *
 * Two-tier semantics — only `duplicate_detection` and `empty_window` are
 * "this is fine" green completions. `pagination_limit` and `time_limit`
 * are completions but represent operator-visible caps the operator may
 * want to revisit. Everything else is a hard failure: BookingExperts
 * gave us something we don't know how to handle (422 means we hit them
 * too hard, missing/echoed token means pagination broke, unparseable
 * means the response shape changed). We deliberately do NOT treat any
 * of those as a clean end — silently completing on them would mask
 * real upstream issues.
 *
 * Values must stay in lockstep with `WorkerController::STOP_REASONS`
 * and the `STOP_REASON_META` label map in `pages/Jobs/Index.vue`.
 */
export type StopReason =
    | 'duplicate_detection'
    | 'pagination_limit'
    | 'time_limit'
    | 'pagination_error'
    | 'token_missing'
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
