<?php
namespace OnKupon\Agent\Affiliate;

class AffiliateLinkClassifier {
    public function classify( string $url ): array {
        $parts = parse_url( trim( $url ) );
        if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) || empty( $parts['host'] ) ) {
            return $this->result( '', false, 'invalid', 'URL is missing or not HTTPS' );
        }
        $host = strtolower( preg_replace( '/^www\./', '', (string) $parts['host'] ) ?: '' );
        $path = strtolower( trim( (string) ( $parts['path'] ?? '' ), '/' ) );
        parse_str( (string) ( $parts['query'] ?? '' ), $query );
        $query = array_change_key_case( $query, CASE_LOWER );

        if ( $this->host_is( $host, 'pxf.io' ) || $this->host_is( $host, 'sjv.io' ) ) {
            return $this->result( 'impact', true, 'high', 'Impact tracking domain' );
        }
        if ( 'udemy.com' === $host && ! empty( $query['referralcode'] ) ) {
            return $this->result( 'udemy', true, 'high', 'Udemy referral code' );
        }
        if ( 'apify.com' === $host && ! empty( $query['fpr'] ) ) {
            return $this->result( 'apify', true, 'high', 'Apify partner referral parameter' );
        }
        if ( 'heygen.com' === $host && ( ! empty( $query['via'] ) || ! empty( $query['sid'] ) ) ) {
            return $this->result( 'heygen', true, 'medium', 'HeyGen referral parameters' );
        }
        if ( 'try.quillbot.com' === $host && str_contains( $path, 'onkupon' ) ) {
            return $this->result( 'quillbot', true, 'high', 'Dedicated QuillBot referral path' );
        }
        if ( 'refer.instantly.ai' === $host && '' !== $path ) {
            return $this->result( 'instantly', true, 'high', 'Instantly referral subdomain' );
        }
        foreach ( [ 'ref', 'referral', 'affiliate', 'aff', 'partner', 'via' ] as $key ) {
            if ( ! empty( $query[ $key ] ) ) {
                return $this->result( 'affiliate', true, 'medium', 'Generic affiliate query parameter' );
            }
        }
        return $this->result( '', false, 'unverified', 'No recognized revenue tracking structure' );
    }

    private function host_is( string $host, string $domain ): bool {
        return $host === $domain || str_ends_with( $host, '.' . $domain );
    }

    private function result( string $provider, bool $monetized, string $confidence, string $reason ): array {
        return [
            'provider' => $provider,
            'monetized' => $monetized,
            'confidence' => $confidence,
            'reason' => $reason,
        ];
    }
}
