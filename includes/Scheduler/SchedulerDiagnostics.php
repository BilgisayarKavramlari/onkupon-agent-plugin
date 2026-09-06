<?php
namespace OnKupon\Agent\Scheduler;

use OnKupon\Agent\Logging\Logger;

class SchedulerDiagnostics {
    public function report(): array {
        $hooks = JobRegistrar::hooks();
        $report = [
            'action_scheduler_functions' => $this->functions(),
            'action_scheduler_available' => function_exists( 'as_schedule_recurring_action' ) && function_exists( 'as_next_scheduled_action' ),
            'wp_cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
            'hooks' => [],
            'totals' => [ 'pending' => 0, 'complete' => 0, 'failed' => 0, 'canceled' => 0, 'past_due' => 0 ],
            'recommendations' => [],
        ];
        foreach ( array_keys( $hooks ) as $hook ) {
            $row = $this->hook_status( $hook );
            $report['hooks'][ $hook ] = $row;
            foreach ( $report['totals'] as $status => $count ) {
                $report['totals'][ $status ] += absint( $row[ $status ] ?? 0 );
            }
        }
        if ( ! $report['action_scheduler_available'] ) {
            $report['recommendations'][] = 'Install/activate WooCommerce Action Scheduler or ensure Action Scheduler has loaded.';
        }
        if ( $report['wp_cron_disabled'] ) {
            $report['recommendations'][] = 'DISABLE_WP_CRON is true; configure a real cron to run due WordPress events.';
        }
        if ( 0 === $report['totals']['pending'] ) {
            $report['recommendations'][] = 'No pending OnKupon jobs found; use Reschedule All Jobs or wait for self-healing registration.';
        }
        return $report;
    }

    public function repair_missing(): void {
        if ( ! function_exists( 'as_next_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
            return;
        }
        $intervals = JobRegistrar::intervals();
        foreach ( $intervals as $hook => $interval ) {
            if ( as_next_scheduled_action( $hook, [], JobRegistrar::GROUP ) ) {
                continue;
            }
            $timestamp = time() + MINUTE_IN_SECONDS;
            as_schedule_recurring_action( $timestamp, $interval, $hook, [], JobRegistrar::GROUP );
            ( new Logger() )->log( 'warning', 'scheduler', 'Missing scheduled action repaired', [ 'hook' => $hook, 'group' => JobRegistrar::GROUP, 'next_scheduled_time' => gmdate( 'c', $timestamp ) ] );
        }
    }

    private function functions(): array {
        $names = [ 'as_schedule_recurring_action', 'as_next_scheduled_action', 'as_enqueue_async_action', 'as_unschedule_all_actions' ];
        return array_combine( $names, array_map( 'function_exists', $names ) );
    }

    private function hook_status( string $hook ): array {
        $row = [
            'registered_callback' => has_action( $hook ) ? 'yes' : 'no',
            'pending' => 0,
            'complete' => 0,
            'failed' => 0,
            'canceled' => 0,
            'past_due' => 0,
            'next_scheduled' => '',
            'last_success' => '',
            'last_failure' => '',
            'wp_cron_fallback' => $this->wp_cron_has_hook( $hook ) ? 'yes' : 'no',
        ];
        if ( function_exists( 'as_next_scheduled_action' ) ) {
            $next = as_next_scheduled_action( $hook, [], JobRegistrar::GROUP );
            $row['next_scheduled'] = $next ? gmdate( 'c', (int) $next ) : '';
        }
        if ( function_exists( 'as_get_scheduled_actions' ) ) {
            foreach ( [ 'pending', 'complete', 'failed', 'canceled' ] as $status ) {
                $actions = as_get_scheduled_actions( [ 'hook' => $hook, 'group' => JobRegistrar::GROUP, 'status' => $status, 'per_page' => 50 ], 'ids' );
                $row[ $status ] = is_array( $actions ) ? count( $actions ) : 0;
            }
        }
        global $wpdb;
        $success = $wpdb->get_var( $wpdb->prepare( "SELECT completed_at FROM {$wpdb->prefix}onkupon_agent_actions WHERE metadata_json LIKE %s AND status IN ('completed','published') ORDER BY id DESC LIMIT 1", '%' . $wpdb->esc_like( $hook ) . '%' ) );
        $failure = $wpdb->get_var( $wpdb->prepare( "SELECT error_message FROM {$wpdb->prefix}onkupon_agent_actions WHERE metadata_json LIKE %s AND status IN ('failed','rejected') ORDER BY id DESC LIMIT 1", '%' . $wpdb->esc_like( $hook ) . '%' ) );
        $row['last_success'] = (string) $success;
        $row['last_failure'] = (string) $failure;
        return $row;
    }

    private function wp_cron_has_hook( string $hook ): bool {
        $cron = function_exists( '_get_cron_array' ) ? _get_cron_array() : [];
        foreach ( (array) $cron as $events ) {
            if ( isset( $events[ $hook ] ) ) {
                return true;
            }
        }
        return false;
    }
}
