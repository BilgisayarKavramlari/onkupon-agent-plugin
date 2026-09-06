<?php
namespace OnKupon\Agent\AI;

use OnKupon\Agent\Plugin;

class ArticleExpansionService {
    public function expand( array $article, array $diagnostics = [] ): array {
        $settings = Plugin::settings();
        $builder = new PromptBuilder();
        $prompt = [
            'instruction' => 'Expand this OnKupon article candidate and return strict JSON only. Do not use Markdown, markdown fences, raw URLs in prose, or prose outside JSON.',
            'reason' => 'The previous article was rejected only for thin_content.',
            'requirements' => [
                'Preserve seo_title, slug, meta_description, excerpt, focus_keyphrase, categories, tags, CTA, FAQ topics, product_mentions, product_cards, and related_product_ids.',
                'Do not change or invent product IDs.',
                'Expand sections into complete commerce-aware paragraphs.',
                'Total article body must be at least ' . max( 100, absint( $settings['min_article_words'] ?? 800 ) ) . ' words.',
                'Target ' . max( 100, absint( $settings['target_article_words'] ?? 1400 ) ) . ' words and do not compress the article.',
                'Expand each section and include at least 7 substantial sections; each section body should be 100-150 words.',
                'Add or expand comparison_table with columns and rows.',
                'Add or expand at least 5 FAQ items; FAQ answers should be 50-80 words each.',
                'Write complete Turkish paragraphs when content_language = tr.',
                'Put OnKupon product URLs only in comparison table link cells; use plain anchor text in prose and product_mentions for link placement.',
            ],
            'content_language' => sanitize_key( (string) ( $settings['content_language'] ?? 'en' ) ),
            'target_article_words' => absint( $settings['target_article_words'] ?? 1400 ),
            'diagnostics' => $diagnostics,
            'article' => $article,
        ];
        $expanded = ( \OnKupon\Agent\AI\ProviderFactory::make() )->generateJson( wp_json_encode( $prompt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), $builder->article_schema() );
        if ( $expanded ) {
            foreach ( $article as $key => $value ) {
                if ( str_starts_with( (string) $key, '_onkupon_' ) ) {
                    $expanded[ $key ] = $value;
                }
            }
        }
        return $expanded;
    }
}
