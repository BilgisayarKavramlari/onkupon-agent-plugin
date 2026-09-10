<?php
namespace OnKupon\Agent\Scheduler;

class JobRegistrar {
    public const GROUP = 'onkupon-agent';

    public function register(): void {
        foreach ( self::hooks() as $hook => $class ) {
            add_action( $hook, [ new $class(), 'handle' ] );
        }
    }

    public static function hooks(): array {
        return [
            'onkupon_agent_product_scan' => \OnKupon\Agent\Scheduler\Jobs\ProductScanJob::class,
            'onkupon_agent_research'     => \OnKupon\Agent\Scheduler\Jobs\ResearchJob::class,
            'onkupon_agent_content'      => \OnKupon\Agent\Scheduler\Jobs\ContentGenerationJob::class,
            'onkupon_agent_publish'      => \OnKupon\Agent\Scheduler\Jobs\PublishingJob::class,
            'onkupon_agent_social'       => \OnKupon\Agent\Scheduler\Jobs\SocialPublishingJob::class,
            'onkupon_agent_metrics'      => \OnKupon\Agent\Scheduler\Jobs\MetricsCollectionJob::class,
            'onkupon_agent_learning'     => \OnKupon\Agent\Scheduler\Jobs\LearningJob::class,
            'onkupon_agent_reviews'      => \OnKupon\Agent\Scheduler\Jobs\ReviewRequestJob::class,
            'onkupon_agent_cleanup'      => \OnKupon\Agent\Scheduler\Jobs\CleanupJob::class,
            'onkupon_agent_affiliate_sync' => \OnKupon\Agent\Scheduler\Jobs\AffiliateSyncJob::class,
            'onkupon_agent_affiliate_enrichment' => \OnKupon\Agent\Scheduler\Jobs\AffiliateEnrichmentJob::class,
            'onkupon_agent_quality_audit' => \OnKupon\Agent\Scheduler\Jobs\QualityAuditJob::class,
            'onkupon_agent_revenue_link_audit' => \OnKupon\Agent\Scheduler\Jobs\RevenueLinkAuditJob::class,
            'onkupon_agent_seo_health'    => \OnKupon\Agent\Scheduler\Jobs\SEOHealthJob::class,
            'onkupon_agent_cro_optimization' => \OnKupon\Agent\Scheduler\Jobs\CROOptimizationJob::class,
            'onkupon_agent_marketplace_discovery' => \OnKupon\Agent\Scheduler\Jobs\MarketplaceDiscoveryJob::class,
            'onkupon_agent_sitemap_audit' => \OnKupon\Agent\Scheduler\Jobs\SitemapAuditJob::class,
        ];
    }

    public static function schedule_defaults(): void {
        if ( ! function_exists( 'as_schedule_recurring_action' ) || ! function_exists( 'as_next_scheduled_action' ) ) {
            return;
        }
        foreach ( self::intervals() as $hook => $interval ) {
            if ( ! as_next_scheduled_action( $hook, [], self::GROUP ) ) {
                as_schedule_recurring_action( time() + MINUTE_IN_SECONDS, $interval, $hook, [], self::GROUP );
            }
        }
    }

    public static function intervals(): array {
        return [
            'onkupon_agent_product_scan' => 6 * HOUR_IN_SECONDS,
            'onkupon_agent_research'     => 2 * HOUR_IN_SECONDS,
            'onkupon_agent_content'      => 4 * HOUR_IN_SECONDS,
            'onkupon_agent_publish'      => HOUR_IN_SECONDS,
            'onkupon_agent_social'       => 15 * MINUTE_IN_SECONDS,
            'onkupon_agent_metrics'      => 6 * HOUR_IN_SECONDS,
            'onkupon_agent_learning'     => DAY_IN_SECONDS,
            'onkupon_agent_reviews'      => DAY_IN_SECONDS,
            'onkupon_agent_cleanup'      => WEEK_IN_SECONDS,
            'onkupon_agent_affiliate_sync' => 6 * HOUR_IN_SECONDS,
            'onkupon_agent_affiliate_enrichment' => 5 * MINUTE_IN_SECONDS,
            'onkupon_agent_quality_audit' => 2 * HOUR_IN_SECONDS,
            'onkupon_agent_revenue_link_audit' => 12 * HOUR_IN_SECONDS,
            'onkupon_agent_seo_health'    => 12 * HOUR_IN_SECONDS,
            'onkupon_agent_cro_optimization' => 6 * HOUR_IN_SECONDS,
            'onkupon_agent_marketplace_discovery' => 12 * HOUR_IN_SECONDS,
            'onkupon_agent_sitemap_audit' => DAY_IN_SECONDS,
        ];
    }

    public static function unschedule_all(): void {
        if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
            return;
        }
        foreach ( array_keys( self::hooks() ) as $hook ) {
            as_unschedule_all_actions( $hook, [], self::GROUP );
        }
    }
}
