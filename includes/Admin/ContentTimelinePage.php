<?php
namespace OnKupon\Agent\Admin;

class ContentTimelinePage extends BasePage {
    public function render(): void {
        $rows = array_map(
            static function ( $r ) {
                $metadata = json_decode( (string) ( $r['metadata_json'] ?? '' ), true );
                $metadata = is_array( $metadata ) ? $metadata : [];
                return [
                    esc_html( $r['created_at'] ?? '' ),
                    esc_html( $r['status'] ?? '' ),
                    esc_html( $r['action_type'] ?? '' ),
                    esc_html( (string) ( $r['object_id'] ?? '' ) ),
                    esc_html( wp_trim_words( $r['error_message'] ?? '', 12 ) ),
                    esc_html( (string) ( $metadata['word_count'] ?? '' ) ),
                    esc_html( (string) ( $metadata['initial_word_count'] ?? '' ) ),
                    esc_html( (string) ( $metadata['final_word_count'] ?? '' ) ),
                    esc_html( (string) ( $metadata['retry_count'] ?? '' ) ),
                    esc_html( (string) ( $metadata['validation_status'] ?? $r['status'] ?? '' ) ),
                    esc_html( (string) ( $metadata['quality_score'] ?? '' ) ),
                    esc_html( (string) ( $metadata['risk_score'] ?? '' ) ),
                    esc_html( is_array( $metadata['categories'] ?? null ) ? implode( ', ', $metadata['categories'] ) : (string) ( $metadata['categories'] ?? '' ) ),
                    esc_html( is_array( $metadata['tags'] ?? null ) ? implode( ', ', $metadata['tags'] ) : (string) ( $metadata['tags'] ?? '' ) ),
                    esc_html( is_array( $metadata['related_products'] ?? $metadata['related_product_ids'] ?? null ) ? implode( ', ', $metadata['related_products'] ?? $metadata['related_product_ids'] ) : '' ),
                    ! empty( $metadata['post_url'] ) ? '<a href="' . esc_url( (string) $metadata['post_url'] ) . '">' . esc_html__( 'View', 'onkupon-agent' ) . '</a>' : '',
                    esc_html( (string) ( $metadata['social_queue_status'] ?? '' ) ),
                    esc_html( wp_trim_words( $metadata['body_preview'] ?? '', 30 ) ),
                ];
            },
            $this->recent_rows( 'onkupon_agent_actions' )
        );
        $this->header( __( 'Content Timeline', 'onkupon-agent' ) );
        $this->table( [ 'Date', 'Status', 'Action', 'Object ID', 'Notes', 'Word count', 'Initial words', 'Final words', 'Retries', 'Validation', 'Quality score', 'Risk score', 'Categories', 'Tags', 'Related products', 'Post URL', 'Social', 'Preview' ], $rows );
        $this->footer();
    }
}
