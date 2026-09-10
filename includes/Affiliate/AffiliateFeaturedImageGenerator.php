<?php
namespace OnKupon\Agent\Affiliate;

use OnKupon\Agent\Logging\Logger;

class AffiliateFeaturedImageGenerator {
    public function ensure( int $product_id, string $logo_url = '', bool $force = false ): int {
        $product = wc_get_product( $product_id );
        if ( ! $product || 'partnerstack' !== get_post_meta( $product_id, '_onkupon_affiliate_provider', true ) ) {
            return 0;
        }
        if ( ! $force && $product->get_image_id() ) {
            return (int) $product->get_image_id();
        }
        if ( ! function_exists( 'imagecreatetruecolor' ) || ! function_exists( 'imagepng' ) ) {
            $this->failure( $product_id, 'GD PNG support is unavailable' );
            return 0;
        }

        $uploads = wp_upload_dir();
        if ( ! empty( $uploads['error'] ) ) {
            $this->failure( $product_id, 'WordPress upload directory is unavailable' );
            return 0;
        }
        $directory = trailingslashit( $uploads['basedir'] ) . 'onkupon-affiliate-cards';
        if ( ! wp_mkdir_p( $directory ) ) {
            $this->failure( $product_id, 'Affiliate card directory could not be created' );
            return 0;
        }

        $source_hash = sanitize_text_field( (string) get_post_meta( $product_id, '_onkupon_affiliate_source_hash', true ) );
        $logo_path = $this->fetch_logo( $logo_url );
        $suffix = $logo_path ? '-logo' : '';
        $filename = sanitize_file_name( $product->get_name() . '-' . $product_id . '-' . substr( $source_hash, 0, 8 ) . $suffix . '.png' );
        $path = trailingslashit( $directory ) . $filename;
        if ( ! $this->render( $path, $product->get_name(), $source_hash ?: (string) $product_id, $logo_path ) ) {
            if ( $logo_path ) {
                @unlink( $logo_path );
            }
            $this->failure( $product_id, 'Affiliate card rendering failed' );
            return 0;
        }
        if ( $logo_path ) {
            @unlink( $logo_path );
        }

        $attachment_id = $this->attach( $path, $product_id, $product->get_name(), $source_hash );
        if ( ! $attachment_id ) {
            $this->failure( $product_id, 'Affiliate card attachment creation failed' );
            return 0;
        }
        $product->set_image_id( $attachment_id );
        $product->save();
        return $attachment_id;
    }

