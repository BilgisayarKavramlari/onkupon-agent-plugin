<?php
namespace OnKupon\Agent\Affiliate;

use OnKupon\Agent\Logging\Logger;

/**
 * Ortaklık ürünü için öne çıkan görseli belirler.
 *
 * Öncelik sırası, görselin bilgi değerine göre kurulmuştur:
 *   1. Hedef sitenin kendi paylaşım görseli (og:image / twitter:image)
 *   2. PartnerStack'in program varlıkları içinde verdiği logo
 *   3. Hedef sitenin ana sayfa ekran görüntüsü
 *   4. Ajanın ürettiği gradyan kart (son çare)
 *
 * Elle yüklenmiş bir görsel asla değiştirilmez; yalnızca ajanın kendi ürettiği
 * gradyan kart daha iyi bir aday bulunduğunda yerini bırakır.
 */
class AffiliateImageResolver {

    public const META_SOURCE  = '_onkupon_affiliate_image_source';
    public const META_CHECKED = '_onkupon_affiliate_image_checked_at';

    private const MIN_BYTES = 6000;
    private const MIN_SCREENSHOT_BYTES = 18000;
    private const MIN_WIDTH = 200;

    public function ensure( int $product_id, array $program ): int {
        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return 0;
        }

        $current = (int) $product->get_image_id();
        if ( $current && ! $this->is_generated_card( $current ) ) {
            return $current;
        }

        $destination = $this->final_url( (string) get_post_meta( $product_id, '_onkupon_affiliate_destination', true ) );
        $name = (string) $product->get_name();

        foreach ( $this->candidates( $destination, $program ) as $candidate ) {
            $attachment_id = $this->sideload( $candidate['url'], $product_id, $name, $candidate['source'] );
            if ( $attachment_id ) {
                $product->set_image_id( $attachment_id );
                $product->save();
                update_post_meta( $product_id, self::META_SOURCE, $candidate['source'] );
                update_post_meta( $product_id, self::META_CHECKED, current_time( 'mysql' ) );
                return $attachment_id;
            }
        }

        update_post_meta( $product_id, self::META_CHECKED, current_time( 'mysql' ) );
        if ( $current ) {
            return $current;
        }

