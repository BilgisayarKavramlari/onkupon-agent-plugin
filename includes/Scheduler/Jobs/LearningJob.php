<?php
namespace OnKupon\Agent\Scheduler\Jobs;

class LearningJob extends AbstractJob {
    protected function name(): string { return 'learning'; }
    protected function run(): void { ( new \OnKupon\Agent\Learning\LearningEngine() )->update(); }
}
