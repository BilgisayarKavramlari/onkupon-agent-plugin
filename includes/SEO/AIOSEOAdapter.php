<?php
namespace OnKupon\Agent\SEO;

use OnKupon\Agent\Logging\Logger;

class AIOSEOAdapter implements SEOProviderInterface {
    public function is_available(): bool {
        return defined( 'AIOSEO_VERSION' ) || class_exists( '\AIOSEO\Plugin\AIOSEO' ) || function_exists( 'aioseo' );
    }
    public function apply( int $post_id, array $article ): void {
        if ( ! $this->is_available() ) {
            return;
        }
        update_post_meta( $post_id, '_aioseo_title', sanitize_text_field( (string) ( $article['seo_title'] ?? '' ) ) );
        update_post_meta( $post_id, '_aioseo_description', sanitize_text_field( (string) ( $article['meta_description'] ?? '' ) ) );
        update_post_meta( $post_id, '_aioseo_keywords', sanitize_text_field( (string) ( $article['focus_keyphrase'] ?? '' ) ) );
        update_post_meta( $post_id, '_aioseo_og_title', sanitize_text_field( (string) ( $article['seo_title'] ?? '' ) ) );
        update_post_meta( $post_id, '_aioseo_og_description', sanitize_text_field( (string) ( $article['meta_description'] ?? '' ) ) );
        update_post_meta( $post_id, '_aioseo_twitter_title', sanitize_text_field( (string) ( $article['seo_title'] ?? '' ) ) );
        update_post_meta( $post_id, '_aioseo_twitter_description', sanitize_text_field( (string) ( $article['meta_description'] ?? '' ) ) );
        ( new Logger() )->log( 'info', 'seo', 'AIOSEO metadata applied defensively', [ 'post_id' => $post_id ] );
    }
}
