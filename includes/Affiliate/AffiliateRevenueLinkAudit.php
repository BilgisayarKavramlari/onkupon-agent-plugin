<?php
namespace OnKupon\Agent\Affiliate;

use OnKupon\Agent\Logging\ActionTimelineRepository;

class AffiliateRevenueLinkAudit {
    public function run(): array {
        $summary = [
            'external_products' => 0,
            'partnerstack_products' => 0,
            'verified_revenue_links' => 0,
            'wrapped_for_local_tracking' => 0,
            'unverified_external_links' => 0,
            'needs_review' => 0,
            'providers' => [],
        ];
        $classifier = new AffiliateLinkClassifier();
        $timeline = new ActionTimelineRepository();
        foreach ( $this->published_product_ids() as $product_id ) {
            $product = wc_get_product( $product_id );
            if ( ! $product || ! $product->is_type( 'external' ) ) {
                continue;
            }
            $summary['external_products']++;
            $existing_provider = sanitize_key( (string) get_post_meta( $product_id, '_onkupon_affiliate_provider', true ) );
            if ( 'partnerstack' === $existing_provider ) {
                $summary['partnerstack_products']++;
                continue;
            }
            $destination = esc_url_raw( (string) get_post_meta( $product_id, '_onkupon_affiliate_destination', true ) );
            if ( ! $destination ) {
                $destination = esc_url_raw( (string) $product->get_product_url() );
            }
            $classification = $classifier->classify( $destination );
            if ( empty( $classification['monetized'] ) ) {
                $summary['unverified_external_links']++;
                if ( $existing_provider ) {
                    update_post_meta( $product_id, '_onkupon_affiliate_link_status', 'needs_review' );
                    update_post_meta( $product_id, '_onkupon_affiliate_last_checked_at', current_time( 'mysql' ) );
                    $summary['needs_review']++;
                    $timeline->record(
                        'affiliate_link_needs_review',
                        'failed',
                        [ 'object_type' => 'product', 'object_id' => $product_id, 'notes' => 'Affiliate link structure is no longer recognized' ]
                    );
                }
                continue;
            }

            $provider = sanitize_key( (string) $classification['provider'] );
            $was_wrapped = $this->is_local_tracking_url( (string) $product->get_product_url() );
            update_post_meta( $product_id, '_onkupon_affiliate_provider', $provider );
            update_post_meta( $product_id, '_onkupon_affiliate_managed', 1 );
            update_post_meta( $product_id, '_onkupon_affiliate_destination', $destination );
            update_post_meta( $product_id, '_onkupon_affiliate_link_status', 'tracking_structure_verified' );
            update_post_meta( $product_id, '_onkupon_affiliate_confidence', sanitize_key( (string) $classification['confidence'] ) );
            update_post_meta( $product_id, '_onkupon_affiliate_evidence_hash', hash( 'sha256', $destination ) );
            update_post_meta( $product_id, '_onkupon_affiliate_last_checked_at', current_time( 'mysql' ) );
            $product->set_product_url( AffiliateClickTracker::tracking_url( $product_id ) );
            $product->save();

            $summary['verified_revenue_links']++;
            $summary['providers'][ $provider ] = 1 + absint( $summary['providers'][ $provider ] ?? 0 );
            if ( ! $was_wrapped ) {
                $summary['wrapped_for_local_tracking']++;
                $timeline->record(
                    'affiliate_link_tracking_enabled',
                    'completed',
                    [ 'object_type' => 'product', 'object_id' => $product_id, 'notes' => 'Revenue link wrapped with local aggregate click tracking', 'metadata' => [ 'provider' => $provider, 'confidence' => $classification['confidence'] ] ]
                );
            }
        }
        ksort( $summary['providers'] );
        return $summary;
    }

    private function published_product_ids(): array {
        return array_map(
            'absint',
            get_posts(
                [
                    'post_type' => 'product',
                    'post_status' => 'publish',
                    'posts_per_page' => -1,
                    'fields' => 'ids',
                    'no_found_rows' => true,
                ]
            )
        );
    }

    private function is_local_tracking_url( string $url ): bool {
        if ( strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) !== strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ) ) {
            return false;
        }
        parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
        return 'onkupon_affiliate_click' === ( $query['action'] ?? '' ) && ! empty( $query['product_id'] );
    }
}
