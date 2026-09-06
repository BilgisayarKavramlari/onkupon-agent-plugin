<?php
namespace OnKupon\Agent\Scheduler\Jobs;

use OnKupon\Agent\Logging\Logger;
use OnKupon\Agent\Logging\ActionTimelineRepository;
use OnKupon\Agent\Plugin;
use OnKupon\Agent\Security\LockManager;

abstract class AbstractJob {
    abstract protected function name(): string;
    abstract protected function run(): void;

    public function handle(): void {
        $settings = Plugin::settings();
        if ( 'emergency_stopped' === ( $settings['agent_status'] ?? '' ) ) {
            return;
        }
        $lock = new LockManager();
        if ( ! $lock->acquire( $this->name(), 20 * MINUTE_IN_SECONDS ) ) {
            ( new Logger() )->log( 'warning', 'job', 'Skipped overlapping job', [ 'job' => $this->name() ] );
            return;
        }
        $logger = new Logger();
        $logger->start_run( $this->name() );
        $timeline = new ActionTimelineRepository();
        $timeline->record( $this->name(), 'started', [ 'notes' => 'Job started', 'metadata' => [ 'hook' => 'onkupon_agent_' . str_replace( [ 'content_generation', 'product_scan' ], [ 'content', 'product_scan' ], $this->name() ) ] ] );
        try {
            $this->run();
            $logger->finish_run( $this->name(), 'completed' );
            $timeline->record( $this->name(), 'completed', [ 'notes' => 'Job completed' ] );
        } catch ( \Throwable $e ) {
            $logger->log( 'error', 'job', 'Job failed', [ 'job' => $this->name(), 'error' => $e->getMessage() ] );
            $logger->finish_run( $this->name(), 'failed' );
            $timeline->record( $this->name(), 'failed', [ 'notes' => $e->getMessage(), 'metadata' => [ 'exception' => get_class( $e ) ] ] );
        } finally {
            $lock->release( $this->name() );
        }
    }
}
