<?php
namespace OnKupon\Agent\CLI;

use OnKupon\Agent\Plugin;
use OnKupon\Agent\Scheduler\ActionSchedulerBridge;
use OnKupon\Agent\Security\LockManager;

class Command {
    public function status( $args, $assoc_args ): void {
        unset( $args, $assoc_args );
        \WP_CLI::success( 'Status: ' . ( Plugin::settings()['agent_status'] ?? 'stopped' ) );
    }

    public function start(): void { Plugin::update_status( 'running' ); \WP_CLI::success( 'Started' ); }
    public function pause(): void { Plugin::update_status( 'paused' ); \WP_CLI::success( 'Paused' ); }
    public function resume(): void { Plugin::update_status( 'running' ); \WP_CLI::success( 'Resumed' ); }
    public function stop(): void { Plugin::update_status( 'stopped' ); \WP_CLI::success( 'Stopped' ); }
    public function emergency_stop(): void { Plugin::update_status( 'emergency_stopped' ); ( new LockManager() )->clear(); \WP_CLI::success( 'Emergency stopped' ); }

    public function run( $args, $assoc_args ): void {
        unset( $args );
        $job = sanitize_key( $assoc_args['job'] ?? 'all' );
        $map = [
            'product-scan' => 'onkupon_agent_product_scan',
            'research' => 'onkupon_agent_research',
            'content' => 'onkupon_agent_content',
            'publish' => 'onkupon_agent_publish',
            'social' => 'onkupon_agent_social',
            'metrics' => 'onkupon_agent_metrics',
            'learning' => 'onkupon_agent_learning',
            'affiliate' => 'onkupon_agent_affiliate_sync',
            'revenue-links' => 'onkupon_agent_revenue_link_audit',
            'seo' => 'onkupon_agent_seo_health',
        ];
        $bridge = new ActionSchedulerBridge();
        if ( 'all' === $job ) {
            foreach ( $map as $hook ) { $bridge->enqueue( $hook ); }
        } elseif ( isset( $map[ $job ] ) ) {
            $bridge->enqueue( $map[ $job ] );
        } else {
            \WP_CLI::error( 'Unknown job.' );
        }
        \WP_CLI::success( 'Queued job: ' . $job );
    }

    public function collect_metrics(): void { ( new ActionSchedulerBridge() )->enqueue( 'onkupon_agent_metrics' ); \WP_CLI::success( 'Metrics collection queued' ); }
    public function reset_locks(): void { ( new LockManager() )->clear(); \WP_CLI::success( 'Locks reset' ); }
    public function build_report(): void { \WP_CLI::line( wp_json_encode( [ 'status' => Plugin::settings()['agent_status'] ?? 'stopped', 'version' => ONKUPON_AGENT_VERSION ], JSON_PRETTY_PRINT ) ); }

    public function seo_plan(): void {
        $plan = ( new \OnKupon\Agent\SEO\CommercialContentPlanner() )->next_plan();
        if ( ! $plan ) {
            \WP_CLI::success( 'No eligible source-backed commercial SEO plan is pending.' );
            return;
        }
        \WP_CLI::line(
            wp_json_encode(
                [
                    'key' => sanitize_key( (string) $plan['key'] ),
                    'topic' => sanitize_text_field( (string) $plan['topic'] ),
                    'slug' => sanitize_title( (string) $plan['slug'] ),
                    'product_ids' => array_map( static fn( $product ): int => absint( $product->get_id() ), (array) $plan['products'] ),
                    'required_source_urls' => array_values( (array) $plan['required_source_urls'] ),
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            )
        );
    }
}
