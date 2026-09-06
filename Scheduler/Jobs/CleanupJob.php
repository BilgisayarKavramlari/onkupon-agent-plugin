<?php
namespace OnKupon\Agent\Scheduler\Jobs;

class CleanupJob extends AbstractJob {
    protected function name(): string { return 'cleanup'; }
    protected function run(): void {
        global $wpdb;
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}onkupon_agent_logs WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS ) ) );
    }
}
