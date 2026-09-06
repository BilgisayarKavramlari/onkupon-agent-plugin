<?php
namespace OnKupon\Agent\AI;

use OnKupon\Agent\Logging\Logger;
use OnKupon\Agent\Logging\ActionTimelineRepository;
use OnKupon\Agent\Plugin;
use OnKupon\Agent\Security\RateLimiter;
use OnKupon\Agent\Security\SecretsManager;

class OpenAIProvider implements AIProviderInterface {
    private array $settings;

    public function __construct() {
        $this->settings = Plugin::settings();
    }

    public function generateJson( string $prompt, array $schema, array $options = [] ): array {
        $result = $this->request( $prompt, true, $schema, $options );
        if ( empty( $result['ok'] ) ) {
            return [];
        }
        $text = (string) ( $result['content'] ?? '' );
        $data = json_decode( $text, true );
        if ( ! is_array( $data ) ) {
            $context = [
                'model' => $this->settings['openai_model'] ?? '',
                'finish_reason' => $result['finish_reason'] ?? '',
                'json_error' => json_last_error_msg(),
            ];
            ( new Logger() )->log( 'warning', 'ai', 'OpenAI-compatible provider returned invalid JSON', $context );
            ( new ActionTimelineRepository() )->record( 'ai_invalid_json', 'failed', [ 'notes' => 'OpenAI-compatible provider returned invalid JSON', 'metadata' => $context ] );
            return [];
        }
        $validation = ( new JsonSchemaValidator() )->validate( $data, $schema );
        if ( ! $validation['valid'] ) {
            ( new Logger() )->log( 'warning', 'ai', 'Generated JSON failed schema validation', [ 'errors' => $validation['errors'] ] );
            ( new ActionTimelineRepository() )->record( 'ai_invalid_json', 'failed', [ 'notes' => implode( '; ', $validation['errors'] ), 'metadata' => [ 'model' => $this->settings['openai_model'] ?? '' ] ] );
            return [];
        }
        return $data;
    }

    public function generateText( string $prompt, array $options = [] ): string {
        $result = $this->request( $prompt, false, [], $options );
        return ! empty( $result['ok'] ) ? (string) ( $result['content'] ?? '' ) : '';
    }

    public function validateConnection(): bool {
        return '' !== $this->api_key() && wp_http_validate_url( $this->base_url() );
    }

    public function estimateCost( array $usage ): float {
        return ( absint( $usage['input_tokens'] ?? 0 ) * 0.00000015 ) + ( absint( $usage['output_tokens'] ?? 0 ) * 0.0000006 );
    }

