// Send a one-off test event to Sentry. Requires SENTRY_DSN in the environment.
//
// Usage:
//   SENTRY_DSN=https://… npm run sentry:verify
import '../src/instrument.js';
import * as Sentry from '@sentry/node';

if (!process.env.SENTRY_DSN) {
    console.error('SENTRY_DSN is not set — skipping verify');
    process.exit(1);
}

Sentry.captureException(new Error('bexlogs scraper Sentry verify'));
await Sentry.close(2000);
console.log('Sent verify event — check your Sentry project');
