<?php
namespace OnKupon\Agent\Woo;

class ProductRepository {
    public function active( int $limit = -1 ): array {
        if ( ! function_exists( 'wc_get_products' ) ) {
            return [];
        }
        return wc_get_products( [ 'status' => 'publish', 'limit' => $limit, 'return' => 'objects' ] );
    }

    public function content_candidates( int $limit = 5 ): array {
        if ( ! function_exists( 'wc_get_product' ) ) {
            return [];
        }
        global $wpdb;
        $pool_limit = max( 30, $limit * 8 );
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ps.product_id FROM {$wpdb->prefix}onkupon_agent_product_scores ps LEFT JOIN {$wpdb->prefix}onkupon_agent_learning_weights lw ON lw.dimension='product_priority' AND lw.variant_key=CAST(ps.product_id AS CHAR) WHERE ps.product_status='publish' ORDER BY COALESCE(ps.last_content_at,'1970-01-01 00:00:00') ASC, (ps.content_score + ps.trend_score + ps.revenue_score + (COALESCE(lw.weight,1) * 10)) DESC LIMIT %d",
                $pool_limit
            )
        );
        $products = [];
        foreach ( array_map( 'absint', $ids ) as $product_id ) {
            $product = wc_get_product( $product_id );
            if ( $product && 'publish' === $product->get_status() ) {
                $products[] = $product;
            }
        }
        if ( count( $products ) < $pool_limit ) {
            $seen = array_map( static fn( $product ) => $product->get_id(), $products );
            foreach ( $this->active( $pool_limit * 2 ) as $product ) {
                if ( in_array( $product->get_id(), $seen, true ) ) {
                    continue;
                }
                $products[] = $product;
                $seen[] = $product->get_id();
                if ( count( $products ) >= $pool_limit ) {
                    break;
                }
            }
        }
        return $this->coherent_cluster( $products, $limit );
    }

    public function mark_content_used( array $product_ids ): void {
        global $wpdb;
        foreach ( array_values( array_filter( array_unique( array_map( 'absint', $product_ids ) ) ) ) as $product_id ) {
            $wpdb->update(
                $wpdb->prefix . 'onkupon_agent_product_scores',
                [ 'last_content_at' => current_time( 'mysql' ) ],
                [ 'product_id' => $product_id ],
                [ '%s' ],
                [ '%d' ]
            );
        }
    }

    private function coherent_cluster( array $products, int $limit ): array {
        $products = array_values( $products );
        if ( count( $products ) <= $limit ) {
            return $products;
        }

        $tokens = array_map( [ $this, 'product_tokens' ], $products );
        $frequency = [];
        foreach ( $tokens as $set ) {
            foreach ( array_unique( $set ) as $token ) {
                $frequency[ $token ] = ( $frequency[ $token ] ?? 0 ) + 1;
            }
        }

        $best_indices = range( 0, $limit - 1 );
        $best_score = -INF;
        $best_positive = 0;
        $pool_size = count( $products );

        foreach ( array_keys( $products ) as $seed_index ) {
            $neighbors = [];
            foreach ( array_keys( $products ) as $candidate_index ) {
                if ( $candidate_index === $seed_index ) {
                    continue;
                }
                $neighbors[] = [
                    'index' => $candidate_index,
                    'score' => $this->token_similarity( $tokens[ $seed_index ], $tokens[ $candidate_index ], $frequency, $pool_size ),
                ];
            }
            usort(
                $neighbors,
                static fn( array $left, array $right ): int => $right['score'] <=> $left['score'] ?: $left['index'] <=> $right['index']
            );
            $neighbors = array_slice( $neighbors, 0, max( 0, $limit - 1 ) );
            $positive = count( array_filter( $neighbors, static fn( array $row ): bool => $row['score'] > 0 ) );
            $score = array_sum( array_column( $neighbors, 'score' ) ) + ( $positive * 2 ) - ( $seed_index * 0.001 );
            if ( $score > $best_score ) {
                $best_score = $score;
                $best_positive = $positive;
                $best_indices = array_merge( [ $seed_index ], array_column( $neighbors, 'index' ) );
            }
        }

        if ( $best_positive < min( 2, max( 0, $limit - 1 ) ) ) {
            return array_slice( $products, 0, $limit );
        }

        sort( $best_indices );
        return array_values( array_map( static fn( int $index ) => $products[ $index ], array_slice( $best_indices, 0, $limit ) ) );
    }

    private function product_tokens( $product ): array {
        $parts = [ html_entity_decode( (string) $product->get_name(), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ];
        foreach ( $product->get_category_ids() as $term_id ) {
            $name = get_term_field( 'name', $term_id, 'product_cat' );
            if ( is_string( $name ) ) {
                $parts[] = $name;
            }
        }
        $normalized = strtolower( remove_accents( implode( ' ', $parts ) ) );
        $raw = array_filter( preg_split( '/[^a-z0-9]+/', $normalized ) ?: [] );
        $stopwords = [ 've', 'ile', 'icin', 'bir', 'the', 'and', 'with', 'dijital', 'kitap', 'egitim', 'egitimler', 'sertifika', 'sertifikalar', 'kurs', 'course', 'specialist', 'uzmanlik', 'uzmanligi', 'temelleri', 'fundamentals', 'uygulamalar' ];
        $aliases = [
            'machine' => 'makine',
            'learning' => 'ogrenmesi',
            'data' => 'veri',
            'science' => 'bilimi',
            'artificial' => 'yapay',
            'intelligence' => 'zeka',
            'ai' => 'yapay',
            'generative' => 'uretken',
            'genai' => 'uretken',
            'language' => 'dil',
            'models' => 'modelleri',
            'processing' => 'isleme',
        ];
        $tokens = [];
        foreach ( $raw as $token ) {
            if ( strlen( $token ) < 3 || in_array( $token, $stopwords, true ) ) {
                continue;
            }
            $tokens[] = $aliases[ $token ] ?? $token;
        }
        return array_values( array_unique( $tokens ) );
    }

    private function token_similarity( array $left, array $right, array $frequency, int $pool_size ): float {
        $shared = array_intersect( $left, $right );
        $score = 0.0;
        foreach ( $shared as $token ) {
            $score += 1 + log( ( $pool_size + 1 ) / ( ( $frequency[ $token ] ?? $pool_size ) + 1 ) );
        }
        return $score;
    }
}
