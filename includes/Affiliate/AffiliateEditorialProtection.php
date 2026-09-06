<?php
namespace OnKupon\Agent\Affiliate;

class AffiliateEditorialProtection {
    public function register(): void {
        add_action( 'save_post_product', [ $this, 'protect_manual_editorial' ], 20, 3 );
    }

    public function protect_manual_editorial( int $post_id, \WP_Post $post, bool $update ): void {
        if ( ! $update || 'product' !== $post->post_type || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }
        if ( ! is_admin() || wp_doing_ajax() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
            return;
        }
        if ( 'partnerstack' !== sanitize_key( (string) get_post_meta( $post_id, '_onkupon_affiliate_provider', true ) ) ) {
            return;
        }
        if ( '1' !== (string) get_post_meta( $post_id, '_onkupon_affiliate_managed', true ) ) {
            return;
        }
        $submitted_id = absint( wp_unslash( $_POST['post_ID'] ?? 0 ) );
        $nonce = sanitize_text_field( wp_unslash( $_POST['woocommerce_meta_nonce'] ?? '' ) );
        if ( $submitted_id !== $post_id || ! $nonce || ! wp_verify_nonce( $nonce, 'woocommerce_save_data' ) ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }
        update_post_meta( $post_id, '_onkupon_affiliate_preserve_editorial', 1 );
    }
}
