<?php
namespace OnKupon\Agent\Research;

class ResearchDeduplicator {
    public function is_duplicate( ResearchResult $result ): bool {
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}onkupon_agent_candidates WHERE source_hash=%s", $result->hash() ) );
    }

    public function store_candidate( ResearchResult $result, array $product_ids = [] ): int {
        if ( $this->is_duplicate( $result ) ) {
            return 0;
        }
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'onkupon_agent_candidates',
            [
                'candidate_uuid'           => wp_generate_uuid4(),
                'source_type'              => sanitize_key( $result->source ?: 'unknown' ),
                'source_url'               => esc_url_raw( $result->url ),
                'source_title'             => sanitize_text_field( $result->title ),
                'source_hash'              => $result->hash(),
                'related_product_ids_json' => wp_json_encode( array_map( 'absint', $product_ids ) ),
                'trend_score'              => 50,
                'commercial_score'         => 50,
                'freshness_score'          => 50,
                'duplicate_status'         => 'new',
                'status'                   => 'new',
                'created_at'               => current_time( 'mysql' ),
                'metadata_json'            => wp_json_encode( $result->metadata ),
            ]
        );
        return (int) $wpdb->insert_id;
    }
}
