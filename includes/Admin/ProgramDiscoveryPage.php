<?php
namespace OnKupon\Agent\Admin;

use OnKupon\Agent\Affiliate\MarketplaceProgramRepository;
use OnKupon\Agent\Affiliate\ProgramDigestNotifier;
use OnKupon\Agent\Plugin;
use OnKupon\Agent\Security\CapabilityManager;

class ProgramDiscoveryPage extends BasePage {

    private const TABS = [
        'pending'         => 'Onay bekleyen',
        'approved'        => 'Onaylanan',
        'joined'          => 'Katılınan',
        'below_threshold' => 'Eşik altı',
        'rejected'        => 'Atlanan',
        'blocked'         => 'Yasaklı kategori',
    ];

    public function render(): void {
        $this->header( 'Program Keşfi' );

        $settings = Plugin::settings();
        $repository = new MarketplaceProgramRepository();
        $counts = $repository->counts();
        $last_run = get_option( 'onkupon_partnerstack_last_discovery', [] );
        $last_digest = get_option( ProgramDigestNotifier::LAST_OPTION, [] );

        echo '<p class="description">PartnerStack Partner API salt okunurdur; başvuru oluşturan uç Vendor API\'ye aittir ve bir partner tarafından çağrılamaz. '
            . 'Bu yüzden keşif, skorlama ve karar tamamen otomatiktir; başvurunun kendisi için program başına tek bir onay gerekir.</p>';

        $this->card_grid(
            [
                'Keşif' => empty( $settings['partnerstack_discovery_enabled'] ) ? 'kapalı' : 'açık',
                'Skor eşiği' => (string) ( $settings['partnerstack_min_program_score'] ?? 65 ),
                'Günlük onay limiti' => (string) ( $settings['partnerstack_discovery_daily_limit'] ?? 5 ),
                'Onay bekleyen' => (string) ( $counts['pending'] ?? 0 ),
                'Onaylanan' => (string) ( $counts['approved'] ?? 0 ),
                'Eşik altı' => (string) ( $counts['below_threshold'] ?? 0 ),
                'Son tarama' => is_array( $last_run ) ? (string) ( $last_run['at'] ?? '—' ) : '—',
                'Son özet e-postası' => is_array( $last_digest ) ? (string) ( $last_digest['status'] ?? '—' ) : '—',
            ]
        );

        if ( is_array( $last_run ) && isset( $last_run['ok'] ) && ! $last_run['ok'] ) {
            echo '<div class="notice notice-error"><p>Son tarama başarısız: ' . esc_html( (string) ( $last_run['error'] ?? '' ) ) . '</p></div>';
        }
        if ( is_array( $last_digest ) && 'failed' === ( $last_digest['status'] ?? '' ) ) {
            echo '<div class="notice notice-error"><p>Son özet e-postası gönderilemedi. wp_mail hatası: <code>'
                . esc_html( (string) ( $last_digest['error'] ?? 'bilinmiyor' ) ) . '</code></p></div>';
        }

        $this->run_button();

        $tab = sanitize_key( (string) ( $_GET['tab'] ?? 'pending' ) );
        if ( ! isset( self::TABS[ $tab ] ) ) {
            $tab = 'pending';
        }

        echo '<h2 class="nav-tab-wrapper">';
        foreach ( self::TABS as $slug => $label ) {
            printf(
                '<a href="%s" class="nav-tab %s">%s (%d)</a>',
                esc_url( admin_url( 'admin.php?page=onkupon-agent-program-discovery&tab=' . $slug ) ),
                $tab === $slug ? 'nav-tab-active' : '',
                esc_html( $label ),
                (int) ( $counts[ $slug ] ?? 0 )
            );
        }
        echo '</h2>';

        $rows = [];
        foreach ( $repository->by_decision( $tab, 200 ) as $program ) {
            $breakdown = json_decode( (string) $program['score_breakdown'], true );
            $detail = '';
            if ( is_array( $breakdown ) ) {
                $parts = [];
                foreach ( $breakdown as $key => $value ) {
                    $parts[] = esc_html( $key . ': ' . $value );
                }
                $detail = '<br><span style="color:#646970;font-size:11px">' . implode( ' · ', $parts ) . '</span>';
            }

            $actions = 'pending' === $tab ? $this->decision_buttons( (string) $program['company_key'] ) : '';
            if ( ! empty( $program['listing_url'] ) ) {
                $actions .= '<br><a href="' . esc_url( (string) $program['listing_url'] ) . '" target="_blank" rel="noreferrer noopener">PartnerStack sayfası</a>';
            }

            $rows[] = [
                '<strong>' . esc_html( (string) $program['name'] ) . '</strong>' . $detail,
                esc_html( (string) $program['category'] ),
                esc_html( number_format_i18n( (float) $program['score'], 1 ) ),
                esc_html( wp_trim_words( (string) $program['decision_reason'], 22 ) ),
                esc_html( (string) $program['first_seen_at'] ),
                $actions,
            ];
        }

        $this->table( [ 'Program', 'Kategori', 'Skor', 'Gerekçe', 'İlk görülme', 'İşlem' ], $rows );

        $this->footer();
    }

    private function run_button(): void {
        echo '<p>';
        $this->control_form( 'run-marketplace-discovery-now', 'Marketplace taramasını şimdi çalıştır', [], 'button button-primary' );
        $this->control_form( 'run-marketplace-full-rescan', 'Tam yeniden tarama (son 180 gün)' );
        $this->control_form( 'send-program-digest', 'Onay e-postasını gönder' );
        echo '</p>';
    }

    private function decision_buttons( string $company_key ): string {
        ob_start();
        $this->control_form( 'approve-program', 'Onayla', [ 'company_key' => $company_key ], 'button button-primary button-small' );
        $this->control_form( 'reject-program', 'Atla', [ 'company_key' => $company_key ], 'button button-small' );
        return (string) ob_get_clean();
    }

    private function control_form( string $action, string $label, array $extra = [], string $class = 'button' ): void {
        if ( ! current_user_can( CapabilityManager::capability() ) ) {
            return;
        }
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:0 6px 6px 0">';
        wp_nonce_field( 'onkupon_agent_control' );
        echo '<input type="hidden" name="action" value="onkupon_agent_control">';
        echo '<input type="hidden" name="agent_action" value="' . esc_attr( $action ) . '">';
        foreach ( $extra as $name => $value ) {
            echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '">';
        }
        echo '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button>';
        echo '</form>';
    }
}
