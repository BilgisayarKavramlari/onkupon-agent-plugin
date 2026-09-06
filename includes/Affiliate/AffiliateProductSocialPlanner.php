<?php
namespace OnKupon\Agent\Affiliate;

use OnKupon\Agent\Logging\ActionTimelineRepository;
use OnKupon\Agent\Social\SocialPost;
use OnKupon\Agent\Social\SocialQueueRepository;

class AffiliateProductSocialPlanner {
    private const SEED_VERSION = '1';

    public function register(): void {
        add_action( 'transition_post_status', [ $this, 'plan_newly_published_product' ], 30, 3 );
        add_action( 'init', [ $this, 'seed_recent_products' ], 30 );
    }

    public function plan_newly_published_product( string $new_status, string $old_status, \WP_Post $post ): void {
        if ( 'publish' !== $new_status || 'publish' === $old_status || 'product' !== $post->post_type ) {
            return;
        }
        $this->plan( (int) $post->ID );
    }

    public function seed_recent_products(): void {
        if ( self::SEED_VERSION === (string) get_option( 'onkupon_affiliate_social_seed_version', '' ) ) {
            return;
        }

        global $wpdb;
        $product_ids = $wpdb->get_col(
            "SELECT DISTINCT p.ID
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} managed ON managed.post_id=p.ID
                AND managed.meta_key='_onkupon_affiliate_managed' AND managed.meta_value='1'
             INNER JOIN {$wpdb->postmeta} provider ON provider.post_id=p.ID
                AND provider.meta_key='_onkupon_affiliate_provider' AND provider.meta_value='partnerstack'
             WHERE p.post_type='product' AND p.post_status='publish'
             ORDER BY p.post_date_gmt DESC
             LIMIT 5"
        );

        foreach ( array_map( 'absint', $product_ids ) as $product_id ) {
            $this->plan( $product_id );
        }
        update_option( 'onkupon_affiliate_social_seed_version', self::SEED_VERSION, false );
    }

    public function plan( int $product_id ): array {
        if ( ! $this->is_managed_partnerstack_product( $product_id ) ) {
            return [];
        }

        $title = sanitize_text_field( get_the_title( $product_id ) );
        $excerpt = sanitize_text_field( wp_strip_all_tags( (string) get_post_field( 'post_excerpt', $product_id ) ) );
        $excerpt = wp_html_excerpt( $excerpt, 320, '…' );
        $image_id = (int) get_post_thumbnail_id( $product_id );
        $queue = new SocialQueueRepository();
        $queued = [];

        foreach ( [ 'linkedin', 'x' ] as $platform ) {
            if ( $this->already_planned( $product_id, $platform ) ) {
                continue;
            }
            $url = add_query_arg(
                [
                    'utm_source'   => $platform,
                    'utm_medium'   => 'organic_social',
                    'utm_campaign' => 'partnerstack_product_launch',
                    'utm_content'  => 'product_' . $product_id,
                ],
                get_permalink( $product_id )
            );
            $message = $this->message( $platform, $title, $excerpt, $url );
            $id = $queue->queue(
                new SocialPost( $platform, $message, $product_id, $image_id ? [ $image_id ] : [] ),
                current_time( 'mysql' ),
                'awaiting_connection'
            );
            if ( $id ) {
                $queued[] = $platform;
            }
        }

        if ( $queued ) {
            update_post_meta( $product_id, '_onkupon_affiliate_social_planned_at', current_time( 'mysql' ) );
            update_post_meta( $product_id, '_onkupon_affiliate_social_platforms', wp_json_encode( $queued ) );
            ( new ActionTimelineRepository() )->record(
                'affiliate_social_plan',
                'awaiting_connection',
                [
                    'object_type' => 'product',
                    'object_id'   => $product_id,
                    'notes'       => 'Affiliate product social posts prepared',
                    'metadata'    => [ 'platforms' => $queued, 'product_url' => get_permalink( $product_id ) ],
                ]
            );
        }
        return $queued;
    }

    private function is_managed_partnerstack_product( int $product_id ): bool {
        return 'product' === get_post_type( $product_id )
            && 'publish' === get_post_status( $product_id )
            && '1' === (string) get_post_meta( $product_id, '_onkupon_affiliate_managed', true )
            && 'partnerstack' === sanitize_key( (string) get_post_meta( $product_id, '_onkupon_affiliate_provider', true ) );
    }

    private function already_planned( int $product_id, string $platform ): bool {
        global $wpdb;
        return (bool) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}onkupon_agent_social_queue
                 WHERE post_id=%d AND platform=%s
                 AND status IN ('awaiting_connection','queued','published','suggested')
                 ORDER BY id DESC LIMIT 1",
                $product_id,
                $platform
            )
        );
    }

    private function message( string $platform, string $title, string $excerpt, string $url ): string {
        if ( 'x' === $platform ) {
            $copy = trim( $title . ': ' . wp_html_excerpt( $excerpt, 120, '…' ) );
            return trim( $copy . "\n" . $url . "\n#reklam #affiliate" );
        }
        return trim(
            "Yeni araç: {$title}\n\n{$excerpt}\n\nÖzellikleri ve güncel teklifi inceleyin:\n{$url}\n\nAffiliate bağlantı içerir. #reklam #işbirliği"
        );
    }
}
