<?php
namespace OnKupon\Agent\Admin;

use OnKupon\Agent\Affiliate\PartnerStackClient;
use OnKupon\Agent\Security\CapabilityManager;
use OnKupon\Agent\Security\SecretsManager;
use OnKupon\Agent\Social\OAuth\LinkedInOAuthProvider;
use OnKupon\Agent\Social\OAuth\SocialOAuthManager;
use OnKupon\Agent\Social\OAuth\XOAuthProvider;

class IntegrationsPage extends BasePage {
    private array $notices = [];

    public function render(): void {
        $this->handle_post();
        $this->header( __( 'Integrations', 'onkupon-agent' ) );
        foreach ( $this->notices as $notice ) {
            echo '<div class="notice notice-' . esc_attr( $notice['type'] ) . '"><p>' . esc_html( $notice['message'] ) . '</p></div>';
        }

        $oauth = get_option( 'onkupon_agent_social_oauth', [] );
        $oauth = is_array( $oauth ) ? $oauth : [];
        $secrets = new SecretsManager();
        $linkedin = new LinkedInOAuthProvider();
        $x = new XOAuthProvider();

        echo '<h2>' . esc_html__( 'Social Accounts', 'onkupon-agent' ) . '</h2>';
        $rows = [];
        foreach ( [
            'linkedin' => [ 'label' => 'LinkedIn', 'provider' => $linkedin, 'redirect' => rest_url( 'onkupon-agent/v1/oauth/linkedin/callback' ) ],
            'x' => [ 'label' => 'X', 'provider' => $x, 'redirect' => rest_url( 'onkupon-agent/v1/oauth/x/callback' ) ],
        ] as $provider => $config ) {
            $status = SocialOAuthManager::masked_status( $provider );
            $configured = $config['provider']->configured();
            $url = $configured ? $config['provider']->authorization_url() : '';
            $actions = $configured && $url
                ? '<a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Connect', 'onkupon-agent' ) . '</a>'
                : '<button class="button" disabled>' . esc_html__( 'Save credentials first', 'onkupon-agent' ) . '</button>';
            if ( $status['connected'] ) {
                $actions .= $this->disconnect_form( $provider );
            }
            $rows[] = [
                esc_html( $config['label'] ),
                $status['connected'] ? 'connected' : 'not connected',
                esc_html( $status['token'] ),
                $configured ? 'ready' : 'credentials missing',
                '<code>' . esc_html( $config['redirect'] ) . '</code>',
                $actions,
            ];
        }
        $this->table( [ 'Provider', 'Token status', 'Masked token', 'Configuration', 'Redirect URI', 'Actions' ], $rows );
        echo '<p>' . esc_html__( 'OAuth/API integrations use official APIs only. Passwords, browser cookies and scraping-based posting are never used. Secrets and OAuth tokens entered below are encrypted at rest and never displayed or logged.', 'onkupon-agent' ) . '</p>';

        echo '<div class="oka-cards">';
        $this->linkedin_form( $oauth['linkedin'] ?? [], $secrets );
        $this->x_form( $oauth['x'] ?? [], $secrets );
        $this->partnerstack_form( $secrets );
        $this->anthropic_form( $secrets );
        echo '</div>';

