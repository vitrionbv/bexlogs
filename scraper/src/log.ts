import { config } from './config.js';

type Level = 'debug' | 'info' | 'warn' | 'error';
const ORDER: Record<Level, number> = { debug: 10, info: 20, warn: 30, error: 40 };

function should(level: Level): boolean {
    return ORDER[level] >= ORDER[config.LOG_LEVEL];
}

function format(level: Level, msg: string, extra?: unknown): string {
    const ts = new Date().toISOString();
    const tag = level.toUpperCase().padEnd(5);
    const suffix = extra === undefined ? '' : ' ' + JSON.stringify(extra);
    return `${ts} ${tag} ${msg}${suffix}`;
}

export const log = {
    debug: (msg: string, extra?: unknown) =>
        should('debug') && console.log(format('debug', msg, extra)),
    info: (msg: string, extra?: unknown) =>
        should('info') && console.log(format('info', msg, extra)),
    warn: (msg: string, extra?: unknown) =>
        should('warn') && console.warn(format('warn', msg, extra)),
    error: (msg: string, extra?: unknown) =>
        should('error') && console.error(format('error', msg, extra)),
};
