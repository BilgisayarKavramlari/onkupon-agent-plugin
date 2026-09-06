<?php
namespace OnKupon\Agent\Migration;

class JsonPostMetaRepair {
    public static function run(): void {
        $post_ids = get_posts(
            [
                'post_type'      => 'post',
                'post_status'    => 'any',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'meta_key'       => '_onkupon_agent_generated',
            ]
        );

        foreach ( array_map( 'absint', $post_ids ) as $post_id ) {
            self::repair_faq( $post_id );
            self::repair_secondary_keyphrases( $post_id );
        }
    }

    private static function repair_faq( int $post_id ): void {
        $raw = (string) get_post_meta( $post_id, '_onkupon_agent_faq', true );
        $decoded = json_decode( $raw, true );
        if ( is_array( $decoded ) && $decoded ) {
            return;
        }

        $faq = self::faq_from_content( (string) get_post_field( 'post_content', $post_id ) );
        if ( ! $faq ) {
            return;
        }

        update_post_meta(
            $post_id,
            '_onkupon_agent_faq',
            wp_slash( wp_json_encode( $faq, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) )
        );
    }

    private static function repair_secondary_keyphrases( int $post_id ): void {
        $raw = (string) get_post_meta( $post_id, '_onkupon_agent_secondary_keyphrases', true );
        if ( '' === $raw || is_array( json_decode( $raw, true ) ) ) {
            return;
        }

        $keywords = wp_get_post_tags( $post_id, [ 'fields' => 'names' ] );
        update_post_meta(
            $post_id,
            '_onkupon_agent_secondary_keyphrases',
            wp_slash( wp_json_encode( array_values( array_map( 'sanitize_text_field', $keywords ) ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) )
        );
    }

    private static function faq_from_content( string $content ): array {
        if ( '' === trim( $content ) || ! class_exists( '\\DOMDocument' ) ) {
            return [];
        }

        $dom = new \DOMDocument();
        libxml_use_internal_errors( true );
        $loaded = $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $content );
        libxml_clear_errors();
        if ( ! $loaded ) {
            return [];
        }

        $faq_heading = null;
        foreach ( $dom->getElementsByTagName( 'h2' ) as $heading ) {
            $label = strtolower( trim( (string) $heading->textContent ) );
            if ( in_array( $label, [ 'sık sorulan sorular', 'frequently asked questions' ], true ) ) {
                $faq_heading = $heading;
                break;
            }
        }
        if ( ! $faq_heading ) {
            return [];
        }

        $faq = [];
        $question = '';
        for ( $node = $faq_heading->nextSibling; $node; $node = $node->nextSibling ) {
            if ( XML_ELEMENT_NODE !== $node->nodeType ) {
                continue;
            }
            $tag = strtolower( (string) $node->nodeName );
            if ( 'h2' === $tag ) {
                break;
            }
            if ( 'h3' === $tag ) {
                $question = sanitize_text_field( trim( (string) $node->textContent ) );
                continue;
            }
            if ( 'p' === $tag && '' !== $question ) {
                $answer = sanitize_textarea_field( trim( (string) $node->textContent ) );
                if ( '' !== $answer ) {
                    $faq[] = [ 'question' => $question, 'answer' => $answer ];
                }
                $question = '';
            }
        }

        return $faq;
    }
}
