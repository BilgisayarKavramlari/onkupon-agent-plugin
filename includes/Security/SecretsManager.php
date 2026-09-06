<?php
namespace OnKupon\Agent\Security;

class SecretsManager {
    private const OPTION = 'onkupon_agent_secret_store';

    public function get( string $key ): string {
        $key = $this->normalize_key( $key );
        $constant = 'ONKUPON_AGENT_' . $key;
        if ( defined( $constant ) ) {
            return (string) constant( $constant );
        }
        $env = getenv( $constant );
        if ( $env ) {
            return (string) $env;
        }
        $store = get_option( self::OPTION, [] );
        if ( is_array( $store ) && ! empty( $store[ $key ] ) ) {
            return $this->decrypt( (string) $store[ $key ] );
        }
        $settings = \OnKupon\Agent\Plugin::settings();
        return (string) ( $settings[ strtolower( $key ) ] ?? '' );
    }

    public function set( string $key, string $value ): bool {
        $key = $this->normalize_key( $key );
        $value = trim( $value );
        if ( '' === $value ) {
            return false;
        }
        $encrypted = $this->encrypt( $value );
        if ( '' === $encrypted ) {
            return false;
        }
        $store = get_option( self::OPTION, [] );
        $store = is_array( $store ) ? $store : [];
        $store[ $key ] = $encrypted;
        if ( update_option( self::OPTION, $store, false ) ) {
            return true;
        }
        $saved = get_option( self::OPTION, [] );
        return is_array( $saved ) && $encrypted === (string) ( $saved[ $key ] ?? '' );
    }

    public function delete( string $key ): bool {
        $key = $this->normalize_key( $key );
        $store = get_option( self::OPTION, [] );
        if ( ! is_array( $store ) || ! array_key_exists( $key, $store ) ) {
            return true;
        }
        unset( $store[ $key ] );
        return update_option( self::OPTION, $store, false );
    }

    public function source( string $key ): string {
        $key = $this->normalize_key( $key );
        $constant = 'ONKUPON_AGENT_' . $key;
        if ( defined( $constant ) || false !== getenv( $constant ) ) {
            return 'server';
        }
        $store = get_option( self::OPTION, [] );
        if ( is_array( $store ) && ! empty( $store[ $key ] ) && '' !== $this->decrypt( (string) $store[ $key ] ) ) {
            return 'encrypted';
        }
        return '' !== $this->get( $key ) ? 'legacy' : 'missing';
    }

    public function migrate_legacy_social_oauth(): void {
        $oauth = get_option( 'onkupon_agent_social_oauth', [] );
        if ( ! is_array( $oauth ) ) {
            return;
        }
        $changed = false;
        foreach ( [ 'linkedin', 'x' ] as $provider ) {
            foreach ( [ 'access_token' => 'TOKEN', 'refresh_token' => 'REFRESH_TOKEN', 'client_secret' => 'CLIENT_SECRET' ] as $field => $suffix ) {
                $value = (string) ( $oauth[ $provider ][ $field ] ?? '' );
                if ( '' !== $value && $this->set( strtoupper( $provider ) . '_' . $suffix, $value ) ) {
                    unset( $oauth[ $provider ][ $field ] );
                    $changed = true;
                }
            }
        }
        if ( $changed ) {
            update_option( 'onkupon_agent_social_oauth', $oauth, false );
        }
    }

    public function mask( string $value ): string {
        return $value ? substr( $value, 0, 4 ) . '…' . substr( $value, -4 ) : '';
    }

    private function normalize_key( string $key ): string {
        return strtoupper( preg_replace( '/[^A-Za-z0-9_]/', '', $key ) ?? '' );
    }

    private function encrypt( string $value ): string {
        if ( ! function_exists( 'openssl_encrypt' ) ) {
            return '';
        }
        $cipher = 'aes-256-gcm';
        $iv_length = openssl_cipher_iv_length( $cipher );
        if ( false === $iv_length ) {
            return '';
        }
        try {
            $iv = random_bytes( $iv_length );
        } catch ( \Throwable $exception ) {
            return '';
        }
        $tag = '';
        $encrypted = openssl_encrypt( $value, $cipher, $this->encryption_key(), OPENSSL_RAW_DATA, $iv, $tag );
        if ( false === $encrypted || 16 !== strlen( $tag ) ) {
            return '';
        }
        return 'v1:' . base64_encode( $iv . $tag . $encrypted );
    }

    private function decrypt( string $payload ): string {
        if ( ! str_starts_with( $payload, 'v1:' ) || ! function_exists( 'openssl_decrypt' ) ) {
            return '';
        }
        $raw = base64_decode( substr( $payload, 3 ), true );
        $cipher = 'aes-256-gcm';
        $iv_length = openssl_cipher_iv_length( $cipher );
        if ( false === $raw || false === $iv_length || strlen( $raw ) <= $iv_length + 16 ) {
            return '';
        }
        $iv = substr( $raw, 0, $iv_length );
        $tag = substr( $raw, $iv_length, 16 );
        $encrypted = substr( $raw, $iv_length + 16 );
        $decrypted = openssl_decrypt( $encrypted, $cipher, $this->encryption_key(), OPENSSL_RAW_DATA, $iv, $tag );
        return false === $decrypted ? '' : $decrypted;
    }

    private function encryption_key(): string {
        return hash( 'sha256', wp_salt( 'auth' ), true );
    }
}
