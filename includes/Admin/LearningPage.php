<?php
namespace OnKupon\Agent\Admin;

class LearningPage extends BasePage {
    public function render(): void {
        $rows = array_map( static fn( $r ) => [ $r['dimension'] ?? '', $r['variant_key'] ?? '', $r['weight'] ?? 0, $r['impressions'] ?? 0, $r['clicks'] ?? 0, $r['conversions'] ?? 0, $r['updated_at'] ?? '' ], $this->recent_rows( 'onkupon_agent_learning_weights' ) );
        $this->header( __( 'Learning', 'onkupon-agent' ) );
        $this->table( [ 'Dimension', 'Variant', 'Weight', 'Impressions', 'Clicks', 'Conversions', 'Updated' ], $rows );
        $this->footer();
    }
}
