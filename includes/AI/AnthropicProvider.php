<?php
namespace OnKupon\Agent\AI;

use OnKupon\Agent\Logging\ActionTimelineRepository;
use OnKupon\Agent\Logging\Logger;
use OnKupon\Agent\Plugin;
use OnKupon\Agent\Security\RateLimiter;
use OnKupon\Agent\Security\SecretsManager;

/**
 * Native Claude (Anthropic Messages API) provider.
 *
 * Katı JSON, OpenAI'daki response_format yerine tool-use ile zorlanır: makale
 * şeması bir aracın input_schema'sı olarak verilir ve tool_choice ile o araç
 * zorunlu kılınır. Bu, Anthropic'in OpenAI uyumluluk katmanından farklı olarak
 * şema uyumunu garanti eder; uyumluluk katmanı response_format alanını yok sayar.
 */
class AnthropicProvider implements AIProviderInterface {
    private const TOOL_NAME = 'emit_article';
    private const API_VERSION = '2023-06-01';

    private array $settings;

    public function __construct() {
        $this->settings = Plugin::settings();
    }

    public function generateJson( string $prompt, array $schema, array $options = [] ): array {
        $result = $this->request( $prompt, $schema, $options );
        if ( empty( $result['ok'] ) ) {
            return [];
        }

        $data = $result['data'];
        if ( ! is_array( $data ) ) {
            $context = [ 'model' => $this->model(), 'stop_reason' => $result['stop_reason'] ?? '' ];
            ( new Logger() )->log( 'warning', 'ai', 'Anthropic provider returned no structured payload', $context );
            ( new ActionTimelineRepository() )->record( 'ai_invalid_json', 'failed', [ 'notes' => 'Anthropic provider returned no structured payload', 'metadata' => $context ] );
            return [];
        }

        $validation = ( new JsonSchemaValidator() )->validate( $data, $schema );
        if ( ! $validation['valid'] ) {
            ( new Logger() )->log( 'warning', 'ai', 'Generated JSON failed schema validation', [ 'errors' => $validation['errors'], 'provider' => 'anthropic' ] );
            ( new ActionTimelineRepository() )->record( 'ai_invalid_json', 'failed', [ 'notes' => implode( '; ', $validation['errors'] ), 'metadata' => [ 'model' => $this->model() ] ] );
            return [];
        }

        return $data;
    }

    public function generateText( string $prompt, array $options = [] ): string {
        $result = $this->request( $prompt, [], $options );
        return ! empty( $result['ok'] ) ? (string) ( $result['text'] ?? '' ) : '';
    }

    public function validateConnection(): bool {
        return '' !== $this->api_key() && '' !== $this->model();
    }

    /**
     * Kaba maliyet tahmini. Gerçek fiyatlar modele göre değişir; bütçe
     * denetiminin sıfırdan sapmaması için üst sınır olarak Sonnet kullanılır.
     */
    public function estimateCost( array $usage ): float {
        return ( absint( $usage['input_tokens'] ?? 0 ) * 0.000003 ) + ( absint( $usage['output_tokens'] ?? 0 ) * 0.000015 );
    }

    /**
     * @return array{ok:bool,data:?array,text:string,stop_reason:string,error:string}
     */
    private function request( string $prompt, array $schema, array $options ): array {
        if ( ! $this->validateConnection() ) {
            return $this->failure( 'Anthropic provider is not configured', 'configuration' );
        }
        if ( ! $this->daily_budget_available() ) {
            return $this->failure( 'Daily AI budget reached', 'budget' );
        }
        if ( ! ( new RateLimiter() )->allow( 'anthropic', 120, HOUR_IN_SECONDS ) ) {
            return $this->failure( 'AI rate limit reached', 'rate_limit' );
        }

        $json_mode = ! empty( $schema );
        $configured_max_tokens = absint( $this->settings['openai_max_tokens'] ?? 5000 );

        $body = [
            'model'      => sanitize_text_field( (string) ( $options['model'] ?? $this->model() ) ),
            'max_tokens' => $json_mode ? max( 5000, $configured_max_tokens ) : max( 256, $configured_max_tokens ),
            'system'     => 'You are a safe commerce editorial assistant. Return factual, non-deceptive content. Never create customer reviews or fake ratings.',
            'messages'   => [
                [ 'role' => 'user', 'content' => $prompt ],
            ],
        ];

        // Anthropic sıcaklığı 0-1 aralığında kabul eder.
        $temperature = (float) ( $this->settings['openai_temperature'] ?? 0.3 );
        $body['temperature'] = max( 0.0, min( 1.0, $temperature ) );

        if ( $json_mode ) {
            $body['tools'] = [
                [
                    'name'         => self::TOOL_NAME,
                    'description'  => 'Return the finished editorial article strictly matching the provided schema.',
                    'input_schema' => $this->normalize_schema( $schema ),
                ],
            ];
            $body['tool_choice'] = [ 'type' => 'tool', 'name' => self::TOOL_NAME ];
        }

        $response = wp_remote_post(
            trailingslashit( $this->base_url() ) . 'messages',
            [
                'timeout' => absint( $this->settings['request_timeout'] ?? 30 ),
                'headers' => [
                    'x-api-key'         => $this->api_key(),
                    'anthropic-version' => self::API_VERSION,
                    'Content-Type'      => 'application/json',
                    'User-Agent'        => 'OnKupon-Agent/' . ONKUPON_AGENT_VERSION . '; ' . home_url( '/' ),
                ],
                'body'    => wp_json_encode( $body ),
            ]
        );

        if ( is_wp_error( $response ) ) {
            ( new Logger() )->log( 'error', 'ai', 'AI request failed', [ 'provider' => 'anthropic', 'error' => $response->get_error_message() ] );
            return [ 'ok' => false, 'data' => null, 'text' => '', 'stop_reason' => '', 'error' => 'transport' ];
        }

        $status_code = (int) wp_remote_retrieve_response_code( $response );
        $decoded = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $status_code < 200 || $status_code >= 300 || ! is_array( $decoded ) ) {
            $message = sanitize_text_field( (string) ( $decoded['error']['message'] ?? 'Anthropic returned HTTP ' . $status_code ) );
            ( new Logger() )->log( 'error', 'ai', 'AI API request rejected', [ 'provider' => 'anthropic', 'status_code' => $status_code, 'error' => $message ] );
            ( new ActionTimelineRepository() )->record( 'ai_api_error', 'failed', [ 'notes' => $message, 'metadata' => [ 'status_code' => $status_code, 'model' => $body['model'], 'provider' => 'anthropic' ] ] );
            return [ 'ok' => false, 'data' => null, 'text' => '', 'stop_reason' => '', 'error' => 'api' ];
        }

