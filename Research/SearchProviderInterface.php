<?php
namespace OnKupon\Agent\Research;

interface SearchProviderInterface extends ResearchProviderInterface {
    /**
     * Future official search API adapters should return normalized ResearchResult objects.
     * Implementations must not scrape Google Search directly.
     *
     * @return ResearchResult[]
     */
    public function search( string $query, array $options = [] ): array;
}
