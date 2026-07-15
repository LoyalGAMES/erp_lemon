<?php
/**
 * Plugin Name: Lemon Woo Returns
 * Description: Customer return request form for WooCommerce and Elementor with ERP handoff.
 * Version: 1.3.0
 * Author: Loyal Lemon Sp. z o.o.
 * Text Domain: lemon-woo-returns
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 9.0
 *
 * @package Lemon_Woo_Returns
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LL_RETURNS_VERSION', '1.3.0' );
define( 'LL_RETURNS_FILE', __FILE__ );
define( 'LL_RETURNS_PATH', plugin_dir_path( __FILE__ ) );
define( 'LL_RETURNS_URL', plugin_dir_url( __FILE__ ) );

require_once LL_RETURNS_PATH . 'includes/class-settings.php';
require_once LL_RETURNS_PATH . 'includes/class-assets.php';
require_once LL_RETURNS_PATH . 'includes/class-erp-client.php';
require_once LL_RETURNS_PATH . 'includes/class-order-service.php';
require_once LL_RETURNS_PATH . 'includes/class-return-repository.php';
require_once LL_RETURNS_PATH . 'includes/class-refund-service.php';
require_once LL_RETURNS_PATH . 'includes/class-status-sync.php';
require_once LL_RETURNS_PATH . 'includes/class-shortcodes.php';
require_once LL_RETURNS_PATH . 'includes/class-ajax.php';
require_once LL_RETURNS_PATH . 'includes/class-admin-settings.php';
require_once LL_RETURNS_PATH . 'includes/class-plugin.php';

/**
 * Declares WooCommerce feature compatibility.
 */
function ll_returns_declare_woocommerce_features() {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', LL_RETURNS_FILE, true );
	}
}
add_action( 'before_woocommerce_init', 'll_returns_declare_woocommerce_features' );

register_deactivation_hook( __FILE__, array( 'LL_Returns_Status_Sync', 'clear_schedule' ) );

/**
 * Gets the plugin singleton.
 *
 * @return LL_Returns_Plugin
 */
function ll_returns() {
	return LL_Returns_Plugin::instance();
}

add_action( 'plugins_loaded', 'll_returns', 11 );
