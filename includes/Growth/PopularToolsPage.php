<?php
namespace OnKupon\Agent\Growth;

use OnKupon\Agent\Plugin;

class PopularToolsPage {
    private const SLUG = 'populer-yapay-zeka-ve-yazilim-araclari';

    public function register(): void {
        add_shortcode( 'onkupon_agent_popular_tools', [ $this, 'render' ] );
        add_action( 'init', [ $this, 'ensure_page' ], 25 );
        add_action( 'wp_head', [ $this, 'schema' ], 40 );
    }

    public function ensure_page(): int {
        if ( empty( Plugin::settings()['popular_tools_page_enabled'] ) ) {
            return 0;
        }
        $existing = get_page_by_path( self::SLUG, OBJECT, 'page' );
        if ( $existing instanceof \WP_Post ) {
            update_option( 'onkupon_popular_tools_page_id', (int) $existing->ID, false );
            return (int) $existing->ID;
        }

        $page_id = wp_insert_post(
            [
                'post_type'    => 'page',
                'post_status'  => 'publish',
                'post_title'   => 'OnKupon’da En Çok İncelenen Yapay Zeka ve Yazılım Araçları',
                'post_name'    => self::SLUG,
                'post_excerpt' => 'OnKupon ziyaretçilerinin son 30 gündeki anonim ve toplu inceleme davranışlarına göre otomatik güncellenen araç sıralaması.',
                'post_content' => '<!-- wp:paragraph --><p>Hangi araçların daha fazla ilgi gördüğünü merak ediyorsanız, bu sayfa OnKupon’daki son 30 günlük anonim ve toplu inceleme verilerinden yararlanır. Liste her gün otomatik güncellenir.</p><!-- /wp:paragraph --><!-- wp:shortcode -->[onkupon_agent_popular_tools]<!-- /wp:shortcode -->',
                'post_author'  => absint( Plugin::settings()['default_author_id'] ?? 0 ) ?: 1,
                'meta_input'   => [ '_onkupon_popular_tools_page' => 1 ],
            ],
            true
        );
        if ( is_wp_error( $page_id ) ) {
            return 0;
        }
        $description = 'Son 30 günlük anonim OnKupon ilgi verilerine göre en çok incelenen yapay zeka ve yazılım araçlarını keşfedin. Liste otomatik güncellenir.';
        update_post_meta( (int) $page_id, '_aioseo_title', 'En Çok İncelenen Yapay Zeka Araçları | OnKupon' );
        update_post_meta( (int) $page_id, '_aioseo_description', $description );
        update_post_meta( (int) $page_id, '_aioseo_og_title', 'OnKupon’da En Çok İncelenen Araçlar' );
        update_post_meta( (int) $page_id, '_aioseo_og_description', $description );
        update_option( 'onkupon_popular_tools_page_id', (int) $page_id, false );
        return (int) $page_id;
    }

    public function render(): string {
        $ranked = ( new AutonomousConversionOptimizer() )->global_ranked_products( 10 );
        if ( ! $ranked ) {
            return '<p>Henüz sıralama oluşturmak için yeterli toplu veri bulunmuyor.</p>';
        }
        $page_id = $this->ensure_page();
        $max_score = max( 1.0, (float) ( $ranked[0]['score'] ?? 1 ) );
        $items = '';
        foreach ( $ranked as $index => $row ) {
            $id = absint( $row['id'] ?? 0 );
            $interest = max( 1, min( 100, (int) round( ( (float) $row['score'] / $max_score ) * 100 ) ) );
            $url = ( new AutonomousConversionOptimizer() )->recommendation_url( $id, $page_id, 'popular_tools' );
            $image = get_the_post_thumbnail( $id, 'thumbnail', [ 'loading' => 'lazy' ] );
            $description = wp_html_excerpt( wp_strip_all_tags( (string) get_post_field( 'post_excerpt', $id ) ), 170, '…' );
            $items .= '<article class="onkupon-popular-tool">'
                . ( $image ? '<a href="' . esc_url( $url ) . '">' . wp_kses_post( $image ) . '</a>' : '<span aria-hidden="true">#' . esc_html( (string) ( $index + 1 ) ) . '</span>' )
                . '<div><h2><a href="' . esc_url( $url ) . '">' . esc_html( (string) ( $index + 1 ) . '. ' . get_the_title( $id ) ) . '</a></h2><p>' . esc_html( $description ) . '</p></div>'
                . '<div class="onkupon-interest-score">İlgi skoru ' . esc_html( (string) $interest ) . '</div></article>';
        }
        return '<section class="onkupon-popular-tools">' . $items . '</section>'
            . '<p class="onkupon-cro-method"><strong>Yöntem:</strong> Sıralama; son 30 gündeki anonim, toplu ürün inceleme tıklamaları, yeni seçeneklerin adil biçimde keşfedilmesi ve veri yeterliliği birlikte değerlendirilerek hazırlanır. Kişisel veri kullanılmaz. İlgi skoru bir kalite garantisi değildir. Affiliate bağlantılar içerebilir; satın alma fiyatınız değişmez. Son güncelleme: ' . esc_html( current_time( 'd.m.Y' ) ) . '.</p>';
    }

    public function schema(): void {
        $page_id = absint( get_option( 'onkupon_popular_tools_page_id', 0 ) );
        if ( ! $page_id || ! is_page( $page_id ) ) {
            return;
        }
        $ranked = ( new AutonomousConversionOptimizer() )->global_ranked_products( 10 );
        $items = [];
        foreach ( $ranked as $index => $row ) {
            $id = absint( $row['id'] ?? 0 );
            $items[] = [
                '@type'    => 'ListItem',
                'position' => $index + 1,
                'name'     => get_the_title( $id ),
                'url'      => get_permalink( $id ),
            ];
        }
        if ( $items ) {
            echo '<script type="application/ld+json">' . wp_json_encode( [ '@context' => 'https://schema.org', '@type' => 'ItemList', 'name' => get_the_title( $page_id ), 'itemListElement' => $items ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>';
        }
    }
}
