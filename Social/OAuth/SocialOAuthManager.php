<?php
namespace OnKupon\Agent\Social\OAuth;

use OnKupon\Agent\Logging\Logger;
use OnKupon\Agent\Plugin;
use OnKupon\Agent\Security\SecretsManager;

class SocialOAuthManager {
    public function register_routes(): void {
        add_action( 'rest_api_init', function (): void {
            register_rest_route( 'onkupon-agent/v1', '/oauth/(?P<provider>linkedin|x)/callback', [
                'methods' => 'GET',
                'callback' => [ $this, 'callback' ],
                'permission_callback' => '__return_true',
            ] );
        } );
    }

    public function callback( \WP_REST_Request $request ): \WP_REST_Response {
        $provider = sanitize_key( (string) $request['provider'] );
        $state = sanitize_text_field( (string) $request->get_param( 'state' ) );
        $expected = get_transient( 'onkupon_agent_oauth_state_' . $provider );
        if ( ! $state || ! $expected || ! hash_equals( (string) $expected, $state ) ) {
            ( new Logger() )->log( 'warning', 'social_oauth', 'OAuth callback rejected due to invalid state', [ 'provider' => $provider ] );
            return new \WP_REST_Response( [ 'error' => 'invalid_state' ], 403 );
        }
        if ( $request->get_param( 'error' ) ) {
            ( new Logger() )->log( 'warning', 'social_oauth', 'OAuth authorization was denied or failed', [ 'provider' => $provider, 'error_code' => sanitize_key( (string) $request->get_param( 'error' ) ) ] );
            return new \WP_REST_Response( [ 'error' => 'authorization_denied' ], 400 );
        }
        $code = sanitize_text_field( (string) $request->get_param( 'code' ) );
        if ( '' === $code ) {
            return new \WP_REST_Response( [ 'error' => 'authorization_code_missing' ], 400 );
        }
        $token = 'linkedin' === $provider ? ( new LinkedInOAuthProvider() )->exchange_code( $code ) : ( new XOAuthProvider() )->exchange_code( $code );
        if ( empty( $token['access_token'] ) ) {
            ( new Logger() )->log( 'error', 'social_oauth', 'OAuth token exchange failed', [ 'provider' => $provider, 'error_code' => sanitize_key( (string) ( $token['error'] ?? 'unknown' ) ), 'http_status' => absint( $token['_http_status'] ?? 0 ) ] );
            return new \WP_REST_Response( [ 'error' => 'token_exchange_failed' ], 400 );
        }
        $secrets = new SecretsManager();
        if ( ! $secrets->set( strtoupper( $provider ) . '_TOKEN', sanitize_text_field( (string) $token['access_token'] ) ) ) {
            ( new Logger() )->log( 'error', 'social_oauth', 'OAuth token could not be stored in the encrypted secret store', [ 'provider' => $provider ] );
            return new \WP_REST_Response( [ 'error' => 'secure_storage_failed' ], 500 );
        }
        if ( ! empty( $token['refresh_token'] ) ) {
            $secrets->set( strtoupper( $provider ) . '_REFRESH_TOKEN', sanitize_text_field( (string) $token['refresh_token'] ) );
        }
        $settings = get_option( 'onkupon_agent_social_oauth', [] );
        $settings[ $provider ]['expires_at'] = time() + absint( $token['expires_in'] ?? 0 );
        if ( ! empty( $token['refresh_token_expires_in'] ) ) {
            $settings[ $provider ]['refresh_expires_at'] = time() + absint( $token['refresh_token_expires_in'] );
        }
        update_option( 'onkupon_agent_social_oauth', $settings, false );
        $agent_settings = Plugin::settings();
        $agent_settings[ 'social_' . $provider . '_enabled' ] = true;
        Plugin::save_settings( $agent_settings );
        delete_transient( 'onkupon_agent_oauth_state_' . $provider );
        delete_transient( 'onkupon_agent_oauth_verifier_' . $provider );
        ( new Logger() )->log( 'info', 'social_oauth', 'OAuth token stored', [ 'provider' => $provider, 'token' => '***masked***' ] );
        return new \WP_REST_Response( [ 'status' => 'connected', 'provider' => $provider ], 200 );
    }