        echo '<h2>' . esc_html__( 'Other Integrations', 'onkupon-agent' ) . '</h2><ul class="oka-integration-list">';
        foreach ( [ 'OpenAI-compatible provider', 'RSS and official search APIs', 'Facebook Page', 'Instagram', 'Manual Quora suggestions', 'Google Analytics 4', 'Search Console', 'WooCommerce review requests' ] as $section ) {
            echo '<li><strong>' . esc_html( $section ) . '</strong> — ' . esc_html__( 'Configure related secrets through constants/environment variables or Settings.', 'onkupon-agent' ) . '</li>';
        }
        $partnerstack = new PartnerStackClient();
        echo '<li><strong>PartnerStack</strong> — ' . esc_html( $partnerstack->configured() ? 'API key configured. Scheduled sync can be enabled in Settings.' : 'API key missing. Save it in the encrypted field above or define ONKUPON_AGENT_PARTNERSTACK_API_KEY on the server.' ) . '</li>';
        echo '</ul>';
        $this->footer();
    }

    private function handle_post(): void {
        if ( empty( $_REQUEST['onkupon_integration_action'] ) || ! current_user_can( CapabilityManager::capability() ) ) {
            return;
        }
        check_admin_referer( 'onkupon_agent_integrations' );
        $action = sanitize_key( wp_unslash( $_REQUEST['onkupon_integration_action'] ) );
        if ( 'disconnect' === $action ) {
            $provider = sanitize_key( wp_unslash( $_REQUEST['provider'] ?? '' ) );
            SocialOAuthManager::disconnect( $provider );
            $this->notices[] = [ 'type' => 'success', 'message' => ucfirst( $provider ) . ' disconnected and automatic publishing disabled.' ];
            return;
        }

        $oauth = get_option( 'onkupon_agent_social_oauth', [] );
        $oauth = is_array( $oauth ) ? $oauth : [];
        $secrets = new SecretsManager();
        if ( 'save_linkedin' === $action ) {
            $oauth['linkedin']['client_id'] = sanitize_text_field( wp_unslash( $_POST['linkedin_client_id'] ?? '' ) );
            $oauth['linkedin']['author_urn'] = sanitize_text_field( wp_unslash( $_POST['linkedin_author_urn'] ?? '' ) );
            $mode = sanitize_key( wp_unslash( $_POST['linkedin_posting_mode'] ?? 'organization' ) );
            $oauth['linkedin']['posting_mode'] = in_array( $mode, [ 'member', 'organization' ], true ) ? $mode : 'organization';
            update_option( 'onkupon_agent_social_oauth', $oauth, false );
            $this->save_secret_if_supplied( $secrets, 'LINKEDIN_CLIENT_SECRET', 'linkedin_client_secret' );
            $this->notices[] = [ 'type' => 'success', 'message' => 'LinkedIn application settings saved. Use Connect after the configuration status becomes ready.' ];
        } elseif ( 'save_x' === $action ) {
            $oauth['x']['client_id'] = sanitize_text_field( wp_unslash( $_POST['x_client_id'] ?? '' ) );
            update_option( 'onkupon_agent_social_oauth', $oauth, false );
            $this->save_secret_if_supplied( $secrets, 'X_CLIENT_SECRET', 'x_client_secret' );
            $this->notices[] = [ 'type' => 'success', 'message' => 'X application settings saved. Use Connect after the configuration status becomes ready.' ];
        } elseif ( 'save_anthropic' === $action ) {
            $saved = $this->save_secret_if_supplied( $secrets, 'ANTHROPIC_API_KEY', 'anthropic_api_key' );
            if ( true === $saved ) {
                $this->notices[] = [ 'type' => 'success', 'message' => 'Anthropic anahtarı şifreli sır deposuna kaydedildi.' ];
                delete_transient( 'onkupon_agent_ai_health' );
            } elseif ( null === $saved ) {
                $this->notices[] = [ 'type' => 'warning', 'message' => 'Yeni bir Anthropic anahtarı verilmedi; mevcut değer korundu.' ];
            }
        } elseif ( 'save_partnerstack' === $action ) {
            $saved = $this->save_secret_if_supplied( $secrets, 'PARTNERSTACK_API_KEY', 'partnerstack_api_key' );
            if ( true === $saved ) {
                $this->notices[] = [ 'type' => 'success', 'message' => 'PartnerStack credential saved to the encrypted secret store.' ];
            } elseif ( null === $saved ) {
                $this->notices[] = [ 'type' => 'warning', 'message' => 'No new PartnerStack key was supplied; the existing value was left unchanged.' ];
            }
        }
    }

    private function save_secret_if_supplied( SecretsManager $secrets, string $key, string $field ): ?bool {
        $value = trim( (string) wp_unslash( $_POST[ $field ] ?? '' ) );
        if ( '' === $value ) {
            return null;
        }
        if ( ! $secrets->set( $key, $value ) ) {
            $this->notices[] = [ 'type' => 'error', 'message' => 'Secure storage failed. No plaintext fallback was written.' ];
            return false;
        }
        return true;
    }

    private function disconnect_form( string $provider ): string {
        $url = wp_nonce_url(
            add_query_arg(
                [ 'page' => 'onkupon-agent-integrations', 'onkupon_integration_action' => 'disconnect', 'provider' => $provider ],
                admin_url( 'admin.php' )
            ),
            'onkupon_agent_integrations'
        );
        return ' <a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Disconnect', 'onkupon-agent' ) . '</a>';
    }

    private function linkedin_form( array $settings, SecretsManager $secrets ): void {
        echo '<section><h2>LinkedIn</h2><form method="post">';
        wp_nonce_field( 'onkupon_agent_integrations' );
        echo '<input type="hidden" name="onkupon_integration_action" value="save_linkedin">';
        $this->text_field( 'linkedin_client_id', 'Client ID', (string) ( $settings['client_id'] ?? '' ) );
        $this->secret_field( 'linkedin_client_secret', 'Client Secret', $secrets->source( 'LINKEDIN_CLIENT_SECRET' ) );
        echo '<p><label><strong>Posting mode</strong><br><select name="linkedin_posting_mode">';
        foreach ( [ 'organization' => 'Company page', 'member' => 'Personal profile' ] as $value => $label ) {
            echo '<option value="' . esc_attr( $value ) . '" ' . selected( (string) ( $settings['posting_mode'] ?? 'organization' ), $value, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select></label></p>';
        $this->text_field( 'linkedin_author_urn', 'Author URN', (string) ( $settings['author_urn'] ?? '' ), 'urn:li:organization:123456' );
        echo '<p><button class="button button-primary" type="submit">Save LinkedIn settings</button></p></form></section>';
    }

    private function x_form( array $settings, SecretsManager $secrets ): void {
        echo '<section><h2>X</h2><form method="post">';
        wp_nonce_field( 'onkupon_agent_integrations' );
        echo '<input type="hidden" name="onkupon_integration_action" value="save_x">';
        $this->text_field( 'x_client_id', 'OAuth 2.0 Client ID', (string) ( $settings['client_id'] ?? '' ) );
        $this->secret_field( 'x_client_secret', 'Client Secret (confidential apps)', $secrets->source( 'X_CLIENT_SECRET' ) );
        echo '<p><small>Authorization Code Flow with PKCE is used. Public clients may leave the secret empty.</small></p>';
        echo '<p><button class="button button-primary" type="submit">Save X settings</button></p></form></section>';
    }

    private function anthropic_form( SecretsManager $secrets ): void {
        $settings = \OnKupon\Agent\Plugin::settings();
        $active = 'anthropic' === ( $settings['ai_provider'] ?? 'openai' );
        echo '<section><h2>Anthropic (Claude)</h2><form method="post">';
        wp_nonce_field( 'onkupon_agent_integrations' );
        echo '<input type="hidden" name="onkupon_integration_action" value="save_anthropic">';
        $this->secret_field( 'anthropic_api_key', 'Anthropic API key', $secrets->source( 'ANTHROPIC_API_KEY' ) );
        echo '<p><small>Aktif sağlayıcı: <strong>' . esc_html( $active ? 'Anthropic' : 'OpenAI-compatible' ) . '</strong>. '
            . 'Sağlayıcıyı Settings → Ai Provider alanından değiştirebilirsiniz. Katı JSON çıktısı tool-use ile zorlanır; '
            . 'OpenAI uyumluluk katmanı kullanılmaz.</small></p>';
        echo '<p><button class="button button-primary" type="submit">Save Anthropic key</button></p></form></section>';
    }

    private function partnerstack_form( SecretsManager $secrets ): void {
        echo '<section><h2>PartnerStack</h2><form method="post">';
        wp_nonce_field( 'onkupon_agent_integrations' );
        echo '<input type="hidden" name="onkupon_integration_action" value="save_partnerstack">';
        $this->secret_field( 'partnerstack_api_key', 'Partner API key', $secrets->source( 'PARTNERSTACK_API_KEY' ) );
        echo '<p><small>Imports remain draft-first; scheduled synchronization stays disabled until explicitly enabled in Settings.</small></p>';
        echo '<p><button class="button button-primary" type="submit">Save PartnerStack key</button></p></form></section>';
    }

    private function text_field( string $name, string $label, string $value, string $placeholder = '' ): void {
        echo '<p><label><strong>' . esc_html( $label ) . '</strong><br><input class="regular-text" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr( $placeholder ) . '" autocomplete="off"></label></p>';
    }

    private function secret_field( string $name, string $label, string $source ): void {
        $status = 'missing' === $source ? 'not configured' : ( 'server' === $source ? 'server-managed' : 'encrypted and configured' );
        echo '<p><label><strong>' . esc_html( $label ) . '</strong><br><input class="regular-text" type="password" name="' . esc_attr( $name ) . '" value="" placeholder="Leave blank to keep current value" autocomplete="new-password"></label><br><small>Status: ' . esc_html( $status ) . '</small></p>';
    }
}
