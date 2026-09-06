<?php
namespace OnKupon\Agent\Affiliate;

/**
 * Marketplace programlarının ve başvuru kararlarının kalıcı kaydı.
 */
class MarketplaceProgramRepository {

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'onkupon_agent_marketplace_programs';
    }

    public function find( string $company_key ): ?array {
        global $wpdb;
        $table = self::table();
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE company_key = %s", $company_key ), ARRAY_A );
        return $row ?: null;
    }

    public function find_by_token( string $token ): ?array {
        global $wpdb;
        $table = self::table();
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE approval_token = %s AND approval_token <> ''", $token ), ARRAY_A );
        return $row ?: null;
    }

    public function upsert( array $data ): int {
        global $wpdb;
        $table = self::table();
        $now = current_time( 'mysql' );
        $company_key = sanitize_text_field( (string) ( $data['company_key'] ?? '' ) );
        if ( '' === $company_key ) {
            return 0;
        }

        $existing = $this->find( $company_key );

        $payload = [ 'company_key' => $company_key, 'last_seen_at' => $now ];
        $map = [
            'name' => 'sanitize_text_field',
            'category' => 'sanitize_text_field',
            'description' => 'wp_strip_all_tags',
            'listing_url' => 'esc_url_raw',
            'logo_url' => 'esc_url_raw',
            'commission' => 'sanitize_text_field',
            'decision' => 'sanitize_key',
            'decision_reason' => 'sanitize_text_field',
            'approval_token' => 'sanitize_text_field',
        ];
        foreach ( $map as $key => $callback ) {
            if ( array_key_exists( $key, $data ) ) {
                $payload[ $key ] = call_user_func( $callback, (string) $data[ $key ] );
            }
        }
        if ( array_key_exists( 'score', $data ) ) {
            $payload['score'] = (float) $data['score'];
        }
        if ( array_key_exists( 'score_breakdown', $data ) ) {
            $payload['score_breakdown'] = wp_json_encode( (array) $data['score_breakdown'] );
        }
        foreach ( [ 'decided_at', 'applied_at', 'joined_at' ] as $key ) {
            if ( array_key_exists( $key, $data ) ) {
                $payload[ $key ] = $data[ $key ] ? sanitize_text_field( (string) $data[ $key ] ) : null;
            }
        }

        if ( $existing ) {
            $wpdb->update( $table, $payload, [ 'id' => (int) $existing['id'] ] );
            return (int) $existing['id'];
        }

        $payload['first_seen_at'] = $now;
        $payload['decision'] = $payload['decision'] ?? 'pending';
        $wpdb->insert( $table, $payload );
        return (int) $wpdb->insert_id;
    }

    public function decide( string $company_key, string $decision, string $reason = '' ): void {
        $data = [
            'company_key' => $company_key,
            'decision' => $decision,
            'decision_reason' => $reason,
            'decided_at' => current_time( 'mysql' ),
        ];
        if ( 'approved' === $decision ) {
            $data['applied_at'] = current_time( 'mysql' );
            $data['approval_token'] = '';
        }
        if ( in_array( $decision, [ 'rejected', 'joined' ], true ) ) {
            $data['approval_token'] = '';
        }
        if ( 'joined' === $decision ) {
            $data['joined_at'] = current_time( 'mysql' );
        }
        $this->upsert( $data );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function by_decision( string $decision, int $limit = 200 ): array {
        global $wpdb;
        $table = self::table();
        return (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE decision = %s ORDER BY score DESC, last_seen_at DESC LIMIT %d",
                $decision,
                max( 1, min( 500, $limit ) )
            ),
            ARRAY_A
        );
    }

    public function approved_today(): int {
        global $wpdb;
        $table = self::table();
        return (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE applied_at IS NOT NULL AND DATE(applied_at) = %s", current_time( 'Y-m-d' ) )
        );
    }

    public function counts(): array {
        global $wpdb;
        $table = self::table();
        $rows = (array) $wpdb->get_results( "SELECT decision, COUNT(*) AS n FROM {$table} GROUP BY decision", ARRAY_A );
        $out = [];
        foreach ( $rows as $row ) {
            $out[ (string) $row['decision'] ] = (int) $row['n'];
        }
        return $out;
    }

    /**
     * Zaten iş birliği kurulmuş programların anahtarları.
     */
    public function partnered_keys(): array {
        $keys = array_map(
            static fn( $post_id ): string => (string) get_post_meta( (int) $post_id, '_onkupon_affiliate_program_key', true ),
            get_posts(
                [
                    'post_type' => 'product',
                    'post_status' => [ 'publish', 'draft', 'private', 'pending' ],
                    'posts_per_page' => -1,
                    'fields' => 'ids',
                    'meta_key' => '_onkupon_affiliate_provider',
                    'meta_value' => 'partnerstack',
                    'no_found_rows' => true,
                ]
            )
        );
        return array_values( array_unique( array_filter( $keys ) ) );
    }
}
