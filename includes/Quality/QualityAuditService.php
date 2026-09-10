<?php
namespace OnKupon\Agent\Quality;

use OnKupon\Agent\Affiliate\AffiliateContentComposer;
use OnKupon\Agent\Affiliate\AffiliateImageResolver;
use OnKupon\Agent\Logging\ActionTimelineRepository;
use OnKupon\Agent\Logging\Logger;

/**
 * Katalog kalite denetçisi.
 *
 * Üretim ajanları "bir şey üretmiş olmayı" başarı sayar; bu denetçi ise
 * üretilenin yeterli olup olmadığını sorar. Her turda kataloğun bir dilimini
 * gezer, ürünün görselini, başlığını, açıklamasını, kategorisini, etiketlerini
 * ve SEO alanlarını kurallara göre puanlar. Onarılabilir bulguları ilgili
 * ajanın yeniden ele alması için işaretler; onarılamayanları rapora yazar.
 *
 * Tasarım ilkesi: denetçi kendisi içerik üretmez. Yalnızca teşhis koyar ve işi
 * üretim ajanına geri verir. Böylece tek bir yerde iki farklı sorumluluk
 * birikmez ve denetim ucuz kalır.
 */
class QualityAuditService {

    public const REPORT_OPTION = 'onkupon_agent_quality_report';
    public const CURSOR_OPTION = 'onkupon_agent_quality_cursor';

    private const PER_RUN = 12;
    private const REPAIR_PER_RUN = 4;
    private const MIN_DESCRIPTION_CHARS = 320;
    private const MIN_TAGS = 3;

    /**
     * Bulgu türleri: kod => [ağırlık, onarılabilir mi, açıklama].
     */
    public const RULES = [
        'description_confidential' => [ 40, true,  'Açıklamada gizli ortaklık şartı var' ],
        'image_missing'            => [ 20, true,  'Öne çıkan görsel yok' ],
        'image_challenge_shot'     => [ 20, true,  'Görsel bot doğrulama/boş ekran görüntüsü' ],
        'description_thin'         => [ 18, true,  'Açıklama çok kısa' ],
        'category_missing'         => [ 15, true,  'Kategori yok veya yalnızca eğitim kategorisinde' ],
        'title_brand_only'         => [ 12, true,  'Başlık yalnızca marka adı' ],
        'image_placeholder'        => [ 10, true,  'Görsel hâlâ üretilmiş gradyan kart' ],
        'short_description_missing'=> [ 10, true,  'Kısa açıklama yok' ],
        'tags_missing'             => [  8, true,  'Etiket sayısı yetersiz' ],
        'seo_keyphrase_missing'    => [  6, true,  'Odak anahtar kelime yok' ],
        'disclosure_in_body'       => [  5, true,  'Bildirim metni açıklama gövdesinde' ],
        'destination_unreachable'  => [ 15, false, 'Hedef bağlantı yanıt vermiyor' ],
    ];

    public function run(): array {
        $ids = $this->catalogue();
        if ( ! $ids ) {
            return [ 'inspected' => 0, 'issues' => 0, 'queued' => 0 ];
        }

        $cursor = absint( get_option( self::CURSOR_OPTION, 0 ) );
        if ( $cursor >= count( $ids ) ) {
            $cursor = 0;
        }
        $slice = array_slice( $ids, $cursor, self::PER_RUN );

        $findings = [];
        $queued = 0;

        foreach ( $slice as $product_id ) {
            $issues = $this->inspect( (int) $product_id );
            if ( ! $issues ) {
                continue;
            }
            $findings[] = [
                'product_id' => (int) $product_id,
                'title'      => get_the_title( (int) $product_id ),
                'issues'     => $issues,
                'score'      => $this->score( $issues ),
            ];
            if ( $queued < self::REPAIR_PER_RUN && $this->queue_repair( (int) $product_id, $issues ) ) {
                ++$queued;
            }
        }

        $next = $cursor + self::PER_RUN;
        update_option( self::CURSOR_OPTION, $next >= count( $ids ) ? 0 : $next, false );

        $this->merge_report( $findings, $slice, count( $ids ), $next >= count( $ids ) );

        ( new Logger() )->log(
            'info',
            'quality',
            'Kalite denetimi turu tamamlandı',
            [ 'inspected' => count( $slice ), 'flagged' => count( $findings ), 'queued_for_repair' => $queued, 'catalogue' => count( $ids ) ]
        );
        ( new ActionTimelineRepository() )->record(
            'quality_audit',
            $findings ? 'failed' : 'completed',
            [ 'notes' => sprintf( '%d üründen %d tanesinde bulgu', count( $slice ), count( $findings ) ), 'metadata' => [ 'queued' => $queued ] ]
        );

        return [ 'inspected' => count( $slice ), 'issues' => count( $findings ), 'queued' => $queued ];
    }

    /**
     * Tek bir ürünü kurallara göre denetler.
     *
     * @return string[] bulgu kodları
     */
    public function inspect( int $product_id ): array {
        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return [];
        }

        $issues = [];
        $long = (string) $product->get_description();
        $short = (string) $product->get_short_description();
        $plain = trim( wp_strip_all_tags( $long ) );

        if ( AffiliateContentComposer::contains_confidential( $long ) || AffiliateContentComposer::contains_confidential( $short ) ) {
            $issues[] = 'description_confidential';
        }
        if ( mb_strlen( $plain ) < self::MIN_DESCRIPTION_CHARS ) {
            $issues[] = 'description_thin';
        }
        if ( false !== mb_strpos( $long, AffiliateContentComposer::DISCLOSURE ) ) {
            $issues[] = 'disclosure_in_body';
        }
        if ( '' === trim( wp_strip_all_tags( $short ) ) ) {
            $issues[] = 'short_description_missing';
        }

