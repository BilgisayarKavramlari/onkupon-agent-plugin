<?php
namespace OnKupon\Agent\Scheduler\Jobs;

use OnKupon\Agent\Affiliate\MarketplaceDiscoveryService;

class MarketplaceDiscoveryJob extends AbstractJob {
    protected function name(): string { return 'marketplace_discovery'; }

    protected function run(): void {
        $full = (bool) get_transient( 'onkupon_agent_marketplace_full_rescan' );
        if ( $full ) {
            delete_transient( 'onkupon_agent_marketplace_full_rescan' );
        }
        ( new MarketplaceDiscoveryService() )->run( $full );
    }
}
