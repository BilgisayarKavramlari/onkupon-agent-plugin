<?php
namespace OnKupon\Agent\Research;

class SearchApiResearchProvider implements SearchProviderInterface {
    public function search( string $query, array $options = [] ): array {
        // Placeholder for future official search API integrations. Do not scrape Google Search.
        return [];
    }

    public function fetch( string $url ): ResearchResult {
        return new ResearchResult( '', esc_url_raw( $url ), '', 'search_api' );
    }

    public function normalize( ResearchResult $result ): ResearchResult {
        return $result;
    }

    public function getProviderName(): string {
        return 'search_api_placeholder';
    }
}
