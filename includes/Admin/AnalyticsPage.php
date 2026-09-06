<?php
namespace OnKupon\Agent\Admin;

class AnalyticsPage extends BasePage {
    public function render(): void {
        $rows = array_map( static fn( $r ) => [ $r['measured_at'] ?? '', $r['object_type'] ?? '', $r['object_id'] ?? '', $r['platform'] ?? '', $r['metric_name'] ?? '', $r['metric_value'] ?? 0 ], $this->recent_rows( 'onkupon_agent_metrics' ) );
        $this->header( __( 'Analytics', 'onkupon-agent' ) );
        echo '<p>' . esc_html__( 'Stores internal metrics and future GA4/Search Console/WooCommerce attribution signals.', 'onkupon-agent' ) . '</p>';
        $this->table( [ 'Measured', 'Object Type', 'Object ID', 'Platform', 'Metric', 'Value' ], $rows );
        $this->footer();
    }
}
