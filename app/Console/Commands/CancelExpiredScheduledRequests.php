<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\RequestExpirationService;

class CancelExpiredScheduledRequests extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'requests:cancel-expired-scheduled';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Auto-cancel scheduled requests that have passed departure start_time by >= 24 hours without starting';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Scanning for scheduled requests expired by >= 24 hours...');
        $count = RequestExpirationService::checkAndCancelExpired();
        $this->info("Completed. Successfully auto-cancelled {$count} expired scheduled request(s).");
        return 0;
    }
}
