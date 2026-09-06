<?php
namespace OnKupon\Agent\Affiliate;

use OnKupon\Agent\Logging\ActionTimelineRepository;
use OnKupon\Agent\Logging\Logger;
use OnKupon\Agent\Security\RateLimiter;
use OnKupon\Agent\Security\SecretsManager;

class PartnerStackClient {
    private const BASE_URL = 'https://api.partnerstack.com/api/v2';

    public function configured(): bool {
        return '' !== $this->api_key();
    }

    public function list_partnerships( int $limit = 50 ): array {
        if ( ! $this->configured() ) {
            return $this->failure( 'PartnerStack API key is not configured', 'configuration' );
        }
        if ( ! ( new RateLimiter() )->allow( 'partnerstack', 20, HOUR_IN_SECONDS ) ) {
            return $this->failure( 'PartnerStack request rate limit reached', 'rate_limit' );
        }

        $remaining = max( 1, min( 250, $limit ) );
        $cursor = '';
        $items = [];
        $complete = true;
        $normalizer = new PartnershipNormalizer();

        while ( $remaining > 0 ) {
            $page_size = min( 100, $remaining );
            $query = [ 'include_offers' => 'true', 'limit' => $page_size ];
            if ( $cursor ) {
                $query['starting_after'] = $cursor;
            }
            $response = wp_remote_get(
                add_query_arg( $query, self::BASE_URL . '/partnerships' ),
                [
                    'timeout' => 30,
                    'headers' => [
                        'Accept' => 'application/json',
                        'Authorization' => 'Bearer ' . $this->api_key(),
                        'User-Agent' => 'OnKupon-Agent/' . ONKUPON_AGENT_VERSION . '; ' . home_url( '/' ),
                    ],
                ]
            );
            if ( is_wp_error( $response ) ) {
                return $this->failure( 'PartnerStack transport error: ' . $response->get_error_message(), 'transport' );
            }
            $status_code = (int) wp_remote_retrieve_response_code( $response );
            $decoded = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( $status_code < 200 || $status_code >= 300 || ! is_array( $decoded ) ) {
                $message = sanitize_text_field( (string) ( $decoded['message'] ?? 'PartnerStack returned HTTP ' . $status_code ) );
                return $this->failure( $message, 'api', [ 'status_code' => $status_code ] );
            }
            $data = $decoded['data'] ?? [];
            $page_items = is_array( $data['items'] ?? null ) ? $data['items'] : ( is_array( $data ) && array_is_list( $data ) ? $data : [] );
            foreach ( $page_items as $item ) {
                if ( is_array( $item ) ) {
                    $items[] = $normalizer->normalize( $item );
                }
            }
            $remaining -= count( $page_items );
            $has_more = ! empty( $data['has_more'] );
            if ( $has_more && $remaining <= 0 ) {
                $complete = false;
                break;
            }
            $last = end( $page_items );
            $next_cursor = is_array( $last ) ? sanitize_text_field( (string) ( $last['key'] ?? $last['partnership_key'] ?? '' ) ) : '';
            if ( ! $has_more || ! $next_cursor || $next_cursor === $cursor || empty( $page_items ) ) {
                break;
            }
            $cursor = $next_cursor;
        }

        return [
            'ok' => true,
            'items' => array_slice( $items, 0, $limit ),
            'complete' => $complete,
            'error' => '',
        ];
    }

