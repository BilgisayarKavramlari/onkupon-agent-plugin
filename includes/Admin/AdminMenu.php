<?php
namespace OnKupon\Agent\Admin;

use OnKupon\Agent\Logging\Logger;
use OnKupon\Agent\Logging\ActionTimelineRepository;
use OnKupon\Agent\Plugin;
use OnKupon\Agent\Scheduler\ActionSchedulerBridge;
use OnKupon\Agent\Scheduler\JobRegistrar;
use OnKupon\Agent\Scheduler\SchedulerDiagnostics;
use OnKupon\Agent\Security\CapabilityManager;
use OnKupon\Agent\Security\LockManager;

class AdminMenu {
    private static bool $registered = false;
    private static bool $menu_registered = false;

    public function register(): void {
        if ( self::$registered ) {
            return;
        }
        self::$registered = true;
        add_action( 'admin_menu', [ $this, 'menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
        add_action( 'admin_post_onkupon_agent_control', [ $this, 'handle_control' ] );
        add_action( 'admin_notices', [ $this, 'duplicate_notice' ] );
    }

    public function menu(): void {
        if ( self::$menu_registered ) {
            return;
        }
        self::$menu_registered = true;
        $capability = CapabilityManager::capability();
        add_menu_page( 'OnKupon Agent', 'OnKupon Agent', $capability, 'onkupon-agent', [ new DashboardPage(), 'render' ], 'dashicons-chart-line', 56 );
        $pages = [
            'Overview'             => DashboardPage::class,
            'Control Center'       => ControlCenterPage::class,
            'Scheduler Health'     => SchedulerHealthPage::class,
            'Content Timeline'     => ContentTimelinePage::class,
            'Product Intelligence' => ProductIntelligencePage::class,
            'Affiliate Programs'   => AffiliateProgramsPage::class,
            'Program Discovery'    => ProgramDiscoveryPage::class,
            'SEO Health'           => SEOHealthPage::class,
            'Social Queue'         => SocialQueuePage::class,
            'Analytics'            => AnalyticsPage::class,
            'Learning'             => LearningPage::class,
            'Integrations'         => IntegrationsPage::class,
            'Logs'                 => LogsPage::class,
            'Review Integrity'     => ReviewIntegrityPage::class,
            'Settings'             => SettingsPage::class,
        ];
        foreach ( $pages as $title => $class ) {
            $slug = 'Overview' === $title ? 'onkupon-agent' : 'onkupon-agent-' . sanitize_title( $title );
            add_submenu_page( 'onkupon-agent', $title, $title, $capability, $slug, [ new $class(), 'render' ] );
        }
    }

    public function duplicate_notice(): void {
        if ( ! current_user_can( CapabilityManager::capability() ) ) {
            return;
        }
        $active = (array) get_option( 'active_plugins', [] );
        $matches = array_filter( $active, static fn( $plugin ) => str_contains( (string) $plugin, 'onkupon-autonomous-commerce-agent' ) );
        if ( count( $matches ) > 1 ) {
            echo '<div class="notice notice-warning"><p>' . esc_html__( 'Multiple OnKupon Agent plugin instances may be active. Please keep only one plugin folder active.', 'onkupon-agent' ) . '</p></div>';
        }
    }

    public function assets( string $hook ): void {
        if ( ! str_contains( $hook, 'onkupon-agent' ) ) {
            return;
        }
        wp_enqueue_style( 'onkupon-agent', ONKUPON_AGENT_URL . 'assets/admin.css', [], ONKUPON_AGENT_VERSION );
        wp_enqueue_script( 'onkupon-agent-charts', ONKUPON_AGENT_URL . 'assets/charts.js', [], ONKUPON_AGENT_VERSION, true );
        wp_enqueue_script( 'onkupon-agent-admin', ONKUPON_AGENT_URL . 'assets/admin.js', [ 'onkupon-agent-charts' ], ONKUPON_AGENT_VERSION, true );
    }

    public function handle_control(): void {
        if ( ! current_user_can( CapabilityManager::capability() ) ) {
            wp_die( esc_html__( 'Forbidden', 'onkupon-agent' ) );
        }
        check_admin_referer( 'onkupon_agent_control' );
        $action = sanitize_key( wp_unslash( $_POST['agent_action'] ?? '' ) );
        $bridge = new ActionSchedulerBridge();
        $redirect_args = [ 'page' => 'onkupon-agent-control-center', 'oka_notice' => 'ok' ];

        switch ( $action ) {
            case 'start':
            case 'resume':
                Plugin::update_status( 'running' );
                break;
            case 'pause':
                Plugin::update_status( 'paused' );
                break;
            case 'stop':
                Plugin::update_status( 'stopped' );
                break;
            case 'emergency-stop':
                Plugin::update_status( 'emergency_stopped' );
                ( new LockManager() )->clear();
                break;
            case 'run-now':
                $bridge->enqueue( 'onkupon_agent_product_scan' );
                $bridge->enqueue( 'onkupon_agent_research' );
                $bridge->enqueue( 'onkupon_agent_content' );
                $redirect_args['oka_run_now'] = 1;
                $redirect_args['oka_actions'] = 3;
                $redirect_args['oka_scheduler'] = $bridge->available() ? 'yes' : 'no';
                break;
            case 'run-diagnostics':
                $report = ( new SchedulerDiagnostics() )->report();
                $redirect_args['oka_run_now'] = 1;
                $redirect_args['oka_actions'] = count( $report['hooks'] ?? [] );
                $redirect_args['oka_scheduler'] = ! empty( $report['action_scheduler_available'] ) ? 'yes' : 'no';
                break;
            case 'reschedule-all-jobs':
                JobRegistrar::unschedule_all();
                JobRegistrar::schedule_defaults();
                $redirect_args['oka_run_now'] = 1;
                $redirect_args['oka_actions'] = count( JobRegistrar::hooks() );
                $redirect_args['oka_scheduler'] = $bridge->available() ? 'yes' : 'no';
                break;
            case 'clear-onkupon-jobs':
                JobRegistrar::unschedule_all();
                break;
            case 'run-product-scan-now':
                $bridge->enqueue( 'onkupon_agent_product_scan' );
                break;
            case 'run-research-now':
                $bridge->enqueue( 'onkupon_agent_research' );
                break;
            case 'run-content-generation-now':
                $bridge->enqueue( 'onkupon_agent_content' );
                break;
            case 'run-publishing-now':
                $bridge->enqueue( 'onkupon_agent_publish' );
                break;
            case 'run-social-queue-now':
                $bridge->enqueue( 'onkupon_agent_social' );
                break;
            case 'run-metrics-now':
                $bridge->enqueue( 'onkupon_agent_metrics' );
                break;
            case 'run-learning-now':
                $bridge->enqueue( 'onkupon_agent_learning' );
                break;
            case 'run-affiliate-sync-now':
                $bridge->enqueue( 'onkupon_agent_affiliate_sync' );
                break;
            case 'run-affiliate-enrichment-now':
                ( new \OnKupon\Agent\Affiliate\AffiliateEnrichmentService() )->run();
                break;
            case 'run-revenue-link-audit-now':
                $bridge->enqueue( 'onkupon_agent_revenue_link_audit' );
                break;
            case 'run-seo-health-now':
                $bridge->enqueue( 'onkupon_agent_seo_health' );
                break;
            case 'run-content-generation-debug':
                @set_time_limit( 60 );
                ( new \OnKupon\Agent\Scheduler\Jobs\ContentGenerationJob() )->handle();
                $redirect_args['oka_run_now'] = 1;
                $redirect_args['oka_actions'] = 1;
                $redirect_args['oka_scheduler'] = 'synchronous-debug';
                break;
            case 'test-social-queue':
                $queue_id = ( new \OnKupon\Agent\Social\SocialQueueRepository() )->create_dry_run_for_latest_post();
                $redirect_args['oka_run_now'] = 1;
                $redirect_args['oka_actions'] = $queue_id ? 1 : 0;
                $redirect_args['oka_scheduler'] = 'dry-run';
                break;
            case 'collect-metrics':
                $bridge->enqueue( 'onkupon_agent_metrics' );
                break;
            case 'recalculate-scores':
                $bridge->enqueue( 'onkupon_agent_product_scan' );
                break;
            case 'clear-locks':
                ( new LockManager() )->clear();
                break;
            case 'run-sitemap-audit-now':
                \OnKupon\Agent\SEO\SitemapAuditor::reset();
                $bridge->enqueue( 'onkupon_agent_sitemap_audit' );
                $redirect_args = [ 'page' => 'onkupon-agent-seo-health', 'oka_notice' => 'ok' ];
                break;
            case 'run-marketplace-full-rescan':
                set_transient( 'onkupon_agent_marketplace_full_rescan', 1, 10 * MINUTE_IN_SECONDS );
                $bridge->enqueue( 'onkupon_agent_marketplace_discovery' );
                $redirect_args = [ 'page' => 'onkupon-agent-program-discovery', 'oka_notice' => 'ok' ];
                break;
            case 'run-marketplace-discovery-now':
                $bridge->enqueue( 'onkupon_agent_marketplace_discovery' );
                $redirect_args = [ 'page' => 'onkupon-agent-program-discovery', 'oka_notice' => 'ok' ];
                break;
            case 'send-program-digest':
                ( new \OnKupon\Agent\Affiliate\ProgramDigestNotifier() )->send_if_pending();
                $redirect_args = [ 'page' => 'onkupon-agent-program-discovery', 'oka_notice' => 'ok' ];
                break;
            case 'approve-program':
            case 'reject-program':
                $company_key = sanitize_text_field( wp_unslash( $_POST['company_key'] ?? '' ) );
                if ( '' !== $company_key ) {
                    $coordinator = new \OnKupon\Agent\Affiliate\ProgramApplicationCoordinator();
                    if ( 'approve-program' === $action ) {
                        $coordinator->approve( $company_key );
                    } else {
                        $coordinator->reject( $company_key );
                    }
                }
                $redirect_args = [ 'page' => 'onkupon-agent-program-discovery', 'oka_notice' => 'ok' ];
                break;
            case 'safe-mode':
                $settings = Plugin::settings();
                $settings['safe_mode'] = empty( $settings['safe_mode'] );
                Plugin::save_settings( $settings );
                break;
        }

        ( new Logger() )->log( 'info', 'audit', 'Control action executed', [ 'action' => $action, 'user' => get_current_user_id() ] );
        ( new ActionTimelineRepository() )->record( 'admin_control', 'completed', [ 'notes' => 'Control action executed: ' . $action, 'metadata' => [ 'action' => $action, 'user' => get_current_user_id() ] ] );
        wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
        exit;
    }
}
