<?php
namespace OnKupon\Agent\Research;

use OnKupon\Agent\Plugin;

class RssResearchProvider implements ResearchProviderInterface {
    public function search( string $query, array $options = [] ): array {
        include_once ABSPATH . WPINC . '/feed.php';
        $urls = array_filter( array_map( 'trim', explode( "\n", (string) ( Plugin::settings()['rss_sources'] ?? '' ) ) ) );
        $results = [];
        foreach ( $urls as $url ) {
            if ( ! $this->is_allowed_url( $url ) ) {
                continue;
            }
            $feed = fetch_feed( esc_url_raw( $url ) );
            if ( is_wp_error( $feed ) ) {
                continue;
            }
            foreach ( $feed->get_items( 0, absint( $options['limit'] ?? 5 ) ) as $item ) {
                $results[] = $this->normalize( new ResearchResult( (string) $item->get_title(), (string) $item->get_permalink(), wp_strip_all_tags( (string) $item->get_description() ), 'rss' ) );
            }
        }
        return $results;
    }

    public function fetch( string $url ): ResearchResult {
        if ( ! $this->is_allowed_url( $url ) ) {
            return new ResearchResult( '', '', '', 'rss', [ 'blocked' => true ] );
        }
        $response = wp_safe_remote_get( esc_url_raw( $url ), [ 'timeout' => 10, 'reject_unsafe_urls' => true ] );
        if ( is_wp_error( $response ) ) {
            return new ResearchResult( '', esc_url_raw( $url ), '', 'rss', [ 'error' => $response->get_error_message() ] );
        }
        return new ResearchResult( esc_url_raw( $url ), esc_url_raw( $url ), wp_strip_all_tags( wp_remote_retrieve_body( $response ) ), 'rss' );
    }

    public function normalize( ResearchResult $result ): ResearchResult {
        $result->title = sanitize_text_field( $result->title );
        $result->url = esc_url_raw( $result->url );
        $result->summary = sanitize_textarea_field( wp_strip_all_tags( $result->summary ) );
        return $result;
    }

    public function getProviderName(): string {
        return 'rss';
    }

    private function is_allowed_url( string $url ): bool {
        $host = wp_parse_url( $url, PHP_URL_HOST );
        if ( ! $host || ! wp_http_validate_url( $url ) ) {
            return false;
        }
        $settings = Plugin::settings();
        $blocklist = array_filter( array_map( 'trim', explode( "\n", (string) $settings['source_blocklist'] ) ) );
        foreach ( $blocklist as $blocked ) {
            if ( str_contains( $host, $blocked ) ) {
                return false;
            }
        }
        $allowlist = array_filter( array_map( 'trim', explode( "\n", (string) $settings['source_allowlist'] ) ) );
        if ( empty( $allowlist ) ) {
            return true;
        }
        foreach ( $allowlist as $allowed ) {
            if ( str_contains( $host, $allowed ) ) {
                return true;
            }
        }
        return false;
    }
}
