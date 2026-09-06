<?php
namespace OnKupon\Agent\Social;

use OnKupon\Agent\Logging\Logger;
use OnKupon\Agent\Logging\ActionTimelineRepository;
use OnKupon\Agent\Plugin;

class SocialQueueRepository {
    public function queue( SocialPost $post, ?string $scheduled_at = null, string $status = 'queued' ): int {
        global $wpdb;
        $status = in_array( $status, [ 'queued', 'awaiting_connection' ], true ) ? $status : 'queued';
        $wpdb->insert(
            $wpdb->prefix . 'onkupon_agent_social_queue',
            [
                'social_uuid'    => wp_generate_uuid4(),
                'post_id'        => absint( $post->post_id ),
                'platform'       => sanitize_key( $post->platform ),
                'status'         => $status,
                'scheduled_at'   => $scheduled_at ?: current_time( 'mysql' ),
                'message'        => sanitize_textarea_field( $post->message ),
                'media_ids_json' => wp_json_encode( array_map( 'absint', $post->media_ids ) ),
                'retry_count'    => 0,
                'metrics_json'   => wp_json_encode( [] ),
                'created_at'     => current_time( 'mysql' ),
                'updated_at'     => current_time( 'mysql' ),
            ]
        );
        $id = (int) $wpdb->insert_id;
        ( new ActionTimelineRepository() )->record( 'social_post_queued', $status, [ 'object_type' => 'social_queue', 'object_id' => $id, 'notes' => 'Social post queued', 'metadata' => [ 'platform' => $post->platform, 'article_id' => $post->post_id, 'utm_url' => get_permalink( $post->post_id ) ] ] );
        return $id;
    }

