<?php
namespace OnKupon\Agent\Admin;

class SocialQueuePage extends BasePage {
    public function render(): void {
        $rows = array_map( static fn( $r ) => [ $r['platform'] ?? '', $r['status'] ?? '', $r['scheduled_at'] ?? '', $r['published_at'] ?? '', esc_html( wp_trim_words( $r['message'] ?? '', 16 ) ), esc_url( $r['remote_url'] ?? '' ), esc_html( $r['last_error'] ?? '' ) ], $this->recent_rows( 'onkupon_agent_social_queue' ) );
        $this->header( __( 'Social Queue', 'onkupon-agent' ) );
        $this->table( [ 'Platform', 'Status', 'Scheduled', 'Published', 'Message', 'Remote URL', 'Error' ], $rows );
        $this->footer();
    }
}
