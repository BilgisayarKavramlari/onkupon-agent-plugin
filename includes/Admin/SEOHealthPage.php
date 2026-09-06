<?php
namespace OnKupon\Agent\Admin;

use OnKupon\Agent\Plugin;
use OnKupon\Agent\SEO\SitemapAuditor;
use OnKupon\Agent\Security\CapabilityManager;

class SEOHealthPage extends BasePage {
    public function render(): void {
        $report = (array) get_option( 'onkupon_seo_health_last', [] );
        $rows = [];
        foreach ( (array) ( $report['checks'] ?? [] ) as $name => $check ) {
            $rows[] = [ esc_html( ucwords( str_replace( '_', ' ', $name ) ) ), ! empty( $check['ok'] ) ? 'pass' : 'fail', esc_html( (string) ( $check['detail'] ?? '' ) ) ];
        }
        $this->header( __( 'SEO Health', 'onkupon-agent' ) );
        $this->card_grid(
            [
                'Score' => isset( $report['score'] ) ? absint( $report['score'] ) . '/100' : 'not checked',
                'Last check' => sanitize_text_field( (string) ( $report['checked_at'] ?? 'never' ) ),
                'Search visibility' => sanitize_text_field( (string) ( $report['search_engine_visibility'] ?? 'unknown' ) ),
                'Commercial plans' => absint( $report['commercial_content']['published_plans'] ?? 0 ) . '/' . absint( $report['commercial_content']['total_plans'] ?? 0 ),
                'Rewrite auto-repair' => ! empty( Plugin::settings()['seo_auto_repair_rewrites'] ) ? 'enabled' : 'disabled',
            ]
        );
        echo '<p>' . esc_html__( 'The monitor checks permalinks, REST API, robots.txt, sitemap availability, AIOSEO and published product inventory. Optional rewrite repair is throttled to once per day and remains disabled by default.', 'onkupon-agent' ) . '</p>';
        if ( ! empty( $report['commercial_content']['next_plan_topic'] ) ) {
            echo '<p><strong>' . esc_html__( 'Next source-backed commercial topic:', 'onkupon-agent' ) . '</strong> ' . esc_html( (string) $report['commercial_content']['next_plan_topic'] ) . '</p>';
        }
        $this->table( [ 'Check', 'Status', 'Detail' ], $rows );

        $this->sitemap_section();

        $this->footer();
    }

    /**
     * Site haritasındaki kırık adresler. Search Console yalnızca sayı verir;
     * burada adres adres listelenir.
     */
    private function sitemap_section(): void {
        echo '<h2>' . esc_html__( 'Sitemap Denetimi', 'onkupon-agent' ) . '</h2>';

        $progress = SitemapAuditor::progress();
        $result = SitemapAuditor::last_result();

        if ( ! empty( $progress['running'] ) ) {
            echo '<div class="notice notice-info inline"><p>' . esc_html(
                sprintf( 'Tarama sürüyor: %d / %d adres yoklandı.', absint( $progress['cursor'] ), absint( $progress['total'] ) )
            ) . '</p></div>';
        }

        if ( empty( $result ) ) {
            echo '<p class="description">' . esc_html__( 'Henüz tarama yapılmadı. Site haritasındaki her adres sunucudan yoklanır ve 200 dönmeyenler burada listelenir.', 'onkupon-agent' ) . '</p>';
        } else {
            $by_status = (array) ( $result['by_status'] ?? [] );
            $summary = [];
            foreach ( $by_status as $code => $count ) {
                $summary[] = ( '0' === (string) $code ? 'bağlantı hatası' : 'HTTP ' . $code ) . ': ' . absint( $count );
            }
            $this->card_grid(
                [
                    'Taranan adres' => absint( $result['total'] ?? 0 ),
                    'Kırık adres' => absint( $result['broken_count'] ?? 0 ),
                    'Dağılım' => $summary ? implode( ' · ', $summary ) : 'temiz',
                    'Son tarama' => sanitize_text_field( (string) ( $result['at'] ?? '—' ) ),
                ]
            );

            $rows = [];
            foreach ( (array) ( $result['broken'] ?? [] ) as $item ) {
                $url = esc_url( (string) ( $item['url'] ?? '' ) );
                $code = absint( $item['status'] ?? 0 );
                $rows[] = [
                    '<a href="' . $url . '" target="_blank" rel="noreferrer noopener">' . esc_html( (string) ( $item['url'] ?? '' ) ) . '</a>',
                    0 === $code ? 'bağlantı hatası' : (string) $code,
                    $this->hint( (string) ( $item['url'] ?? '' ), $code ),
                ];
            }
            $this->table( [ 'Adres', 'Durum', 'Olası neden' ], $rows );
        }

        if ( current_user_can( CapabilityManager::capability() ) ) {
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
            wp_nonce_field( 'onkupon_agent_control' );
            echo '<input type="hidden" name="action" value="onkupon_agent_control">';
            echo '<input type="hidden" name="agent_action" value="run-sitemap-audit-now">';
            echo '<button type="submit" class="button button-primary">' . esc_html__( 'Site haritasını şimdi tara', 'onkupon-agent' ) . '</button>';
            echo '</form>';
        }
    }

    private function hint( string $url, int $status ): string {
        if ( false !== strpos( $url, '__trashed' ) ) {
            return esc_html__( 'Çöp kutusuna atılmış içerik hâlâ site haritasında', 'onkupon-agent' );
        }
        if ( 403 === $status ) {
            return esc_html__( 'Erişim kısıtlı içerik (üyelik/kurs koruması) — site haritasında olmamalı', 'onkupon-agent' );
        }
        if ( 404 === $status ) {
            return esc_html__( 'Silinmiş veya slug\'ı değişmiş içerik', 'onkupon-agent' );
        }
        if ( 0 === $status ) {
            return esc_html__( 'Sunucu yanıt vermedi; zaman aşımı olabilir', 'onkupon-agent' );
        }
        return '';
    }
}
