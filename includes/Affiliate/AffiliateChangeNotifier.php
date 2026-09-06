<?php
namespace OnKupon\Agent\Affiliate;

use OnKupon\Agent\Logging\ActionTimelineRepository;
use OnKupon\Agent\Plugin;

class AffiliateChangeNotifier {
    public function notify( array $summary ): array {
        $product_groups = [
            'Yeni ürünler' => array_map( 'absint', (array) ( $summary['created_product_ids'] ?? [] ) ),
            'Görünmez hale getirilen ürünler' => array_map( 'absint', (array) ( $summary['deactivated_product_ids'] ?? [] ) ),
            'Yeniden etkinleşen ürünler' => array_map( 'absint', (array) ( $summary['reactivated_product_ids'] ?? [] ) ),
        ];
        $event_count = array_sum( array_map( 'count', $product_groups ) );
        if ( 0 === $event_count ) {
            return [ 'status' => 'no_changes', 'sent' => false, 'event_count' => 0 ];
        }
        return $this->send( '[OnKupon Agent] PartnerStack ürün değişikliği', $product_groups, 'change', $event_count );
    }

    public function send_inventory( array $product_ids = [] ): array {
        if ( empty( $product_ids ) ) {
            $product_ids = array_map(
                'absint',
                get_posts(
                    [
                        'post_type' => 'product',
                        'post_status' => [ 'publish', 'draft', 'private', 'pending' ],
                        'posts_per_page' => -1,
                        'fields' => 'ids',
                        'meta_key' => '_onkupon_affiliate_provider',
                        'meta_value' => 'partnerstack',
                        'orderby' => 'title',
                        'order' => 'ASC',
                        'no_found_rows' => true,
                    ]
                )
            );
        }
        return $this->send(
            '[OnKupon Agent] PartnerStack ürün sayfaları',
            [ 'Güncel ürün listesi' => $product_ids ],
            'inventory',
            count( $product_ids )
        );
    }

    private function send( string $subject, array $product_groups, string $kind, int $event_count ): array {
        $settings = Plugin::settings();
        if ( empty( $settings['partnerstack_notifications_enabled'] ) ) {
            return [ 'status' => 'disabled', 'sent' => false, 'event_count' => $event_count ];
        }
        $recipient = sanitize_email( (string) ( $settings['partnerstack_notification_email'] ?? '' ) );
        if ( ! is_email( $recipient ) ) {
            $recipient = sanitize_email( (string) get_option( 'admin_email', '' ) );
        }
        if ( ! is_email( $recipient ) ) {
            return [ 'status' => 'missing_recipient', 'sent' => false, 'event_count' => $event_count ];
        }

        $lines = [
            'OnKupon PartnerStack ajanı bir ürün güncellemesi tamamladı.',
            'Tarih: ' . current_time( 'mysql' ),
            '',
        ];
        foreach ( $product_groups as $heading => $product_ids ) {
            $product_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $product_ids ) ) ) );
            if ( empty( $product_ids ) ) {
                continue;
            }
            $lines[] = $heading . ' (' . count( $product_ids ) . ')';
            foreach ( $product_ids as $product_id ) {
                $lines = array_merge( $lines, $this->product_lines( $product_id ) );
            }
            $lines[] = '';
        }
        $lines[] = 'Yönetim ekranı: ' . admin_url( 'admin.php?page=onkupon-agent-affiliates' );
        // wp_mail yalnızca false döndürür; asıl neden wp_mail_failed ile gelir.
        $failure = '';
        $capture = static function ( $wp_error ) use ( &$failure ): void {
            $failure = is_wp_error( $wp_error ) ? $wp_error->get_error_message() : 'unknown';
        };
        add_action( 'wp_mail_failed', $capture );
        $sent = (bool) wp_mail( $recipient, $subject, implode( "\n", $lines ), [ 'Content-Type: text/plain; charset=UTF-8' ] );
        remove_action( 'wp_mail_failed', $capture );

        $result = [ 'status' => $sent ? 'sent' : 'failed', 'sent' => $sent, 'event_count' => $event_count, 'kind' => $kind, 'error' => $failure ];
        update_option( 'onkupon_partnerstack_last_notification', [ 'at' => current_time( 'mysql' ), 'status' => $result['status'], 'event_count' => $event_count, 'kind' => $kind, 'error' => $failure ], false );
        if ( ! $sent ) {
            ( new \OnKupon\Agent\Logging\Logger() )->log( 'error', 'affiliate', 'PartnerStack bildirim e-postası gönderilemedi', [ 'recipient' => $recipient, 'wp_mail_error' => $failure ] );
        }
        ( new ActionTimelineRepository() )->record(
            'affiliate_notification',
            $sent ? 'completed' : 'failed',
            [ 'notes' => $sent ? 'PartnerStack product notification sent' : 'PartnerStack product notification could not be queued', 'metadata' => [ 'event_count' => $event_count, 'kind' => $kind ] ]
        );
        return $result;
    }

    private function product_lines( int $product_id ): array {
        $title = html_entity_decode( get_the_title( $product_id ), ENT_QUOTES, get_bloginfo( 'charset' ) ?: 'UTF-8' );
        $status = get_post_status( $product_id ) ?: 'unknown';
        $page_url = 'publish' === $status ? get_permalink( $product_id ) : get_preview_post_link( $product_id );
        return [
            '- ' . $title . ' [' . $status . ']',
            '  Sayfa: ' . esc_url_raw( (string) $page_url ),
            '  Düzenle: ' . admin_url( 'post.php?post=' . $product_id . '&action=edit' ),
        ];
    }
}
