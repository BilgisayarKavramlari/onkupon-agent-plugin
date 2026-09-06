<?php
namespace OnKupon\Agent\Social;

class ManualQuoraSuggestionProvider implements SocialProviderInterface {
    public function validateConnection(): bool { return true; }
    public function publish( SocialPost $post ): array { return [ 'status' => 'suggested', 'remote_id' => '', 'url' => '' ]; }
    public function deleteRemotePost( string $remoteId ): bool { return false; }
    public function fetchMetrics( string $remoteId ): array { return []; }
    public function getPlatformName(): string { return 'quora_suggestion'; }
}
