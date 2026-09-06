<?php
namespace OnKupon\Agent\Scheduler\Jobs;

use OnKupon\Agent\Analytics\MetricsRepository;
use OnKupon\Agent\Analytics\WooConversionTracker;

class MetricsCollectionJob extends AbstractJob {
    protected function name(): string { return 'metrics_collection'; }
    protected function run(): void {
        $repository = new MetricsRepository();
        $repository->record( 'system', 0, 'internal', 'heartbeat', 1 );
        foreach ( ( new WooConversionTracker() )->get() as $window => $metrics ) {
            $metadata = [ 'window' => $window, 'currency' => $metrics['currency'] ?? '' ];
            foreach ( [ 'orders', 'failed_orders', 'items', 'gross_revenue', 'refunded', 'net_revenue' ] as $name ) {
                $repository->record( 'commerce', 0, 'woocommerce', $name . '_' . $window, (float) ( $metrics[ $name ] ?? 0 ), $metadata );
            }
        }
    }
}
