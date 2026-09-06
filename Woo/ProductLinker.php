<?php
namespace OnKupon\Agent\Woo;

use OnKupon\Agent\Plugin;

class ProductLinker {
    public function register_shortcodes(): void {
        add_shortcode( 'onkupon_agent_product_card', [ $this, 'card' ] );
        add_shortcode( 'onkupon_agent_related_products', [ $this, 'related' ] );
    }

    public function card( $atts ): string {
        $id = absint( $atts['id'] ?? 0 );
        if ( ! $id || 'publish' !== get_post_status( $id ) ) {
            return '';
        }
        $product = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;
        $url = get_permalink( $id );
        $title = get_the_title( $id );
        $image = get_the_post_thumbnail( $id, 'medium' );
        $description = $product ? wp_trim_words( wp_strip_all_tags( $product->get_short_description() ), 18 ) : wp_trim_words( wp_strip_all_tags( get_post_field( 'post_excerpt', $id ) ), 18 );
        $price = $product ? $product->get_price_html() : '';
        return '<div class="onkupon-product-card">'
            . '<a class="onkupon-product-card__image" href="' . esc_url( $url ) . '">' . wp_kses_post( $image ) . '</a>'
            . '<div class="onkupon-product-card__body"><h3><a href="' . esc_url( $url ) . '">' . esc_html( $title ) . '</a></h3>'
            . '<p>' . esc_html( $description ) . '</p>'
            . ( $price ? '<div class="onkupon-product-card__price">' . wp_kses_post( $price ) . '</div>' : '' )
            . '<a class="button" href="' . esc_url( $url ) . '">' . esc_html( $this->label( 'Ürünü incele', 'View product' ) ) . '</a></div></div>';
    }

    public function related( $atts ): string {
        $ids = array_filter( array_map( 'absint', explode( ',', (string) ( $atts['ids'] ?? '' ) ) ) );
        return '<div class="onkupon-related-products">' . implode( '', array_map( fn( $id ) => $this->card( [ 'id' => $id ] ), $ids ) ) . '</div>';
    }

    private function label( string $turkish, string $english ): string {
        return 'tr' === sanitize_key( (string) ( Plugin::settings()['content_language'] ?? 'en' ) ) ? $turkish : $english;
    }
}
