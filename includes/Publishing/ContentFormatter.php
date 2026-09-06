<?php
namespace OnKupon\Agent\Publishing;

use OnKupon\Agent\Plugin;
use OnKupon\Agent\Woo\ProductRepository;

class ContentFormatter {
    public function format( array $article ): string {
        $blocks = [];
        $blocks[] = $this->paragraph( '<strong>' . esc_html( $this->label( 'Kısa yanıt:', 'Quick answer:' ) ) . '</strong> ' . esc_html( (string) ( $article['concise_answer'] ?? '' ) ) );
        if ( 'commercial_comparison' === (string) ( $article['_onkupon_content_intent'] ?? '' ) ) {
            $blocks[] = $this->paragraph( '<em>' . esc_html( $this->label( 'Şeffaflık notu: Bu içerik affiliate bağlantılar içerebilir. Bir bağlantı üzerinden işlem yaparsanız OnKupon komisyon kazanabilir; bu, ödediğiniz fiyatı artırmaz.', 'Disclosure: This content may include affiliate links. OnKupon may earn a commission if you transact through a link, at no additional cost to you.' ) ) . '</em>' );
        }
        if ( ! empty( $article['introduction'] ) ) {
            $blocks[] = $this->paragraph( esc_html( (string) $article['introduction'] ) );
        }
        foreach ( (array) ( $article['sections'] ?? [] ) as $section ) {
            $heading = sanitize_text_field( (string) ( $section['heading'] ?? '' ) );
            $body = $this->link_products( (string) ( $section['body'] ?? '' ), (array) ( $article['product_mentions'] ?? [] ) );
            if ( $heading ) {
                $blocks[] = $this->heading( $heading );
            }
            if ( $body ) {
                foreach ( $this->paragraph_blocks( $body ) as $paragraph ) {
                    $blocks[] = $paragraph;
                }
            }
        }
        if ( ! empty( $article['comparison_table'] ) && is_array( $article['comparison_table'] ) ) {
            $blocks[] = $this->table( $article['comparison_table'] );
        }
        if ( ! empty( $article['product_recommendations'] ) ) {
            $blocks[] = $this->heading( $this->label( 'Ürün önerileri', 'Product-aware recommendations' ) );
            foreach ( (array) $article['product_recommendations'] as $recommendation ) {
                $blocks[] = $this->paragraph( $this->recommendation_html( (array) $recommendation ) );
            }
        }
        if ( ! empty( $article['product_cards'] ) ) {
            foreach ( array_slice( array_map( 'absint', (array) $article['product_cards'] ), 0, 6 ) as $product_id ) {
                $blocks[] = '<!-- wp:shortcode -->[onkupon_agent_product_card id="' . absint( $product_id ) . '"]<!-- /wp:shortcode -->';
            }
        }
        if ( ! empty( $article['faq'] ) ) {
            $blocks[] = $this->heading( $this->label( 'Sık sorulan sorular', 'Frequently asked questions' ) );
            foreach ( (array) $article['faq'] as $faq ) {
                $blocks[] = $this->heading( sanitize_text_field( (string) ( $faq['question'] ?? '' ) ), 3 );
                $blocks[] = $this->paragraph( esc_html( (string) ( $faq['answer'] ?? '' ) ) );
            }
        }
        if ( 'commercial_comparison' === (string) ( $article['_onkupon_content_intent'] ?? '' ) && ! empty( $article['sources'] ) ) {
            $source_list = $this->source_list( (array) $article['sources'] );
            if ( $source_list ) {
                $blocks[] = $this->heading( $this->label( 'Resmî kaynaklar', 'Official sources' ) );
                $blocks[] = $source_list;
            }
        }
        if ( ! empty( $article['cta'] ) ) {
            $blocks[] = '<!-- wp:paragraph {"className":"onkupon-agent-cta"} --><p class="onkupon-agent-cta">' . esc_html( (string) $article['cta'] ) . '</p><!-- /wp:paragraph -->';
        }
        return implode( "\n\n", array_filter( $blocks ) );
    }

    public function has_raw_markdown( string $content ): bool {
        $plain = wp_strip_all_tags( preg_replace( '/href="[^"]+"/', '', $content ) );
        return (bool) preg_match( '/(^|\n)#{1,6}\s+|\[[^\]]+\]\([^\)]+\)|```|https?:\/\/\S+/u', $plain );
    }

    private function heading( string $text, int $level = 2 ): string {
        $level = max( 2, min( 4, $level ) );
        return '<!-- wp:heading {"level":' . $level . '} --><h' . $level . '>' . esc_html( $text ) . '</h' . $level . '><!-- /wp:heading -->';
    }

    private function paragraph( string $html ): string {
        return '<!-- wp:paragraph --><p>' . wp_kses_post( $html ) . '</p><!-- /wp:paragraph -->';
    }

