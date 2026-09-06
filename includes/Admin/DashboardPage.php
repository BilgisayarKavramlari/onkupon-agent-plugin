<?php
namespace OnKupon\Agent\Admin;

use OnKupon\Agent\AI\ProviderFactory;
use OnKupon\Agent\Scheduler\SchedulerDiagnostics;

class DashboardPage extends BasePage {
    public function render(): void {
        global $wpdb;
        $today = current_time( 'Y-m-d' );
        $articles = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='post' AND post_status='publish' AND post_date >= %s AND ID IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_onkupon_agent_generated')", $today . ' 00:00:00' ) );
        $social = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}onkupon_agent_social_queue WHERE status='published' AND published_at >= %s", $today . ' 00:00:00' ) );
        $errors = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}onkupon_agent_logs WHERE level IN ('error','warning') AND created_at >= %s", $today . ' 00:00:00' ) );
        $cost = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(estimated_cost),0) FROM {$wpdb->prefix}onkupon_agent_costs WHERE created_at >= %s", $today . ' 00:00:00' ) );
        $orders_30d = $this->latest_metric( 'orders_30d' );
        $revenue_30d = $this->latest_metric( 'net_revenue_30d' );
        $affiliate_products = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key='_onkupon_affiliate_provider' AND meta_value<>''" );
        $affiliate_clicks_30d = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(metric_value),0) FROM {$wpdb->prefix}onkupon_agent_metrics WHERE object_type='affiliate_product' AND metric_name='outbound_click' AND measured_at >= %s", gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) );
        $seo_health = (array) get_option( 'onkupon_seo_health_last', [] );
        $diagnostics = ( new SchedulerDiagnostics() )->report();
        $this->header( __( 'OnKupon Agent Overview', 'onkupon-agent' ) );
        $this->card_grid( [
            'Agent status' => $this->status(),
            'Safe mode' => ! empty( \OnKupon\Agent\Plugin::settings()['safe_mode'] ) ? 'on' : 'off',
            'Action Scheduler' => ! empty( $diagnostics['action_scheduler_available'] ) ? 'available' : 'missing',
            'WP-Cron' => ! empty( $diagnostics['wp_cron_disabled'] ) ? 'disabled' : 'enabled',
            'Pending OnKupon jobs' => absint( $diagnostics['totals']['pending'] ?? 0 ),
            'Articles today' => $articles,
            'Social posts today' => $social,
            'Failed/warning logs today' => $errors,
            'Estimated AI/API cost today' => '$' . number_format_i18n( $cost, 4 ),
            'WooCommerce orders / 30 days' => number_format_i18n( $orders_30d, 0 ),
            'WooCommerce net revenue / 30 days' => function_exists( 'wc_price' ) ? wp_strip_all_tags( wc_price( $revenue_30d ) ) : number_format_i18n( $revenue_30d, 2 ),
            'Revenue-linked products' => $affiliate_products,
            'Affiliate outbound clicks / 30 days' => number_format_i18n( $affiliate_clicks_30d, 0 ),
            'SEO health' => isset( $seo_health['score'] ) ? absint( $seo_health['score'] ) . '/100' : 'not checked',
            'AI provider' => ProviderFactory::label(),
            'AI connection' => ( ProviderFactory::health()['ok'] ? 'ok' : 'FAILING: ' . ProviderFactory::health()['message'] ),
            'WooCommerce active' => class_exists( 'WooCommerce' ) ? 'yes' : 'no',
            'AIOSEO detected' => ( defined( 'AIOSEO_VERSION' ) || function_exists( 'aioseo' ) ) ? 'yes' : 'no',
        ] );
        echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=onkupon-agent-scheduler-health' ) ) . '">' . esc_html__( 'Open Scheduler Health / System Check', 'onkupon-agent' ) . '</a></p>';
        echo '<h2>' . esc_html__( 'Last 7 Days', 'onkupon-agent' ) . '</h2><canvas id="okaChart" height="90"></canvas>';
        $this->footer();
    }

    private function latest_metric( string $name ): float {
        global $wpdb;
        return (float) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT metric_value FROM {$wpdb->prefix}onkupon_agent_metrics WHERE object_type='commerce' AND platform='woocommerce' AND metric_name=%s ORDER BY measured_at DESC, id DESC LIMIT 1",
                $name
            )
        );
    }
}
