<?php
namespace OnKupon\Agent\Affiliate;

use OnKupon\Agent\Logging\ActionTimelineRepository;
use OnKupon\Agent\Logging\Logger;

/**
 * Eksik kalmış ortaklık ürünlerini tamamlar.
 *
 * Ana senkron altı saatte bir çalışır ve tur başına sınırlı sayıda ürün için
 * metin/görsel üretir; bu, PHP zaman aşımını önler ama eksik ürünlerin
 * tamamlanmasını yavaşlatır. Bu servis boşluğu kapatır: kısa aralıklarla
 * çalışır, yalnızca eksik ürünleri ele alır ve her turda azıcık iş yapar.
 * Böylece kuyruk kendiliğinden ve öngörülebilir biçimde boşalır.
 */
class AffiliateEnrichmentService {

    private const PER_RUN = 2;

    public function run(): array {
        $summary = [ 'candidates' => 0, 'enriched' => 0, 'failed' => 0, 'product_ids' => [] ];

        $candidates = $this->candidates();
        $summary['candidates'] = count( $candidates );
        if ( ! $candidates ) {
            return $summary;
        }

        AffiliateContentComposer::reset_run();
        AffiliateImageResolver::reset_run();

        $composer = new AffiliateContentComposer();
        $resolver = new AffiliateImageResolver();

        foreach ( array_slice( $candidates, 0, self::PER_RUN ) as $product_id ) {
            $product = wc_get_product( $product_id );
            if ( ! $product ) {
                continue;
            }

            $program = $this->program_from_meta( $product_id, $product );
            if ( '' === $program['referral_url'] ) {
                ++$summary['failed'];
                continue;
            }

            try {
                $needs_text = '' === (string) get_post_meta( $product_id, AffiliateContentComposer::META_CONTENT_HASH, true )
                    && $composer->needs_content( $product, $program );
                $composed = $needs_text ? $composer->compose( $program, $program['referral_url'] ) : [];
                if ( $composed ) {
                    $product->set_name( sanitize_text_field( (string) $composed['title'] ) );
                    $product->set_short_description( wp_kses_post( (string) $composed['short'] ) );
                    $product->set_description( wp_kses_post( (string) $composed['long'] ) );
                    if ( ! empty( $composed['category_ids'] ) ) {
                        $product->set_category_ids( array_map( 'absint', (array) $composed['category_ids'] ) );
                    }
                    $product->save();

                    update_post_meta( $product_id, AffiliateContentComposer::META_CONTENT_HASH, sanitize_text_field( $program['source_hash'] ) );
                    if ( ! empty( $composed['tags'] ) ) {
                        wp_set_object_terms( $product_id, array_map( 'sanitize_text_field', (array) $composed['tags'] ), 'product_tag', false );
                    }
                    $adapter = new \OnKupon\Agent\SEO\AIOSEOAdapter();
                    if ( $adapter->is_available() ) {
                        $adapter->apply(
                            $product_id,
                            [
                                'seo_title'        => (string) $composed['title'],
                                'meta_description' => (string) $composed['meta_description'],
                                'focus_keyphrase'  => (string) $composed['focus_keyphrase'],
                            ]
                        );
                    }
                    ++$summary['enriched'];
                    $summary['product_ids'][] = $product_id;
                } elseif ( $needs_text ) {
                    ++$summary['failed'];
                }

                // Bu servis zaten yalnizca eksik urunleri ele aliyor; gorsel
                // icin 12 saatlik bekleme penceresi burada gecerli degil.
                delete_post_meta( $product_id, AffiliateImageResolver::META_CHECKED );
                $resolver->ensure( $product_id, $program );
            } catch ( \Throwable $e ) {
                ++$summary['failed'];
                ( new Logger() )->log( 'warning', 'affiliate', 'Affiliate enrichment failed', [ 'product_id' => $product_id, 'error' => sanitize_text_field( $e->getMessage() ) ] );
            }
        }

        if ( $summary['enriched'] || $summary['failed'] ) {
            ( new Logger() )->log( 'info', 'affiliate', 'Affiliate enrichment pass completed', $summary );
            ( new ActionTimelineRepository() )->record(
                'affiliate_enrichment',
                $summary['enriched'] ? 'completed' : 'failed',
                [ 'notes' => sprintf( '%d üründen %d tanesi tamamlandı', $summary['candidates'], $summary['enriched'] ), 'metadata' => $summary ]
            );
        }

        return $summary;
    }

    /**
     * Metni hâlâ eksik olan yönetilen ortaklık ürünleri.
     *
     * @return int[]
     */
    public function candidates(): array {
        $product_ids = get_posts(
            [
                'post_type'      => 'product',
                'post_status'    => [ 'publish', 'draft', 'private', 'pending' ],
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'no_found_rows'  => true,
                'meta_query'     => [
                    [
                        'key'   => '_onkupon_affiliate_provider',
                        'value' => 'partnerstack',
                    ],
                ],
            ]
        );

        $composer = new AffiliateContentComposer();
        $pending = [];
        foreach ( $product_ids as $product_id ) {
            $product_id = (int) $product_id;
            if ( '1' === (string) get_post_meta( $product_id, AffiliateContentComposer::META_PRESERVE, true ) ) {
                continue;
            }
            $product = wc_get_product( $product_id );
            if ( ! $product ) {
                continue;
            }

            $has_text = '' !== (string) get_post_meta( $product_id, AffiliateContentComposer::META_CONTENT_HASH, true );
            $needs_text = ! $has_text && $composer->needs_content( $product, [ 'source_hash' => (string) get_post_meta( $product_id, '_onkupon_affiliate_source_hash', true ) ] );

            // Gorseli hala ajanin urettigi gradyan kart olan urun de eksiktir.
            $image_id = (int) $product->get_image_id();
            $needs_image = ! $image_id || AffiliateImageResolver::is_placeholder( $image_id );

            if ( $needs_text || $needs_image ) {
                $pending[] = $product_id;
            }
        }

        return $pending;
    }

    /**
     * Ürün metasından asgari program yükü kurar. Metnin birincil kaynağı zaten
     * hedef sitenin kendisi olduğu için PartnerStack açıklaması gerekmez.
     */
    private function program_from_meta( int $product_id, $product ): array {
        $brand = (string) get_post_meta( $product_id, '_onkupon_affiliate_brand', true );
        if ( '' === $brand ) {
            $brand = (string) $product->get_name();
        }

        return [
            'key'           => (string) get_post_meta( $product_id, '_onkupon_partnerstack_key', true ),
            'name'          => $brand,
            'description'   => '',
            'offer_summary' => '',
            'referral_url'  => (string) get_post_meta( $product_id, '_onkupon_affiliate_destination', true ),
            'logo_url'      => (string) get_post_meta( $product_id, '_onkupon_affiliate_logo_url', true ),
            'source_hash'   => (string) get_post_meta( $product_id, '_onkupon_affiliate_source_hash', true ),
            'active'        => true,
        ];
    }
}

