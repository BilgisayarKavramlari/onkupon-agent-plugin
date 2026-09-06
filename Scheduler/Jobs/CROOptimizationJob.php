<?php
namespace OnKupon\Agent\Scheduler\Jobs;

class CROOptimizationJob extends AbstractJob {
    protected function name(): string { return 'cro_optimization'; }

    protected function run(): void {
        ( new \OnKupon\Agent\Growth\AutonomousConversionOptimizer() )->refresh_report();
        ( new \OnKupon\Agent\Growth\PopularToolsPage() )->ensure_page();
    }
}
