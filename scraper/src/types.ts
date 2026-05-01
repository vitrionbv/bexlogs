import type { Environment } from './config.js';

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
