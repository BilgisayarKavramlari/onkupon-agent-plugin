<?php
namespace OnKupon\Agent\Scheduler\Jobs;

use OnKupon\Agent\Affiliate\EarningsService;

class EarningsSyncJob extends AbstractJob {
    protected function name(): string { return 'earnings_sync'; }

    protected function run(): void {
        ( new EarningsService() )->refresh();
    }
}

