<?php
namespace OnKupon\Agent\Scheduler\Jobs;

class ProductScanJob extends AbstractJob {
    protected function name(): string { return 'product_scan'; }
    protected function run(): void { ( new \OnKupon\Agent\Woo\ProductScorer() )->scan_and_score(); }
}
