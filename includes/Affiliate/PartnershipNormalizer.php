<?php
namespace OnKupon\Agent\Affiliate;

class PartnershipNormalizer {
    public function normalize( array $item ): array {
        $company = is_array( $item['company'] ?? null ) ? $item['company'] : [];
        $program = is_array( $item['program'] ?? null ) ? $item['program'] : [];
        $key = $this->first_string( [ $item['key'] ?? '', $item['partnership_key'] ?? '', $item['id'] ?? '' ] );
        $name = $this->first_string(
            [
                $company['name'] ?? '',
                $program['name'] ?? '',
                $item['company_name'] ?? '',
                $item['name'] ?? '',
            ]
        );
        $description = $this->clean_text(
            $this->first_string(
                [
                    $company['description'] ?? '',
                    $program['description'] ?? '',
                    $item['description'] ?? '',
                ]
            ),
            2000
        );
        $status = strtolower( $this->first_string( [ $item['status'] ?? '', $item['state'] ?? '', $company['status'] ?? '' ] ) );
        $inactive = in_array( $status, [ 'archived', 'inactive', 'disabled', 'rejected', 'declined', 'canceled', 'cancelled' ], true );
        $active = empty( $item['archived'] ) && empty( $item['is_archived'] ) && ! $inactive;
        $url = $this->referral_url( $item, $company );
        $logo = $this->https_url( $this->first_string( [ $company['logo_url'] ?? '', $company['logo'] ?? '', $program['logo_url'] ?? '' ] ) );

        $normalized = [
            'key' => $this->key( $key ),
            'name' => $this->clean_text( $name, 180 ),
            'description' => $description,
            'referral_url' => $url,
            'logo_url' => $logo,
            'status' => $status ?: ( $active ? 'active' : 'inactive' ),
            'active' => $active,
            'offer_summary' => $this->offer_summary( (array) ( $item['offers'] ?? [] ) ),
        ];
        $normalized['source_hash'] = hash( 'sha256', json_encode( $normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ?: '' );
        return $normalized;
    }

    private function referral_url( array $item, array $company ): string {
        $candidates = [
            $item['referral_url'] ?? '',
            $item['referral_link'] ?? '',
            $item['link'] ?? '',
            is_array( $item['link'] ?? null ) ? ( $item['link']['url'] ?? '' ) : '',
            $company['referral_url'] ?? '',
            $company['referral_link'] ?? '',
            $company['link'] ?? '',
            is_array( $company['link'] ?? null ) ? ( $company['link']['url'] ?? '' ) : '',
        ];
        foreach ( (array) ( $item['offers'] ?? [] ) as $offer ) {
            if ( ! is_array( $offer ) ) {
                continue;
            }
            $candidates[] = $offer['referral_url'] ?? '';
            $candidates[] = $offer['referral_link'] ?? '';
            $candidates[] = $offer['link'] ?? '';
            $candidates[] = is_array( $offer['link'] ?? null ) ? ( $offer['link']['url'] ?? '' ) : '';
        }
        foreach ( $candidates as $candidate ) {
            $url = $this->https_url( is_string( $candidate ) ? $candidate : '' );
            if ( $url ) {
                return $url;
            }
        }
        return '';
    }

    private function offer_summary( array $offers ): string {
        $parts = [];
        foreach ( array_slice( $offers, 0, 5 ) as $offer ) {
            if ( ! is_array( $offer ) ) {
                continue;
            }
            $part = $this->first_string( [ $offer['description'] ?? '', $offer['name'] ?? '', $offer['title'] ?? '' ] );
            if ( $part ) {
                $parts[] = $this->clean_text( $part, 240 );
            }
        }
        return implode( ' | ', array_values( array_unique( array_filter( $parts ) ) ) );
    }

    private function first_string( array $values ): string {
        foreach ( $values as $value ) {
            if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
                return trim( (string) $value );
            }
        }
        return '';
    }

    private function clean_text( string $value, int $limit ): string {
        $value = trim( preg_replace( '/\s+/u', ' ', strip_tags( $value ) ) ?: '' );
        if ( function_exists( 'mb_substr' ) ) {
            return mb_substr( $value, 0, $limit );
        }
        return substr( $value, 0, $limit );
    }

    private function https_url( string $value ): string {
        $url = filter_var( trim( $value ), FILTER_VALIDATE_URL );
        if ( ! is_string( $url ) || 'https' !== strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) ) ) {
            return '';
        }
        return $url;
    }

    private function key( string $value ): string {
        return substr( preg_replace( '/[^A-Za-z0-9_-]/', '', $value ) ?: '', 0, 191 );
    }
}
