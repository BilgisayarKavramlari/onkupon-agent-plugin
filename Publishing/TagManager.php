<?php
namespace OnKupon\Agent\Publishing;

class TagManager {
    public function assign( int $post_id, array $article ): array {
        $generic = [ 'ai', 'best', 'cheap', 'sale', 'product', 'review', 'awesome' ];
        $tags = [];
        foreach ( (array) ( $article['tags'] ?? [] ) as $tag ) {
            $tag = sanitize_text_field( (string) $tag );
            if ( '' === $tag ) {
                continue;
            }
            $lower = strtolower( $tag );
            if ( in_array( $lower, $generic, true ) ) {
                continue;
            }
            $tags[] = $tag;
        }
        foreach ( (array) ( $article['secondary_keyphrases'] ?? [] ) as $phrase ) {
            $phrase = sanitize_text_field( (string) $phrase );
            if ( $phrase && ! in_array( strtolower( $phrase ), $generic, true ) ) {
                $tags[] = $phrase;
            }
        }
        $tags = array_slice( array_values( array_unique( $tags ) ), 0, 15 );
        if ( count( $tags ) < 8 && ! empty( $article['focus_keyphrase'] ) ) {
            $tags[] = sanitize_text_field( (string) $article['focus_keyphrase'] );
            $tags[] = 'OnKupon rehberi';
        }
        $tags = array_slice( array_values( array_unique( $tags ) ), 0, 15 );
        if ( $tags ) {
            wp_set_post_tags( $post_id, $tags, false );
        }
        return $tags;
    }
}
