<?php
namespace OnKupon\Agent\Admin;

use OnKupon\Agent\Affiliate\PartnerStackClient;
use OnKupon\Agent\Plugin;

class AffiliateProgramsPage extends BasePage {
    public function render(): void {
        $settings = Plugin::settings();
        $query = new \WP_Query(
            [
                'post_type' => 'product',
                'post_status' => [ 'publish', 'draft', 'private', 'pending' ],
                'posts_per_page' => -1,
                'meta_key' => '_onkupon_affiliate_provider',
                'meta_value' => 'partnerstack',
                'orderby' => 'modified',
                'order' => 'DESC',
            ]
        );
        $rows = [];
        foreach ( $query->posts as $post ) {
            $destination = esc_url( (string) get_post_meta( $post->ID, '_onkupon_affiliate_destination', true ) );
            $rows[] = [
                '<a href="' . esc_url( get_edit_post_link( $post->ID ) ) . '">' . esc_html( get_the_title( $post->ID ) ) . '</a>',
                esc_html( get_post_status( $post->ID ) ),
                '0' === (string) get_post_meta( $post->ID, '_onkupon_affiliate_active', true ) ? 'inactive / hidden' : 'active',
                '<a href="' . $destination . '" rel="noreferrer noopener">' . esc_html( wp_parse_url( $destination, PHP_URL_HOST ) ?: 'link' ) . '</a>',
                '<a href="' . esc_url( 'publish' === get_post_status( $post->ID ) ? get_permalink( $post->ID ) : get_preview_post_link( $post->ID ) ) . '">Review</a>',
                esc_html( (string) get_post_meta( $post->ID, '_onkupon_affiliate_last_synced_at', true ) ),
            ];
        }
        $tracked_query = new \WP_Query(
            [
                'post_type' => 'product',
                'post_status' => [ 'publish', 'draft', 'private', 'pending' ],
                'posts_per_page' => -1,
                'meta_query' => [ [ 'key' => '_onkupon_affiliate_provider', 'compare' => 'EXISTS' ] ],
                'orderby' => 'modified',
                'order' => 'DESC',
            ]
        );
        $revenue_rows = [];
        foreach ( $tracked_query->posts as $post ) {
            $provider = sanitize_key( (string) get_post_meta( $post->ID, '_onkupon_affiliate_provider', true ) );
            if ( ! $provider || 'partnerstack' === $provider ) {
                continue;
            }
            $destination = esc_url( (string) get_post_meta( $post->ID, '_onkupon_affiliate_destination', true ) );
            $revenue_rows[] = [
                '<a href="' . esc_url( get_edit_post_link( $post->ID ) ) . '">' . esc_html( get_the_title( $post->ID ) ) . '</a>',
                esc_html( $provider ),
                esc_html( (string) get_post_meta( $post->ID, '_onkupon_affiliate_link_status', true ) ),
                esc_html( (string) get_post_meta( $post->ID, '_onkupon_affiliate_confidence', true ) ),
                esc_html( wp_parse_url( $destination, PHP_URL_HOST ) ?: 'link' ),
                '<a href="' . esc_url( 'publish' === get_post_status( $post->ID ) ? get_permalink( $post->ID ) : get_preview_post_link( $post->ID ) ) . '">Review</a>',
                esc_html( (string) get_post_meta( $post->ID, '_onkupon_affiliate_last_checked_at', true ) ),
            ];
        }
        $last_sync = (array) get_option( 'onkupon_partnerstack_last_sync', [] );
        $last_notification = (array) get_option( 'onkupon_partnerstack_last_notification', [] );
        $last_revenue_audit = (array) get_option( 'onkupon_revenue_link_audit_last', [] );
        $this->header( __( 'Affiliate Programs', 'onkupon-agent' ) );
        $this->card_grid(
            [
                'PartnerStack API' => ( new PartnerStackClient() )->configured() ? 'configured' : 'missing',
                'Sync enabled' => ! empty( $settings['partnerstack_enabled'] ) ? 'yes' : 'no',
                'Auto publish' => ! empty( $settings['partnerstack_auto_publish'] ) ? 'yes' : 'no (draft first)',
                'Change notifications' => ! empty( $settings['partnerstack_notifications_enabled'] ) ? 'email enabled' : 'disabled',
                'Imported products' => (int) $query->found_posts,
                'Other tracked revenue links' => count( $revenue_rows ),
                'Last sync' => sanitize_text_field( (string) ( $last_sync['at'] ?? 'never' ) ),
                'Last revenue-link audit' => sanitize_text_field( (string) ( $last_revenue_audit['at'] ?? 'never' ) ),
                'Last notification' => sanitize_text_field( (string) ( $last_notification['status'] ?? 'never' ) ),
            ]
        );
        echo '<p>' . esc_html__( 'PartnerStack products are idempotently imported as WooCommerce external products. New records remain drafts unless auto publish is enabled and the agent is running outside Safe Mode. Affiliate clicks are recorded as aggregate metrics without visitor profiling.', 'onkupon-agent' ) . '</p>';
        $this->table( [ 'Product', 'Status', 'Agreement', 'Destination host', 'Page', 'Last synced' ], $rows );
        echo '<h2>' . esc_html__( 'Other Revenue Links', 'onkupon-agent' ) . '</h2>';
        echo '<p>' . esc_html__( 'These links have a recognized affiliate tracking structure. Structural verification does not confirm an active contract or guarantee commission.', 'onkupon-agent' ) . '</p>';
        $this->table( [ 'Product', 'Provider', 'Link status', 'Confidence', 'Destination host', 'Page', 'Last checked' ], $revenue_rows );
        $this->footer();
    }
}
