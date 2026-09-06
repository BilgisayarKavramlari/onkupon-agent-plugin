<?php
namespace OnKupon\Agent\Publishing;

use OnKupon\Agent\Plugin;

class CategoryManager {
    public function assign( int $post_id, array $article ): array {
        $settings = Plugin::settings();
        $fallback = absint( $settings['default_post_category_id'] ?? get_option( 'default_category' ) );
        $allowed = array_filter( array_map( 'absint', explode( ',', (string) ( $settings['allowed_category_ids'] ?? '' ) ) ) );
        $allow_create = ! empty( $settings['allow_create_categories'] ) || 'create_if_allowed' === ( $settings['category_strategy'] ?? '' );
        if ( $allow_create ) {
            $this->ensure_recommended_categories();
        }
        $ids = [];
        foreach ( (array) ( $article['categories'] ?? [] ) as $category ) {
            $term = is_numeric( $category ) ? get_term( absint( $category ), 'category' ) : get_term_by( 'name', sanitize_text_field( (string) $category ), 'category' );
            if ( ! $term && $allow_create ) {
                $created = wp_insert_term( sanitize_text_field( (string) $category ), 'category' );
                if ( ! is_wp_error( $created ) ) {
                    $term = get_term( (int) $created['term_id'], 'category' );
                }
            }
            if ( $term && ! is_wp_error( $term ) ) {
                $ids[] = (int) $term->term_id;
            }
        }
        if ( $allowed ) {
            $ids = array_values( array_intersect( $ids, $allowed ) );
        }
        if ( ! $ids && $fallback ) {
            $ids[] = $fallback;
        }
        if ( ! $ids ) {
            $guide = get_term_by( 'name', 'OnKupon Rehberleri', 'category' );
            if ( $guide && ! is_wp_error( $guide ) ) {
                $ids[] = (int) $guide->term_id;
            }
        }
        if ( $ids ) {
            wp_set_post_categories( $post_id, array_unique( $ids ) );
        }
        return $ids;
    }

    private function ensure_recommended_categories(): void {
        foreach ( [ 'Yapay Zeka', 'Otomasyon', 'E-Ticaret Araçları', 'Dijital Pazarlama', 'Eğitim ve Verimlilik', 'OnKupon Rehberleri' ] as $name ) {
            if ( ! get_term_by( 'name', $name, 'category' ) ) {
                wp_insert_term( $name, 'category' );
            }
        }
    }
}
