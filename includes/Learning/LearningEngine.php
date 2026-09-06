<?php
namespace OnKupon\Agent\Learning;

use OnKupon\Agent\Plugin;
use OnKupon\Agent\Woo\ProductMetrics;

class LearningEngine {
    public function update(): void {
        if ( empty( Plugin::settings()['learning_enabled'] ) || ! empty( Plugin::settings()['learning_frozen'] ) ) {
            return;
        }
        $repository = new StrategyWeightsRepository();
        $this->learn_product_priorities( $repository );
        $this->learn_order_hours( $repository );
    }

    private function learn_product_priorities( StrategyWeightsRepository $repository ): void {
        global $wpdb;
        $rows = $wpdb->get_results( "SELECT product_id,content_score,trend_score,revenue_score,metadata_json FROM {$wpdb->prefix}onkupon_agent_product_scores WHERE product_status='publish' ORDER BY revenue_score DESC,trend_score DESC LIMIT 200", ARRAY_A ) ?: [];
        foreach ( $rows as $row ) {
            $metadata = json_decode( (string) ( $row['metadata_json'] ?? '' ), true );
            $metadata = is_array( $metadata ) ? $metadata : [];
            $product_id = absint( $row['product_id'] ?? 0 );
            $clicks = ( new ProductMetrics() )->clicks( $product_id );
            $conversions = absint( $metadata['total_sales'] ?? 0 );
            $weight = 0.5 + ( (float) $row['content_score'] / 300 ) + ( (float) $row['trend_score'] / 300 ) + ( (float) $row['revenue_score'] / 200 );
            $repository->snapshot( 'product_priority', (string) $product_id, $weight, 0, $clicks, $conversions, (float) $row['revenue_score'], [ 'source' => 'woocommerce_product_scores' ] );
        }
    }

    private function learn_order_hours( StrategyWeightsRepository $repository ): void {
        if ( ! function_exists( 'wc_get_orders' ) ) {
            return;
        }
        $hours = [];
        $orders = wc_get_orders(
            [
                'status' => [ 'wc-processing', 'wc-completed', 'wc-on-hold' ],
                'limit' => 250,
                'return' => 'objects',
                'date_created' => '>=' . strtotime( '-90 days' ),
                'orderby' => 'date',
                'order' => 'DESC',
            ]
        );
        foreach ( $orders as $order ) {
            $created = $order->get_date_created();
            if ( ! $created ) {
                continue;
            }
            $hour = $created->date( 'G' );
            $hours[ $hour ] = $hours[ $hour ] ?? [ 'orders' => 0, 'revenue' => 0.0 ];
            $hours[ $hour ]['orders']++;
            $hours[ $hour ]['revenue'] += (float) $order->get_total();
        }
        foreach ( $hours as $hour => $metrics ) {
            $weight = 1 + log( 1 + (float) $metrics['revenue'] ) + ( (int) $metrics['orders'] * 0.2 );
            $repository->snapshot( 'publishing_hour', (string) $hour, $weight, 0, 0, (int) $metrics['orders'], (float) $metrics['revenue'], [ 'window_days' => 90 ] );
        }
    }
}
