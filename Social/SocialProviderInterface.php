<?php
namespace OnKupon\Agent\Social;

interface SocialProviderInterface {
    public function validateConnection(): bool;
    public function publish( SocialPost $post ): array;
    public function deleteRemotePost( string $remoteId ): bool;
    public function fetchMetrics( string $remoteId ): array;
    public function getPlatformName(): string;
}
