<?php
namespace OnKupon\Agent\AI;

use OnKupon\Agent\Logging\ActionTimelineRepository;
use OnKupon\Agent\SEO\CommercialContentPlanner;
use OnKupon\Agent\Woo\ProductRepository;

class ContentGenerator {
    public function generate_next(): array {
        $timeline = new ActionTimelineRepository();
        $timeline->record( 'content_generation', 'started', [ 'notes' => 'Content generation started' ] );
        $commercial_planner = new CommercialContentPlanner();
        $commercial_plan = $commercial_planner->next_plan();
        $product_objects = $commercial_plan
            ? (array) $commercial_plan['products']
            : ( new ProductRepository() )->content_candidates( 5 );
        $products = array_map(
            [ $this, 'product_payload' ],
            $product_objects
        );
        $strategy = [];
        if ( $commercial_plan ) {
            $strategy = [
                'content_intent' => 'commercial_comparison',
                'topic' => sanitize_text_field( (string) $commercial_plan['topic'] ),
                'focus_keyphrase' => sanitize_text_field( (string) $commercial_plan['focus_keyphrase'] ),
                'audience' => sanitize_text_field( (string) $commercial_plan['audience'] ),
                'decision_criteria' => array_map( 'sanitize_text_field', (array) $commercial_plan['decision_criteria'] ),
                'requirements' => [
                    'Compare every supplied product without declaring a universal winner.',
                    'Explain which product is suitable for which use case and decision criterion.',
                    'Use only the supplied verified product summaries and official source URLs for factual claims.',
                    'Do not invent or state current prices, discounts, commission rates, test results, market-share claims, or feature availability that is absent from the supplied data.',
                    'Include every supplied official source URL exactly in the sources array.',
                ],
            ];
            $timeline->record(
                'commercial_seo_plan',
                'selected',
                [
                    'notes' => 'Source-backed commercial comparison plan selected',
                    'metadata' => [
                        'plan_key' => sanitize_key( (string) $commercial_plan['key'] ),
                        'topic' => $strategy['topic'],
                        'product_ids' => array_map( static fn( $product ): int => absint( $product->get_id() ), $product_objects ),
                    ],
                ]
            );
        }
        $timeline->record( 'content_generation', 'products_loaded', [ 'notes' => count( $products ) . ' products loaded', 'metadata' => [ 'product_count' => count( $products ), 'content_intent' => $strategy['content_intent'] ?? 'rotation' ] ] );
        $builder = new PromptBuilder();
        $prompt = $builder->article_prompt( $products, [], $strategy );
        $timeline->record( 'content_generation', 'ai_requested', [ 'notes' => 'OpenAI-compatible provider called' ] );
        $article = ( \OnKupon\Agent\AI\ProviderFactory::make() )->generateJson( $prompt, $builder->article_schema() );
        if ( $article ) {
            $article['_onkupon_allowed_product_ids'] = array_map( 'absint', array_column( $products, 'id' ) );
            if ( $commercial_plan ) {
                $article['slug'] = sanitize_title( (string) $commercial_plan['slug'] );
                $article['focus_keyphrase'] = sanitize_text_field( (string) $commercial_plan['focus_keyphrase'] );
                $article['_onkupon_content_intent'] = 'commercial_comparison';
                $article['_onkupon_comparison_key'] = sanitize_key( (string) $commercial_plan['key'] );
                $article['_onkupon_required_product_ids'] = array_map( 'absint', array_column( $products, 'id' ) );
                $article['_onkupon_required_source_urls'] = array_values( (array) $commercial_plan['required_source_urls'] );
                $article['_onkupon_allowed_source_urls'] = array_values( array_unique( array_merge( ...array_map( static fn( array $product ): array => (array) ( $product['official_sources'] ?? [] ), $products ) ) ) );
            }
        }
        $timeline->record( 'content_generation', $article ? 'ai_completed' : 'failed', [ 'notes' => $article ? 'AI returned valid JSON' : 'AI returned invalid or empty JSON' ] );
        return $article;
    }

    private function product_payload( $product ): array {
        $categories = [];
        foreach ( $product->get_category_ids() as $term_id ) {
            $name = get_term_field( 'name', $term_id, 'product_cat' );
            if ( ! is_wp_error( $name ) && is_string( $name ) && '' !== $name ) {
                $categories[] = $name;
            }
        }
        $sources = ( new CommercialContentPlanner() )->product_sources( $product->get_id() );
        return [
            'id' => $product->get_id(),
            'name' => $product->get_name(),
            'url' => get_permalink( $product->get_id() ),
            'price' => $product->get_price(),
            'type' => $product->get_type(),
            'summary' => wp_trim_words( wp_strip_all_tags( trim( $product->get_short_description() . ' ' . $product->get_description() ) ), 140, '' ),
            'categories' => $categories,
            'official_sources' => $sources,
        ];
    }
}
