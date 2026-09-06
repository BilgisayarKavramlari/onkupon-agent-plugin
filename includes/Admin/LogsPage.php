<?php
namespace OnKupon\Agent\Admin;

class LogsPage extends BasePage {
    public function render(): void {
        $rows = array_map( static fn( $r ) => [ $r['created_at'] ?? '', $r['level'] ?? '', $r['channel'] ?? '', esc_html( wp_trim_words( $r['message'] ?? '', 20 ) ), '<code>' . esc_html( wp_trim_words( $r['context_json'] ?? '{}', 20 ) ) . '</code>' ], $this->recent_rows( 'onkupon_agent_logs', 50 ) );
        $this->header( __( 'Logs', 'onkupon-agent' ) );
        $this->table( [ 'Date', 'Level', 'Channel', 'Message', 'Context' ], $rows );
        $this->footer();
    }
}
