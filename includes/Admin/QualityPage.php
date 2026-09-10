<?php
namespace OnKupon\Agent\Admin;

use OnKupon\Agent\Quality\QualityAuditService;
use OnKupon\Agent\Security\CapabilityManager;

class QualityPage extends BasePage {

    public function render(): void {
        $this->header( 'Kalite Denetimi' );

        $report = QualityAuditService::report();

        echo '<p class="description">Denetçi kataloğu turlar hâlinde gezer; her üründe görsel, başlık, açıklama, kategori, etiket ve SEO alanlarını kurallara göre yoklar. '
            . 'Onarılabilir bulgular zenginleştirme ajanına geri verilir, kalanlar aşağıda listelenir.</p>';

        $this->card_grid(
            [
                'Katalog'          => (string) ( $report['catalogue'] ?? 0 ),
                'Sorunsuz'         => (string) ( $report['healthy'] ?? 0 ),
                'Bulgulu'          => (string) ( $report['flagged'] ?? 0 ),
                'Katalog sağlığı'  => (string) ( $report['health_percent'] ?? 100 ) . '/100',
                'Son tur'          => (string) ( $report['at'] ?? '—' ),
                'Tam tur bitti mi' => empty( $report['completed_cycle'] ) ? 'devam ediyor' : 'evet',
            ]
        );

        $this->run_button();

        $by_issue = is_array( $report['by_issue'] ?? null ) ? $report['by_issue'] : [];
        if ( $by_issue ) {
            $rows = [];
            foreach ( $by_issue as $code => $count ) {
                $rule = QualityAuditService::RULES[ $code ] ?? [ 5, false, $code ];
                $rows[] = [
                    esc_html( (string) $rule[2] ),
                    esc_html( (string) $code ),
                    esc_html( (string) $count ),
                    empty( $rule[1] ) ? 'elle' : 'otomatik',
                ];
            }
            echo '<h2>Bulgu dağılımı</h2>';
            $this->table( [ 'Bulgu', 'Kod', 'Ürün sayısı', 'Onarım' ], $rows );
        }

        $items = is_array( $report['items'] ?? null ) ? $report['items'] : [];
        if ( $items ) {
            uasort( $items, static fn( $a, $b ): int => (int) ( $a['score'] ?? 0 ) <=> (int) ( $b['score'] ?? 0 ) );
            $rows = [];
            foreach ( array_slice( $items, 0, 100, true ) as $item ) {
                $labels = [];
                foreach ( (array) ( $item['issues'] ?? [] ) as $issue ) {
                    $labels[] = esc_html( (string) ( QualityAuditService::RULES[ $issue ][2] ?? $issue ) );
                }
                $product_id = (int) ( $item['product_id'] ?? 0 );
                $rows[] = [
                    sprintf(
                        '<a href="%s">%s</a>',
                        esc_url( get_edit_post_link( $product_id ) ?: '#' ),
                        esc_html( (string) ( $item['title'] ?? $product_id ) )
                    ),
                    esc_html( (string) ( $item['score'] ?? 0 ) ),
                    implode( '<br>', $labels ),
                    (string) ( get_post_meta( $product_id, '_onkupon_quality_repair_queued_at', true ) ?: '—' ),
                ];
            }
            echo '<h2>Bulgulu ürünler</h2>';
            $this->table( [ 'Ürün', 'Puan', 'Bulgular', 'Onarım kuyruğuna alındı' ], $rows );
        } else {
            echo '<p>Bu turda bulgu kaydedilmedi.</p>';
        }

        $this->footer();
    }

    private function run_button(): void {
        if ( ! current_user_can( CapabilityManager::capability() ) ) {
            return;
        }
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:0 0 16px">';
        wp_nonce_field( 'onkupon_agent_control' );
        echo '<input type="hidden" name="action" value="onkupon_agent_control">';
        echo '<input type="hidden" name="agent_action" value="run-quality-audit-now">';
        echo '<button type="submit" class="button button-primary">Kalite denetimini şimdi çalıştır</button>';
        echo '</form>';
    }
}