    public function publish_due(): void {
        global $wpdb;

        // Hiçbir platform etkin değilse iş sessizce biter. Aksi hâlde kapalı
        // kanallar için 15 dakikada bir log satırı üretilir ve gerçek sinyal boğulur.
        if ( ! $this->any_platform_enabled() ) {
            return;
        }

        $limit = $this->remaining_daily_limit();
        if ( $limit <= 0 ) {
            // Günlük limit bilgisi günde bir kez yazılır.
            if ( ! get_transient( 'onkupon_agent_social_limit_logged' ) ) {
                ( new Logger() )->log( 'info', 'social', 'Daily social publication limit reached' );
                set_transient( 'onkupon_agent_social_limit_logged', 1, DAY_IN_SECONDS );
            }
            return;
        }

        // Kuyrukta işlenecek bir şey yoksa da sessiz kal.
        $pending = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}onkupon_agent_social_queue WHERE status IN ('queued','awaiting_connection') AND scheduled_at <= NOW()" );
        if ( 0 === $pending ) {
            return;
        }
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}onkupon_agent_social_queue WHERE status IN ('queued','awaiting_connection') AND scheduled_at <= NOW() ORDER BY scheduled_at ASC, id ASC LIMIT %d",
                min( 20, $limit )
            )
        );
        foreach ( $rows as $row ) {
            $provider = $this->provider_for( (string) $row->platform );
            if ( ! $provider || ! $this->platform_enabled( (string) $row->platform ) || ! $provider->validateConnection() ) {
                $wpdb->update( $wpdb->prefix . 'onkupon_agent_social_queue', [ 'status' => 'awaiting_connection', 'last_error' => 'Provider is not enabled or connected', 'updated_at' => current_time( 'mysql' ) ], [ 'id' => (int) $row->id ] );
                continue;
            }
            $result = $provider->publish( new SocialPost( (string) $row->platform, (string) $row->message, (int) $row->post_id ) );
            $status = sanitize_key( (string) ( $result['status'] ?? 'failed' ) );
            $processed = in_array( $status, [ 'published', 'suggested' ], true );
            $wpdb->update(
                $wpdb->prefix . 'onkupon_agent_social_queue',
                [
                    'status' => $status,
                    'published_at' => $processed ? current_time( 'mysql' ) : null,
                    'remote_post_id' => sanitize_text_field( $result['remote_id'] ?? '' ),
                    'remote_url' => esc_url_raw( $result['url'] ?? '' ),
                    'retry_count' => $processed ? (int) $row->retry_count : (int) $row->retry_count + 1,
                    'last_error' => $processed ? '' : sanitize_text_field( (string) ( $result['error'] ?? 'Provider publish failed' ) ),
                    'updated_at' => current_time( 'mysql' ),
                ],
                [ 'id' => (int) $row->id ]
            );
            ( new Logger() )->log( $processed ? 'info' : 'warning', 'social', 'Social post processed', [ 'platform' => $row->platform, 'queue_id' => $row->id, 'status' => $status ] );
            ( new ActionTimelineRepository() )->record( $processed ? 'social_post_published' : 'social_post_failed', $status, [ 'object_type' => 'social_queue', 'object_id' => (int) $row->id, 'notes' => 'Social post processed', 'metadata' => [ 'platform' => $row->platform, 'remote_post_id' => $result['remote_id'] ?? '', 'remote_url' => $result['url'] ?? '' ] ] );
        }
    }

    /**
     * En az bir sosyal platform ayarlarda etkin mi?
     */
    private function any_platform_enabled(): bool {
        $settings = Plugin::settings();
        foreach ( [ 'social_linkedin_enabled', 'social_x_enabled', 'social_facebook_enabled', 'social_instagram_enabled' ] as $key ) {
            if ( ! empty( $settings[ $key ] ) ) {
                return true;
            }
        }
        return false;
    }

    private function remaining_daily_limit(): int {
        global $wpdb;
        $settings = Plugin::settings();
        $limits = array_filter(
            [
                absint( $settings['daily_social_limit'] ?? 0 ),
                absint( $settings['daily_social_post_limit'] ?? 0 ),
            ]
        );
        if ( ! $limits ) {
            return 0;
        }
        $limit = min( $limits );
        $today = current_time( 'Y-m-d' ) . ' 00:00:00';
        $published = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}onkupon_agent_social_queue WHERE status IN ('published','suggested') AND published_at >= %s",
                $today
            )
        );
        return max( 0, $limit - $published );
    }

    public function create_dry_run_for_latest_post(): int {
        global $wpdb;
        $post_id = (int) $wpdb->get_var( "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON p.ID=pm.post_id AND pm.meta_key='_onkupon_agent_generated' WHERE p.post_type='post' AND p.post_status='publish' ORDER BY p.ID DESC LIMIT 1" );
        if ( ! $post_id ) {
            ( new Logger() )->log( 'warning', 'social', 'Social dry run skipped because no published OnKupon Agent post exists' );
            return 0;
        }
        $first_id = 0;
        foreach ( [ 'linkedin', 'x', 'facebook', 'quora_suggestion' ] as $platform ) {
            $id = $this->queue( new SocialPost( $platform, '[' . $platform . ' dry run] ' . get_the_title( $post_id ) . ' ' . get_permalink( $post_id ), $post_id ) );
            $first_id = $first_id ?: $id;
            $wpdb->update( $wpdb->prefix . 'onkupon_agent_social_queue', [ 'status' => 'dry_run', 'last_error' => 'Dry run only; no external provider called.', 'updated_at' => current_time( 'mysql' ) ], [ 'id' => $id ] );
            ( new ActionTimelineRepository() )->record( 'social_dry_run', 'dry_run', [ 'object_type' => 'social_queue', 'object_id' => $id, 'notes' => 'Dry-run social queue item created without external posting', 'metadata' => [ 'platform' => $platform, 'post_id' => $post_id, 'post_url' => get_permalink( $post_id ) ] ] );
        }
        return $first_id;
    }

    private function provider_for( string $platform ): ?SocialProviderInterface {
        return match ( $platform ) {
            'linkedin' => new LinkedInProvider(),
            'x' => new XProvider(),
            'facebook' => new FacebookPageProvider(),
            'instagram' => new InstagramProvider(),
            'quora_suggestion' => new ManualQuoraSuggestionProvider(),
            default => null,
        };
    }

    private function platform_enabled( string $platform ): bool {
        if ( 'quora_suggestion' === $platform ) {
            return true;
        }
        $settings = Plugin::settings();
        return ! empty( $settings[ 'social_' . sanitize_key( $platform ) . '_enabled' ] );
    }
}