    private function request( string $prompt, bool $json, array $schema, array $options ): array {
        if ( ! $this->validateConnection() ) {
            return $this->failure( 'AI provider is not configured', 'configuration' );
        }
        if ( ! $this->daily_budget_available() ) {
            return $this->failure( 'Daily AI budget reached', 'budget' );
        }
        if ( ! ( new RateLimiter() )->allow( 'openai', 120, HOUR_IN_SECONDS ) ) {
            return $this->failure( 'AI rate limit reached', 'rate_limit' );
        }
        $configured_max_tokens = absint( $this->settings['openai_max_tokens'] ?? 5000 );
        $body = [
            'model' => sanitize_text_field( $options['model'] ?? $this->settings['openai_model'] ),
            'messages' => [
                [ 'role' => 'system', 'content' => 'You are a safe commerce editorial assistant. Return factual, non-deceptive content. Never create customer reviews or fake ratings.' ],
                [ 'role' => 'user', 'content' => $prompt ],
            ],
            'temperature' => (float) $this->settings['openai_temperature'],
            'max_tokens' => $json ? max( 5000, $configured_max_tokens ) : max( 256, $configured_max_tokens ),
        ];
        if ( $json ) {
            $body['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'onkupon_article',
                    'strict' => true,
                    'schema' => $schema,
                ],
            ];
        }
        $response = wp_remote_post(
            trailingslashit( $this->base_url() ) . 'chat/completions',
            [
                'timeout' => absint( $this->settings['request_timeout'] ),
                'headers' => [ 'Authorization' => 'Bearer ' . $this->api_key(), 'Content-Type' => 'application/json' ],
                'body' => wp_json_encode( $body ),
            ]
        );
        if ( is_wp_error( $response ) ) {
            ( new Logger() )->log( 'error', 'ai', 'AI request failed', [ 'error' => $response->get_error_message() ] );
            return [ 'ok' => false, 'content' => '', 'finish_reason' => '', 'error' => 'transport' ];
        }
        $decoded = json_decode( wp_remote_retrieve_body( $response ), true );
        $status_code = (int) wp_remote_retrieve_response_code( $response );
        if ( $status_code < 200 || $status_code >= 300 ) {
            $message = sanitize_text_field( (string) ( $decoded['error']['message'] ?? 'OpenAI-compatible provider returned HTTP ' . $status_code ) );
            ( new Logger() )->log( 'error', 'ai', 'AI API request rejected', [ 'status_code' => $status_code, 'error' => $message ] );
            ( new ActionTimelineRepository() )->record( 'ai_api_error', 'failed', [ 'notes' => $message, 'metadata' => [ 'status_code' => $status_code, 'model' => $body['model'] ] ] );
            return [ 'ok' => false, 'content' => '', 'finish_reason' => '', 'error' => 'api' ];
        }
        if ( isset( $decoded['usage'] ) ) {
            ( new Logger() )->cost( 'openai-compatible', (string) $body['model'], [ 'input_tokens' => $decoded['usage']['prompt_tokens'] ?? 0, 'output_tokens' => $decoded['usage']['completion_tokens'] ?? 0 ], $this->estimateCost( [ 'input_tokens' => $decoded['usage']['prompt_tokens'] ?? 0, 'output_tokens' => $decoded['usage']['completion_tokens'] ?? 0 ] ) );
        }
        $finish_reason = sanitize_key( (string) ( $decoded['choices'][0]['finish_reason'] ?? '' ) );
        $refusal = sanitize_text_field( (string) ( $decoded['choices'][0]['message']['refusal'] ?? '' ) );
        if ( $refusal ) {
            ( new Logger() )->log( 'warning', 'ai', 'AI response refused', [ 'model' => $body['model'], 'finish_reason' => $finish_reason ] );
            ( new ActionTimelineRepository() )->record( 'ai_refused', 'failed', [ 'notes' => 'AI response refused', 'metadata' => [ 'model' => $body['model'], 'finish_reason' => $finish_reason ] ] );
            return [ 'ok' => false, 'content' => '', 'finish_reason' => $finish_reason, 'error' => 'refusal' ];
        }
        if ( 'length' === $finish_reason ) {
            ( new Logger() )->log( 'warning', 'ai', 'AI response truncated at token limit', [ 'model' => $body['model'], 'max_tokens' => $body['max_tokens'] ] );
            ( new ActionTimelineRepository() )->record( 'ai_output_truncated', 'failed', [ 'notes' => 'AI response truncated at token limit', 'metadata' => [ 'model' => $body['model'], 'max_tokens' => $body['max_tokens'] ] ] );
            return [ 'ok' => false, 'content' => '', 'finish_reason' => $finish_reason, 'error' => 'length' ];
        }
        $content = (string) ( $decoded['choices'][0]['message']['content'] ?? '' );
        if ( '' === trim( $content ) ) {
            return $this->failure( 'AI response was empty', 'empty_response', [ 'finish_reason' => $finish_reason, 'model' => $body['model'] ] );
        }
        return [ 'ok' => true, 'content' => $content, 'finish_reason' => $finish_reason, 'error' => '' ];
    }

    private function failure( string $message, string $code, array $metadata = [] ): array {
        $metadata = array_merge( [ 'model' => $this->settings['openai_model'] ?? '', 'code' => $code ], $metadata );
        ( new Logger() )->log( 'warning', 'ai', $message, $metadata );
        ( new ActionTimelineRepository() )->record( 'ai_' . $code, 'failed', [ 'notes' => $message, 'metadata' => $metadata ] );
        return [ 'ok' => false, 'content' => '', 'finish_reason' => (string) ( $metadata['finish_reason'] ?? '' ), 'error' => $code ];
    }

    private function daily_budget_available(): bool {
        $budget = (float) ( $this->settings['daily_budget'] ?? 0 );
        if ( $budget <= 0 ) {
            return false;
        }
        global $wpdb;
        $start = current_time( 'Y-m-d' ) . ' 00:00:00';
        $spent = (float) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(SUM(estimated_cost),0) FROM {$wpdb->prefix}onkupon_agent_costs WHERE created_at >= %s",
                $start
            )
        );
        return $spent < $budget;
    }

    private function api_key(): string {
        return ( new SecretsManager() )->get( 'OPENAI_API_KEY' );
    }

    private function base_url(): string {
        return esc_url_raw( (string) $this->settings['openai_base_url'] );
    }
}
