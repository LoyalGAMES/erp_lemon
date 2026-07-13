<?php
/**
 * Main plugin coordinator.
 *
 * @package Lemon_Woo_Returns
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coordinates plugin services and hooks.
 */
class LL_Returns_Plugin {
	/**
	 * Singleton instance.
	 *
	 * @var LL_Returns_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Settings service.
	 *
	 * @var LL_Returns_Settings
	 */
	private $settings;

	/**
	 * Gets singleton instance.
	 *
	 * @return LL_Returns_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->hooks();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->settings = new LL_Returns_Settings();
	}

	/**
	 * Registers hooks.
	 */
	private function hooks() {
		load_plugin_textdomain( 'lemon-woo-returns', false, dirname( plugin_basename( LL_RETURNS_FILE ) ) . '/languages' );

		$assets        = new LL_Returns_Assets( $this->settings );
		$erp_client    = new LL_Returns_ERP_Client( $this->settings );
		$order_service = new LL_Returns_Order_Service( $this->settings, $erp_client );
		$repository    = new LL_Returns_Return_Repository();
		$refund_service = new LL_Returns_Refund_Service( $this->settings, $repository );
		$status_sync   = new LL_Returns_Status_Sync( $this->settings, $repository, $erp_client, $refund_service );

		$repository->hooks();
		$status_sync->hooks();
		$assets->hooks();

		$admin = new LL_Returns_Admin_Settings( $this->settings, $repository );
		$admin->hooks();

		$shortcodes = new LL_Returns_Shortcodes( $this->settings, $assets );
		$shortcodes->hooks();

		$ajax = new LL_Returns_Ajax( $this->settings, $order_service, $repository, $erp_client, $status_sync );
		$ajax->hooks();

		$this->register_elementor_hooks();

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'render_woocommerce_notice' ) );
		}
	}

	/**
	 * Registers Elementor widget hooks.
	 */
	private function register_elementor_hooks() {
		add_action( 'elementor/elements/categories_registered', array( $this, 'register_elementor_category' ) );
		add_action( 'elementor/widgets/register', array( $this, 'register_elementor_widgets' ) );
	}

	/**
	 * Adds Elementor category for Lemon widgets.
	 *
	 * @param object $elements_manager Elementor elements manager.
	 */
	public function register_elementor_category( $elements_manager ) {
		if ( ! method_exists( $elements_manager, 'add_category' ) ) {
			return;
		}

		$elements_manager->add_category(
			'lemon-elementor',
			array(
				'title' => __( 'Lemon Elementor', 'lemon-woo-returns' ),
				'icon'  => 'fa fa-plug',
			)
		);
	}

	/**
	 * Registers Elementor widgets.
	 *
	 * @param object $widgets_manager Elementor widgets manager.
	 */
	public function register_elementor_widgets( $widgets_manager ) {
		if ( ! did_action( 'elementor/loaded' ) || ! class_exists( '\Elementor\Widget_Base' ) ) {
			return;
		}

		require_once LL_RETURNS_PATH . 'includes/elementor/class-ll-returns-form-widget.php';

		if ( ! class_exists( 'LL_Returns_Form_Widget' ) ) {
			return;
		}

		$widget = new LL_Returns_Form_Widget();

		if ( method_exists( $widgets_manager, 'register' ) ) {
			$widgets_manager->register( $widget );
			return;
		}

		$widgets_manager->register_widget_type( $widget );
	}

	/**
	 * Renders WooCommerce dependency notice.
	 */
	public function render_woocommerce_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>';
		echo esc_html__( 'Lemon Woo Returns wymaga WooCommerce do lokalnego wyszukiwania zamowien. Po skonfigurowaniu endpointu ERP formularz moze dzialac bez lokalnych zamowien WooCommerce.', 'lemon-woo-returns' );
		echo '</p></div>';
	}

	/**
	 * Gets settings service.
	 *
	 * @return LL_Returns_Settings
	 */
	public function settings() {
		return $this->settings;
	}
}