    private function paragraph_blocks( string $html ): array {
        $paragraphs = preg_split( '/\R{2,}/u', trim( $html ) ) ?: [];
        return array_values(
            array_filter(
                array_map( fn( $paragraph ) => $this->paragraph( trim( (string) $paragraph ) ), $paragraphs )
            )
        );
    }

    private function table( array $table ): string {
        $rows = [];
        if ( isset( $table['columns'] ) ) {
            $rows[] = '<tr>' . implode( '', array_map( fn( $cell ) => $this->table_cell( $cell, 'th' ), (array) $table['columns'] ) ) . '</tr>';
            $table = (array) ( $table['rows'] ?? [] );
        }
        foreach ( $table as $row ) {
            $cells = array_map( fn( $cell ) => $this->table_cell( $cell, 'td' ), (array) $row );
            $rows[] = '<tr>' . implode( '', $cells ) . '</tr>';
        }
        return '<!-- wp:table --><figure class="wp-block-table"><table><tbody>' . implode( '', $rows ) . '</tbody></table></figure><!-- /wp:table -->';
    }

    private function table_cell( $cell, string $tag ): string {
        $text = trim( (string) $cell );
        $html = esc_html( $text );
        $url = '';
        $label = '';

        if ( preg_match( '/^\[([^\]]+)\]\((https?:\/\/[^\)]+)\)$/u', $text, $matches ) ) {
            $label = sanitize_text_field( (string) $matches[1] );
            $url = esc_url_raw( (string) $matches[2] );
        } elseif ( wp_http_validate_url( $text ) ) {
            $label = $this->label( 'Ürünü incele', 'View product' );
            $url = esc_url_raw( $text );
        }

        if ( $url && $this->is_onkupon_url( $url ) ) {
            $html = '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
        }

        $tag = 'th' === $tag ? 'th' : 'td';
        return '<' . $tag . '>' . $html . '</' . $tag . '>';
    }

    private function is_onkupon_url( string $url ): bool {
        $url_host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
        $site_host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
        return '' !== $url_host && '' !== $site_host && $url_host === $site_host;
    }

    private function source_list( array $sources ): string {
        $items = [];
        foreach ( array_slice( array_values( array_unique( array_map( 'strval', $sources ) ) ), 0, 12 ) as $source ) {
            $url = esc_url_raw( $source );
            if ( ! $url || ! wp_http_validate_url( $url ) || 'https' !== strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ) {
                continue;
            }
            $host = sanitize_text_field( (string) wp_parse_url( $url, PHP_URL_HOST ) );
            $items[] = '<li><a href="' . esc_url( $url ) . '" rel="noopener noreferrer">' . esc_html( $host ) . '</a></li>';
        }
        if ( ! $items ) {
            return '';
        }
        return '<!-- wp:list --><ul>' . implode( '', $items ) . '</ul><!-- /wp:list -->';
    }

    private function label( string $turkish, string $english ): string {
        return 'tr' === sanitize_key( (string) ( Plugin::settings()['content_language'] ?? 'en' ) ) ? $turkish : $english;
    }

    private function recommendation_html( array $recommendation ): string {
        $product_id = absint( $recommendation['product_id'] ?? 0 );
        $anchor = sanitize_text_field( (string) ( $recommendation['anchor_text'] ?? get_the_title( $product_id ) ) );
        $url = $product_id ? get_permalink( $product_id ) : '';
        $reason = esc_html( (string) ( $recommendation['reason'] ?? '' ) );
        $use_case = esc_html( (string) ( $recommendation['use_case'] ?? '' ) );
        $link = $url && 'publish' === get_post_status( $product_id ) ? '<a href="' . esc_url( $url ) . '">' . esc_html( $anchor ) . '</a>' : esc_html( $anchor );
        return '<strong>' . $link . '</strong> — ' . $reason . ( $use_case ? ' ' . esc_html( $this->label( 'Kullanım alanı:', 'Use case:' ) ) . ' ' . $use_case : '' );
    }

    private function link_products( string $text, array $mentions ): string {
        foreach ( $mentions as $mention ) {
            $product_id = absint( $mention['product_id'] ?? 0 );
            $anchor = sanitize_text_field( (string) ( $mention['anchor_text'] ?? '' ) );
            $url = $product_id ? get_permalink( $product_id ) : '';
            if ( ! $product_id || ! $anchor || ! $url || 'publish' !== get_post_status( $product_id ) ) {
                continue;
            }
            $link = '<a href="' . esc_url( add_query_arg( [ 'utm_source' => 'onkupon_agent', 'utm_medium' => 'internal_content', 'utm_campaign' => 'ai_article', 'utm_content' => 'product_' . $product_id ], $url ) ) . '">' . esc_html( $anchor ) . '</a>';
            $text = preg_replace( '/' . preg_quote( $anchor, '/' ) . '/u', $link, $text, 1 );
        }
        return $text;
    }
}
