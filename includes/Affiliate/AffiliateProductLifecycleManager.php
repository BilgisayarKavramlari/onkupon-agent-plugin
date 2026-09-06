<?php
namespace OnKupon\Agent\Affiliate;

use OnKupon\Agent\Logging\ActionTimelineRepository;
use OnKupon\Agent\Plugin;

class AffiliateProductLifecycleManager {
    public function reconcile( array $programs, bool $complete_snapshot ): array {
        $summary = [
            'deactivated' => 0,
            'reactivated' => 0,
            'missing_pending' => 0,
            'deactivated_product_ids' => [],
            'reactivated_product_ids' => [],
        ];
        $programs_by_key = [];
        foreach ( $programs as $program ) {
            if ( is_array( $program ) && ! empty( $program['key'] ) ) {
                $programs_by_key[ (string) $program['key'] ] = $program;
            }
        }

        $settings = Plugin::settings();
        $missing_threshold = max( 2, min( 10, absint( $settings['partnerstack_missing_threshold'] ?? 3 ) ) );
        foreach ( $this->managed_product_ids() as $product_id ) {
            $key = (string) get_post_meta( $product_id, '_onkupon_partnerstack_key', true );
            if ( '' === $key ) {
                continue;
            }
            $program = $programs_by_key[ $key ] ?? null;
            if ( is_array( $program ) ) {
                delete_post_meta( $product_id, '_onkupon_partnerstack_missing_count' );
                if ( empty( $program['active'] ) ) {
                    if ( $this->deactivate( $product_id, 'partnerstack_inactive' ) ) {
                        $summary['deactivated']++;
                        $summary['deactivated_product_ids'][] = $product_id;
                    }
                } elseif ( ! empty( $program['referral_url'] ) && $this->reactivate( $product_id ) ) {
                    $summary['reactivated']++;
                    $summary['reactivated_product_ids'][] = $product_id;
                }
                continue;
            }

            if ( ! $complete_snapshot || '0' === (string) get_post_meta( $product_id, '_onkupon_affiliate_active', true ) ) {
                continue;
            }
            $missing_count = 1 + absint( get_post_meta( $product_id, '_onkupon_partnerstack_missing_count', true ) );
            update_post_meta( $product_id, '_onkupon_partnerstack_missing_count', $missing_count );
            if ( $missing_count < $missing_threshold ) {
                $summary['missing_pending']++;
                continue;
            }
            if ( $this->deactivate( $product_id, 'missing_from_complete_snapshot' ) ) {
                $summary['deactivated']++;
                $summary['deactivated_product_ids'][] = $product_id;
            }
        }
        return $summary;
    }

    private function managed_product_ids(): array {
        return array_map(
            'absint',
            get_posts(
                [
                    'post_type' => 'product',
                    'post_status' => [ 'publish', 'draft', 'private', 'pending' ],
                    'posts_per_page' => -1,
                    'fields' => 'ids',
                    'meta_key' => '_onkupon_affiliate_provider',
                    'meta_value' => 'partnerstack',
                    'no_found_rows' => true,
                ]
            )
        );
    }

    private function deactivate( int $product_id, string $reason ): bool {
        $product = wc_get_product( $product_id );
        if ( ! $product || ! $product->is_type( 'external' ) ) {
            return false;
        }
        $was_active = '0' !== (string) get_post_meta( $product_id, '_onkupon_affiliate_active', true );
        if ( $was_active ) {
            update_post_meta( $product_id, '_onkupon_affiliate_status_before_deactivation', $product->get_status() );
            update_post_meta( $product_id, '_onkupon_affiliate_visibility_before_deactivation', $product->get_catalog_visibility() );
        }
        $product->set_status( 'draft' );
        $product->set_catalog_visibility( 'hidden' );
        $product->save();
        update_post_meta( $product_id, '_onkupon_affiliate_active', 0 );
        update_post_meta( $product_id, '_onkupon_affiliate_inactive_reason', sanitize_key( $reason ) );
        update_post_meta( $product_id, '_onkupon_affiliate_deactivated_at', current_time( 'mysql' ) );
        if ( $was_active ) {
            ( new ActionTimelineRepository() )->record(
                'affiliate_product_deactivated',
                'completed',
                [ 'object_type' => 'product', 'object_id' => $product_id, 'notes' => 'PartnerStack product hidden', 'metadata' => [ 'reason' => $reason ] ]
            );
        }
        return $was_active;
    }

    private function reactivate( int $product_id ): bool {
        if ( '0' !== (string) get_post_meta( $product_id, '_onkupon_affiliate_active', true ) ) {
            return false;
        }
        $product = wc_get_product( $product_id );
        if ( ! $product || ! $product->is_type( 'external' ) ) {
            return false;
        }
        $previous_status = sanitize_key( (string) get_post_meta( $product_id, '_onkupon_affiliate_status_before_deactivation', true ) );
        $previous_visibility = sanitize_key( (string) get_post_meta( $product_id, '_onkupon_affiliate_visibility_before_deactivation', true ) );
        $status = 'publish' === $previous_status && Plugin::can_publish() ? 'publish' : 'draft';
        $visibility = in_array( $previous_visibility, [ 'visible', 'catalog', 'search', 'hidden' ], true ) ? $previous_visibility : 'visible';
        $product->set_status( $status );
        $product->set_catalog_visibility( $visibility );
        $product->save();
        update_post_meta( $product_id, '_onkupon_affiliate_active', 1 );
        delete_post_meta( $product_id, '_onkupon_affiliate_inactive_reason' );
        delete_post_meta( $product_id, '_onkupon_affiliate_deactivated_at' );
        delete_post_meta( $product_id, '_onkupon_partnerstack_missing_count' );
        ( new ActionTimelineRepository() )->record(
            'affiliate_product_reactivated',
            'completed',
            [ 'object_type' => 'product', 'object_id' => $product_id, 'notes' => 'PartnerStack product restored' ]
        );
        return true;
    }
}
