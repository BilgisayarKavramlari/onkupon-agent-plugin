<?php
namespace OnKupon\Agent\Scheduler\Jobs;

use OnKupon\Agent\AI\ArticleExpansionService;
use OnKupon\Agent\AI\ContentGenerator;
use OnKupon\Agent\AI\ContentValidator;
use OnKupon\Agent\Logging\ActionTimelineRepository;
use OnKupon\Agent\Logging\Logger;
use OnKupon\Agent\Plugin;
use OnKupon\Agent\Publishing\WordPressPublisher;

class ContentGenerationJob extends AbstractJob {
    protected function name(): string { return 'content_generation'; }

    protected function run(): void {
        if ( ! Plugin::can_publish() ) {
            ( new ActionTimelineRepository() )->record( 'content_generation', 'skipped', [ 'notes' => 'Content generation skipped because agent cannot publish' ] );
            return;
        }
        if ( ! $this->within_daily_article_limit() ) {
            ( new ActionTimelineRepository() )->record( 'content_generation', 'skipped', [ 'notes' => 'Daily article limit reached', 'metadata' => [ 'daily_article_limit' => absint( Plugin::settings()['daily_article_limit'] ?? 0 ) ] ] );
            return;
        }

        $timeline = new ActionTimelineRepository();
        $logger = new Logger();
        $validator = new ContentValidator();
        $article = ( new ContentGenerator() )->generate_next();
        if ( ! $article ) {
            $timeline->record( 'content_generation', 'failed', [ 'notes' => 'No article candidate returned from AI' ] );
            return;
        }

        $validation = $validator->validate( $article );
        $initial_word_count = absint( $validation['diagnostics']['word_count'] ?? 0 );
        $retry_count = 0;
        $expansion_attempted = false;

        while ( ! $validation['valid'] && $retry_count < 2 && $this->only_thin_content( $validation ) ) {
            $expansion_attempted = true;
            $retry_count++;
            $timeline->record( 'content_generation', 'expansion_attempted', [ 'notes' => 'Thin content expansion attempt ' . $retry_count, 'metadata' => [ 'initial_word_count' => $initial_word_count, 'retry_count' => $retry_count, 'validation_status' => 'thin_content', 'related_product_ids' => $article['related_product_ids'] ?? [] ] ] );
            $expanded = ( new ArticleExpansionService() )->expand( $article, $validation['diagnostics'] ?? [] );
            if ( ! $expanded ) {
                break;
            }
            $article = $expanded;
            $validation = $validator->validate( $article );
            $timeline->record( 'content_generation', 'expanded', [ 'notes' => 'Expanded article candidate validated', 'metadata' => [ 'initial_word_count' => $initial_word_count, 'final_word_count' => $validation['diagnostics']['word_count'] ?? 0, 'retry_count' => $retry_count, 'validation_status' => $validation['valid'] ? 'valid' : 'invalid', 'body_preview' => $validation['diagnostics']['body_preview'] ?? '', 'related_product_ids' => $article['related_product_ids'] ?? [] ] ] );
        }

        if ( ! $validation['valid'] ) {
            $logger->log( 'warning', 'validation', 'Article rejected after expansion retries', $this->log_context( $validation, $retry_count, $expansion_attempted, 'rejected', 0 ) );
            $article['_onkupon_retry_count'] = $retry_count;
            $article['_onkupon_expansion_attempted'] = $expansion_attempted;
            ( new WordPressPublisher() )->publish( $article );
            return;
        }

        $post_id = ( new WordPressPublisher() )->publish( $article );
        $logger->log( 'info', 'publishing', 'Article generation completed', $this->log_context( $validation, $retry_count, $expansion_attempted, $post_id ? 'published' : 'rejected', $post_id ) );
        if ( ! $post_id ) {
            $timeline->record( 'social_sharing', 'skipped', [ 'notes' => 'Social sharing skipped because article was not published', 'metadata' => [ 'retry_count' => $retry_count ] ] );
        }
    }

    private function only_thin_content( array $validation ): bool {
        $codes = (array) ( $validation['diagnostics']['error_codes'] ?? [] );
        return [ 'thin_content' ] === array_values( array_unique( $codes ) );
    }

    private function within_daily_article_limit(): bool {
        $limit = absint( Plugin::settings()['daily_article_limit'] ?? 0 );
        if ( 0 === $limit ) {
            return false;
        }
        $query = new \WP_Query(
            [
                'post_type' => 'post',
                'post_status' => 'publish',
                'posts_per_page' => 1,
                'fields' => 'ids',
                'no_found_rows' => false,
                'meta_key' => '_onkupon_agent_generated',
                'meta_value' => 1,
                'date_query' => [
                    [
                        'after' => current_time( 'Y-m-d' ) . ' 00:00:00',
                        'inclusive' => true,
                    ],
                ],
            ]
        );
        return (int) $query->found_posts < $limit;
    }

    private function log_context( array $validation, int $retry_count, bool $expansion_attempted, string $final_status, int $post_id ): array {
        $diagnostics = (array) ( $validation['diagnostics'] ?? [] );
        return [
            'word_count' => absint( $diagnostics['word_count'] ?? 0 ),
            'min_article_words' => absint( $diagnostics['min_article_words'] ?? 0 ),
            'target_article_words' => absint( $diagnostics['target_article_words'] ?? 0 ),
            'retry_count' => $retry_count,
            'expansion_attempted' => $expansion_attempted,
            'final_status' => $final_status,
            'post_id' => $post_id,
        ];
    }
}
