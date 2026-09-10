<?php
namespace OnKupon\Agent\Affiliate;

use OnKupon\Agent\Logging\Logger;
use OnKupon\Agent\Plugin;

class AffiliateProductImporter {
    public function import( array $programs ): array {
        $summary = [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
            'created_product_ids' => [],
        ];
        if ( ! class_exists( 'WC_Product_External' ) ) {
            $summary['errors'] = count( $programs );
            return $summary;
        }
        foreach ( $programs as $program ) {
            if ( ! is_array( $program ) || empty( $program['active'] ) || empty( $program['key'] ) || empty( $program['name'] ) || empty( $program['referral_url'] ) ) {
                $summary['skipped']++;
                continue;
            }
            try {
                $result = $this->upsert( $program );
                $summary[ $result['created'] ? 'created' : 'updated' ]++;
                if ( $result['created'] ) {
                    $summary['created_product_ids'][] = $result['product_id'];
                }
            } catch ( \Throwable $e ) {
                $summary['errors']++;
                ( new Logger() )->log( 'warning', 'affiliate', 'Affiliate product import failed', [ 'partnership_key' => sanitize_text_field( (string) ( $program['key'] ?? '' ) ), 'error' => sanitize_text_field( $e->getMessage() ) ] );
            }
        }
        return $summary;
    }

    private function upsert( array $program ): array {
        $key = sanitize_text_field( (string) $program['key'] );
        $name = sanitize_text_field( (string) $program['name'] );
        $referral_url = esc_url_raw( (string) $program['referral_url'] );
        $existing_id = $this->find_product_id( $key, $name, $referral_url );
        $product = $existing_id ? wc_get_product( $existing_id ) : new \WC_Product_External();
        if ( ! $product || ( $existing_id && ! $product->is_type( 'external' ) ) ) {
            throw new \RuntimeException( 'Affiliate product type conflict' );
        }
        $created = ! $existing_id;
        $managed = $existing_id && '1' === (string) get_post_meta( $existing_id, '_onkupon_affiliate_managed', true );
        $preserve_editorial = $existing_id && '1' === (string) get_post_meta( $existing_id, AffiliateContentComposer::META_PRESERVE, true );
        $settings = Plugin::settings();

        // Ortaklık şartları (komisyon oranı, süre, ödeme modeli) kamuya açık
        // hiçbir alana yazılmaz; yalnızca özel meta olarak saklanır.
        $offer_terms = sanitize_textarea_field( (string) ( $program['offer_summary'] ?? '' ) );

        $description = AffiliateContentComposer::redact( sanitize_textarea_field( (string) ( $program['description'] ?? '' ) ) );
        $disclosure = AffiliateContentComposer::DISCLOSURE;
        $fallback_description = trim( implode( "\n\n", array_filter( [ $description, $disclosure ] ) ) );

        $composer = new AffiliateContentComposer();
        $needs_content = ! $preserve_editorial && ( $created || $managed ) && $composer->needs_content( $existing_id ? $product : null, $program );
        $composed = $needs_content ? $composer->compose( $program, $referral_url ) : [];

        if ( $composed ) {
            $product->set_name( sanitize_text_field( (string) $composed['title'] ) );
            $product->set_catalog_visibility( 'visible' );
            $product->set_short_description( wp_kses_post( (string) $composed['short'] ) );
            $product->set_description( wp_kses_post( (string) $composed['long'] ) );
        } elseif ( $needs_content ) {
            $product->set_name( $name );
            $product->set_catalog_visibility( 'visible' );
            $product->set_short_description( AffiliateContentComposer::redact( wp_trim_words( $description ?: $disclosure, 45, '' ) ) );
            $product->set_description( $fallback_description );
        } else {
            // Elle hazırlanmış içerik korunur, ama gizli şart taşıyorsa ayıklanır.
            $current_long = (string) $product->get_description();
            $current_short = (string) $product->get_short_description();
            if ( '' === trim( wp_strip_all_tags( $current_long ) ) ) {
                $product->set_description( $fallback_description );
            } elseif ( AffiliateContentComposer::contains_confidential( $current_long ) ) {
                $product->set_description( AffiliateContentComposer::redact( $current_long ) );
            }
            if ( '' === trim( wp_strip_all_tags( $current_short ) ) ) {
                $product->set_short_description( AffiliateContentComposer::redact( wp_trim_words( $description ?: $disclosure, 45, '' ) ) );
            } elseif ( AffiliateContentComposer::contains_confidential( $current_short ) ) {
                $product->set_short_description( AffiliateContentComposer::redact( $current_short ) );
            }
        }
        $product->set_product_url( $referral_url );
        $product->set_button_text( sanitize_text_field( (string) ( $settings['partnerstack_button_text'] ?? 'Ürünü incele' ) ) );
        if ( $created ) {
            $product->set_status( ! empty( $settings['partnerstack_auto_publish'] ) && Plugin::can_publish() ? 'publish' : 'draft' );
        } elseif ( ! empty( $settings['partnerstack_auto_publish'] ) && Plugin::can_publish() ) {
            $product->set_status( 'publish' );
        }
        $category_ids = $this->category_ids( $composed, $composer, $settings, $product, $name . ' ' . $description );
        if ( $category_ids ) {
            $product->set_category_ids( $category_ids );
        }
        $product_id = (int) $product->save();
        if ( ! $product_id ) {
            throw new \RuntimeException( 'Affiliate product save failed' );
        }
        update_post_meta( $product_id, '_onkupon_affiliate_provider', 'partnerstack' );
        update_post_meta( $product_id, '_onkupon_partnerstack_key', $key );
        update_post_meta( $product_id, '_onkupon_affiliate_managed', 1 );
        update_post_meta( $product_id, '_onkupon_affiliate_active', 1 );
        update_post_meta( $product_id, '_onkupon_affiliate_destination', $referral_url );
        update_post_meta( $product_id, '_onkupon_affiliate_source_hash', sanitize_text_field( (string) ( $program['source_hash'] ?? '' ) ) );
        update_post_meta( $product_id, '_onkupon_affiliate_last_synced_at', current_time( 'mysql' ) );
        if ( $existing_id && ! $managed ) {
            update_post_meta( $product_id, '_onkupon_affiliate_preserve_editorial', 1 );
        }
        if ( ! empty( $program['logo_url'] ) ) {
            update_post_meta( $product_id, '_onkupon_affiliate_logo_url', esc_url_raw( (string) $program['logo_url'] ) );
        }

        if ( '' !== $offer_terms ) {
            update_post_meta( $product_id, AffiliateContentComposer::META_OFFER_TERMS, $offer_terms );
        }

        if ( $composed ) {
            update_post_meta( $product_id, AffiliateContentComposer::META_CONTENT_HASH, sanitize_text_field( (string) ( $program['source_hash'] ?? '' ) ) );
            if ( ! empty( $composed['tags'] ) ) {
                wp_set_object_terms( $product_id, array_map( 'sanitize_text_field', (array) $composed['tags'] ), 'product_tag', false );
            }
            $this->apply_seo( $product_id, $composed );
        }
        $this->scrub_seo_meta( $product_id );

        $product->set_product_url( AffiliateClickTracker::tracking_url( $product_id ) );
        $product->save();
        ( new AffiliateImageResolver() )->ensure( $product_id, $program );
        return [ 'created' => $created, 'product_id' => $product_id ];
    }

