<?php
namespace OnKupon\Agent\Social;

use OnKupon\Agent\Logging\Logger;
use OnKupon\Agent\Plugin;
use OnKupon\Agent\Social\OAuth\SocialOAuthManager;

class XProvider implements SocialProviderInterface {
    public function validateConnection(): bool {
        return '' !== SocialOAuthManager::access_token( 'x' );
    }
    public function publish( SocialPost $post ): array {
        if ( ! $this->validateConnection() ) {
            return [ 'status' => 'failed', 'remote_id' => '', 'url' => '', 'error' => 'X OAuth token missing' ];
        }
        $settings = Plugin::settings();
        $message = $post->message;
        if ( ! empty( $settings['x_text_only_mode'] ) || empty( $settings['x_allow_url_posts'] ) ) {
            $message = preg_replace( '#https?://\S+#', '', $message );
        }
        $token = SocialOAuthManager::access_token( 'x' );
        $response = wp_remote_post( 'https://api.x.com/2/tweets', [ 'timeout' => 20, 'headers' => [ 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ], 'body' => wp_json_encode( [ 'text' => trim( wp_trim_words( $message, 45, '' ) ) ] ) ] );
        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( is_wp_error( $response ) || $code >= 300 ) {
            ( new Logger() )->log( 'error', 'social', 'X official API post failed', [ 'http_status' => $code, 'error' => is_wp_error( $response ) ? $response->get_error_message() : wp_json_encode( $body ) ] );
            return [ 'status' => 'failed', 'remote_id' => '', 'url' => '' ];
        }
        $id = sanitize_text_field( (string) ( $body['data']['id'] ?? '' ) );
        return [ 'status' => 'published', 'remote_id' => $id, 'url' => $id ? 'https://x.com/i/web/status/' . $id : '' ];
    }
    public function deleteRemotePost( string $remoteId ): bool { return false; }
    public function fetchMetrics( string $remoteId ): array { return []; }
    public function getPlatformName(): string { return 'x'; }
}
