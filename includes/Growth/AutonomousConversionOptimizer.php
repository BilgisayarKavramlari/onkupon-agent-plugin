<?php
namespace OnKupon\Agent\Growth;

use OnKupon\Agent\Analytics\MetricsRepository;
use OnKupon\Agent\Plugin;

class AutonomousConversionOptimizer {
    private const COOKIE = 'onkupon_cro_attribution';

    public function register(): void {
        if ( empty( Plugin::settings()['autonomous_cro_enabled'] ) ) {
            return;
        }
        add_filter( 'the_content', [ $this, 'inject_recommendations' ], 30 );
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
        add_action( 'template_redirect', [ $this, 'capture_attribution' ], 1 );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_filter( 'woocommerce_loop_add_to_cart_args', [ $this, 'qualify_affiliate_link' ], 20, 2 );
    }

    public function enqueue_assets(): void {
        if ( ! is_singular( [ 'post', 'page' ] ) ) {
            return;
        }
        wp_enqueue_style( 'onkupon-agent-cro', ONKUPON_AGENT_URL . 'assets/cro.css', [], ONKUPON_AGENT_VERSION );
        wp_enqueue_script( 'onkupon-agent-cro', ONKUPON_AGENT_URL . 'assets/cro.js', [], ONKUPON_AGENT_VERSION, true );
    }

    public function inject_recommendations( string $content ): string {
        if ( is_admin() || is_feed() || ! is_singular( 'post' ) || ! in_the_loop() || ! is_main_query() ) {
            return $content;
        }
        $post_id = get_queried_object_id();
        $products = $this->rank_for_post( $post_id, 3 );
        if ( ! $post_id || count( $products ) < 2 ) {
            return $content;
        }

        $html = $this->recommendation_html( $post_id, $products );
        preg_match_all( '/<\/p>/i', $content, $paragraphs, PREG_OFFSET_CAPTURE );
        if ( count( $paragraphs[0] ) < 3 ) {
            return $content . $html;
        }
        $offset = (int) $paragraphs[0][2][1] + strlen( (string) $paragraphs[0][2][0] );
        return substr( $content, 0, $offset ) . $html . substr( $content, $offset );
    }

    public function register_routes(): void {
        register_rest_route(
            'onkupon-agent/v1',
            '/cro/impression',
            [
                'methods'             => 'POST',
                'permission_callback' => '__return_true',
                'callback'            => [ $this, 'record_impression' ],
            ]
        );
    }

