<?php
namespace OnKupon\Agent\Social;

class SocialPost {
    public function __construct(
        public string $platform,
        public string $message,
        public int $post_id = 0,
        public array $media_ids = []
    ) {}
}