        $fallback = ( new AffiliateFeaturedImageGenerator() )->ensure( $product_id );
        if ( $fallback ) {
            update_post_meta( $product_id, self::META_SOURCE, 'generated_card' );
        }
        return $fallback;
    }

    /**
     * @return array<int,array{url:string,source:string}>
     */
    private function candidates( string $destination, array $program ): array {
        $candidates = [];

        if ( '' !== $destination ) {
            $social = $this->social_image( $destination );
            if ( '' !== $social ) {
                $candidates[] = [ 'url' => $social, 'source' => 'og_image' ];
            }
        }

        $logo = esc_url_raw( (string) ( $program['logo_url'] ?? '' ) );
        if ( '' !== $logo ) {
            $candidates[] = [ 'url' => $logo, 'source' => 'partnerstack_logo' ];
        }

        if ( '' !== $destination ) {
            // Ekran görüntüsü servisine yalnızca markanın herkese açık ana sayfası
            // gönderilir; ortaklık kimliğimizi taşıyan yönlendirme adresi değil.
            $host = (string) wp_parse_url( $destination, PHP_URL_HOST );
            if ( '' !== $host ) {
                $candidates[] = [
                    'url'    => 'https://s.wordpress.com/mshots/v1/' . rawurlencode( 'https://' . $host . '/' ) . '?w=1200&h=630',
                    'source' => 'screenshot',
                ];
            }
        }

        return $candidates;
    }

    /**
     * Yönlendirmeleri izleyerek markanın gerçek adresine ulaşır.
     */
    private function final_url( string $url ): string {
        $url = esc_url_raw( $url );
        if ( '' === $url ) {
            return '';
        }

        $response = wp_remote_get(
            $url,
            [
                'timeout'     => 15,
                'redirection' => 6,
                'user-agent'  => $this->user_agent(),
            ]
        );
        if ( is_wp_error( $response ) ) {
            return '';
        }

        $final = '';
        $http = $response['http_response'] ?? null;
        if ( is_object( $http ) && method_exists( $http, 'get_response_object' ) ) {
            $object = $http->get_response_object();
            if ( is_object( $object ) && ! empty( $object->url ) ) {
                $final = (string) $object->url;
            }
        }

        return esc_url_raw( $final ?: $url );
    }

    /**
     * Hedef sayfadaki og:image veya twitter:image adresini okur.
     */
    private function social_image( string $url ): string {
        $response = wp_remote_get(
            $url,
            [
                'timeout'     => 15,
                'redirection' => 6,
                'user-agent'  => $this->user_agent(),
            ]
        );
        if ( is_wp_error( $response ) ) {
            return '';
        }
        $body = (string) wp_remote_retrieve_body( $response );
        if ( '' === $body ) {
            return '';
        }

        $patterns = [
            '#<meta[^>]+property=["\']og:image(?::secure_url)?["\'][^>]+content=["\']([^"\']+)["\']#i',
            '#<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\']#i',
            '#<meta[^>]+name=["\']twitter:image["\'][^>]+content=["\']([^"\']+)["\']#i',
        ];
        foreach ( $patterns as $pattern ) {
            if ( preg_match( $pattern, $body, $matches ) ) {
                $candidate = html_entity_decode( trim( $matches[1] ), ENT_QUOTES, 'UTF-8' );
                $absolute = $this->absolutize( $candidate, $url );
                if ( '' !== $absolute ) {
                    return $absolute;
                }
            }
        }
        return '';
    }

    private function absolutize( string $candidate, string $base ): string {
        if ( '' === $candidate ) {
            return '';
        }
        if ( 0 === strpos( $candidate, '//' ) ) {
            $candidate = 'https:' . $candidate;
        } elseif ( 0 === strpos( $candidate, '/' ) ) {
            $parts = wp_parse_url( $base );
            if ( empty( $parts['host'] ) ) {
                return '';
            }
            $candidate = ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host'] . $candidate;
        }
        return esc_url_raw( $candidate );
    }

    private function sideload( string $url, int $product_id, string $name, string $source ): int {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $temp = download_url( $url, 25 );
        if ( is_wp_error( $temp ) ) {
            return 0;
        }

        $minimum = 'screenshot' === $source ? self::MIN_SCREENSHOT_BYTES : self::MIN_BYTES;
        $size = (int) @filesize( $temp );
        $info = @getimagesize( $temp );

        if ( $size < $minimum || ! is_array( $info ) || (int) ( $info[0] ?? 0 ) < self::MIN_WIDTH ) {
            // Ekran görüntüsü servisi ilk istekte üretimi başlatıp yer tutucu
            // döndürür; küçük dosya "henüz hazır değil" demektir, sonraki
            // senkronda yeniden denenir.
            @unlink( $temp );
            return 0;
        }

        $extension = image_type_to_extension( (int) $info[2], false );
        if ( ! in_array( $extension, [ 'jpeg', 'jpg', 'png', 'webp', 'gif' ], true ) ) {
            @unlink( $temp );
            return 0;
        }

        $file = [
            'name'     => sanitize_file_name( sanitize_title( $name ) . '-' . $product_id . '-' . $source . '.' . $extension ),
            'tmp_name' => $temp,
        ];

        $attachment_id = media_handle_sideload( $file, $product_id, sanitize_text_field( $name . ' - OnKupon' ) );
        if ( is_wp_error( $attachment_id ) ) {
            @unlink( $temp );
            ( new Logger() )->log( 'warning', 'affiliate', 'Affiliate image sideload failed', [ 'product_id' => $product_id, 'source' => $source, 'error' => $attachment_id->get_error_message() ] );
            return 0;
        }

        update_post_meta( (int) $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $name . ' ürün görseli' ) );
        update_post_meta( (int) $attachment_id, '_onkupon_agent_generated_asset', 'affiliate_' . $source );
        return (int) $attachment_id;
    }

    private function is_generated_card( int $attachment_id ): bool {
        return 'affiliate_card' === (string) get_post_meta( $attachment_id, '_onkupon_agent_generated_asset', true );
    }

    private function user_agent(): string {
        return 'Mozilla/5.0 (compatible; OnKupon-Agent/' . ONKUPON_AGENT_VERSION . '; +' . home_url( '/' ) . ')';
    }
}