    /**
     * Kategori seçimi: önce üretilen içerik, sonra deterministik anahtar kelime
     * eşlemesi, sonra ayarlardaki varsayılan. WooCommerce'in "uncategorized"
     * terimi (sitede "Kurslar" adıyla görünür) hiçbir koşulda atanmaz.
     *
     * @return int[]
     */
    private function category_ids( array $composed, AffiliateContentComposer $composer, array $settings, $product, string $context ): array {
        if ( ! empty( $composed['category_ids'] ) ) {
            return array_map( 'absint', (array) $composed['category_ids'] );
        }

        $existing = array_map( 'absint', (array) $product->get_category_ids() );
        $default_term = get_term_by( 'slug', 'uncategorized', 'product_cat' );
        $default_id = ( $default_term && ! is_wp_error( $default_term ) ) ? (int) $default_term->term_id : 0;
        $meaningful = array_values( array_diff( $existing, [ $default_id, 0 ] ) );
        if ( $meaningful ) {
            return $meaningful;
        }

        $mapped = $composer->keyword_category_ids( $context );
        if ( $mapped ) {
            return $mapped;
        }

        $configured = absint( $settings['partnerstack_default_category_id'] ?? 0 );
        if ( $configured && $configured !== $default_id && term_exists( $configured, 'product_cat' ) ) {
            return [ $configured ];
        }

        return [];
    }

    private function apply_seo( int $product_id, array $composed ): void {
        $seo = [
            'seo_title'        => (string) ( $composed['title'] ?? '' ),
            'meta_description' => (string) ( $composed['meta_description'] ?? '' ),
            'focus_keyphrase'  => (string) ( $composed['focus_keyphrase'] ?? '' ),
        ];

        $adapter = new \OnKupon\Agent\SEO\AIOSEOAdapter();
        if ( $adapter->is_available() ) {
            $adapter->apply( $product_id, $seo );
        }
    }

    /**
     * SEO alanları çoğu kurulumda ürün özetinden türetilir; yine de daha önce
     * kalıcı olarak yazılmış bir değer varsa ticari şartlardan arındırılır.
     */
    private function scrub_seo_meta( int $product_id ): void {
        $keys = [ '_aioseo_title', '_aioseo_description', '_aioseo_og_title', '_aioseo_og_description', '_aioseo_twitter_title', '_aioseo_twitter_description', '_aioseo_keywords' ];
        foreach ( $keys as $key ) {
            $value = (string) get_post_meta( $product_id, $key, true );
            if ( '' === $value || ! AffiliateContentComposer::contains_confidential( $value ) ) {
                continue;
            }
            $clean = AffiliateContentComposer::redact( $value );
            if ( '' === $clean ) {
                delete_post_meta( $product_id, $key );
            } else {
                update_post_meta( $product_id, $key, sanitize_text_field( $clean ) );
            }
        }
    }

