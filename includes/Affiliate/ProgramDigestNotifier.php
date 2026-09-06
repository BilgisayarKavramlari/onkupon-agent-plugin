<?php
namespace OnKupon\Agent\Affiliate;

use OnKupon\Agent\Logging\ActionTimelineRepository;
use OnKupon\Agent\Logging\Logger;
use OnKupon\Agent\Plugin;

/**
 * Onay bekleyen programları tek tıklık bağlantılarla e-postalar.
 *
 * Bu, sistemin insanla tek düzenli teması. Bekleyen bir şey yoksa e-posta
 * gönderilmez; sessizlik de bir bilgidir.
 */
class ProgramDigestNotifier {

    public const LAST_OPTION = 'onkupon_partnerstack_last_program_digest';

    /**
     * @return array{status:string,sent:bool,pending:int}
     */
    public function send_if_pending(): array {
        $settings = Plugin::settings();
        $pending = ( new MarketplaceProgramRepository() )->by_decision( 'pending', 25 );

        if ( empty( $pending ) ) {
            return [ 'status' => 'no_pending', 'sent' => false, 'pending' => 0 ];
        }
        if ( empty( $settings['partnerstack_notifications_enabled'] ) ) {
            return [ 'status' => 'disabled', 'sent' => false, 'pending' => count( $pending ) ];
        }

        $recipient = sanitize_email( (string) ( $settings['partnerstack_notification_email'] ?? '' ) );
        if ( ! is_email( $recipient ) ) {
            $recipient = sanitize_email( (string) get_option( 'admin_email', '' ) );
        }
        if ( ! is_email( $recipient ) ) {
            return [ 'status' => 'missing_recipient', 'sent' => false, 'pending' => count( $pending ) ];
        }

        // Aynı listeyi her turda tekrar göndermemek için parmak izi.
        $fingerprint = md5( implode( '|', array_column( $pending, 'company_key' ) ) );
        $last = get_option( self::LAST_OPTION, [] );
        if ( is_array( $last ) && ( $last['fingerprint'] ?? '' ) === $fingerprint && 'sent' === ( $last['status'] ?? '' ) ) {
            return [ 'status' => 'already_sent', 'sent' => false, 'pending' => count( $pending ) ];
        }

        $subject = sprintf( '[OnKupon Agent] %d yeni iş ortaklığı programı onay bekliyor', count( $pending ) );
        $failure = '';
        $capture = static function ( $wp_error ) use ( &$failure ): void {
            $failure = is_wp_error( $wp_error ) ? $wp_error->get_error_message() : 'unknown';
        };
        add_action( 'wp_mail_failed', $capture );
        $sent = (bool) wp_mail( $recipient, $subject, $this->body( $pending ), [ 'Content-Type: text/html; charset=UTF-8' ] );
        remove_action( 'wp_mail_failed', $capture );

        $status = $sent ? 'sent' : 'failed';
        update_option(
            self::LAST_OPTION,
            [ 'at' => current_time( 'mysql' ), 'status' => $status, 'pending' => count( $pending ), 'fingerprint' => $fingerprint, 'error' => $failure ],
            false
        );

        if ( ! $sent ) {
            ( new Logger() )->log( 'error', 'affiliate', 'Program digest e-postası gönderilemedi', [ 'recipient' => $recipient, 'wp_mail_error' => $failure ] );
        }
        ( new ActionTimelineRepository() )->record(
            'program_digest',
            $sent ? 'completed' : 'failed',
            [ 'notes' => $sent ? 'Program digest sent' : 'Program digest failed: ' . $failure, 'metadata' => [ 'pending' => count( $pending ) ] ]
        );

        return [ 'status' => $status, 'sent' => $sent, 'pending' => count( $pending ) ];
    }

    private function body( array $pending ): string {
        $html = '<div style="font-family:system-ui,-apple-system,Segoe UI,sans-serif;font-size:14px;line-height:1.6;color:#1f2328;max-width:680px">';
        $html .= '<h2 style="margin:0 0 4px">Onay bekleyen programlar</h2>';
        $html .= '<p style="margin:0 0 18px;color:#57606a">' . esc_html( wp_date( 'j F Y' ) ) . ' · PartnerStack marketplace taraması</p>';
        $html .= '<table style="width:100%;border-collapse:collapse;font-size:13px">';
        $html .= '<tr style="background:#f6f8fa">'
            . '<th align="left" style="padding:8px;border:1px solid #d0d7de">Program</th>'
            . '<th align="right" style="padding:8px;border:1px solid #d0d7de">Skor</th>'
            . '<th align="left" style="padding:8px;border:1px solid #d0d7de">Aksiyon</th></tr>';

        foreach ( $pending as $program ) {
            $token = (string) $program['approval_token'];
            $html .= '<tr>';
            $html .= '<td style="padding:8px;border:1px solid #d0d7de"><strong>' . esc_html( (string) $program['name'] ) . '</strong>'
                . '<br><span style="color:#57606a">' . esc_html( wp_trim_words( (string) $program['decision_reason'], 18 ) ) . '</span></td>';
            $html .= '<td align="right" style="padding:8px;border:1px solid #d0d7de"><strong>' . esc_html( number_format_i18n( (float) $program['score'], 1 ) ) . '</strong></td>';
            $html .= '<td style="padding:8px;border:1px solid #d0d7de">'
                . '<a href="' . esc_url( $this->action_url( $token, 'approve' ) ) . '" style="color:#1a7f37;font-weight:600;text-decoration:none">Onayla</a>'
                . ' &nbsp;·&nbsp; '
                . '<a href="' . esc_url( $this->action_url( $token, 'reject' ) ) . '" style="color:#cf222e;text-decoration:none">Atla</a>';
            if ( ! empty( $program['listing_url'] ) ) {
                $html .= '<br><a href="' . esc_url( (string) $program['listing_url'] ) . '" style="color:#0969da;font-size:12px">PartnerStack sayfası</a>';
            }
            $html .= '</td></tr>';
        }

        $html .= '</table>';
        $html .= '<p style="margin-top:24px"><a href="' . esc_url( admin_url( 'admin.php?page=onkupon-agent-program-discovery' ) ) . '" style="color:#0969da">Keşif paneline git</a></p>';
        $html .= '</div>';

        return $html;
    }

    private function action_url( string $token, string $action ): string {
        return add_query_arg(
            [ 'token' => $token, 'decision' => $action ],
            rest_url( 'onkupon-agent/v1/program-decision' )
        );
    }
}
