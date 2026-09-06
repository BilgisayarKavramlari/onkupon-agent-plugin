<?php
namespace OnKupon\Agent\SEO;

class GenericSEOAdapter implements SEOProviderInterface {
    public function is_available(): bool { return true; }
    public function apply( int $post_id, array $article ): void {
        if ( ! empty( $article['excerpt'] ) ) {
            wp_update_post( [ 'ID' => $post_id, 'post_excerpt' => sanitize_text_field( (string) $article['excerpt'] ) ] );
        }
        update_post_meta( $post_id, '_onkupon_agent_seo_title', sanitize_text_field( (string) ( $article['seo_title'] ?? '' ) ) );
        update_post_meta( $post_id, '_onkupon_agent_meta_description', sanitize_text_field( (string) ( $article['meta_description'] ?? '' ) ) );
        update_post_meta( $post_id, '_onkupon_agent_focus_keyphrase', sanitize_text_field( (string) ( $article['focus_keyphrase'] ?? '' ) ) );
        update_post_meta( $post_id, '_onkupon_agent_secondary_keyphrases', wp_slash( wp_json_encode( array_map( 'sanitize_text_field', (array) ( $article['secondary_keyphrases'] ?? [] ) ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) );
    }
}
