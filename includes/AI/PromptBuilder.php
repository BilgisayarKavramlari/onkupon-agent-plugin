<?php
namespace OnKupon\Agent\AI;

use OnKupon\Agent\Plugin;

class PromptBuilder {
    public function article_prompt( array $products, array $research, array $strategy = [] ): string {
        $settings = Plugin::settings();
        $min_words = max( 100, absint( $settings['min_article_words'] ?? 800 ) );
        $target_words = max( $min_words, absint( $settings['target_article_words'] ?? 1400 ) );
        $heading_count = max( 7, absint( $settings['heading_count'] ?? 7 ) );
        $faq_count = max( 5, absint( $settings['faq_count'] ?? 5 ) );
        $content_language = sanitize_key( (string) ( $settings['content_language'] ?? 'en' ) );
        $comparison_columns = 'tr' === $content_language
            ? 'Ürün/araç, En uygun kullanım, Ana fayda, Kullanım alanı ve OnKupon bağlantısı'
            : 'Product/tool, Best for, Main benefit, Use case, and OnKupon link';
        $payload = [
            'instruction' => 'Return a JSON object only for a factual OnKupon editorial article. No markdown fences. No prose outside JSON. No raw Markdown. Do not use ### headings. Do not use [text](url) markdown links. Do not create customer reviews, fake ratings, medical/legal/financial guarantees, clickbait, or unsupported claims.',
            'body_requirements' => [
                'total article body must be at least ' . $min_words . ' words',
                'write at least target_article_words words; target length should be ' . $target_words . ' words',
                'include at least ' . $heading_count . ' substantial sections',
                'each section body should be at least 100-150 words',
                'include at least ' . $faq_count . ' FAQ items and each FAQ answer should be at least 50-80 words',
                'do not return short summary content',
                'do not compress the article',
                'write complete Turkish paragraphs when content_language = tr',
                'the article must be detailed, engaging, commercially useful, and suitable for SEO/AEO/GEO',
                'use practical examples, comparisons, use cases, and product-aware recommendations',
                'produce content with viral potential but avoid clickbait or unsupported claims',
                'use Turkish if content_language is tr; write natural Turkish paragraphs with clear headings',
                'include concise_answer near the top',
                'include introduction, product recommendation section, comparison table, FAQ, and clear CTA',
                'comparison_table must use these localized columns: ' . $comparison_columns,
                'include product-aware explanation sections that naturally reference relevant OnKupon products',
                'choose one coherent article topic that matches the shared subject of the provided products; never force an unrelated product into the article',
                'the suggested featured image product must be the strongest semantic match for the article topic, not merely the first or highest-priced product',
                'include FAQ entries when appropriate',
                'include a clear call to action',
                'use real product names and product URLs from provided product data; do not invent unavailable products',
            ],
            'score_requirements' => [
                'quality_score must be a 0-100 integer',
                'risk_score must be a 0-100 integer',
            ],
            'min_article_words' => $min_words,
            'target_article_words' => $target_words,
            'max_article_words' => absint( $settings['max_article_words'] ?? 2200 ),
            'heading_count' => $heading_count,
            'faq_count' => $faq_count,
            'product_link_count' => absint( $settings['product_link_count'] ?? 5 ),
            'product_card_count' => absint( $settings['product_card_count'] ?? 3 ),
            'content_language' => $content_language,
            'required_fields' => array_keys( $this->article_schema()['properties'] ),
            'products' => $products,
            'research' => $research,
            'strategy' => $strategy,
        ];
        return wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    }

    public function article_schema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'viral_seo_title' => [ 'type' => 'string' ],
                'seo_title' => [ 'type' => 'string' ],
                'slug' => [ 'type' => 'string' ],
                'meta_description' => [ 'type' => 'string' ],
                'excerpt' => [ 'type' => 'string' ],
                'focus_keyphrase' => [ 'type' => 'string' ],
                'secondary_keyphrases' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                'concise_answer' => [ 'type' => 'string' ],
                'introduction' => [ 'type' => 'string' ],
                'sections' => [
                    'type' => 'array',
                    'items' => $this->strict_object(
                        [
                            'heading' => [ 'type' => 'string' ],
                            'body' => [ 'type' => 'string' ],
                        ]
                    ),
                ],
                'comparison_table' => $this->strict_object(
                    [
                        'columns' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                        'rows' => [
                            'type' => 'array',
                            'items' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                        ],
                    ]
                ),
                'product_recommendations' => [
                    'type' => 'array',
                    'items' => $this->strict_object(
                        [
                            'product_id' => [ 'type' => 'integer' ],
                            'anchor_text' => [ 'type' => 'string' ],
                            'reason' => [ 'type' => 'string' ],
                            'use_case' => [ 'type' => 'string' ],
                        ]
                    ),
                ],
                'faq' => [
                    'type' => 'array',
                    'items' => $this->strict_object(
                        [
                            'question' => [ 'type' => 'string' ],
                            'answer' => [ 'type' => 'string' ],
                        ]
                    ),
                ],
                'sources' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                'tags' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                'categories' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                'related_product_ids' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
                'product_mentions' => [
                    'type' => 'array',
                    'items' => $this->strict_object(
                        [
                            'product_id' => [ 'type' => 'integer' ],
                            'anchor_text' => [ 'type' => 'string' ],
                        ]
                    ),
                ],
                'product_cards' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
                'cta' => [ 'type' => 'string' ],
                'social_posts' => $this->strict_object(
                    [
                        'linkedin' => [ 'type' => 'string' ],
                        'x' => [ 'type' => 'string' ],
                        'facebook' => [ 'type' => 'string' ],
                        'instagram' => [ 'type' => 'string' ],
                    ]
                ),
                'schema' => $this->strict_object(
                    [
                        'article_type' => [ 'type' => 'string' ],
                        'faq_enabled' => [ 'type' => 'boolean' ],
                    ]
                ),
                'quality_score' => [ 'type' => 'number' ],
                'risk_score' => [ 'type' => 'number' ],
                'suggested_featured_image_product_id' => [ 'type' => 'integer' ],
            ],
            'required' => [
                'viral_seo_title',
                'seo_title',
                'slug',
                'meta_description',
                'excerpt',
                'focus_keyphrase',
                'secondary_keyphrases',
                'concise_answer',
                'introduction',
                'sections',
                'comparison_table',
                'product_recommendations',
                'faq',
                'sources',
                'tags',
                'categories',
                'related_product_ids',
                'product_mentions',
                'product_cards',
                'cta',
                'social_posts',
                'schema',
                'quality_score',
                'risk_score',
                'suggested_featured_image_product_id',
            ],
            'additionalProperties' => false,
        ];
    }

    private function strict_object( array $properties ): array {
        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => array_keys( $properties ),
            'additionalProperties' => false,
        ];
    }
}
