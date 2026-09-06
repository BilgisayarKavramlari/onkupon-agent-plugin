<?php
namespace OnKupon\Agent\Admin;

use OnKupon\Agent\Scheduler\SchedulerDiagnostics;

class SchedulerHealthPage extends BasePage {
    public function render(): void {
        $report = ( new SchedulerDiagnostics() )->report();
        $this->header( __( 'Scheduler Health', 'onkupon-agent' ) );
        $this->card_grid(
            [
                'Action Scheduler available' => ! empty( $report['action_scheduler_available'] ) ? 'yes' : 'no',
                'WP-Cron disabled' => ! empty( $report['wp_cron_disabled'] ) ? 'yes' : 'no',
                'Pending jobs' => $report['totals']['pending'] ?? 0,
                'Failed jobs' => $report['totals']['failed'] ?? 0,
                'Past-due jobs' => $report['totals']['past_due'] ?? 0,
            ]
        );
        $rows = [];
        foreach ( $report['hooks'] as $hook => $row ) {
            $rows[] = [
                esc_html( $hook ),
                esc_html( $row['registered_callback'] ),
                esc_html( (string) $row['pending'] ),
                esc_html( (string) $row['complete'] ),
                esc_html( (string) $row['failed'] ),
                esc_html( (string) $row['canceled'] ),
                esc_html( $row['next_scheduled'] ),
                esc_html( $row['last_success'] ),
                esc_html( wp_trim_words( $row['last_failure'], 12 ) ),
                esc_html( $row['wp_cron_fallback'] ),
            ];
        }
        echo '<h2>' . esc_html__( 'Registered Hooks and Scheduled Actions', 'onkupon-agent' ) . '</h2>';
        $this->table( [ 'Hook', 'Callback', 'Pending', 'Complete', 'Failed', 'Canceled', 'Next scheduled', 'Last success', 'Last failure', 'WP-Cron fallback' ], $rows );
        echo '<h2>' . esc_html__( 'Recommended Fixes', 'onkupon-agent' ) . '</h2><ul>';
        foreach ( $report['recommendations'] as $message ) {
            echo '<li>' . esc_html( $message ) . '</li>';
        }
        echo '</ul>';
        $this->footer();
    }
}
