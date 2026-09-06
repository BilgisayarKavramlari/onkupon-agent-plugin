<?php
namespace OnKupon\Agent\Affiliate;

use OnKupon\Agent\Logging\ActionTimelineRepository;
use OnKupon\Agent\Logging\Logger;
use OnKupon\Agent\Plugin;

/**
 * PartnerStack marketplace'ini tarar, yeni programları skorlar ve eşiği
 * geçenleri onay kuyruğuna alır.
 *
 * Keşif, skorlama ve karar tamamen otonomdur. İnsan yalnızca başvurunun
 * kendisinde devreye girer; çünkü Partner API başvuru oluşturma ucu sunmaz
 * (POST /v2/applications Vendor API'ye aittir ve program sahibinin kimliğini
 * ister). Bu yüzden akış, insan etkileşimini program başına tek tıka indirir.
 */
class MarketplaceDiscoveryService {

    private const LAST_RUN_OPTION = 'onkupon_partnerstack_last_discovery';

    public function run( bool $full_rescan = false ): array {
        $settings = Plugin::settings();
        $counters = [ 'fetched' => 0, 'new' => 0, 'queued' => 0, 'skipped' => 0, 'blocked' => 0, 'errors' => 0 ];

        if ( empty( $settings['partnerstack_enabled'] ) || empty( $settings['partnerstack_discovery_enabled'] ) ) {
            ( new ActionTimelineRepository() )->record( 'marketplace_discovery', 'skipped', [ 'notes' => 'Marketplace discovery is disabled' ] );
            return $counters + [ 'error' => 'disabled' ];
        }

        // Damga YALNIZCA başarılı bir taramadan ilerler. Başarısız bir çalışma
        // pencereyi daraltırsa sonraki tarama hiçbir programı görmez.
        $last = get_option( self::LAST_RUN_OPTION, [] );
        $since_ms = 0;
        if ( ! $full_rescan && is_array( $last ) && ! empty( $last['last_success_at'] ) ) {
            $timestamp = strtotime( (string) $last['last_success_at'] );
            if ( $timestamp ) {
                $since_ms = ( $timestamp - DAY_IN_SECONDS ) * 1000;
            }
        }
        if ( 0 === $since_ms ) {
            // İlk tarama veya tam yeniden tarama: son 180 gün.
            $since_ms = ( time() - ( 180 * DAY_IN_SECONDS ) ) * 1000;
        }

        $result = ( new PartnerStackClient() )->list_marketplace_programs(
            absint( $settings['partnerstack_discovery_limit'] ?? 100 ),
            $since_ms
        );

        if ( empty( $result['ok'] ) ) {
            ++$counters['errors'];
            $previous_success = is_array( $last ) ? (string) ( $last['last_success_at'] ?? '' ) : '';
            update_option(
                self::LAST_RUN_OPTION,
                [ 'at' => current_time( 'mysql' ), 'ok' => false, 'error' => sanitize_text_field( (string) ( $result['error'] ?? '' ) ), 'last_success_at' => $previous_success ],
                false
            );
            return $counters + [ 'error' => (string) ( $result['error'] ?? '' ) ];
        }

        $programs = (array) ( $result['items'] ?? [] );
        $counters['fetched'] = count( $programs );

        $repository = new MarketplaceProgramRepository();
        $scorer = new MarketplaceProgramScorer();
        $coordinator = new ProgramApplicationCoordinator();

        $partnered = $repository->partnered_keys();
        $histogram = $this->category_histogram();
        $threshold = (float) ( $settings['partnerstack_min_program_score'] ?? 65 );

        foreach ( $programs as $program ) {
            $key = (string) ( $program['company_key'] ?? '' );
            if ( '' === $key ) {
                ++$counters['skipped'];
                continue;
            }

            // Zaten iş birliği kurulmuşsa keşif dışı.
            if ( in_array( $key, $partnered, true ) ) {
                $repository->upsert( $program + [ 'decision' => 'joined', 'decision_reason' => 'Sitede bu programa ait ürün mevcut' ] );
                ++$counters['skipped'];
                continue;
            }

            $known = $repository->find( $key );
            $evaluation = $scorer->score( $program, $histogram );

            $record = $program + [
                'score' => $evaluation['score'],
                'score_breakdown' => $evaluation['breakdown'],
            ];

            // Daha önce verilmiş bir kararı asla ezme.
            if ( $known && ! in_array( (string) $known['decision'], [ '', 'pending' ], true ) ) {
                $repository->upsert( $record );
                ++$counters['skipped'];
                continue;
            }

            if ( ! $known ) {
                ++$counters['new'];
            }

            if ( ! empty( $evaluation['breakdown']['blocked'] ) ) {
                ++$counters['blocked'];
                $record['decision'] = 'blocked';
                $record['decision_reason'] = implode( ' · ', $evaluation['reasons'] );
                $repository->upsert( $record );
                continue;
            }

            if ( $evaluation['score'] < $threshold ) {
                ++$counters['skipped'];
                $record['decision'] = 'below_threshold';
                $record['decision_reason'] = sprintf( 'Skor %.1f, eşik %.1f altında. %s', $evaluation['score'], $threshold, implode( ' · ', $evaluation['reasons'] ) );
                $repository->upsert( $record );
                continue;
            }

            $record['decision'] = 'pending';
            $record['decision_reason'] = sprintf( 'Skor %.1f eşiği geçti. %s', $evaluation['score'], implode( ' · ', $evaluation['reasons'] ) );
            $repository->upsert( $record );

            if ( $coordinator->enqueue( $key ) ) {
                ++$counters['queued'];
            }
        }

        $notification = ( new ProgramDigestNotifier() )->send_if_pending();

        update_option(
            self::LAST_RUN_OPTION,
            [ 'at' => current_time( 'mysql' ), 'ok' => true, 'last_success_at' => current_time( 'mysql' ), 'full_rescan' => $full_rescan, 'summary' => $counters, 'notification' => $notification ],
            false
        );
        ( new Logger() )->log( 'info', 'affiliate', 'PartnerStack marketplace discovery completed', $counters + [ 'notification' => $notification['status'] ?? '' ] );
        ( new ActionTimelineRepository() )->record( 'marketplace_discovery', 'completed', [ 'notes' => 'Marketplace discovery completed', 'metadata' => $counters ] );

        return $counters;
    }

    /**
     * Mevcut iş birliklerinin kategori dağılımı; portföy çeşitliliği skoru için.
     */
    private function category_histogram(): array {
        $histogram = [];
        $product_ids = get_posts(
            [
                'post_type' => 'product',
                'post_status' => [ 'publish', 'draft', 'private', 'pending' ],
                'posts_per_page' => -1,
                'fields' => 'ids',
                'meta_key' => '_onkupon_affiliate_provider',
                'meta_value' => 'partnerstack',
                'no_found_rows' => true,
            ]
        );
        foreach ( $product_ids as $product_id ) {
            $terms = wp_get_post_terms( (int) $product_id, 'product_cat', [ 'fields' => 'names' ] );
            if ( is_wp_error( $terms ) ) {
                continue;
            }
            foreach ( $terms as $name ) {
                $slug = strtolower( trim( (string) $name ) );
                if ( '' !== $slug ) {
                    $histogram[ $slug ] = ( $histogram[ $slug ] ?? 0 ) + 1;
                }
            }
        }
        return $histogram;
    }
}