    /**
     * Marketplace'te listelenen aktif programlar (Partner API, salt okunur).
     *
     * @param int $limit    En fazla kaç kayıt.
     * @param int $since_ms Yalnızca bu epoch (ms) sonrası listelenenler; 0 = tümü.
     */
    public function list_marketplace_programs( int $limit = 100, int $since_ms = 0 ): array {
        if ( ! $this->configured() ) {
            return $this->failure( 'PartnerStack API key is not configured', 'configuration' );
        }
        if ( ! ( new RateLimiter() )->allow( 'partnerstack_marketplace', 12, HOUR_IN_SECONDS ) ) {
            return $this->failure( 'PartnerStack request rate limit reached', 'rate_limit' );
        }

        $remaining = max( 1, min( 250, $limit ) );
        $cursor = '';
        $items = [];
        $complete = true;

        while ( $remaining > 0 ) {
            $query = [ 'limit' => min( 100, $remaining ) ];
            if ( $since_ms > 0 ) {
                $query['min_created'] = $since_ms;
            }
            if ( $cursor ) {
                $query['starting_after'] = $cursor;
            }
            $response = wp_remote_get(
                add_query_arg( $query, self::BASE_URL . '/marketplace/programs' ),
                [
                    'timeout' => 30,
                    'headers' => [
                        'Accept' => 'application/json',
                        'Authorization' => 'Bearer ' . $this->api_key(),
                        'User-Agent' => 'OnKupon-Agent/' . ONKUPON_AGENT_VERSION . '; ' . home_url( '/' ),
                    ],
                ]
            );
            if ( is_wp_error( $response ) ) {
                return $this->failure( 'PartnerStack transport error: ' . $response->get_error_message(), 'transport' );
            }
            $status_code = (int) wp_remote_retrieve_response_code( $response );
            $decoded = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( $status_code < 200 || $status_code >= 300 || ! is_array( $decoded ) ) {
                $message = sanitize_text_field( (string) ( $decoded['message'] ?? 'PartnerStack returned HTTP ' . $status_code ) );
                return $this->failure( $message, 'api', [ 'status_code' => $status_code, 'endpoint' => 'marketplace-programs' ] );
            }
            $data = $decoded['data'] ?? [];
            $page_items = is_array( $data['items'] ?? null ) ? $data['items'] : ( is_array( $data ) && array_is_list( $data ) ? $data : [] );
            foreach ( $page_items as $item ) {
                if ( is_array( $item ) ) {
                    $items[] = $this->normalize_program( $item );
                }
            }
            $remaining -= count( $page_items );
            $has_more = ! empty( $data['has_more'] );
            if ( $has_more && $remaining <= 0 ) {
                $complete = false;
                break;
            }
            $last = end( $page_items );
            $next_cursor = is_array( $last ) ? sanitize_text_field( (string) ( $last['key'] ?? $last['company_key'] ?? '' ) ) : '';
            if ( ! $has_more || ! $next_cursor || $next_cursor === $cursor || empty( $page_items ) ) {
                break;
            }
            $cursor = $next_cursor;
        }

        return [ 'ok' => true, 'items' => array_slice( $items, 0, $limit ), 'complete' => $complete, 'error' => '' ];
    }

    /**
     * Marketplace kaydını sabit bir iç şemaya indirger. PartnerStack alan
     * adları sürümler arasında değişebildiği için birden çok aday denenir.
     */
    private function normalize_program( array $row ): array {
        $company = $this->pick( $row, [ 'company_name', 'name', 'company', 'title' ] );
        if ( is_array( $company ) ) {
            $company = (string) $this->pick( $company, [ 'name', 'title' ] );
        }

        return [
            'company_key' => sanitize_text_field( (string) $this->pick( $row, [ 'key', 'company_key', 'id', 'slug' ] ) ),
            'name'        => sanitize_text_field( (string) $company ),
            'category'    => sanitize_text_field( (string) $this->pick( $row, [ 'category', 'vertical', 'industry' ] ) ),
            'description' => wp_strip_all_tags( (string) $this->pick( $row, [ 'description', 'summary', 'about', 'tagline' ] ) ),
            'listing_url' => esc_url_raw( (string) $this->pick( $row, [ 'listing_url', 'url', 'marketplace_url', 'link' ] ) ),
            'website'     => esc_url_raw( (string) $this->pick( $row, [ 'website', 'company_url', 'domain' ] ) ),
            'logo_url'    => esc_url_raw( (string) $this->pick( $row, [ 'logo', 'logo_url', 'image', 'image_url' ] ) ),
            'commission'  => sanitize_text_field( (string) $this->pick( $row, [ 'commission', 'reward', 'payout', 'commission_description' ] ) ),
            'created_at'  => sanitize_text_field( (string) $this->pick( $row, [ 'created_at', 'listed_at' ] ) ),
        ];
    }

    private function pick( array $row, array $keys ) {
        foreach ( $keys as $key ) {
            if ( isset( $row[ $key ] ) && '' !== $row[ $key ] && null !== $row[ $key ] ) {
                return $row[ $key ];
            }
        }
        return '';
    }

    private function failure( string $message, string $code, array $metadata = [] ): array {
        $safe_message = sanitize_text_field( $message );
        $metadata = array_merge( [ 'code' => $code ], $metadata );
        ( new Logger() )->log( 'warning', 'affiliate', $safe_message, $metadata );
        ( new ActionTimelineRepository() )->record( 'partnerstack_' . $code, 'failed', [ 'notes' => $safe_message, 'metadata' => $metadata ] );
        return [ 'ok' => false, 'items' => [], 'error' => $safe_message ];
    }

    private function api_key(): string {
        return trim( ( new SecretsManager() )->get( 'PARTNERSTACK_API_KEY' ) );
    }
}
