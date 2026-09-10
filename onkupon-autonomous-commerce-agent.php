<?php
/**
 * Plugin Name: OnKupon Autonomous Commerce Content Agent
 * Description: Autonomous AI content, social, analytics, learning, and verified-review integrity agent for WooCommerce marketplaces.
 * Version: 0.8.2
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * Author: OnKupon
 * Text Domain: onkupon-agent
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'ONKUPON_AGENT_VERSION', '0.8.2' );
define( 'ONKUPON_AGENT_FILE', __FILE__ );
define( 'ONKUPON_AGENT_DIR', plugin_dir_path( __FILE__ ) );
define( 'ONKUPON_AGENT_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register( static function ( string $class ): void {
    $prefix = 'OnKupon\\Agent\\';
    if ( 0 !== strpos( $class, $prefix ) ) { return; }
    $relative = substr( $class, strlen( $prefix ) );
    $file = ONKUPON_AGENT_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';
    if ( is_readable( $file ) ) { require_once $file; }
} );

register_activation_hook( __FILE__, [ OnKupon\Agent\Activator::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ OnKupon\Agent\Deactivator::class, 'deactivate' ] );

add_action( 'plugins_loaded', static function (): void {
    OnKupon\Agent\Plugin::instance()->boot();
} );
