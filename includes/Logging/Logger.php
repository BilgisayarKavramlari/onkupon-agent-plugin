<?php
namespace OnKupon\Agent\Logging;

class Logger {
    public function log( string $level, string $channel, string $message, array $context = [] ): void {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'onkupon_agent_logs',
            [
                'level'        => sanitize_key( $level ),
                'channel'      => sanitize_key( $channel ),
                'message'      => sanitize_text_field( $message ),
                'context_json' => wp_json_encode( $this->mask( $context ) ),
                'created_at'   => current_time( 'mysql' ),
            ]
        );
    }

    public function start_run( string $type ): string {
        global $wpdb;
        $uuid = wp_generate_uuid4();
        $wpdb->insert(
            $wpdb->prefix . 'onkupon_agent_runs',
            [
                'run_uuid'   => $uuid,
                'run_type'   => sanitize_key( $type ),
                'status'     => 'running',
                'started_at' => current_time( 'mysql' ),
                'notes'      => '',
            ]
        );
        return $uuid;
    }

    public function finish_run( string $type, string $status ): void {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}onkupon_agent_runs WHERE run_type=%s ORDER BY id DESC LIMIT 1", $type ) );
        if ( ! $row ) {
            return;
        }
        $started = $row->started_at ? strtotime( $row->started_at ) : time();
        $wpdb->update(
            $wpdb->prefix . 'onkupon_agent_runs',
            [
                'status'      => sanitize_key( $status ),
                'finished_at' => current_time( 'mysql' ),
                'duration_ms' => max( 0, ( time() - $started ) * 1000 ),
            ],
            [ 'id' => (int) $row->id ]
        );
    }

    public function cost( string $provider, string $model, array $usage, float $cost, string $run_uuid = '' ): void {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'onkupon_agent_costs',
            [
                'run_uuid'       => sanitize_text_field( $run_uuid ),
                'provider'       => sanitize_key( $provider ),
                'model'          => sanitize_text_field( $model ),
                'input_tokens'   => absint( $usage['input_tokens'] ?? 0 ),
                'output_tokens'  => absint( $usage['output_tokens'] ?? 0 ),
                'estimated_cost' => $cost,
                'created_at'     => current_time( 'mysql' ),
                'metadata_json'  => wp_json_encode( [] ),
            ]
        );
    }

    private function mask( array $context ): array {
        foreach ( $context as $key => $value ) {
            if ( str_contains( strtolower( (string) $key ), 'token' ) || str_contains( strtolower( (string) $key ), 'key' ) || str_contains( strtolower( (string) $key ), 'secret' ) ) {
                $context[ $key ] = '***masked***';
            } elseif ( is_array( $value ) ) {
                $context[ $key ] = $this->mask( $value );
            } elseif ( is_string( $value ) ) {
                $context[ $key ] = preg_replace( '/(?i)(bearer\s+|access_token["\'\s:=]+|refresh_token["\'\s:=]+|client_secret["\'\s:=]+|api_key["\'\s:=]+)[^\s,"\'}]+/', '$1***masked***', $value ) ?? $value;
            }
        }
        return $context;
    }
}
