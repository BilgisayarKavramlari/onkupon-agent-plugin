<?php
namespace OnKupon\Agent\Scheduler\Jobs;

class PublishingJob extends AbstractJob {
    protected function name(): string { return 'publishing'; }
    protected function run(): void { /* Generated articles are published only by WordPressPublisher after validation. */ }
}
