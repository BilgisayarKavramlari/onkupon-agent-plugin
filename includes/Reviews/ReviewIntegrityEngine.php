<?php
namespace OnKupon\Agent\Reviews;

use OnKupon\Agent\Logging\Logger;
use OnKupon\Agent\Plugin;

class ReviewIntegrityEngine {
    public function send_due_requests(): int {
        if ( empty( Plugin::settings()['review_requests_enabled'] ) || ! function_exists( 'wc_get_orders' ) ) {
            return 0;
        }
        // Production integrations should send via wp_mail or configured email provider after verified order lookup.
        ( new Logger() )->log( 'info', 'reviews', 'Review request scan completed' );
        return 0;
    }

    public function can_publish_review( int $review_id, int $customer_id, int $product_id ): bool {
        unset( $review_id );
        return ( new VerifiedBuyerChecker() )->is_verified( $customer_id, $product_id );
    }

    public function editorial_label(): string {
        return __( 'Editorial / AI-assisted product insight — not a customer review.', 'onkupon-agent' );
    }

    public function policy(): string {
        return 'Never creates fake reviews, fake ratings, impersonation, or fake social proof; only verified buyer review workflows and labeled editorial insights are allowed.';
    }
}
