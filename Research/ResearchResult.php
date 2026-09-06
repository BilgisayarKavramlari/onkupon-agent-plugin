<?php
namespace OnKupon\Agent\Research;

class ResearchResult {
    public function __construct(
        public string $title = '',
        public string $url = '',
        public string $summary = '',
        public string $source = '',
        public array $metadata = []
    ) {}

    public function hash(): string {
        return hash( 'sha256', strtolower( $this->url ?: $this->title . $this->summary ) );
    }
}