    /**
     * Marka logosunu indirir. Hedef sitesi bot korumasi ardindaysa kendi
     * sayfasindan gorsel alinamaz; bu durumda favicon servisi markanin gercek
     * simgesini yeterli cozunurlukte verir ve kart en azindan taninabilir olur.
     */
    private function fetch_logo( string $logo_url ): string {
        $logo_url = esc_url_raw( $logo_url );
        if ( '' === $logo_url ) {
            return '';
        }
        $response = wp_remote_get( $logo_url, [ 'timeout' => 15, 'redirection' => 5, 'user-agent' => 'Mozilla/5.0 (compatible; OnKupon-Agent/' . ONKUPON_AGENT_VERSION . ')' ] );
        if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
            return '';
        }
        $body = (string) wp_remote_retrieve_body( $response );
        if ( strlen( $body ) < 300 ) {
            return '';
        }
        $temp = wp_tempnam( 'onkupon-logo' );
        if ( ! $temp || false === file_put_contents( $temp, $body ) ) {
            return '';
        }
        $info = @getimagesize( $temp );
        if ( ! is_array( $info ) || (int) ( $info[0] ?? 0 ) < 48 ) {
            @unlink( $temp );
            return '';
        }
        return $temp;
    }

    private function render( string $path, string $name, string $seed, string $logo_path = '' ): bool {
        $width = 1200;
        $height = 630;
        $image = imagecreatetruecolor( $width, $height );
        if ( false === $image ) {
            return false;
        }
        imagealphablending( $image, true );
        $hash = hash( 'sha256', $seed, true );
        $start = [ 18 + ord( $hash[0] ) % 28, 39 + ord( $hash[1] ) % 40, 84 + ord( $hash[2] ) % 52 ];
        $end = [ 72 + ord( $hash[3] ) % 52, 34 + ord( $hash[4] ) % 44, 126 + ord( $hash[5] ) % 72 ];
        for ( $y = 0; $y < $height; $y++ ) {
            $ratio = $y / max( 1, $height - 1 );
            $color = imagecolorallocate(
                $image,
                (int) round( $start[0] + ( $end[0] - $start[0] ) * $ratio ),
                (int) round( $start[1] + ( $end[1] - $start[1] ) * $ratio ),
                (int) round( $start[2] + ( $end[2] - $start[2] ) * $ratio )
            );
            imageline( $image, 0, $y, $width, $y, $color );
        }

        $glow = imagecolorallocatealpha( $image, 86, 224, 255, 92 );
        $shade = imagecolorallocatealpha( $image, 8, 18, 55, 78 );
        imagefilledellipse( $image, 1030, 80, 430, 430, $glow );
        imagefilledellipse( $image, 120, 585, 520, 360, $shade );
        imagefilledpolygon( $image, [ 780, 0, 1200, 0, 1200, 260 ], 3, $shade );

        $white = [ 255, 255, 255 ];
        $muted = [ 195, 228, 255 ];
        $this->draw_scaled_text( $image, 'ONKUPON', 3, 52, $white );
        $this->draw_scaled_text( $image, 'PARTNER TOOL', 2, 104, $muted );

        $logo_bottom = 0;
        if ( '' !== $logo_path ) {
            $logo_bottom = $this->draw_logo( $image, $logo_path );
        }

        // Kartta markanin adi yeterlidir; urunun uzun Turkce basligi bu olcude
        // okunmaz ve kartin disina tasar.
        $safe_name = $this->ascii( $this->brand_of( $name ) );
        $max_lines = $logo_bottom > 0 ? 2 : 3;
        $lines = $this->wrap( $safe_name ?: 'DIGITAL TOOL', 20, $max_lines );
        $line_height = 76;
        $block_height = count( $lines ) * $line_height;

        if ( $logo_bottom > 0 ) {
            $start_y = $logo_bottom + 34;
        } else {
            $start_y = (int) round( ( $height - $block_height ) / 2 ) + 20;
        }
        $start_y = min( $start_y, 520 - $block_height );

        foreach ( $lines as $index => $line ) {
            $this->draw_scaled_text( $image, $line, 5, $start_y + $index * $line_height, $white );
        }

        $this->draw_scaled_text( $image, 'OFFICIAL EXTERNAL PRODUCT LINK', 2, 566, $muted );
        $result = imagepng( $image, $path, 8 );
        imagedestroy( $image );
        return $result;
    }

    /**
     * Logoyu kartin ust orta bolgesine, oranini bozmadan yerlestirir.
     * Geriye cizilen yuksekligi dondurur ki marka adi altina hizalanabilsin.
     */
    private function draw_logo( \GdImage $canvas, string $logo_path ): int {
        $data = @file_get_contents( $logo_path );
        if ( false === $data ) {
            return 0;
        }
        $logo = @imagecreatefromstring( $data );
        if ( ! $logo ) {
            return 0;
        }

        $source_width = imagesx( $logo );
        $source_height = imagesy( $logo );
        if ( $source_width < 1 || $source_height < 1 ) {
            imagedestroy( $logo );
            return 0;
        }

        $max = 176;
        $scale = min( $max / $source_width, $max / $source_height );
        $target_width = (int) max( 1, round( $source_width * $scale ) );
        $target_height = (int) max( 1, round( $source_height * $scale ) );
        $x = (int) round( ( 1200 - $target_width ) / 2 );
        $y = 158;

        $pad = 22;
        $cushion = imagecolorallocatealpha( $canvas, 255, 255, 255, 18 );
        imagefilledrectangle( $canvas, $x - $pad, $y - $pad, $x + $target_width + $pad, $y + $target_height + $pad, $cushion );

        imagealphablending( $canvas, true );
        imagecopyresampled( $canvas, $logo, $x, $y, 0, 0, $target_width, $target_height, $source_width, $source_height );
        imagedestroy( $logo );

        return $y + $target_height + $pad;
    }

    /**
     * Urun basligindan marka adini ayirir.
     */
    private function brand_of( string $name ): string {
        $parts = preg_split( '/\s+(?:\x{2013}|\x{2014}|-|\||:)\s+/u', $name, 2 );
        $brand = trim( (string) ( $parts[0] ?? $name ) );
        return '' !== $brand ? $brand : $name;
    }

    private function draw_scaled_text( \GdImage $canvas, string $text, int $scale, int $y, array $rgb ): void {
        $font = 5;
        $base_width = max( 1, imagefontwidth( $font ) * strlen( $text ) );
        $base_height = imagefontheight( $font );
        $layer = imagecreatetruecolor( $base_width, $base_height );
        imagealphablending( $layer, false );
        imagesavealpha( $layer, true );
        $transparent = imagecolorallocatealpha( $layer, 0, 0, 0, 127 );
        imagefill( $layer, 0, 0, $transparent );
        imagealphablending( $layer, true );
        $color = imagecolorallocate( $layer, $rgb[0], $rgb[1], $rgb[2] );
        imagestring( $layer, $font, 0, 0, $text, $color );
        $target_width = $base_width * $scale;
        $target_height = $base_height * $scale;
        $x = (int) max( 40, ( 1200 - $target_width ) / 2 );
        imagecopyresampled( $canvas, $layer, $x, $y, 0, 0, $target_width, $target_height, $base_width, $base_height );
        imagedestroy( $layer );
    }

    private function wrap( string $text, int $limit, int $max_lines ): array {
        $words = preg_split( '/\s+/', trim( $text ) ) ?: [];
        $lines = [];
        $current = '';
        foreach ( $words as $word ) {
            $word = strlen( $word ) > $limit ? substr( $word, 0, $limit ) : $word;
            $candidate = '' === $current ? $word : $current . ' ' . $word;
            if ( strlen( $candidate ) <= $limit ) {
                $current = $candidate;
                continue;
            }
            if ( '' !== $current ) {
                $lines[] = $current;
            }
            $current = $word;
            if ( count( $lines ) >= $max_lines - 1 ) {
                break;
            }
        }
        if ( '' !== $current && count( $lines ) < $max_lines ) {
            $lines[] = $current;
        }
        return $lines ?: [ 'DIGITAL TOOL' ];
    }

    private function ascii( string $value ): string {
        $value = strtoupper( remove_accents( wp_strip_all_tags( $value ) ) );
        return trim( preg_replace( '/[^A-Z0-9.,&+() -]/', '', $value ) ?: '' );
    }

    private function attach( string $path, int $product_id, string $name, string $source_hash ): int {
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $filetype = wp_check_filetype( basename( $path ), null );
        $attachment_id = wp_insert_attachment(
            [
                'post_mime_type' => $filetype['type'] ?: 'image/png',
                'post_title'     => sanitize_text_field( $name . ' - OnKupon Partner Tool' ),
                'post_content'   => '',
                'post_status'    => 'inherit',
                'post_parent'    => $product_id,
            ],
            $path,
            $product_id,
            true
        );
        if ( is_wp_error( $attachment_id ) ) {
            return 0;
        }
        $metadata = wp_generate_attachment_metadata( (int) $attachment_id, $path );
        if ( is_array( $metadata ) ) {
            wp_update_attachment_metadata( (int) $attachment_id, $metadata );
        }
        update_post_meta( (int) $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $name . ' OnKupon partner aracı' ) );
        update_post_meta( (int) $attachment_id, '_onkupon_agent_generated_asset', 'affiliate_card' );
        update_post_meta( (int) $attachment_id, '_onkupon_affiliate_source_hash', $source_hash );
        return (int) $attachment_id;
    }

    private function failure( int $product_id, string $message ): void {
        ( new Logger() )->log( 'warning', 'affiliate', $message, [ 'product_id' => $product_id ] );
    }
}
