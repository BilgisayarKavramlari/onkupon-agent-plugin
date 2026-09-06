<?php
namespace OnKupon\Agent\AI;

interface AIProviderInterface {
    public function generateJson( string $prompt, array $schema, array $options = [] ): array;
    public function generateText( string $prompt, array $options = [] ): string;
    public function validateConnection(): bool;
    public function estimateCost( array $usage ): float;
}