        $title = (string) $product->get_name();
        if ( mb_strlen( $title ) < 26 && false === mb_strpos( $title, '–' ) && false === mb_strpos( $title, '-' ) ) {
            $issues[] = 'title_brand_only';
        }

        $image_id = (int) $product->get_image_id();
        if ( ! $image_id ) {
            $issues[] = 'image_missing';
        } else {
            $asset = (string) get_post_meta( $image_id, '_onkupon_agent_generated_asset', true );
            if ( 'affiliate_card' === $asset ) {
                $issues[] = 'image_placeholder';
            } elseif ( 'affiliate_screenshot' === $asset ) {
                $path = get_attached_file( $image_id );
                if ( $path && file_exists( $path ) && AffiliateImageResolver::looks_blank( $path ) ) {
                    $issues[] = 'image_challenge_shot';
                }
            }
        }

        if ( ! $this->has_meaningful_category( $product ) ) {
            $issues[] = 'category_missing';
        }

        $tags = wp_get_post_terms( $product_id, 'product_tag', [ 'fields' => 'ids' ] );
        if ( is_wp_error( $tags ) || count( $tags ) < self::MIN_TAGS ) {
            $issues[] = 'tags_missing';
        }

        if ( '' === trim( (string) get_post_meta( $product_id, '_aioseo_keywords', true ) ) ) {
            $issues[] = 'seo_keyphrase_missing';
        }

        return $issues;
    }

    private function has_meaningful_category( $product ): bool {
        $excluded = [];
        foreach ( AffiliateContentComposer::NON_PRODUCT_SLUGS as $slug ) {
            $term = get_term_by( 'slug', $slug, 'product_cat' );
            if ( $term && ! is_wp_error( $term ) ) {
                $excluded[] = (int) $term->term_id;
            }
        }
        $assigned = array_map( 'absint', (array) $product->get_category_ids() );
        return (bool) array_diff( $assigned, $excluded );
    }

    /**
     * Onarımı üretim ajanına devreder: içerik özeti ve görsel damgası
     * silinince zenginleştirme işi o ürünü yeniden ele alır.
     */
    private function queue_repair( int $product_id, array $issues ): bool {
        $repairable = false;
        foreach ( $issues as $issue ) {
            if ( ! empty( self::RULES[ $issue ][1] ) ) {
                $repairable = true;
                break;
            }
        }
        if ( ! $repairable ) {
            return false;
        }

        delete_post_meta( $product_id, AffiliateContentComposer::META_CONTENT_HASH );
        delete_post_meta( $product_id, AffiliateImageResolver::META_CHECKED );

        // Kusurlu görsel öne çıkan görsellikten düşürülür ki zenginleştirme
        // yeni bir aday arasın; dosya silinmez, medya kitaplığında kalır.
        if ( in_array( 'image_challenge_shot', $issues, true ) ) {
            delete_post_thumbnail( $product_id );
        }

        update_post_meta( $product_id, '_onkupon_quality_repair_queued_at', current_time( 'mysql' ) );
        return true;
    }

    private function score( array $issues ): int {
        $penalty = 0;
        foreach ( $issues as $issue ) {
            $penalty += (int) ( self::RULES[ $issue ][0] ?? 5 );
        }
        return max( 0, 100 - $penalty );
    }

    /**
     * @return int[]
     */
    private function catalogue(): array {
        $ids = get_posts(
            [
                'post_type'      => 'product',
                'post_status'    => [ 'publish', 'draft', 'private', 'pending' ],
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'orderby'        => 'ID',
                'order'          => 'ASC',
                'no_found_rows'  => true,
                'meta_query'     => [
                    [
                        'key'   => '_onkupon_affiliate_provider',
                        'value' => 'partnerstack',
                    ],
                ],
            ]
        );
        return array_map( 'absint', (array) $ids );
    }

    /**
     * Rapor turlar arasında birikir: bu turda temiz çıkan ürünler listeden
     * düşer, bulgulu olanlar eklenir. Böylece panel her zaman kataloğun
     * tamamına dair güncel bir tablo gösterir.
     */
    private function merge_report( array $findings, array $inspected, int $catalogue_size, bool $completed_cycle ): void {
        $report = get_option( self::REPORT_OPTION, [] );
        $items = is_array( $report['items'] ?? null ) ? $report['items'] : [];

        foreach ( $inspected as $product_id ) {
            unset( $items[ (string) (int) $product_id ] );
        }
        foreach ( $findings as $finding ) {
            $items[ (string) $finding['product_id'] ] = $finding;
        }

        $by_issue = [];
        foreach ( $items as $item ) {
            foreach ( (array) ( $item['issues'] ?? [] ) as $issue ) {
                $by_issue[ $issue ] = ( $by_issue[ $issue ] ?? 0 ) + 1;
            }
        }
        arsort( $by_issue );

        $healthy = max( 0, $catalogue_size - count( $items ) );

        update_option(
            self::REPORT_OPTION,
            [
                'at'              => current_time( 'mysql' ),
                'catalogue'       => $catalogue_size,
                'flagged'         => count( $items ),
                'healthy'         => $healthy,
                'health_percent'  => $catalogue_size > 0 ? (int) round( 100 * $healthy / $catalogue_size ) : 100,
                'by_issue'        => $by_issue,
                'completed_cycle' => $completed_cycle,
                'items'           => array_slice( $items, 0, 300, true ),
            ],
            false
        );
    }

    public static function report(): array {
        $report = get_option( self::REPORT_OPTION, [] );
        return is_array( $report ) ? $report : [];
    }
}
