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
