<?php
namespace OnKupon\Agent\Publishing;

use OnKupon\Agent\Plugin;

class SchemaWriter {
    public function register(): void { add_action( 'wp_head', [ $this, 'output' ] ); }
    public function output(): void {
        if ( ! is_singular( 'post' ) || empty( Plugin::settings()['schema_enabled'] ) ) {
            return;
        }
        $post_id = get_the_ID();
        $related = json_decode( (string) get_post_meta( $post_id, '_onkupon_related_products', true ), true );
        $faq = json_decode( (string) get_post_meta( $post_id, '_onkupon_agent_faq', true ), true );
        $schema = [
            [
                '@context' => 'https://schema.org',
                '@type' => 'Article',
                'headline' => get_the_title( $post_id ),
                'description' => get_post_meta( $post_id, '_onkupon_agent_meta_description', true ),
                'datePublished' => get_the_date( 'c', $post_id ),
                'dateModified' => get_the_modified_date( 'c', $post_id ),
                'publisher' => [ '@type' => 'Organization', 'name' => get_bloginfo( 'name' ) ],
            ],
            [
                '@context' => 'https://schema.org',
                '@type' => 'BreadcrumbList',
                'itemListElement' => [ [ '@type' => 'ListItem', 'position' => 1, 'name' => get_bloginfo( 'name' ), 'item' => home_url( '/' ) ], [ '@type' => 'ListItem', 'position' => 2, 'name' => get_the_title( $post_id ), 'item' => get_permalink( $post_id ) ] ],
            ],
            [
                '@context' => 'https://schema.org',
                '@type' => 'Organization',
                'name' => get_bloginfo( 'name' ),
                'url' => home_url( '/' ),
            ],
        ];
        if ( is_array( $faq ) && $faq ) {
            $schema[] = [ '@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map( static fn( $row ) => [ '@type' => 'Question', 'name' => sanitize_text_field( (string) ( $row['question'] ?? '' ) ), 'acceptedAnswer' => [ '@type' => 'Answer', 'text' => wp_strip_all_tags( (string) ( $row['answer'] ?? '' ) ) ] ], $faq ) ];
        }
        foreach ( array_map( 'absint', (array) $related ) as $product_id ) {
            if ( 'publish' !== get_post_status( $product_id ) ) {
                continue;
            }
            $schema[] = [ '@context' => 'https://schema.org', '@type' => 'Product', 'name' => get_the_title( $product_id ), 'url' => get_permalink( $product_id ) ];
        }
        echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>';
    }
}
