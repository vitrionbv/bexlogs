<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Continuously enqueue scrape jobs for any subscription whose interval has
// elapsed. The Node Playwright worker picks them up via /api/worker/jobs/next.
Schedule::command('scrape:enqueue')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->runInBackground();

// Reap running scrape_jobs whose worker died mid-flight (e.g. the
// container was OOM-killed or the stack got redeployed). One indexed
// query per tick — cheap, and it gets stuck rows back into the flow
// fast so scrape:enqueue can put fresh queued jobs in their place.
Schedule::command('scrape:reap-stale')
    ->everyMinute()
    ->withoutOverlapping();

// Re-validate every BookingExperts session hourly so the UI is honest about
// which ones are still usable (and so the cookies get a "warm" hit which
// extends the underlying Rails session).
Schedule::command('bex:refresh-sessions')
    ->hourly()
    ->withoutOverlapping(30)
    ->runInBackground();

// Push host CPU / memory / disk to the operator dashboard. 5s feels live
// without flooding the WS or the runqueue; the command itself takes
// ~200ms (it samples /proc/stat twice).
Schedule::command('server-stats:broadcast')
    ->everyFiveSeconds()
    ->withoutOverlapping();

// Per-subscription log_messages retention. Runs nightly at 03:00 UTC
// because it's a chunked DELETE that we don't want competing with
// the scrape worker's INSERT path during business hours. NULL
// `retention_days` means "keep forever" so the command is a fast
// no-op when no operator has opted into a window. The hard
// MAX_PER_SUB ceiling inside the command means an enormous backlog
// drains over multiple ticks rather than locking the table for
// hours on first enable.
Schedule::command('bex:apply-retention')
    ->dailyAt('03:00')
    ->withoutOverlapping(60)
    ->runInBackground();

// Per-subscription cold-tier archival to Hetzner Object Storage.
// Runs nightly at 04:00 UTC, an hour after the retention pass, so
// the two cron jobs never compete for log_messages locks. NULL
// `archive_after_days` means "never archive" so the command is a
// fast no-op for subs that haven't opted in. Each archived day
// becomes one .jsonl.gz object on the cold-logs disk; the
// log_archive_manifest table records which days exist so the
// ColdLogReader can fall through to S3 without a LIST call.
Schedule::command('bex:archive-cold')
    ->dailyAt('04:00')
    ->withoutOverlapping(60)
    ->runInBackground();
