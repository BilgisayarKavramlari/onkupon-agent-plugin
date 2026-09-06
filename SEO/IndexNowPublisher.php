<?php
namespace OnKupon\Agent\SEO;

use OnKupon\Agent\Logging\ActionTimelineRepository;
use OnKupon\Agent\Plugin;
use OnKupon\Agent\Scheduler\JobRegistrar;

class IndexNowPublisher {
    private const HOOK = 'onkupon_agent_indexnow_submit';

    public function register(): void {
        if ( empty( Plugin::settings()['indexnow_enabled'] ) ) {
            return;
        }
        add_action( 'init', [ $this, 'ensure_key' ], 5 );
        add_action( 'transition_post_status', [ $this, 'queue_transition' ], 50, 3 );
        add_action( 'post_updated', [ $this, 'queue_updated' ], 50, 3 );
        add_action( self::HOOK, [ $this, 'submit' ] );
    }

    public function ensure_key(): string {
        $key = sanitize_text_field( (string) get_option( 'onkupon_indexnow_key', '' ) );
        if ( ! preg_match( '/^[A-Za-z0-9-]{8,128}$/', $key ) ) {
            $key = wp_generate_password( 32, false, false );
            update_option( 'onkupon_indexnow_key', $key, false );
        }
        $path = trailingslashit( ABSPATH ) . $key . '.txt';
        if ( ! is_readable( $path ) || trim( (string) @file_get_contents( $path ) ) !== $key ) {
            @file_put_contents( $path, $key, LOCK_EX );
        }
        return $key;
    }

    public function queue_transition( string $new_status, string $old_status, \WP_Post $post ): void {
        if ( ! in_array( $post->post_type, [ 'post', 'page', 'product' ], true ) || ( 'publish' !== $new_status && 'publish' !== $old_status ) ) {
            return;
        }
        $this->queue_url( get_permalink( $post ) );
    }

    public function queue_updated( int $post_id, \WP_Post $post_after, \WP_Post $post_before ): void {
        if ( 'publish' !== $post_after->post_status || ! in_array( $post_after->post_type, [ 'post', 'page', 'product' ], true ) || wp_is_post_revision( $post_id ) ) {
            return;
        }
        if ( $post_after->post_title === $post_before->post_title && $post_after->post_content === $post_before->post_content && $post_after->post_excerpt === $post_before->post_excerpt ) {
            return;
        }
        $this->queue_url( get_permalink( $post_after ) );
    }

    public function queue_url( string $url ): void {
        $url = esc_url_raw( $url );
        if ( ! $url || wp_parse_url( $url, PHP_URL_HOST ) !== wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ) {
            return;
        }
        $queue = (array) get_option( 'onkupon_indexnow_queue', [] );
        $queue[ $url ] = time();
        if ( count( $queue ) > 100 ) {
            asort( $queue );
            $queue = array_slice( $queue, -100, null, true );
        }
        update_option( 'onkupon_indexnow_queue', $queue, false );
        $this->schedule( 2 * MINUTE_IN_SECONDS );
    }

    public function submit(): array {
        $queue = (array) get_option( 'onkupon_indexnow_queue', [] );
        $urls = array_slice( array_keys( $queue ), 0, 100 );
        if ( ! $urls ) {
            return [ 'status' => 'empty', 'submitted' => 0 ];
        }
        $key = $this->ensure_key();
        $payload = [
            'host'        => (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ),
            'key'         => $key,
            'keyLocation' => home_url( '/' . $key . '.txt' ),
            'urlList'     => $urls,
        ];
        $response = wp_remote_post(
            'https://api.indexnow.org/indexnow',
            [
                'timeout' => 20,
                'headers' => [ 'Content-Type' => 'application/json; charset=utf-8' ],
                'body'    => wp_json_encode( $payload ),
            ]
        );
        $code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
        $success = in_array( $code, [ 200, 202 ], true );
        if ( $success ) {
            $latest = (array) get_option( 'onkupon_indexnow_queue', [] );
            foreach ( $urls as $url ) {
                unset( $latest[ $url ] );
            }
            update_option( 'onkupon_indexnow_queue', $latest, false );
        } else {
            $this->schedule( HOUR_IN_SECONDS );
        }
        $report = [
            'status'       => $success ? 'success' : 'failed',
            'submitted'    => $success ? count( $urls ) : 0,
            'http_status'  => $code,
            'checked_at'   => current_time( 'mysql' ),
            'queue_remaining' => count( (array) get_option( 'onkupon_indexnow_queue', [] ) ),
        ];
        update_option( 'onkupon_indexnow_last', $report, false );
        ( new ActionTimelineRepository() )->record( 'indexnow_submission', $success ? 'completed' : 'failed', [ 'notes' => $success ? 'Changed URLs submitted to IndexNow' : 'IndexNow submission failed', 'metadata' => $report ] );
        return $report;
    }

    private function schedule( int $delay ): void {
        if ( function_exists( 'as_next_scheduled_action' ) && function_exists( 'as_schedule_single_action' ) ) {
            if ( ! as_next_scheduled_action( self::HOOK, [], JobRegistrar::GROUP ) ) {
                as_schedule_single_action( time() + $delay, self::HOOK, [], JobRegistrar::GROUP );
            }
            return;
        }
        if ( ! wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_single_event( time() + $delay, self::HOOK );
        }
    }
}
