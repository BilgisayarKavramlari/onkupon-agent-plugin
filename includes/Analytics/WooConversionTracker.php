<?php
namespace OnKupon\Agent\Analytics;

class WooConversionTracker {
    public function get(): array {
        if ( ! function_exists( 'wc_get_orders' ) ) {
            return [];
        }
        $metrics = [];
        foreach ( [ 1, 7, 30 ] as $days ) {
            $metrics[ $days . 'd' ] = $this->aggregate( $days );
        }
        return $metrics;
    }

    private function aggregate( int $days ): array {
        $created_after = '>=' . ( time() - ( $days * DAY_IN_SECONDS ) );
        $recognized_ids = wc_get_orders(
            [
                'status' => [ 'wc-processing', 'wc-completed', 'wc-on-hold' ],
                'date_created' => $created_after,
                'limit' => -1,
                'return' => 'ids',
            ]
        );
        $failed_ids = wc_get_orders(
            [
                'status' => [ 'wc-failed' ],
                'date_created' => $created_after,
                'limit' => -1,
                'return' => 'ids',
            ]
        );
        $gross_revenue = 0.0;
        $refunds = 0.0;
        $items = 0;
        foreach ( $recognized_ids as $order_id ) {
            $order = wc_get_order( $order_id );
            if ( ! $order ) {
                continue;
            }
            $gross_revenue += (float) $order->get_total();
            $refunds += (float) $order->get_total_refunded();
            $items += (int) $order->get_item_count();
        }
        return [
            'orders' => count( $recognized_ids ),
            'failed_orders' => count( $failed_ids ),
            'items' => $items,
            'gross_revenue' => round( $gross_revenue, 2 ),
            'refunded' => round( $refunds, 2 ),
            'net_revenue' => round( max( 0, $gross_revenue - $refunds ), 2 ),
            'currency' => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
            'days' => $days,
        ];
    }
}