    public static function masked_status( string $provider ): array {
        $token = ( new SecretsManager() )->get( strtoupper( sanitize_key( $provider ) ) . '_TOKEN' );
        return [ 'connected' => '' !== $token, 'token' => ( new SecretsManager() )->mask( $token ) ];
    }

    public static function access_token( string $provider ): string {
        $provider = sanitize_key( $provider );
        if ( ! in_array( $provider, [ 'linkedin', 'x' ], true ) ) {
            return '';
        }
        $secrets = new SecretsManager();
        $token = $secrets->get( strtoupper( $provider ) . '_TOKEN' );
        $settings = get_option( 'onkupon_agent_social_oauth', [] );
        $expires_at = absint( $settings[ $provider ]['expires_at'] ?? 0 );
        if ( '' === $token || 0 === $expires_at || $expires_at > time() + 10 * MINUTE_IN_SECONDS ) {
            return $token;
        }
        $refresh_token = $secrets->get( strtoupper( $provider ) . '_REFRESH_TOKEN' );
        if ( '' === $refresh_token ) {
            return $expires_at > time() ? $token : '';
        }
        $response = 'linkedin' === $provider
            ? ( new LinkedInOAuthProvider() )->refresh_token( $refresh_token )
            : ( new XOAuthProvider() )->refresh_token( $refresh_token );
        if ( empty( $response['access_token'] ) ) {
            ( new Logger() )->log( 'warning', 'social_oauth', 'OAuth token refresh failed', [ 'provider' => $provider, 'error_code' => sanitize_key( (string) ( $response['error'] ?? 'unknown' ) ), 'http_status' => absint( $response['_http_status'] ?? 0 ) ] );
            return $expires_at > time() ? $token : '';
        }
        if ( ! $secrets->set( strtoupper( $provider ) . '_TOKEN', sanitize_text_field( (string) $response['access_token'] ) ) ) {
            return $expires_at > time() ? $token : '';
        }
        if ( ! empty( $response['refresh_token'] ) ) {
            $secrets->set( strtoupper( $provider ) . '_REFRESH_TOKEN', sanitize_text_field( (string) $response['refresh_token'] ) );
        }
        $settings[ $provider ]['expires_at'] = time() + absint( $response['expires_in'] ?? 0 );
        if ( ! empty( $response['refresh_token_expires_in'] ) ) {
            $settings[ $provider ]['refresh_expires_at'] = time() + absint( $response['refresh_token_expires_in'] );
        }
        update_option( 'onkupon_agent_social_oauth', $settings, false );
        ( new Logger() )->log( 'info', 'social_oauth', 'OAuth access token refreshed', [ 'provider' => $provider ] );
        return (string) $response['access_token'];
    }

    public static function disconnect( string $provider ): void {
        $provider = sanitize_key( $provider );
        if ( ! in_array( $provider, [ 'linkedin', 'x' ], true ) ) {
            return;
        }
        $secrets = new SecretsManager();
        $secrets->delete( strtoupper( $provider ) . '_TOKEN' );
        $secrets->delete( strtoupper( $provider ) . '_REFRESH_TOKEN' );
        $settings = get_option( 'onkupon_agent_social_oauth', [] );
        unset( $settings[ $provider ]['access_token'], $settings[ $provider ]['refresh_token'], $settings[ $provider ]['expires_at'], $settings[ $provider ]['refresh_expires_at'] );
        update_option( 'onkupon_agent_social_oauth', $settings, false );
        $agent_settings = Plugin::settings();
        $agent_settings[ 'social_' . $provider . '_enabled' ] = false;
        Plugin::save_settings( $agent_settings );
    }
}
