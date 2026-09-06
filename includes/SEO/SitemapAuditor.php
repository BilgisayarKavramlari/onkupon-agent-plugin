<?php
namespace OnKupon\Agent\SEO;

use OnKupon\Agent\Logging\ActionTimelineRepository;
use OnKupon\Agent\Logging\Logger;

/**
 * Sitemap sağlık denetçisi.
 *
 * Search Console'un "Not found (404)" uyarısı hangi adreslerin kırıldığını
 * söylemez; yalnızca sayıyı verir. Bu sınıf site haritasındaki her adresi
 * sunucu tarafından yoklar ve kırık olanları adres adres raporlar.
 *
 * Tarama sayfalıdır: her çalışmada sınırlı sayıda adres yoklanır ve imleç
 * saklanır, böylece 900 adreslik bir site haritası PHP zaman aşımına
 * takılmadan birkaç turda taranır.
 */
class SitemapAuditor {

    public const STATE_OPTION  = 'onkupon_agent_sitemap_audit';
    public const RESULT_OPTION = 'onkupon_agent_sitemap_audit_result';

    private const URLS_PER_RUN = 120;
    private const REQUEST_TIMEOUT = 8;

    /**
     * @return array{checked:int,broken:int,total:int,complete:bool}
     */
    public function run(): array {
        $state = get_option( self::STATE_OPTION, [] );
        $state = is_array( $state ) ? $state : [];

        $urls = $state['urls'] ?? null;
        $cursor = absint( $state['cursor'] ?? 0 );
        $broken = is_array( $state['broken'] ?? null ) ? $state['broken'] : [];

        // Yeni tur: adres listesini site haritasından topla.
        if ( ! is_array( $urls ) || empty( $urls ) || $cursor >= count( $urls ) ) {
            $urls = $this->collect_urls();
            $cursor = 0;
            $broken = [];
            if ( empty( $urls ) ) {
                ( new Logger() )->log( 'warning', 'seo', 'Site haritasından hiç adres okunamadı', [] );
                return [ 'checked' => 0, 'broken' => 0, 'total' => 0, 'complete' => true ];
            }
        }

        $total = count( $urls );
        $end = min( $total, $cursor + self::URLS_PER_RUN );

        for ( $i = $cursor; $i < $end; $i++ ) {
            $url = (string) $urls[ $i ];
            $status = $this->probe( $url );
            if ( $status < 200 || $status >= 300 ) {
                $broken[] = [ 'url' => $url, 'status' => $status ];
            }
        }

        $complete = $end >= $total;

        update_option(
            self::STATE_OPTION,
            [ 'urls' => $complete ? [] : $urls, 'cursor' => $complete ? 0 : $end, 'broken' => $complete ? [] : $broken ],
            false
        );

        if ( $complete ) {
            $summary = [
                'at' => current_time( 'mysql' ),
                'total' => $total,
                'broken_count' => count( $broken ),
                'broken' => array_slice( $broken, 0, 200 ),
                'by_status' => $this->group_by_status( $broken ),
            ];
            update_option( self::RESULT_OPTION, $summary, false );

            ( new Logger() )->log(
                'info',
                'seo',
                'Sitemap denetimi tamamlandı',
                [ 'total' => $total, 'broken' => count( $broken ), 'by_status' => $summary['by_status'] ]
            );
            ( new ActionTimelineRepository() )->record(
                'sitemap_audit',
                count( $broken ) > 0 ? 'failed' : 'completed',
                [ 'notes' => sprintf( '%d adresten %d tanesi kırık', $total, count( $broken ) ), 'metadata' => $summary['by_status'] ]
            );
        }

        return [ 'checked' => $end - $cursor, 'broken' => count( $broken ), 'total' => $total, 'complete' => $complete ];
    }

    /**
     * Site haritası indeksini ve alt haritaları okuyup tüm adresleri toplar.
     *
     * @return string[]
     */
    private function collect_urls(): array {
        $index = home_url( '/sitemap.xml' );
        $children = $this->extract( $index, 'sitemap' );
        if ( empty( $children ) ) {
            // Tek parçalı site haritası olabilir.
            $children = [ $index ];
        }

        $urls = [];
        foreach ( $children as $child ) {
            foreach ( $this->extract( $child, 'url' ) as $url ) {
                $urls[] = $url;
            }
        }

        return array_values( array_unique( array_filter( $urls ) ) );
    }

    /**
     * @param string $type 'sitemap' (indeks girdileri) veya 'url' (sayfa girdileri)
     * @return string[]
     */
    private function extract( string $sitemap_url, string $type ): array {
        $response = wp_remote_get(
            $sitemap_url,
            [
                'timeout' => 20,
                'headers' => [ 'Accept' => 'application/xml,text/xml' ],
                'user-agent' => 'OnKupon-Agent-SitemapAudit/' . ONKUPON_AGENT_VERSION,
            ]
        );
        if ( is_wp_error( $response ) ) {
            return [];
        }
        $body = (string) wp_remote_retrieve_body( $response );
        if ( '' === $body ) {
            return [];
        }

        // İndeks girdileriyle sayfa girdilerini ayırmak için üst etikete bakılır.
        $pattern = 'sitemap' === $type
            ? '#<sitemap>\s*<loc>(.*?)</loc>#is'
            : '#<url>\s*<loc>(.*?)</loc>#is';

        if ( ! preg_match_all( $pattern, $body, $matches ) ) {
            return [];
        }

        return array_map(
            static fn( $loc ): string => esc_url_raw( html_entity_decode( trim( (string) $loc ) ) ),
            $matches[1]
        );
    }

    private function probe( string $url ): int {
        $args = [
            'timeout' => self::REQUEST_TIMEOUT,
            'redirection' => 3,
            'user-agent' => 'OnKupon-Agent-SitemapAudit/' . ONKUPON_AGENT_VERSION,
        ];

        $response = wp_remote_head( $url, $args );
        if ( is_wp_error( $response ) ) {
            return 0;
        }
        $status = (int) wp_remote_retrieve_response_code( $response );

        // Bazı sunucular HEAD'i reddeder; bu durumda GET ile doğrula.
        if ( 405 === $status || 501 === $status || 0 === $status ) {
            $response = wp_remote_get( $url, $args );
            if ( is_wp_error( $response ) ) {
                return 0;
            }
            $status = (int) wp_remote_retrieve_response_code( $response );
        }

        return $status;
    }

    private function group_by_status( array $broken ): array {
        $out = [];
        foreach ( $broken as $row ) {
            $key = (string) ( $row['status'] ?? 0 );
            $out[ $key ] = ( $out[ $key ] ?? 0 ) + 1;
        }
        arsort( $out );
        return $out;
    }

    /**
     * Panelde gösterilecek son sonuç.
     */
    public static function last_result(): array {
        $result = get_option( self::RESULT_OPTION, [] );
        return is_array( $result ) ? $result : [];
    }

    /**
     * Devam eden taramanın ilerlemesi.
     */
    public static function progress(): array {
        $state = get_option( self::STATE_OPTION, [] );
        if ( ! is_array( $state ) || empty( $state['urls'] ) ) {
            return [ 'running' => false, 'cursor' => 0, 'total' => 0 ];
        }
        return [ 'running' => true, 'cursor' => absint( $state['cursor'] ?? 0 ), 'total' => count( (array) $state['urls'] ) ];
    }

    public static function reset(): void {
        delete_option( self::STATE_OPTION );
    }
}
