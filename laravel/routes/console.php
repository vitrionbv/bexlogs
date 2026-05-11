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

// Recompute per-subscription drift baselines (p50/p95/p99 for duration
// and rows_inserted, plus same-hour rolling averages for the last 7d).
// Nightly is plenty — anomaly detection compares each new completed
// job against yesterday's baseline; recomputing more frequently would
// just smooth out the very signal we want to surface. 03:30 is after
// the daily rollover but well before any operator-side morning
// review, so the Manage badges are fresh by the time the day starts.
Schedule::command('bex:compute-baselines')
    ->dailyAt('03:30')
    ->withoutOverlapping(60)
    ->runInBackground();
