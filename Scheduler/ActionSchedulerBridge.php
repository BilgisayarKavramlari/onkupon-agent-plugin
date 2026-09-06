<?php
namespace OnKupon\Agent\Scheduler;

use OnKupon\Agent\Logging\ActionTimelineRepository;
use OnKupon\Agent\Logging\Logger;

class ActionSchedulerBridge {
    public function available(): bool {
        return function_exists( 'as_enqueue_async_action' );
    }

    public function enqueue( string $hook, array $args = [] ): void {
        $context = [
            'hook' => $hook,
            'args_hash' => hash( 'sha256', wp_json_encode( $args ) ?: '' ),
            'group' => JobRegistrar::GROUP,
            'scheduled_timestamp' => time(),
        ];
        if ( function_exists( 'as_enqueue_async_action' ) ) {
            $action_id = as_enqueue_async_action( $hook, $args, JobRegistrar::GROUP );
            $context['method'] = 'action_scheduler_async';
            $context['action_id'] = $action_id;
            $context['result'] = $action_id ? 'queued' : 'not_queued';
            ( new Logger() )->log( 'info', 'scheduler', 'Scheduler action enqueue attempted', $context );
            ( new ActionTimelineRepository() )->record( 'scheduler_enqueue', $context['result'], [ 'notes' => 'Queued ' . $hook, 'metadata' => $context ] );
            return;
        }
        if ( ! wp_next_scheduled( $hook, $args ) ) {
            wp_schedule_single_event( time() + 5, $hook, $args );
        }
        $context['method'] = 'wp_cron_fallback';
        $context['result'] = 'queued';
        $context['scheduled_timestamp'] = time() + 5;
        ( new Logger() )->log( 'warning', 'scheduler', 'Scheduler action used WP-Cron fallback', $context );
        ( new ActionTimelineRepository() )->record( 'scheduler_enqueue', 'queued', [ 'notes' => 'Fallback queued ' . $hook, 'metadata' => $context ] );
    }
}
