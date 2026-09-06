<?php
namespace OnKupon\Agent\Learning;

class StrategyWeightsRepository {
    public function update( string $dimension, string $variant, float $delta ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'onkupon_agent_learning_weights';
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE dimension=%s AND variant_key=%s", $dimension, $variant ) );
        if ( $row ) {
            $wpdb->update( $table, [ 'weight' => max( 0.01, (float) $row->weight + $delta ), 'updated_at' => current_time( 'mysql' ) ], [ 'id' => $row->id ] );
            return;
        }
        $wpdb->insert( $table, [ 'dimension' => $dimension, 'variant_key' => $variant, 'weight' => max( 0.01, 1 + $delta ), 'updated_at' => current_time( 'mysql' ), 'metadata_json' => '{}' ] );
    }

    public function snapshot( string $dimension, string $variant, float $weight, int $impressions, int $clicks, int $conversions, float $revenue, array $metadata = [] ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'onkupon_agent_learning_weights';
        $row_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE dimension=%s AND variant_key=%s", $dimension, $variant ) );
        $data = [
            'dimension' => sanitize_key( $dimension ),
            'variant_key' => sanitize_text_field( $variant ),
            'weight' => max( 0.01, $weight ),
            'impressions' => max( 0, $impressions ),
            'clicks' => max( 0, $clicks ),
            'conversions' => max( 0, $conversions ),
            'revenue_score' => max( 0, $revenue ),
            'updated_at' => current_time( 'mysql' ),
            'metadata_json' => wp_json_encode( $metadata ),
        ];
        if ( $row_id ) {
            unset( $data['dimension'], $data['variant_key'] );
            $wpdb->update( $table, $data, [ 'id' => $row_id ] );
            return;
        }
        $wpdb->insert( $table, $data );
    }

    public function all(): array {
        global $wpdb;
        return $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}onkupon_agent_learning_weights", ARRAY_A ) ?: [];
    }
}