        $input_tokens = absint( $decoded['usage']['input_tokens'] ?? 0 );
        $output_tokens = absint( $decoded['usage']['output_tokens'] ?? 0 );
        if ( $input_tokens || $output_tokens ) {
            ( new Logger() )->cost(
                'anthropic',
                (string) $body['model'],
                [ 'input_tokens' => $input_tokens, 'output_tokens' => $output_tokens ],
                $this->estimateCost( [ 'input_tokens' => $input_tokens, 'output_tokens' => $output_tokens ] )
            );
        }

        $stop_reason = sanitize_key( (string) ( $decoded['stop_reason'] ?? '' ) );

        if ( 'max_tokens' === $stop_reason ) {
            ( new Logger() )->log( 'warning', 'ai', 'AI response truncated at token limit', [ 'provider' => 'anthropic', 'model' => $body['model'], 'max_tokens' => $body['max_tokens'] ] );
            ( new ActionTimelineRepository() )->record( 'ai_output_truncated', 'failed', [ 'notes' => 'AI response truncated at token limit', 'metadata' => [ 'model' => $body['model'], 'max_tokens' => $body['max_tokens'] ] ] );
            return [ 'ok' => false, 'data' => null, 'text' => '', 'stop_reason' => $stop_reason, 'error' => 'length' ];
        }

        $blocks = is_array( $decoded['content'] ?? null ) ? $decoded['content'] : [];
        $structured = null;
        $text = '';

        foreach ( $blocks as $block ) {
            if ( ! is_array( $block ) ) {
                continue;
            }
            if ( 'tool_use' === ( $block['type'] ?? '' ) && self::TOOL_NAME === ( $block['name'] ?? '' ) && is_array( $block['input'] ?? null ) ) {
                $structured = $block['input'];
            }
            if ( 'text' === ( $block['type'] ?? '' ) ) {
                $text .= (string) ( $block['text'] ?? '' );
            }
        }

        if ( $json_mode && null === $structured ) {
            return $this->failure( 'Anthropic did not call the structured output tool', 'empty_response', [ 'stop_reason' => $stop_reason ] );
        }
        if ( ! $json_mode && '' === trim( $text ) ) {
            return $this->failure( 'AI response was empty', 'empty_response', [ 'stop_reason' => $stop_reason ] );
        }

        return [ 'ok' => true, 'data' => $structured, 'text' => $text, 'stop_reason' => $stop_reason, 'error' => '' ];
    }

    /**
     * OpenAI strict-mode şemaları `additionalProperties:false` ve tam `required`
     * listeleri taşır; bunlar Anthropic input_schema için de geçerlidir.
     * Yalnızca desteklenmeyen anahtarları temizleriz.
     */
    private function normalize_schema( array $schema ): array {
        unset( $schema['$schema'], $schema['strict'] );
        if ( isset( $schema['properties'] ) && is_array( $schema['properties'] ) ) {
            foreach ( $schema['properties'] as $key => $property ) {
                if ( is_array( $property ) ) {
                    $schema['properties'][ $key ] = $this->normalize_schema( $property );
                }
            }
        }
        if ( isset( $schema['items'] ) && is_array( $schema['items'] ) ) {
            $schema['items'] = $this->normalize_schema( $schema['items'] );
        }
        return $schema;
    }

    private function failure( string $message, string $code, array $metadata = [] ): array {
        $metadata = array_merge( [ 'provider' => 'anthropic', 'model' => $this->model(), 'code' => $code ], $metadata );
        ( new Logger() )->log( 'warning', 'ai', $message, $metadata );
        ( new ActionTimelineRepository() )->record( 'ai_' . $code, 'failed', [ 'notes' => $message, 'metadata' => $metadata ] );
        return [ 'ok' => false, 'data' => null, 'text' => '', 'stop_reason' => (string) ( $metadata['stop_reason'] ?? '' ), 'error' => $code ];
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
        return trim( ( new SecretsManager() )->get( 'ANTHROPIC_API_KEY' ) );
    }

    private function model(): string {
        return sanitize_text_field( (string) ( $this->settings['anthropic_model'] ?? '' ) );
    }

    private function base_url(): string {
        $url = (string) ( $this->settings['anthropic_base_url'] ?? 'https://api.anthropic.com/v1' );
        return esc_url_raw( $url ?: 'https://api.anthropic.com/v1' );
    }
}
