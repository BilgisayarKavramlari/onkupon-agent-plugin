<?php
namespace OnKupon\Agent\Social;

class FacebookPageProvider implements SocialProviderInterface {
    public function validateConnection(): bool {
        return '' !== ( new \OnKupon\Agent\Security\SecretsManager() )->get( 'FACEBOOK_TOKEN' );
    }
    public function publish( SocialPost $post ): array {
        // Stub for official facebook API integration. Never posts without admin-provided credentials.
        return [ 'status' => 'published_stub', 'remote_id' => '', 'url' => '' ];
    }
    public function deleteRemotePost( string $remoteId ): bool { return false; }
    public function fetchMetrics( string $remoteId ): array { return []; }
    public function getPlatformName(): string { return 'facebook'; }
}
