<?php
namespace OnKupon\Agent\Scheduler\Jobs;

use OnKupon\Agent\Affiliate\AffiliateEnrichmentService;

class AffiliateEnrichmentJob extends AbstractJob {
    protected function name(): string { return 'affiliate_enrichment'; }

    protected function run(): void {
        ( new AffiliateEnrichmentService() )->run();
    }
}

