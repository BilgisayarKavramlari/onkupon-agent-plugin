<?php
namespace OnKupon\Agent\Scheduler\Jobs;

class ReviewRequestJob extends AbstractJob {
    protected function name(): string { return 'review_requests'; }
    protected function run(): void { ( new \OnKupon\Agent\Reviews\ReviewIntegrityEngine() )->send_due_requests(); }
}
