<?php

namespace App\Console\Commands;

use App\Services\BexSessionRefresher;
use Illuminate\Console\Command;

class BexRefreshSessions extends Command
{
    protected $signature = 'bex:refresh-sessions';

    protected $description = 'Validate every active BexSession against BookingExperts and update its status.';

    public function handle(BexSessionRefresher $refresher): int
    {
        $stats = $refresher->refreshAll();

        $this->info(sprintf(
            'checked=%d still_valid=%d newly_expired=%d',
            $stats['checked'],
            $stats['still_valid'],
            $stats['newly_expired'],
        ));

        return self::SUCCESS;
    }
}