    private function find_product_id( string $key, string $name, string $referral_url ): int {
        $ids = get_posts(
            [
                'post_type' => 'product',
                'post_status' => [ 'publish', 'draft', 'private', 'pending' ],
                'posts_per_page' => 1,
                'fields' => 'ids',
                'meta_key' => '_onkupon_partnerstack_key',
                'meta_value' => $key,
                'no_found_rows' => true,
            ]
        );
        $managed_id = absint( $ids[0] ?? 0 );
        if ( $managed_id ) {
            return $managed_id;
        }

        $candidate_ids = get_posts(
            [
                'post_type' => 'product',
                'post_status' => [ 'publish', 'draft', 'private', 'pending' ],
                'posts_per_page' => -1,
                'fields' => 'ids',
                'no_found_rows' => true,
            ]
        );
        $expected_host = strtolower( (string) wp_parse_url( $referral_url, PHP_URL_HOST ) );
        $expected_url = $this->normalized_url( $referral_url );
        $expected_brand = $this->normalized_brand( $name );
        $exact_destination = [];
        $exact_title = [];
        $brand_matches = [];
        foreach ( $candidate_ids as $candidate_id ) {
            $candidate_id = (int) $candidate_id;
            $product = wc_get_product( $candidate_id );
            $provider = sanitize_key( (string) get_post_meta( $candidate_id, '_onkupon_affiliate_provider', true ) );
            $candidate_key = sanitize_text_field( (string) get_post_meta( $candidate_id, '_onkupon_partnerstack_key', true ) );
            if ( ! $product || ! $product->is_type( 'external' ) || ! in_array( $provider, [ '', 'partnerstack' ], true ) || ( 'partnerstack' === $provider && $candidate_key && $candidate_key !== $key ) ) {
                continue;
            }
            $current_url = 'partnerstack' === $provider
                ? (string) get_post_meta( $candidate_id, '_onkupon_affiliate_destination', true )
                : (string) $product->get_product_url();
            $current_host = strtolower( (string) wp_parse_url( $current_url, PHP_URL_HOST ) );
            if ( ! $expected_host || $expected_host !== $current_host ) {
                continue;
            }
            if ( $expected_url && $expected_url === $this->normalized_url( $current_url ) ) {
                $exact_destination[] = $candidate_id;
            }
            if ( 0 === strcasecmp( trim( get_the_title( $candidate_id ) ), trim( $name ) ) ) {
                $exact_title[] = $candidate_id;
            }
            $candidate_brand = $this->normalized_brand( get_the_title( $candidate_id ) );
            if ( $this->brands_match( $expected_brand, $candidate_brand ) ) {
                $brand_matches[] = $candidate_id;
            }
        }
        foreach ( [ $exact_destination, $exact_title, $brand_matches ] as $matches ) {
            $matches = array_values( array_unique( array_map( 'absint', $matches ) ) );
            if ( 1 === count( $matches ) ) {
                return $matches[0];
            }
        }
        return 0;
    }

    private function normalized_url( string $url ): string {
        $parts = wp_parse_url( esc_url_raw( $url ) );
        if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
            return '';
        }
        $scheme = strtolower( (string) ( $parts['scheme'] ?? 'https' ) );
        $host = strtolower( (string) $parts['host'] );
        $path = untrailingslashit( (string) ( $parts['path'] ?? '' ) );
        $query = isset( $parts['query'] ) ? '?' . (string) $parts['query'] : '';
        return $scheme . '://' . $host . $path . $query;
    }

    private function normalized_brand( string $value ): string {
        $value = html_entity_decode( $value, ENT_QUOTES, 'UTF-8' );
        $value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value ) : strtolower( $value );
        $segments = preg_split( '/\s*(?:–|—|\||\()\s*/u', $value, 2 );
        $value = (string) ( $segments[0] ?? $value );
        $value = preg_replace( '/\s*,?\s*(?:inc|gmbh|ltd|llc|corp|corporation)\.?\s*$/i', '', $value ) ?: $value;
        $value = preg_replace( '/\.(?:ai|io|app)\s*$/i', '', $value ) ?: $value;
        return preg_replace( '/[^a-z0-9]+/', '', $value ) ?: '';
    }

    private function brands_match( string $expected, string $candidate ): bool {
        if ( strlen( $expected ) < 4 || strlen( $candidate ) < 4 ) {
            return false;
        }
        return $expected === $candidate
            || ( min( strlen( $expected ), strlen( $candidate ) ) >= 5 && ( str_starts_with( $expected, $candidate ) || str_starts_with( $candidate, $expected ) ) );
    }
}
