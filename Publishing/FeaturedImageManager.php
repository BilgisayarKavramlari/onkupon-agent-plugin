<?php
namespace OnKupon\Agent\Publishing;

use OnKupon\Agent\Logging\Logger;
use OnKupon\Agent\Plugin;

class FeaturedImageManager {
    public function assign( int $post_id, array $article ): int {
        $settings = Plugin::settings();
        $candidates = [];
        if ( ! empty( $article['suggested_featured_image_product_id'] ) ) {
            $candidates[] = absint( $article['suggested_featured_image_product_id'] );
        }
        $candidates = array_merge( $candidates, array_map( 'absint', (array) ( $article['related_product_ids'] ?? [] ) ) );
        foreach ( array_unique( $candidates ) as $product_id ) {
            $image_id = get_post_thumbnail_id( $product_id );
            if ( $image_id ) {
                set_post_thumbnail( $post_id, $image_id );
                return (int) $image_id;
            }
        }
        $default = absint( $settings['default_featured_image_id'] ?? 0 );
        if ( $default ) {
            set_post_thumbnail( $post_id, $default );
            return $default;
        }
        ( new Logger() )->log( 'warning', 'publishing', 'No featured image available', [ 'post_id' => $post_id ] );
        return 0;
    }
}
