<?php
namespace OnKupon\Agent\SEO;

use OnKupon\Agent\Analytics\MetricsRepository;
use OnKupon\Agent\Logging\Logger;
use OnKupon\Agent\Plugin;

class SEOHealthMonitor {
    public function run(): array {
        $report = $this->inspect();
        if ( $this->should_repair( $report ) ) {
            flush_rewrite_rules( true );
            update_option( 'onkupon_seo_last_rewrite_repair', time(), false );
            $report = $this->inspect();
            $report['rewrite_repair_attempted'] = true;
        } else {
            $report['rewrite_repair_attempted'] = false;
        }
        update_option( 'onkupon_seo_health_last', $report, false );
        ( new MetricsRepository() )->record( 'system', 0, 'seo', 'health_score', (float) $report['score'], [ 'checked_at' => $report['checked_at'] ] );
        if ( $report['score'] < 100 ) {
            ( new Logger() )->log( 'warning', 'seo', 'SEO health check found issues', [ 'score' => $report['score'], 'failed_checks' => $report['failed_checks'] ] );
        }
        return $report;
    }

    private function inspect(): array {
        $checks = [];
        $checks['permalink_structure'] = [ 'ok' => '' !== (string) get_option( 'permalink_structure', '' ), 'detail' => (string) get_option( 'permalink_structure', '' ) ];
        $checks['rest_api'] = $this->endpoint( rest_url(), [ 'namespaces', 'routes' ] );
        $checks['robots'] = $this->endpoint( home_url( '/robots.txt' ), [ 'user-agent' ] );
        $primary_sitemap = $this->endpoint( home_url( '/sitemap.xml' ), [ '<urlset', '<sitemapindex' ] );
        $index_sitemap = $this->endpoint( home_url( '/sitemap_index.xml' ), [ '<urlset', '<sitemapindex' ] );
        $core_sitemap = $this->endpoint( home_url( '/wp-sitemap.xml' ), [ '<urlset', '<sitemapindex' ] );
        $checks['sitemap'] = [ 'ok' => ! empty( $primary_sitemap['ok'] ) || ! empty( $index_sitemap['ok'] ) || ! empty( $core_sitemap['ok'] ), 'detail' => ! empty( $primary_sitemap['ok'] ) ? '/sitemap.xml' : ( ! empty( $index_sitemap['ok'] ) ? '/sitemap_index.xml' : ( ! empty( $core_sitemap['ok'] ) ? '/wp-sitemap.xml' : 'unavailable' ) ) ];
        $checks['aioseo'] = [ 'ok' => defined( 'AIOSEO_VERSION' ) || function_exists( 'aioseo' ), 'detail' => defined( 'AIOSEO_VERSION' ) ? AIOSEO_VERSION : ( function_exists( 'aioseo' ) ? 'detected' : 'missing' ) ];
        $counts = wp_count_posts( 'product' );
        $checks['published_products'] = [ 'ok' => absint( $counts->publish ?? 0 ) > 0, 'detail' => absint( $counts->publish ?? 0 ) ];
        $passed = count( array_filter( $checks, static fn( $check ) => ! empty( $check['ok'] ) ) );
        $score = (int) round( ( $passed / max( 1, count( $checks ) ) ) * 100 );
        return [
            'checked_at' => current_time( 'mysql' ),
            'score' => $score,
            'checks' => $checks,
            'failed_checks' => array_keys( array_filter( $checks, static fn( $check ) => empty( $check['ok'] ) ) ),
            'search_engine_visibility' => (int) get_option( 'blog_public', 1 ) === 1 && empty( $checks['robots']['disallow_all'] ) ? 'indexable' : 'discouraged',
            'commercial_content' => ( new CommercialContentPlanner() )->status(),
        ];
    }

    private function endpoint( string $url, array $needles ): array {
        $probe_url = add_query_arg( 'onkupon_seo_probe', gmdate( 'YmdH' ), $url );
        $response = wp_remote_get(
            $probe_url,
            [
                'timeout' => 12,
                'redirection' => 3,
                'limit_response_size' => 65536,
                'headers' => [
                    'User-Agent' => 'OnKupon-SEO-Health/' . ONKUPON_AGENT_VERSION,
                    'Accept-Encoding' => 'identity',
                    'Cache-Control' => 'no-cache',
                ],
            ]
        );
        if ( is_wp_error( $response ) ) {
            return [ 'ok' => false, 'detail' => sanitize_text_field( $response->get_error_message() ) ];
        }
        $status = (int) wp_remote_retrieve_response_code( $response );
        $body = strtolower( (string) wp_remote_retrieve_body( $response ) );
        $matches = false;
        foreach ( $needles as $needle ) {
            if ( false !== strpos( $body, strtolower( $needle ) ) ) {
                $matches = true;
                break;
            }
        }
        return [
            'ok' => $status >= 200 && $status < 300 && $matches,
            'detail' => 'HTTP ' . $status,
            'disallow_all' => 1 === preg_match( '/^[ \t]*disallow:[ \t]*\/[ \t]*(?:#.*)?\r?$/mi', $body ),
        ];
    }

    private function should_repair( array $report ): bool {
        $settings = Plugin::settings();
        if ( empty( $settings['seo_auto_repair_rewrites'] ) || empty( $report['checks']['permalink_structure']['ok'] ) ) {
            return false;
        }
        $failed = (array) ( $report['failed_checks'] ?? [] );
        if ( ! array_intersect( [ 'rest_api', 'robots', 'sitemap' ], $failed ) ) {
            return false;
        }
        return ( time() - absint( get_option( 'onkupon_seo_last_rewrite_repair', 0 ) ) ) >= DAY_IN_SECONDS;
    }
}
