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

// B5: page operators about BookingExperts sessions whose auth cookies
// expire within 48h. Runs every 6h — the detector only inspects local
// state (no BE round-trips), so the cron itself is cheap; spacing is
// driven by SystemAlertEmitter's 24h dedupe window which collapses
// re-pages between two cron ticks anyway. Aligning to a 6h cadence
// gives operators 8 chances per cycle to be online when an alert
// fires (compared to once-a-day).
Schedule::command('bex:check-sessions')
    ->cron('0 */6 * * *')
    ->withoutOverlapping(30)
    ->runInBackground();

// B5: detect consecutive scrape failures (3 of the same stop_reason)
// and quiet auto-scraped subscriptions (no successful scrape in 24h).
// Tighter cadence (every 15 min) than the session checker because the
// failure-run signal is more time-sensitive — we want to page within a
// scheduling cycle of the third consecutive failure, not a full 6h
// later. SystemAlertEmitter's 1h dedupe window absorbs the per-tick
// re-fires once an alert has been delivered.
Schedule::command('bex:check-failure-runs')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->runInBackground();
