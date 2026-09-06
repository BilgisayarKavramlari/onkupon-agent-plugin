<?php
namespace OnKupon\Agent\Admin;

class ProductIntelligencePage extends BasePage {
    public function render(): void {
        $rows = array_map( static fn( $r ) => [ esc_html( $r['product_name'] ?? '' ), esc_url( $r['product_url'] ?? '' ), $r['product_status'] ?? '', $r['content_score'] ?? 0, $r['trend_score'] ?? 0, $r['revenue_score'] ?? 0, $r['updated_at'] ?? '' ], $this->recent_rows( 'onkupon_agent_product_scores' ) );
        $this->header( __( 'Product Intelligence', 'onkupon-agent' ) );
        $this->table( [ 'Product', 'URL', 'Status', 'Content', 'Trend', 'Revenue', 'Updated' ], $rows );
        $this->footer();
    }
}
