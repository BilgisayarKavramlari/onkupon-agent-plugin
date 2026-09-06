<?php
namespace OnKupon\Agent\Research;

interface ResearchProviderInterface {
    /** @return ResearchResult[] */
    public function search( string $query, array $options = [] ): array;
    public function fetch( string $url ): ResearchResult;
    public function normalize( ResearchResult $result ): ResearchResult;
    public function getProviderName(): string;
}
