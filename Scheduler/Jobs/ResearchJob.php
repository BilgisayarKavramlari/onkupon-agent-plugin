<?php
namespace OnKupon\Agent\Scheduler\Jobs;

class ResearchJob extends AbstractJob {
    protected function name(): string { return 'research'; }
    protected function run(): void {
        $provider = new \OnKupon\Agent\Research\RssResearchProvider();
        foreach ( $provider->search( 'commerce trends', [] ) as $result ) {
            ( new \OnKupon\Agent\Research\ResearchDeduplicator() )->store_candidate( $result );
        }
    }
}
