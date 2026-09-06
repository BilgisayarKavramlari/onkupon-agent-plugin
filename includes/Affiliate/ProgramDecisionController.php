<?php
namespace OnKupon\Agent\Affiliate;

use OnKupon\Agent\Logging\Logger;
use WP_REST_Request;
use WP_REST_Response;

/**
 * E-postadaki tek tıklık onay bağlantılarını karşılar.
 *
 * Güvenlik: bağlantı tahmin edilemez 48 karakterlik bir jeton taşır, jeton tek
 * kullanımlıktır ve kullanıldığında silinir. Uç yalnızca "onayla/atla"
 * kararını kaydeder; ürün açmaz, para hareketi yapmaz, dışa istek atmaz.
 */
class ProgramDecisionController {

    public function register(): void {
        add_action(
            'rest_api_init',
            function (): void {
                register_rest_route(
                    'onkupon-agent/v1',
                    '/program-decision',
                    [
                        'methods' => 'GET',
                        'callback' => [ $this, 'handle' ],
                        'permission_callback' => '__return_true',
                        'args' => [
                            'token' => [
                                'required' => true,
                                'sanitize_callback' => 'sanitize_text_field',
                            ],
                            'decision' => [
                                'required' => true,
                                'validate_callback' => static fn( $value ): bool => in_array( $value, [ 'approve', 'reject' ], true ),
                            ],
                        ],
                    ]
                );
            }
        );
    }

    public function handle( WP_REST_Request $request ): WP_REST_Response {
        $token = (string) $request->get_param( 'token' );
        $decision = (string) $request->get_param( 'decision' );

        if ( strlen( $token ) < 32 ) {
            return $this->page( 'Geçersiz bağlantı.', false, 400 );
        }

        $repository = new MarketplaceProgramRepository();
        $program = $repository->find_by_token( $token );

        if ( ! $program ) {
            return $this->page( 'Bu bağlantı daha önce kullanılmış veya geçersiz.', false, 404 );
        }

        $coordinator = new ProgramApplicationCoordinator();
        $company_key = (string) $program['company_key'];

        if ( 'approve' === $decision ) {
            $coordinator->approve( $company_key );
            $message = sprintf( '"%s" onaylandı. Başvuru sayfasını açmak için aşağıdaki bağlantıyı kullanın.', (string) $program['name'] );
        } else {
            $coordinator->reject( $company_key );
            $message = sprintf( '"%s" atlandı. Bu program bir daha önerilmeyecek.', (string) $program['name'] );
        }

        ( new Logger() )->log( 'info', 'affiliate', 'Program decision link used', [ 'company_key' => $company_key, 'decision' => $decision ] );

        return $this->page( $message, 'approve' === $decision, 200, (string) $program['listing_url'] );
    }

    private function page( string $message, bool $success, int $status, string $listing_url = '' ): WP_REST_Response {
        $html = '<!doctype html><html lang="tr"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1"><title>OnKupon Agent</title></head>'
            . '<body style="font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:#f6f8fa;margin:0;padding:48px 16px">'
            . '<div style="max-width:520px;margin:0 auto;background:#fff;border:1px solid #d0d7de;border-radius:12px;padding:32px">'
            . '<div style="font-size:32px;line-height:1;margin-bottom:12px">' . ( $success ? '&#9989;' : '&#8505;' ) . '</div>'
            . '<p style="font-size:15px;line-height:1.6;color:#1f2328;margin:0 0 20px">' . esc_html( $message ) . '</p>';

        if ( $success && '' !== $listing_url ) {
            $html .= '<p style="margin:0 0 20px"><a href="' . esc_url( $listing_url ) . '" style="display:inline-block;background:#1f883d;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none;font-weight:600">PartnerStack başvuru sayfasını aç</a></p>';
        }

        $html .= '<p style="margin:0"><a href="' . esc_url( admin_url( 'admin.php?page=onkupon-agent-program-discovery' ) ) . '" style="color:#0969da;font-size:14px">Keşif paneline dön</a></p>'
            . '</div></body></html>';

        $response = new WP_REST_Response( $html, $status );
        $response->header( 'Content-Type', 'text/html; charset=utf-8' );
        return $response;
    }
}
