<?php
namespace OnKupon\Agent\Admin;

use OnKupon\Agent\Plugin;
use OnKupon\Agent\Security\CapabilityManager;

class SettingsPage extends BasePage {
    public function render(): void {
        if ( isset( $_POST['onkupon_save'] ) && current_user_can( CapabilityManager::capability() ) ) {
            check_admin_referer( 'onkupon_agent_settings' );
            $settings = Plugin::settings();
            $fields = [ 'daily_article_limit', 'daily_social_limit', 'daily_social_post_limit', 'min_quality_score', 'max_risk_score', 'min_article_words', 'target_article_words', 'max_article_words', 'heading_count', 'faq_count', 'product_link_count', 'product_card_count', 'default_post_category_id', 'allowed_category_ids', 'category_strategy', 'default_author_id', 'default_featured_image_id', 'content_language', 'openai_base_url', 'openai_model', 'openai_temperature', 'openai_max_tokens', 'daily_budget', 'request_timeout', 'rss_sources', 'source_allowlist', 'source_blocklist', 'target_categories', 'excluded_categories', 'excluded_products', 'preferred_products', 'exploration_rate', 'review_delay_days', 'review_max_reminders', 'x_daily_cost_limit', 'x_daily_post_limit', 'linkedin_daily_post_limit', 'partnerstack_sync_limit', 'partnerstack_missing_threshold', 'partnerstack_default_category_id', 'partnerstack_button_text', 'partnerstack_notification_email', 'ai_provider', 'anthropic_base_url', 'anthropic_model', 'partnerstack_discovery_limit', 'partnerstack_target_categories', 'partnerstack_blocked_categories', 'partnerstack_min_program_score', 'partnerstack_discovery_daily_limit' ];
            foreach ( $fields as $field ) {
                if ( 'min_article_words' === $field ) {
                    $settings[ $field ] = max( 100, absint( wp_unslash( $_POST[ $field ] ?? $settings[ $field ] ?? 800 ) ) );
                    continue;
                }
                $settings[ $field ] = sanitize_text_field( wp_unslash( $_POST[ $field ] ?? $settings[ $field ] ?? '' ) );
            }
            $settings['partnerstack_notification_email'] = sanitize_email( (string) ( $settings['partnerstack_notification_email'] ?? '' ) );
            foreach ( [ 'safe_mode', 'schema_enabled', 'learning_enabled', 'review_requests_enabled', 'auto_publish_verified_reviews', 'use_first_product_image_as_featured', 'use_category_fallback_image', 'x_allow_url_posts', 'x_text_only_mode', 'comparison_table_enabled', 'viral_title_enabled', 'allow_create_categories', 'social_linkedin_enabled', 'social_x_enabled', 'social_facebook_enabled', 'social_instagram_enabled', 'partnerstack_enabled', 'partnerstack_auto_publish', 'partnerstack_notifications_enabled', 'revenue_link_audit_enabled', 'seo_auto_repair_rewrites', 'autonomous_cro_enabled', 'popular_tools_page_enabled', 'indexnow_enabled', 'partnerstack_discovery_enabled' ] as $flag ) {
                $settings[ $flag ] = ! empty( $_POST[ $flag ] );
            }
            Plugin::save_settings( $settings );
            echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'onkupon-agent' ) . '</p></div>';
        }
        $settings = Plugin::settings();
        $this->header( __( 'Settings', 'onkupon-agent' ) );
        echo '<form method="post">';
        wp_nonce_field( 'onkupon_agent_settings' );
        foreach ( [ 'daily_article_limit', 'daily_social_limit', 'daily_social_post_limit', 'min_quality_score', 'max_risk_score', 'min_article_words', 'target_article_words', 'max_article_words', 'heading_count', 'faq_count', 'product_link_count', 'product_card_count', 'default_post_category_id', 'allowed_category_ids', 'category_strategy', 'default_author_id', 'default_featured_image_id', 'content_language', 'openai_base_url', 'openai_model', 'openai_temperature', 'openai_max_tokens', 'daily_budget', 'request_timeout', 'rss_sources', 'source_allowlist', 'source_blocklist', 'target_categories', 'excluded_categories', 'excluded_products', 'preferred_products', 'exploration_rate', 'review_delay_days', 'review_max_reminders', 'x_daily_cost_limit', 'x_daily_post_limit', 'linkedin_daily_post_limit', 'partnerstack_sync_limit', 'partnerstack_missing_threshold', 'partnerstack_default_category_id', 'partnerstack_button_text', 'partnerstack_notification_email', 'ai_provider', 'anthropic_base_url', 'anthropic_model', 'partnerstack_discovery_limit', 'partnerstack_target_categories', 'partnerstack_blocked_categories', 'partnerstack_min_program_score', 'partnerstack_discovery_daily_limit' ] as $field ) {
            echo '<p><label><strong>' . esc_html( ucwords( str_replace( '_', ' ', $field ) ) ) . '</strong><br><input class="regular-text" name="' . esc_attr( $field ) . '" value="' . esc_attr( (string) ( $settings[ $field ] ?? '' ) ) . '"></label></p>';
        }
        foreach ( [ 'safe_mode', 'schema_enabled', 'learning_enabled', 'review_requests_enabled', 'auto_publish_verified_reviews', 'use_first_product_image_as_featured', 'use_category_fallback_image', 'x_allow_url_posts', 'x_text_only_mode', 'comparison_table_enabled', 'viral_title_enabled', 'allow_create_categories', 'social_linkedin_enabled', 'social_x_enabled', 'social_facebook_enabled', 'social_instagram_enabled', 'partnerstack_enabled', 'partnerstack_auto_publish', 'partnerstack_notifications_enabled', 'revenue_link_audit_enabled', 'seo_auto_repair_rewrites', 'autonomous_cro_enabled', 'popular_tools_page_enabled', 'indexnow_enabled', 'partnerstack_discovery_enabled' ] as $flag ) {
            echo '<p><label><input type="checkbox" name="' . esc_attr( $flag ) . '" value="1" ' . checked( ! empty( $settings[ $flag ] ), true, false ) . '> ' . esc_html( ucwords( str_replace( '_', ' ', $flag ) ) ) . '</label></p>';
        }
        echo '<p><button class="button button-primary" name="onkupon_save" value="1">' . esc_html__( 'Save Settings', 'onkupon-agent' ) . '</button></p></form>';
        $this->footer();
    }
}
