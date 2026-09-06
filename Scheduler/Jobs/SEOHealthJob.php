<?php
namespace OnKupon\Agent\Scheduler\Jobs;

class SEOHealthJob extends AbstractJob {
    protected function name(): string { return 'seo_health'; }
    protected function run(): void { ( new \OnKupon\Agent\SEO\SEOHealthMonitor() )->run(); }
}
