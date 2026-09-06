<?php
namespace OnKupon\Agent\Scheduler\Jobs;

class SocialPublishingJob extends AbstractJob {
    protected function name(): string { return 'social_publishing'; }
    protected function run(): void { ( new \OnKupon\Agent\Social\SocialQueueRepository() )->publish_due(); }
}
