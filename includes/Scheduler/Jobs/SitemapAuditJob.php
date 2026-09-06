<?php
namespace OnKupon\Agent\Scheduler\Jobs;

use OnKupon\Agent\SEO\SitemapAuditor;
use OnKupon\Agent\Scheduler\ActionSchedulerBridge;

class SitemapAuditJob extends AbstractJob {
    protected function name(): string { return 'sitemap_audit'; }

    protected function run(): void {
        $result = ( new SitemapAuditor() )->run();

        // Tarama bitmediyse kaldığı yerden devam etmek üzere kendini yeniden kuyruğa alır.
        if ( empty( $result['complete'] ) && $result['checked'] > 0 ) {
            ( new ActionSchedulerBridge() )->enqueue( 'onkupon_agent_sitemap_audit' );
        }
    }
}