    public function record_impression( \WP_REST_Request $request ): \WP_REST_Response {
        $post_id = absint( $request->get_param( 'post_id' ) );
        $product_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $request->get_param( 'product_ids' ) ) ) ) );
        $token = sanitize_text_field( (string) $request->get_param( 'token' ) );
        $placement = sanitize_key( (string) $request->get_param( 'placement' ) );
        if ( ! $post_id || ! $product_ids || count( $product_ids ) > 5 || ! $this->valid_impression_token( $post_id, $product_ids, $token ) ) {
            return new \WP_REST_Response( [ 'recorded' => false ], 400 );
        }
        $metrics = new MetricsRepository();
        foreach ( $product_ids as $product_id ) {
            if ( ! $this->is_active_affiliate_product( $product_id ) ) {
                continue;
            }
            $metrics->record(
                'affiliate_product',
                $product_id,
                'internal_recommendation',
                'cro_impression',
                1,
                [ 'post_id' => $post_id, 'placement' => $placement ?: 'in_article' ]
            );
        }
        return new \WP_REST_Response( [ 'recorded' => true ], 200 );
    }

    public function capture_attribution(): void {
        if ( ! is_singular( 'product' ) || 'autonomous_cro' !== sanitize_key( (string) ( $_GET['utm_campaign'] ?? '' ) ) ) {
            return;
        }
        $product_id = get_queried_object_id();
        if ( ! $this->is_active_affiliate_product( $product_id ) ) {
            return;
        }
        $data = [
            'product_id' => $product_id,
            'source'     => sanitize_key( (string) ( $_GET['utm_source'] ?? 'onkupon' ) ),
            'medium'     => sanitize_key( (string) ( $_GET['utm_medium'] ?? 'internal_recommendation' ) ),
            'campaign'   => 'autonomous_cro',
            'content'    => sanitize_text_field( (string) ( $_GET['utm_content'] ?? '' ) ),
            'placement'  => sanitize_key( (string) ( $_GET['ok_placement'] ?? 'in_article' ) ),
            'captured'   => time(),
        ];
        setcookie(
            self::COOKIE,
            rawurlencode( wp_json_encode( $data ) ?: '' ),
            [
                'expires'  => time() + HOUR_IN_SECONDS,
                'path'     => COOKIEPATH ?: '/',
                'domain'   => COOKIE_DOMAIN ?: '',
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );
    }

    public static function consume_attribution( int $product_id ): array {
        $raw = isset( $_COOKIE[ self::COOKIE ] ) ? rawurldecode( wp_unslash( (string) $_COOKIE[ self::COOKIE ] ) ) : '';
        $data = json_decode( $raw, true );
        if ( ! is_array( $data ) || $product_id !== absint( $data['product_id'] ?? 0 ) || ( time() - absint( $data['captured'] ?? 0 ) ) > HOUR_IN_SECONDS ) {
            return [];
        }
        setcookie(
            self::COOKIE,
            '',
            [
                'expires'  => time() - HOUR_IN_SECONDS,
                'path'     => COOKIEPATH ?: '/',
                'domain'   => COOKIE_DOMAIN ?: '',
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );
        return [
            'source'    => sanitize_key( (string) ( $data['source'] ?? '' ) ),
            'medium'    => sanitize_key( (string) ( $data['medium'] ?? '' ) ),
            'campaign'  => sanitize_key( (string) ( $data['campaign'] ?? '' ) ),
            'content'   => sanitize_text_field( (string) ( $data['content'] ?? '' ) ),
            'placement' => sanitize_key( (string) ( $data['placement'] ?? '' ) ),
        ];
    }

    public function qualify_affiliate_link( array $args, $product ): array {
        if ( ! $product || ! method_exists( $product, 'get_id' ) || ! $this->is_active_affiliate_product( (int) $product->get_id() ) ) {
            return $args;
        }
        $args['attributes'] = (array) ( $args['attributes'] ?? [] );
        $existing = sanitize_text_field( (string) ( $args['attributes']['rel'] ?? '' ) );
        $relations = preg_split( '/\s+/', trim( $existing . ' sponsored nofollow' ) ) ?: [];
        $args['attributes']['rel'] = implode( ' ', array_values( array_unique( array_filter( $relations ) ) ) );
        return $args;
    }

    public function rank_for_post( int $post_id, int $limit = 3 ): array {
        $context = $this->context_tokens( $post_id );
        $ranked = $this->rank_products( $context, 100 );
        $by_id = [];
        foreach ( $ranked as $row ) {
            $by_id[ absint( $row['id'] ?? 0 ) ] = $row;
        }
        $related = json_decode( (string) get_post_meta( $post_id, '_onkupon_related_products', true ), true );
        $prioritized = [];
        foreach ( array_values( array_unique( array_map( 'absint', (array) $related ) ) ) as $index => $product_id ) {
            if ( isset( $by_id[ $product_id ] ) && $this->is_active_affiliate_product( $product_id ) ) {
                $row = $by_id[ $product_id ];
                $row['score'] = 200 - $index;
                $prioritized[] = $row;
                unset( $by_id[ $product_id ] );
            }
        }
        return array_slice( array_merge( $prioritized, array_values( $by_id ) ), 0, max( 1, $limit ) );
    }

    public function global_ranked_products( int $limit = 10 ): array {
        return $this->rank_products( [], $limit );
    }

    public function refresh_report(): array {
        global $wpdb;
        $since = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
        $views = 0;
        if ( in_array( $wpdb->prefix . 'statistics_summary_totals', $wpdb->get_col( 'SHOW TABLES' ), true ) ) {
            $views = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(views),0) FROM {$wpdb->prefix}statistics_summary_totals WHERE date >= %s", gmdate( 'Y-m-d', time() - 30 * DAY_IN_SECONDS ) ) );
        }
        $clicks = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(metric_value),0) FROM {$wpdb->prefix}onkupon_agent_metrics WHERE object_type='affiliate_product' AND metric_name='outbound_click' AND measured_at >= %s", $since ) );
        $impressions = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(metric_value),0) FROM {$wpdb->prefix}onkupon_agent_metrics WHERE object_type='affiliate_product' AND metric_name='cro_impression' AND measured_at >= %s", $since ) );
        $cro_clicks = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(metric_value),0) FROM {$wpdb->prefix}onkupon_agent_metrics WHERE object_type='affiliate_product' AND metric_name='cro_click' AND measured_at >= %s", $since ) );
        $report = [
            'updated_at'          => current_time( 'mysql' ),
            'site_views_30d'      => $views,
            'affiliate_clicks_30d'=> $clicks,
            'cro_impressions_30d' => $impressions,
            'cro_clicks_30d'      => $cro_clicks,
            'cro_ctr'             => $impressions ? round( $cro_clicks / $impressions, 4 ) : 0,
            'top_product_ids'     => array_column( $this->global_ranked_products( 10 ), 'id' ),
        ];
        update_option( 'onkupon_cro_last_report', $report, false );
        return $report;
    }

    private function rank_products( array $context, int $limit ): array {
        $ids = get_posts(
            [
                'post_type'      => 'product',
                'post_status'    => 'publish',
                'posts_per_page' => 100,
                'fields'         => 'ids',
                'no_found_rows'  => true,
                'meta_query'     => [
                    'relation' => 'AND',
                    [ 'key' => '_onkupon_affiliate_managed', 'value' => '1' ],
                    [ 'key' => '_onkupon_affiliate_active', 'value' => '1' ],
                ],
            ]
        );
        if ( ! $ids ) {
            return [];
        }
        $metrics = $this->metrics_for_products();
        $now = time();
        $ranked = [];
        foreach ( array_map( 'absint', $ids ) as $id ) {
            $product_tokens = $this->product_tokens( $id );
            $overlap = $context ? count( array_intersect( $context, $product_tokens ) ) : 0;
            $row = $metrics[ $id ] ?? [ 'outbound_click' => 0, 'cro_click' => 0, 'cro_impression' => 0 ];
            $impressions = max( 0, (int) ( $row['cro_impression'] ?? 0 ) );
            $cro_clicks = max( 0, (int) ( $row['cro_click'] ?? 0 ) );
            $popularity = max( 0, (int) ( $row['outbound_click'] ?? 0 ) );
            $bayesian_ctr = ( $cro_clicks + 1 ) / ( $impressions + 20 );
            $exploration = min( 10, 10 / sqrt( 1 + $impressions ) );
            $age_days = max( 0, ( $now - (int) get_post_timestamp( $id ) ) / DAY_IN_SECONDS );
            $freshness = max( 0, 5 - min( 5, $age_days / 90 ) );
            $score = min( 60, $overlap * 18 )
                + min( 15, log( 1 + $popularity ) * 4.5 )
                + min( 20, $bayesian_ctr * 100 )
                + $exploration
                + $freshness;
            $ranked[] = [
                'id'          => $id,
                'score'       => round( $score, 3 ),
                'clicks_30d'  => $popularity,
                'impressions' => $impressions,
                'cro_clicks'  => $cro_clicks,
                'relevance'   => $overlap,
            ];
        }
        usort( $ranked, static fn( array $a, array $b ): int => $b['score'] <=> $a['score'] ?: $b['clicks_30d'] <=> $a['clicks_30d'] );
        return array_slice( $ranked, 0, max( 1, min( 20, $limit ) ) );
    }

    private function metrics_for_products(): array {
        global $wpdb;
        $since = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT object_id,metric_name,SUM(metric_value) total
                 FROM {$wpdb->prefix}onkupon_agent_metrics
                 WHERE object_type='affiliate_product'
                   AND metric_name IN ('outbound_click','cro_click','cro_impression')
                   AND measured_at >= %s
                 GROUP BY object_id,metric_name",
                $since
            ),
            ARRAY_A
        ) ?: [];
        $result = [];
        foreach ( $rows as $row ) {
            $id = absint( $row['object_id'] ?? 0 );
            $name = sanitize_key( (string) ( $row['metric_name'] ?? '' ) );
            $result[ $id ][ $name ] = (float) ( $row['total'] ?? 0 );
        }
        return $result;
    }

    private function context_tokens( int $post_id ): array {
        $text = get_the_title( $post_id ) . ' ' . get_post_field( 'post_excerpt', $post_id );
        foreach ( [ 'category', 'post_tag' ] as $taxonomy ) {
            $terms = wp_get_post_terms( $post_id, $taxonomy, [ 'fields' => 'names' ] );
            if ( ! is_wp_error( $terms ) ) {
                $text .= ' ' . implode( ' ', $terms );
            }
        }
        return $this->tokens( $text );
    }

    private function product_tokens( int $product_id ): array {
        $text = get_the_title( $product_id ) . ' ' . get_post_field( 'post_excerpt', $product_id );
        foreach ( [ 'product_cat', 'product_tag', 'product_brand' ] as $taxonomy ) {
            if ( ! taxonomy_exists( $taxonomy ) ) {
                continue;
            }
            $terms = wp_get_post_terms( $product_id, $taxonomy, [ 'fields' => 'names' ] );
            if ( ! is_wp_error( $terms ) ) {
                $text .= ' ' . implode( ' ', $terms );
            }
        }
        return $this->tokens( $text );
    }

    private function tokens( string $text ): array {
        $text = strtolower( remove_accents( wp_strip_all_tags( $text ) ) );
        $parts = preg_split( '/[^a-z0-9]+/u', $text ) ?: [];
        $stop = [ 've', 'ile', 'icin', 'bir', 'bu', 'en', 'the', 'and', 'for', 'platform', 'platformu', 'arac', 'araci', 'araclar', 'araclari', 'urun', 'urunler', 'yazilim', 'destekli', 'otomasyon', 'yapay', 'zeka' ];
        return array_values( array_unique( array_filter( $parts, static fn( string $token ): bool => strlen( $token ) > 2 && ! in_array( $token, $stop, true ) ) ) );
    }

    private function recommendation_html( int $post_id, array $products ): string {
        $ids = array_map( static fn( array $row ): int => absint( $row['id'] ?? 0 ), $products );
        $cards = '';
        foreach ( $ids as $id ) {
            $title = get_the_title( $id );
            $description = wp_html_excerpt( wp_strip_all_tags( (string) get_post_field( 'post_excerpt', $id ) ), 125, '…' );
            $url = $this->recommendation_url( $id, $post_id, 'in_article' );
            $cards .= '<article class="onkupon-cro-card"><a class="onkupon-cro-card__title" href="' . esc_url( $url ) . '">' . esc_html( $title ) . '</a>'
                . '<p class="onkupon-cro-card__desc">' . esc_html( $description ) . '</p>'
                . '<a class="onkupon-cro-card__button" href="' . esc_url( $url ) . '">Aracı incele</a></article>';
        }
        $token = $this->impression_token( $post_id, $ids, current_time( 'Y-m-d' ) );
        return '<aside class="onkupon-cro-recommendations" aria-label="İlgili araç önerileri" data-post-id="' . esc_attr( (string) $post_id ) . '" data-product-ids="' . esc_attr( implode( ',', $ids ) ) . '" data-impression-url="' . esc_url( rest_url( 'onkupon-agent/v1/cro/impression' ) ) . '" data-impression-token="' . esc_attr( $token ) . '">'
            . '<h2 class="onkupon-cro-recommendations__head">Bu konu için hızlı araç seçimi</h2>'
            . '<p class="onkupon-cro-recommendations__note">İçerikle eşleşme ve OnKupon’daki anonim, toplu ilgi verilerine göre sıralandı.</p>'
            . '<div class="onkupon-cro-recommendations__grid">' . $cards . '</div>'
            . '<p class="onkupon-cro-method">Affiliate bağlantılar içerebilir; satın alma fiyatınız değişmez. Sıralama kalite garantisi değildir.</p></aside>';
    }

    public function recommendation_url( int $product_id, int $source_id, string $placement ): string {
        return add_query_arg(
            [
                'utm_source'   => 'onkupon',
                'utm_medium'   => 'internal_recommendation',
                'utm_campaign' => 'autonomous_cro',
                'utm_content'  => 'post_' . $source_id . '_product_' . $product_id,
                'ok_placement' => sanitize_key( $placement ),
            ],
            get_permalink( $product_id )
        );
    }

    private function impression_token( int $post_id, array $product_ids, string $date ): string {
        sort( $product_ids );
        return hash_hmac( 'sha256', $post_id . '|' . implode( ',', $product_ids ) . '|' . $date, wp_salt( 'nonce' ) );
    }

    private function valid_impression_token( int $post_id, array $product_ids, string $token ): bool {
        sort( $product_ids );
        foreach ( [ current_time( 'Y-m-d' ), wp_date( 'Y-m-d', time() - DAY_IN_SECONDS ) ] as $date ) {
            if ( hash_equals( $this->impression_token( $post_id, $product_ids, $date ), $token ) ) {
                return true;
            }
        }
        return false;
    }

    private function is_active_affiliate_product( int $product_id ): bool {
        return 'product' === get_post_type( $product_id )
            && 'publish' === get_post_status( $product_id )
            && '1' === (string) get_post_meta( $product_id, '_onkupon_affiliate_managed', true )
            && '1' === (string) get_post_meta( $product_id, '_onkupon_affiliate_active', true );
    }
}
