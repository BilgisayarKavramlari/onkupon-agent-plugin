<?php
namespace OnKupon\Agent\Social\OAuth;

use OnKupon\Agent\Security\SecretsManager;

class LinkedInOAuthProvider {
    public function configured(): bool {
        $settings = get_option( 'onkupon_agent_social_oauth', [] );
        return ! empty( $settings['linkedin']['client_id'] ) && '' !== ( new SecretsManager() )->get( 'LINKEDIN_CLIENT_SECRET' );
    }

    public function authorization_url(): string {
        if ( ! $this->configured() ) {
            return '';
        }
        $state = wp_generate_password( 24, false );
        set_transient( 'onkupon_agent_oauth_state_linkedin', $state, 10 * MINUTE_IN_SECONDS );
        $settings = get_option( 'onkupon_agent_social_oauth', [] );
        $mode = sanitize_key( (string) ( $settings['linkedin']['posting_mode'] ?? 'organization' ) );
        $scope = 'member' === $mode ? 'w_member_social' : 'w_organization_social';
        return add_query_arg( [ 'response_type' => 'code', 'client_id' => sanitize_text_field( (string) ( $settings['linkedin']['client_id'] ?? '' ) ), 'redirect_uri' => rest_url( 'onkupon-agent/v1/oauth/linkedin/callback' ), 'state' => $state, 'scope' => $scope ], 'https://www.linkedin.com/oauth/v2/authorization' );
    }

    public function exchange_code( string $code ): array {
        $settings = get_option( 'onkupon_agent_social_oauth', [] );
        $response = wp_remote_post( 'https://www.linkedin.com/oauth/v2/accessToken', [ 'timeout' => 20, 'body' => [ 'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => rest_url( 'onkupon-agent/v1/oauth/linkedin/callback' ), 'client_id' => (string) ( $settings['linkedin']['client_id'] ?? '' ), 'client_secret' => ( new SecretsManager() )->get( 'LINKEDIN_CLIENT_SECRET' ) ] ] );
        if ( is_wp_error( $response ) ) {
            return [ 'error' => 'transport_error' ];
        }
        $decoded = (array) json_decode( wp_remote_retrieve_body( $response ), true );
        $decoded['_http_status'] = wp_remote_retrieve_response_code( $response );
        return $decoded;
    }

    public function refresh_token( string $refresh_token ): array {
        $settings = get_option( 'onkupon_agent_social_oauth', [] );
        $response = wp_remote_post( 'https://www.linkedin.com/oauth/v2/accessToken', [ 'timeout' => 20, 'body' => [ 'grant_type' => 'refresh_token', 'refresh_token' => $refresh_token, 'client_id' => (string) ( $settings['linkedin']['client_id'] ?? '' ), 'client_secret' => ( new SecretsManager() )->get( 'LINKEDIN_CLIENT_SECRET' ) ] ] );
        if ( is_wp_error( $response ) ) {
            return [ 'error' => 'transport_error' ];
        }
        $decoded = (array) json_decode( wp_remote_retrieve_body( $response ), true );
        $decoded['_http_status'] = wp_remote_retrieve_response_code( $response );
        return $decoded;
    }
}
