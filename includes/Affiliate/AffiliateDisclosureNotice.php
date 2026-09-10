<?php
namespace OnKupon\Agent\Affiliate;

/**
 * Is ortakligi bildirimini urun aciklamasinin disinda gosterir.
 *
 * Bildirim metni editoryal icerigin parcasi degildir: okuyucuya urunu
 * anlatmaz, ticari iliskiyi aciklar. Bu yuzden aciklama govdesinden cikarilip
 * satin alma baglantisinin hemen altinda, kucuk ve tek satirlik bir not olarak
 * gosterilir. Bildirimin kendisi kaldirilamaz: ticari baglantilarin acikca
 * belirtilmesi hem Reklam Kurulu duzenlemeleri hem de arama motoru
 * yonergeleri acisindan zorunludur.
 */
class AffiliateDisclosureNotice {

    public function register(): void {
        add_action( 'woocommerce_single_product_summary', [ $this, 'render' ], 35 );
    }

    public function render(): void {
        $product = function_exists( 'wc_get_product' ) ? wc_get_product( get_the_ID() ) : null;
        if ( ! $product || ! $product->is_type( 'external' ) ) {
            return;
        }
        if ( '' === (string) get_post_meta( (int) $product->get_id(), '_onkupon_affiliate_provider', true ) ) {
            return;
        }

        $text = apply_filters(
            'onkupon_agent_affiliate_disclosure',
            'Bağlantı, hizmet sağlayıcının resmi sayfasına gider. Fiyat ve koşullar sağlayıcıya aittir; OnKupon iş ortaklığı geliri elde edebilir.'
        );

        printf(
            '<p class="onkupon-affiliate-disclosure" style="margin:12px 0 0;font-size:12px;line-height:1.5;color:#6b7280">%s</p>',
            esc_html( (string) $text )
        );
    }
}
