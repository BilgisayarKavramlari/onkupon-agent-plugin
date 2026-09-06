<?php
namespace OnKupon\Agent\Logging;

class ActionTimelineRepository {
    public function record( string $action_type, string $status, array $data = [] ): void {
        global $wpdb;
        $metadata = (array) ( $data['metadata'] ?? [] );
        $notes = sanitize_text_field( (string) ( $data['notes'] ?? '' ) );
        $wpdb->insert(
            $wpdb->prefix . 'onkupon_agent_actions',
            [
                'action_uuid' => sanitize_text_field( (string) ( $data['action_uuid'] ?? wp_generate_uuid4() ) ),
                'run_uuid' => sanitize_text_field( (string) ( $data['run_uuid'] ?? '' ) ),
                'action_type' => sanitize_key( $action_type ),
                'object_type' => sanitize_key( (string) ( $data['object_type'] ?? '' ) ),
                'object_id' => absint( $data['object_id'] ?? 0 ),
                'status' => sanitize_key( $status ),
                'input_hash' => sanitize_text_field( (string) ( $data['input_hash'] ?? '' ) ),
                'output_hash' => sanitize_text_field( (string) ( $data['output_hash'] ?? '' ) ),
                'created_at' => sanitize_text_field( (string) ( $data['created_at'] ?? current_time( 'mysql' ) ) ),
                'completed_at' => sanitize_text_field( (string) ( $data['completed_at'] ?? current_time( 'mysql' ) ) ),
                'error_message' => $notes,
                'metadata_json' => wp_json_encode( $metadata ),
            ]
        );
    }
}
