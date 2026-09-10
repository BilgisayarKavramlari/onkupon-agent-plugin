<?php
namespace OnKupon\Agent\Scheduler\Jobs;

use OnKupon\Agent\Quality\QualityAuditService;

class QualityAuditJob extends AbstractJob {
    protected function name(): string { return 'quality_audit'; }

    protected function run(): void {
        ( new QualityAuditService() )->run();
    }
}
