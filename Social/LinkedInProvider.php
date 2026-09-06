<?php
namespace OnKupon\Agent\Social;

use OnKupon\Agent\Logging\Logger;
use OnKupon\Agent\Social\OAuth\SocialOAuthManager;

class LinkedInProvider implements SocialProviderInterface {
    public function validateConnection(): bool {
        return '' !== SocialOAuthManager::access_token( 'linkedin' );
    }
    public function publish( SocialPost $post ): array {
        if ( ! $this->validateConnection() ) {
            return [ 'status' => 'failed', 'remote_id' => '', 'url' => '', 'error' => 'LinkedIn OAuth token missing' ];
        }
        $settings = get_option( 'onkupon_agent_social_oauth', [] );
        $token = SocialOAuthManager::access_token( 'linkedin' );
        $author = sanitize_text_field( (string) ( $settings['linkedin']['author_urn'] ?? '' ) );
        if ( '' === $author ) {
            return [ 'status' => 'failed', 'remote_id' => '', 'url' => '', 'error' => 'LinkedIn author/member or organization URN missing' ];
        }
        $response = wp_remote_post( 'https://api.linkedin.com/rest/posts', [ 'timeout' => 20, 'headers' => [ 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json', 'X-Restli-Protocol-Version' => '2.0.0', 'Linkedin-Version' => '202604' ], 'body' => wp_json_encode( [ 'author' => $author, 'commentary' => $post->message, 'visibility' => 'PUBLIC', 'distribution' => [ 'feedDistribution' => 'MAIN_FEED', 'targetEntities' => [], 'thirdPartyDistributionChannels' => [] ], 'lifecycleState' => 'PUBLISHED', 'isReshareDisabledByAuthor' => false ] ) ] );
        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( is_wp_error( $response ) || $code >= 300 ) {
            ( new Logger() )->log( 'error', 'social', 'LinkedIn official API post failed', [ 'http_status' => $code, 'error' => is_wp_error( $response ) ? $response->get_error_message() : wp_json_encode( $body ) ] );
            return [ 'status' => 'failed', 'remote_id' => '', 'url' => '' ];
        }
        $remote_id = sanitize_text_field( (string) wp_remote_retrieve_header( $response, 'x-restli-id' ) );
        return [ 'status' => 'published', 'remote_id' => $remote_id, 'url' => '' ];
    }
    public function deleteRemotePost( string $remoteId ): bool { return false; }
    public function fetchMetrics( string $remoteId ): array { return []; }
    public function getPlatformName(): string { return 'linkedin'; }
}
