<?php
namespace OnKupon\Agent\SEO;

class SEOManager {
    public function apply( int $post_id, array $article ): void {
        $generic = new GenericSEOAdapter();
        $generic->apply( $post_id, $article );
        $aioseo = new AIOSEOAdapter();
        if ( $aioseo->is_available() ) {
            $aioseo->apply( $post_id, $article );
        }
    }
}
