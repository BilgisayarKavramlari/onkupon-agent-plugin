<?php
namespace OnKupon\Agent\Social\OAuth;

use OnKupon\Agent\Security\SecretsManager;

class XOAuthProvider {
    public function configured(): bool {
        $settings = get_option( 'onkupon_agent_social_oauth', [] );
        return ! empty( $settings['x']['client_id'] );
    }

    public function authorization_url(): string {
        if ( ! $this->configured() ) {
            return '';
        }
        $state = wp_generate_password( 24, false );
        set_transient( 'onkupon_agent_oauth_state_x', $state, 10 * MINUTE_IN_SECONDS );
        $settings = get_option( 'onkupon_agent_social_oauth', [] );
        $verifier = wp_generate_password( 64, false, false );
        $challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
        set_transient( 'onkupon_agent_oauth_verifier_x', $verifier, 10 * MINUTE_IN_SECONDS );
        return add_query_arg( [ 'response_type' => 'code', 'client_id' => sanitize_text_field( (string) ( $settings['x']['client_id'] ?? '' ) ), 'redirect_uri' => rest_url( 'onkupon-agent/v1/oauth/x/callback' ), 'state' => $state, 'scope' => 'tweet.read tweet.write users.read offline.access', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256' ], 'https://twitter.com/i/oauth2/authorize' );
    }

    public function exchange_code( string $code ): array {
        $settings = get_option( 'onkupon_agent_social_oauth', [] );
        $client_id = (string) ( $settings['x']['client_id'] ?? '' );
        $secret = ( new SecretsManager() )->get( 'X_CLIENT_SECRET' );
        $headers = [ 'Content-Type' => 'application/x-www-form-urlencoded' ];
        if ( '' !== $secret ) {
            $headers['Authorization'] = 'Basic ' . base64_encode( $client_id . ':' . $secret );
        }
        $response = wp_remote_post( 'https://api.x.com/2/oauth2/token', [ 'timeout' => 20, 'headers' => $headers, 'body' => [ 'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => rest_url( 'onkupon-agent/v1/oauth/x/callback' ), 'client_id' => $client_id, 'code_verifier' => (string) get_transient( 'onkupon_agent_oauth_verifier_x' ) ] ] );
        if ( is_wp_error( $response ) ) {
            return [ 'error' => 'transport_error' ];
        }
        $decoded = (array) json_decode( wp_remote_retrieve_body( $response ), true );
        $decoded['_http_status'] = wp_remote_retrieve_response_code( $response );
        return $decoded;
    }

    public function refresh_token( string $refresh_token ): array {
        $settings = get_option( 'onkupon_agent_social_oauth', [] );
        $client_id = (string) ( $settings['x']['client_id'] ?? '' );
        $secret = ( new SecretsManager() )->get( 'X_CLIENT_SECRET' );
        $headers = [ 'Content-Type' => 'application/x-www-form-urlencoded' ];
        if ( '' !== $secret ) {
            $headers['Authorization'] = 'Basic ' . base64_encode( $client_id . ':' . $secret );
        }
        $response = wp_remote_post( 'https://api.x.com/2/oauth2/token', [ 'timeout' => 20, 'headers' => $headers, 'body' => [ 'grant_type' => 'refresh_token', 'refresh_token' => $refresh_token, 'client_id' => $client_id ] ] );
        if ( is_wp_error( $response ) ) {
            return [ 'error' => 'transport_error' ];
        }
        $decoded = (array) json_decode( wp_remote_retrieve_body( $response ), true );
        $decoded['_http_status'] = wp_remote_retrieve_response_code( $response );
        return $decoded;
    }
}
