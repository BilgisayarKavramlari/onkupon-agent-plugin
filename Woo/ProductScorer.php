<?php
namespace OnKupon\Agent\Woo;

class ProductScorer {
    public function score( $product ): array {
        $sales = (float) ( $product && method_exists( $product, 'get_total_sales' ) ? $product->get_total_sales() : 0 );
        $price = (float) ( $product && method_exists( $product, 'get_price' ) ? $product->get_price() : 0 );
        $clicks = $product ? ( new ProductMetrics() )->clicks( $product->get_id() ) : 0;
        return [
            'content_score' => min( 100, 45 + $sales ),
            'trend_score' => min( 100, 50 + ( sqrt( max( 0, $clicks ) ) * 5 ) ),
            'revenue_score' => min( 100, ( $sales * 2 ) + min( 25, $price / 10 ) + min( 25, $clicks / 2 ) ),
            'freshness_score' => 75,
            'affiliate_clicks' => $clicks,
        ];
    }

    public function scan_and_score(): int {
        global $wpdb;
        $count = 0;
        foreach ( ( new ProductRepository() )->active() as $product ) {
            $score = $this->score( $product );
            $existing = $wpdb->get_row( $wpdb->prepare( "SELECT last_content_at,last_social_at FROM {$wpdb->prefix}onkupon_agent_product_scores WHERE product_id=%d", $product->get_id() ) );
            $wpdb->replace(
                $wpdb->prefix . 'onkupon_agent_product_scores',
                [
                    'product_id' => $product->get_id(),
                    'product_name' => $product->get_name(),
                    'product_url' => get_permalink( $product->get_id() ),
                    'category_ids_json' => wp_json_encode( $product->get_category_ids() ),
                    'product_status' => $product->get_status(),
                    'content_score' => $score['content_score'],
                    'trend_score' => $score['trend_score'],
                    'revenue_score' => $score['revenue_score'],
                    'freshness_score' => $score['freshness_score'],
                    'last_content_at' => $existing ? $existing->last_content_at : null,
                    'last_social_at' => $existing ? $existing->last_social_at : null,
                    'updated_at' => current_time( 'mysql' ),
                    'metadata_json' => wp_json_encode( [ 'price' => $product->get_price(), 'total_sales' => $product->get_total_sales(), 'affiliate_clicks' => $score['affiliate_clicks'] ] ),
                ]
            );
            $count++;
        }
        return $count;
    }
}
