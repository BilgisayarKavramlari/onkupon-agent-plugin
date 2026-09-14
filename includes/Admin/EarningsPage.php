<?php
namespace OnKupon\Agent\Admin;

use OnKupon\Agent\Affiliate\EarningsService;
use OnKupon\Agent\Security\CapabilityManager;

class EarningsPage extends BasePage {

    public function render(): void {
        $this->header( 'Kazançlar' );

        $snapshot = EarningsService::snapshot();
        $totals = is_array( $snapshot['totals'] ?? null ) ? $snapshot['totals'] : [];
        $currency = (string) ( $snapshot['currency'] ?? 'USD' );

        echo '<p class="description">Partner API kazanç uçları salt okunurdur: ajan tutarları raporlar, ödeme başlatamaz. '
            . '"Alacak" = beklemede olan + onaylanmış ama henüz ödenmemiş tutar.</p>';

        $this->card_grid(
            [
                'Toplam alacak' => $this->money( $totals['outstanding'] ?? 0, $currency ),
                'Beklemede'     => $this->money( $totals['pending'] ?? 0, $currency ),
                'Onaylanmış'    => $this->money( $totals['approved'] ?? 0, $currency ),
                'Ödenmiş'       => $this->money( $totals['paid'] ?? 0, $currency ),
                'Reddedilen'    => $this->money( $totals['declined'] ?? 0, $currency ),
                'Kayıt sayısı'  => (string) ( $totals['count'] ?? 0 ),
                'Son güncelleme' => (string) ( $snapshot['at'] ?? '—' ),
            ]
        );

        $this->run_button();

        if ( ! $snapshot ) {
            echo '<p>Henüz kazanç verisi çekilmedi. Yukarıdaki düğmeyle ilk anlık görüntüyü alın.</p>';
            $this->footer();
            return;
        }

        $rows = [];
        foreach ( (array) ( $snapshot['programs'] ?? [] ) as $program ) {
            $rows[] = [
                esc_html( (string) $program['program'] ),
                $this->money( $program['outstanding'] ?? 0, $currency ),
                $this->money( $program['pending'] ?? 0, $currency ),
                $this->money( $program['approved'] ?? 0, $currency ),
                $this->money( $program['paid'] ?? 0, $currency ),
                esc_html( (string) ( $program['count'] ?? 0 ) ),
                esc_html( substr( (string) ( $program['last_at'] ?? '' ), 0, 10 ) ?: '—' ),
            ];
        }
        echo '<h2>Program bazında</h2>';
        $this->table( [ 'Program', 'Alacak', 'Beklemede', 'Onaylanmış', 'Ödenmiş', 'Kayıt', 'Son hareket' ], $rows );

        if ( empty( $snapshot['payouts_ok'] ) ) {
            echo '<div class="notice notice-warning"><p>Ödeme (payouts) ucu okunamadı; tablo yalnızca kazanç kayıtlarına dayanıyor.</p></div>';
        }

        $payout_rows = [];
        foreach ( (array) ( $snapshot['payouts'] ?? [] ) as $payout ) {
            $payout_rows[] = [
                esc_html( substr( (string) $payout['created_at'], 0, 10 ) ?: '—' ),
                $this->money( $payout['amount'] ?? 0, (string) ( $payout['currency'] ?? $currency ) ),
                esc_html( (string) $payout['status'] ),
            ];
        }
        if ( $payout_rows ) {
            echo '<h2>Ödeme geçmişi</h2>';
            $this->table( [ 'Tarih', 'Tutar', 'Durum' ], $payout_rows );
        } else {
            echo '<h2>Ödeme geçmişi</h2><p>Kayıtlı ödeme bulunamadı. Ödeme yöntemi tanımlı değilse kazançlar birikir ama aktarılmaz.</p>';
        }

        $this->footer();
    }

    private function money( $amount, string $currency ): string {
        return esc_html( number_format_i18n( (float) $amount, 2 ) . ' ' . $currency );
    }

    private function run_button(): void {
        if ( ! current_user_can( CapabilityManager::capability() ) ) {
            return;
        }
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:0 0 16px">';
        wp_nonce_field( 'onkupon_agent_control' );
        echo '<input type="hidden" name="action" value="onkupon_agent_control">';
        echo '<input type="hidden" name="agent_action" value="refresh-earnings-now">';
        echo '<button type="submit" class="button button-primary">Kazançları şimdi çek</button>';
        echo '</form>';
    }
}
