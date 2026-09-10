<?php
namespace OnKupon\Agent;

use OnKupon\Agent\Admin\AdminMenu;
use OnKupon\Agent\Affiliate\AffiliateClickTracker;
use OnKupon\Agent\Affiliate\AffiliateEditorialProtection;
use OnKupon\Agent\Affiliate\AffiliateProductSocialPlanner;
use OnKupon\Agent\CLI\Command;
use OnKupon\Agent\Growth\AutonomousConversionOptimizer;
use OnKupon\Agent\Growth\PopularToolsPage;
use OnKupon\Agent\Publishing\SchemaWriter;
use OnKupon\Agent\Scheduler\JobRegistrar;
use OnKupon\Agent\Scheduler\SchedulerDiagnostics;
use OnKupon\Agent\SEO\IndexNowPublisher;
use OnKupon\Agent\Social\OAuth\SocialOAuthManager;
use OnKupon\Agent\Woo\ProductLinker;

class Plugin {
    private static ?self $instance = null;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    public function boot(): void {
        load_plugin_textdomain( 'onkupon-agent', false, dirname( plugin_basename( ONKUPON_AGENT_FILE ) ) . '/languages' );
        $this->maybe_upgrade();
        ( new AdminMenu() )->register();
        ( new JobRegistrar() )->register();
        ( new ProductLinker() )->register_shortcodes();
        ( new SchemaWriter() )->register();
        ( new SocialOAuthManager() )->register_routes();
        ( new \OnKupon\Agent\Affiliate\ProgramDecisionController() )->register();
        ( new AffiliateClickTracker() )->register();
        ( new \OnKupon\Agent\Affiliate\AffiliateDisclosureNotice() )->register();
        ( new AffiliateEditorialProtection() )->register();
        ( new AffiliateProductSocialPlanner() )->register();
        ( new AutonomousConversionOptimizer() )->register();
        ( new PopularToolsPage() )->register();
        ( new IndexNowPublisher() )->register();
        add_action( 'admin_init', [ new SchedulerDiagnostics(), 'repair_missing' ] );

        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            \WP_CLI::add_command( 'onkupon-agent', Command::class );
        }
    }

    public function maybe_upgrade(): void {
        $installed_version = (string) get_option( 'onkupon_agent_db_version', '' );
        if ( $installed_version !== ONKUPON_AGENT_VERSION ) {
            $settings = self::settings();
            if ( ( '' === $installed_version || version_compare( $installed_version, '0.1.3', '<' ) ) && absint( $settings['openai_max_tokens'] ?? 0 ) <= 2500 ) {
                $settings['openai_max_tokens'] = 5000;
                self::save_settings( $settings );
            }
            Installer::install();
        }
    }

    public static function defaults(): array {
        return [
            'agent_status'                 => 'stopped',
            'safe_mode'                    => false,
            'daily_article_limit'          => 3,
            'daily_social_limit'           => 10,
            'content_language'             => 'tr',
            'target_categories'            => '',
            'excluded_categories'          => '',
            'excluded_products'            => '',
            'preferred_products'           => '',
            'min_quality_score'            => 70,
            'max_risk_score'               => 35,
            'min_article_words'            => 800,
            'target_article_words'         => 1400,
            'max_article_words'            => 2200,
            'heading_count'                => 7,
            'faq_count'                    => 5,
            'product_link_count'           => 5,
            'product_card_count'           => 3,
            'comparison_table_enabled'     => true,
            'viral_title_enabled'          => true,
            'default_post_category_id'     => 0,
            'allowed_category_ids'         => '',
            'category_strategy'            => 'fixed',
            'allow_create_categories'      => false,
            'default_author_id'            => 0,
            'default_featured_image_id'    => 0,
            'use_first_product_image_as_featured' => true,
            'use_category_fallback_image'  => false,
            'daily_social_post_limit'      => 10,
            'x_allow_url_posts'            => false,
            'x_daily_cost_limit'           => 0,
            'x_daily_post_limit'           => 5,
            'x_text_only_mode'             => true,
            'linkedin_daily_post_limit'    => 3,
            'openai_base_url'              => 'https://api.openai.com/v1',
            'openai_model'                 => 'gpt-4o-mini',
            'openai_temperature'           => 0.3,
            'openai_max_tokens'            => 5000,
            'ai_provider'                  => 'openai',
            'anthropic_base_url'           => 'https://api.anthropic.com/v1',
            'anthropic_model'              => 'claude-sonnet-5',
            'daily_budget'                 => 10,
            'request_timeout'              => 30,
            'rss_sources'                  => '',
            'source_allowlist'             => '',
            'source_blocklist'             => '',
            'social_linkedin_enabled'      => false,
            'social_x_enabled'             => false,
            'social_facebook_enabled'      => false,
            'social_instagram_enabled'     => false,
            'learning_enabled'             => true,
            'learning_frozen'              => false,
            'exploration_rate'             => 0.15,
            'schema_enabled'               => true,
            'review_requests_enabled'      => false,
            'review_delay_days'            => 7,
            'review_max_reminders'         => 2,
            'auto_publish_verified_reviews'=> false,
            'partnerstack_enabled'        => false,
            'partnerstack_auto_publish'   => false,
            'partnerstack_sync_limit'     => 50,
            'partnerstack_missing_threshold' => 3,
            'partnerstack_default_category_id' => 0,
            'partnerstack_button_text'    => 'Ürünü incele',
            'partnerstack_notifications_enabled' => false,
            'partnerstack_notification_email' => '',
            'partnerstack_discovery_enabled' => false,
            'partnerstack_discovery_limit' => 100,
            'partnerstack_target_categories' => 'AI, SaaS, Marketing, Productivity, Developer Tools, E-commerce, CRM, No-code, Finance, Sales, Analytics',
            'partnerstack_blocked_categories' => 'Gambling, Adult, Crypto Trading',
            'partnerstack_min_program_score' => 65,
            'partnerstack_discovery_daily_limit' => 5,
            'revenue_link_audit_enabled' => false,
            'seo_auto_repair_rewrites'    => false,
            'autonomous_cro_enabled'      => true,
            'popular_tools_page_enabled' => true,
            'indexnow_enabled'            => true,
        ];
    }

    public static function settings(): array {
        return wp_parse_args( get_option( 'onkupon_agent_settings', [] ), self::defaults() );
    }

    public static function save_settings( array $settings ): void {
        update_option( 'onkupon_agent_settings', wp_parse_args( $settings, self::defaults() ), false );
    }

    public static function update_status( string $status ): void {
        $allowed = [ 'running', 'paused', 'stopped', 'emergency_stopped', 'error', 'recovery' ];
        if ( ! in_array( $status, $allowed, true ) ) {
            return;
        }
        $settings = self::settings();
        $settings['agent_status'] = $status;
        self::save_settings( $settings );
    }

    public static function can_publish(): bool {
        $settings = self::settings();
        return 'running' === $settings['agent_status'] && empty( $settings['safe_mode'] );
    }
}
