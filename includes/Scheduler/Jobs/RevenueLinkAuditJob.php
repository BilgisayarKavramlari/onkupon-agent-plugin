<?php
namespace OnKupon\Agent\Scheduler\Jobs;

use OnKupon\Agent\Affiliate\AffiliateRevenueLinkAudit;
use OnKupon\Agent\Logging\ActionTimelineRepository;
use OnKupon\Agent\Logging\Logger;
use OnKupon\Agent\Plugin;

class RevenueLinkAuditJob extends AbstractJob {
    protected function name(): string { return 'revenue_link_audit'; }

    protected function run(): void {
        if ( empty( Plugin::settings()['revenue_link_audit_enabled'] ) ) {
            ( new ActionTimelineRepository() )->record( 'revenue_link_audit', 'skipped', [ 'notes' => 'Revenue link audit is disabled' ] );
            return;
        }
        $summary = ( new AffiliateRevenueLinkAudit() )->run();
        update_option( 'onkupon_revenue_link_audit_last', [ 'at' => current_time( 'mysql' ), 'ok' => true, 'summary' => $summary ], false );
        ( new Logger() )->log( 'info', 'affiliate', 'Revenue link audit completed', $summary );
        ( new ActionTimelineRepository() )->record( 'revenue_link_audit', 'completed', [ 'notes' => 'Revenue link audit completed', 'metadata' => $summary ] );
    }
}
