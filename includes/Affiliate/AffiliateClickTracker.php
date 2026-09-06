<?php
namespace OnKupon\Agent\Affiliate;

use OnKupon\Agent\Analytics\MetricsRepository;
use OnKupon\Agent\Growth\AutonomousConversionOptimizer;

class AffiliateClickTracker {
    public function register(): void {
        add_action( 'admin_post_onkupon_affiliate_click', [ $this, 'redirect' ] );
        add_action( 'admin_post_nopriv_onkupon_affiliate_click', [ $this, 'redirect' ] );
    }

    public static function tracking_url( int $product_id ): string {
        return add_query_arg(
            [ 'action' => 'onkupon_affiliate_click', 'product_id' => absint( $product_id ) ],
            admin_url( 'admin-post.php' )
        );
    }

    public function redirect(): void {
        $product_id = absint( wp_unslash( $_GET['product_id'] ?? 0 ) );
        $destination = esc_url_raw( (string) get_post_meta( $product_id, '_onkupon_affiliate_destination', true ) );
        $provider = sanitize_key( (string) get_post_meta( $product_id, '_onkupon_affiliate_provider', true ) );
        if ( ! $product_id || ! $provider || 'product' !== get_post_type( $product_id ) || 'https' !== wp_parse_url( $destination, PHP_URL_SCHEME ) ) {
            wp_safe_redirect( home_url( '/' ) );
            exit;
        }
        $attribution = AutonomousConversionOptimizer::consume_attribution( $product_id );
        $metadata = array_merge( [ 'provider' => $provider ], $attribution );
        $metrics = new MetricsRepository();
        $metrics->record( 'affiliate_product', $product_id, $provider, 'outbound_click', 1, $metadata );
        if ( 'autonomous_cro' === ( $attribution['campaign'] ?? '' ) ) {
            $metrics->record( 'affiliate_product', $product_id, 'internal_recommendation', 'cro_click', 1, $metadata );
        }
        wp_redirect( $destination, 302, 'OnKupon Affiliate Redirect' );
        exit;
    }
}
