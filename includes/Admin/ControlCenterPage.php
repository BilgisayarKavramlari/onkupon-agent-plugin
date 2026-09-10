<?php
namespace OnKupon\Agent\Admin;

class ControlCenterPage extends BasePage {
    public function render(): void {
        $actions = [ 'start', 'pause', 'resume', 'stop', 'emergency-stop', 'run-now', 'run-diagnostics', 'reschedule-all-jobs', 'clear-onkupon-jobs', 'run-product-scan-now', 'run-research-now', 'run-content-generation-now', 'run-publishing-now', 'run-social-queue-now', 'run-metrics-now', 'run-learning-now', 'run-affiliate-sync-now', 'run-affiliate-enrichment-now', 'run-revenue-link-audit-now', 'run-seo-health-now', 'run-content-generation-debug', 'test-social-queue', 'collect-metrics', 'recalculate-scores', 'reset-failed', 'clear-locks', 'safe-mode' ];
        $this->header( __( 'Control Center', 'onkupon-agent' ) );
        echo '<p>' . esc_html__( 'All controls require administrator capability, nonce verification, and audit logging.', 'onkupon-agent' ) . '</p><div class="oka-controls">';
        foreach ( $actions as $action ) {
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
            wp_nonce_field( 'onkupon_agent_control' );
            echo '<input type="hidden" name="action" value="onkupon_agent_control">';
            echo '<input type="hidden" name="agent_action" value="' . esc_attr( $action ) . '">';
            echo '<button class="button button-primary">' . esc_html( ucwords( str_replace( '-', ' ', $action ) ) ) . '</button>';
            echo '</form>';
        }
        echo '</div>';
        $this->footer();
    }
}
