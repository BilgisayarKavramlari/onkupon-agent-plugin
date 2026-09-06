<?php
namespace OnKupon\Agent\Scheduler\Jobs;

use OnKupon\Agent\Affiliate\AffiliateProductImporter;
use OnKupon\Agent\Affiliate\AffiliateProductLifecycleManager;
use OnKupon\Agent\Affiliate\AffiliateChangeNotifier;
use OnKupon\Agent\Affiliate\PartnerStackClient;
use OnKupon\Agent\Logging\ActionTimelineRepository;
use OnKupon\Agent\Logging\Logger;
use OnKupon\Agent\Plugin;

class AffiliateSyncJob extends AbstractJob {
    protected function name(): string { return 'affiliate_sync'; }

    protected function run(): void {
        $settings = Plugin::settings();
        if ( empty( $settings['partnerstack_enabled'] ) ) {
            ( new ActionTimelineRepository() )->record( 'affiliate_sync', 'skipped', [ 'notes' => 'PartnerStack sync is disabled' ] );
            return;
        }
        $client = new PartnerStackClient();
        $result = $client->list_partnerships( absint( $settings['partnerstack_sync_limit'] ?? 50 ) );
        if ( empty( $result['ok'] ) ) {
            update_option( 'onkupon_partnerstack_last_sync', [ 'at' => current_time( 'mysql' ), 'ok' => false, 'error' => sanitize_text_field( (string) ( $result['error'] ?? '' ) ) ], false );
            return;
        }
        $programs = (array) ( $result['items'] ?? [] );
        $lifecycle = ( new AffiliateProductLifecycleManager() )->reconcile( $programs, ! empty( $result['complete'] ) );
        $summary = array_merge( ( new AffiliateProductImporter() )->import( $programs ), $lifecycle );
        $summary['snapshot_complete'] = ! empty( $result['complete'] );
        $summary['notification'] = ( new AffiliateChangeNotifier() )->notify( $summary );
        update_option( 'onkupon_partnerstack_last_sync', [ 'at' => current_time( 'mysql' ), 'ok' => true, 'summary' => $summary ], false );
        ( new Logger() )->log( 'info', 'affiliate', 'PartnerStack affiliate sync completed', $summary );
        ( new ActionTimelineRepository() )->record( 'affiliate_sync', 'completed', [ 'notes' => 'PartnerStack affiliate sync completed', 'metadata' => $summary ] );
    }
}
