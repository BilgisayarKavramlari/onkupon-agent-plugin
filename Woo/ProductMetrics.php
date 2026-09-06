<?php
namespace OnKupon\Agent\Woo;

class ProductMetrics {
    private static ?array $clicks_by_product = null;

    public function clicks( int $product_id ): int {
        global $wpdb;
        if ( null === self::$clicks_by_product ) {
            self::$clicks_by_product = [];
            $rows = $wpdb->get_results( "SELECT object_id,SUM(metric_value) AS clicks FROM {$wpdb->prefix}onkupon_agent_metrics WHERE object_type='affiliate_product' AND metric_name='outbound_click' GROUP BY object_id", ARRAY_A ) ?: [];
            foreach ( $rows as $row ) {
                self::$clicks_by_product[ absint( $row['object_id'] ?? 0 ) ] = (int) ( $row['clicks'] ?? 0 );
            }
        }
        return (int) ( self::$clicks_by_product[ $product_id ] ?? 0 );
    }
}
