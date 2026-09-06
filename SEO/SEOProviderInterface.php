<?php
namespace OnKupon\Agent\SEO;

interface SEOProviderInterface {
    public function is_available(): bool;
    public function apply( int $post_id, array $article ): void;
}
