<?php
namespace OnKupon\Agent\Admin;

class ReviewIntegrityPage extends BasePage {
    public function render(): void {
        $rows = array_map( static fn( $r ) => [ $r['created_at'] ?? '', $r['order_id'] ?? '', $r['product_id'] ?? '', $r['status'] ?? '', $r['request_count'] ?? 0, $r['last_requested_at'] ?? '' ], $this->recent_rows( 'onkupon_agent_review_requests' ) );
        $this->header( __( 'Review Integrity', 'onkupon-agent' ) );
        echo '<div class="notice notice-info"><p>' . esc_html__( 'This module never creates fake customer reviews or ratings. It supports verified-buyer review requests and clearly labeled editorial product insights only.', 'onkupon-agent' ) . '</p></div>';
        $this->table( [ 'Created', 'Order', 'Product', 'Status', 'Requests', 'Last Requested' ], $rows );
        $this->footer();
    }
}
