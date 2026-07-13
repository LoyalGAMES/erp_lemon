<?php
/**
 * Asset registry.
 *
 * @package Lemon_Woo_Returns
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and enqueues return form assets.
 */
class LL_Returns_Assets {
	const FORM_STYLE = 'll-returns-form';
	const FORM_SCRIPT = 'll-returns-form';

	/**
	 * Settings service.
	 *
	 * @var LL_Returns_Settings
	 */
	private $settings;

	/**
	 * Whether handles have been registered.
	 *
	 * @var bool
	 */
	private $registered = false;

	/**
	 * Asset version cache.
	 *
	 * @var array
	 */
	private $asset_versions = array();

	/**
	 * Whether public style variables were already printed.
	 *
	 * @var bool
	 */
	private $style_vars_printed = false;

	/**
	 * Constructor.
	 *
	 * @param LL_Returns_Settings $settings Settings.
	 */
	public function __construct( LL_Returns_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Registers hooks.
	 */
	public function hooks() {
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ), 5 );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_form_assets' ) );
		add_action( 'elementor/frontend/after_register_styles', array( $this, 'register_assets' ) );
		add_action( 'elementor/frontend/after_register_scripts', array( $this, 'register_assets' ) );
		add_action( 'elementor/preview/enqueue_styles', array( $this, 'enqueue_form_assets' ) );
	}

	/**
	 * Registers stylesheet and script handles.
	 */
	public function register_assets() {
		if ( $this->registered ) {
			return;
		}

		wp_register_style(
			self::FORM_STYLE,
			LL_RETURNS_URL . 'assets/css/returns-form.css',
			array(),
			$this->get_asset_version( 'assets/css/returns-form.css' )
		);

		wp_register_script(
			self::FORM_SCRIPT,
			LL_RETURNS_URL . 'assets/js/returns-form.js',
			array(),
			$this->get_asset_version( 'assets/js/returns-form.js' ),
			true
		);

		wp_localize_script( self::FORM_SCRIPT, 'llReturnsFormConfig', $this->get_public_config() );

		$this->registered = true;
	}

	/**
	 * Enqueues assets when a singular page contains the shortcode.
	 */
	public function maybe_enqueue_form_assets() {
		$should_enqueue = false;

		if ( is_singular() ) {
			$post           = get_post();
			$should_enqueue = $post && has_shortcode( $post->post_content, 'll_return_form' );
		}

		if ( apply_filters( 'll_returns_enqueue_form_assets', $should_enqueue ) ) {
			$this->enqueue_form_assets();
		}
	}

	/**
	 * Enqueues public form assets.
	 */
	public function enqueue_form_assets() {
		$this->register_assets();
		wp_enqueue_style( self::FORM_STYLE );
		wp_enqueue_script( self::FORM_SCRIPT );
		$this->add_public_style_vars();
	}

	/**
	 * Gets public JS configuration.
	 *
	 * @return array
	 */
	private function get_public_config() {
		$texts = $this->settings->get_form_texts();

		return array(
			'ajaxUrl'                  => admin_url( 'admin-ajax.php' ),
			'nonce'                    => wp_create_nonce( 'll_returns_form' ),
			'wygodneZwrotyUrl'         => $this->settings->get_wygodne_zwroty_url(),
			'ownShippingInstructions'  => $texts['own_shipping_instructions'],
			'successMessage'           => $texts['success_message'],
			'reasons'                  => $this->settings->get_return_reasons(),
			'i18n'                     => array(
				'loading'              => $texts['loading'],
				'lookupError'          => $texts['lookup_error'],
				'selectProduct'        => $texts['select_product_error'],
				'quantityError'        => $texts['quantity_error'],
				'submitError'          => $texts['submit_error'],
				'returnNumber'         => $texts['return_number_label'],
				'wygodneZwrotyButton'  => $texts['wygodne_zwroty_button'],
				'ownShippingTitle'     => $texts['own_shipping_title'],
				'wygodneZwrotyTitle'   => $texts['wygodne_zwroty_title'],
				'qty'                  => $texts['qty_label'],
				'reason'               => $texts['reason_label'],
				'sku'                  => $texts['sku_label'],
				'orderSummaryPrefix'   => $texts['order_summary_prefix'],
			),
		);
	}

	/**
	 * Adds public CSS variables from global settings.
	 */
	private function add_public_style_vars() {
		if ( $this->style_vars_printed ) {
			return;
		}

		$styles = $this->settings->get_form_styles();
		$map    = array(
			'style_text_color'       => '--ll-returns-text',
			'style_muted_color'      => '--ll-returns-muted',
			'style_line_color'       => '--ll-returns-line',
			'style_soft_color'       => '--ll-returns-soft',
			'style_accent_color'     => '--ll-returns-accent',
			'style_accent_dark'      => '--ll-returns-accent-dark',
			'style_danger_color'     => '--ll-returns-danger',
			'style_success_color'    => '--ll-returns-success',
			'style_background'       => '--ll-returns-background',
			'style_shell_background' => '--ll-returns-shell-background',
			'style_input_background' => '--ll-returns-input-background',
			'style_selected_bg'      => '--ll-returns-selected-bg',
			'style_error_bg'         => '--ll-returns-error-bg',
			'style_success_bg'       => '--ll-returns-success-bg',
			'style_shell_width'      => '--ll-returns-shell-width',
			'style_shell_padding'    => '--ll-returns-shell-padding',
			'style_radius'           => '--ll-returns-radius',
			'style_field_radius'     => '--ll-returns-field-radius',
			'style_button_radius'    => '--ll-returns-button-radius',
			'style_image_fit'        => '--ll-returns-image-fit',
		);
		$rules  = array();

		foreach ( $map as $field => $var ) {
			if ( isset( $styles[ $field ] ) && '' !== (string) $styles[ $field ] ) {
				$rules[] = $var . ':' . esc_attr( $styles[ $field ] );
			}
		}

		if ( ! empty( $rules ) ) {
			wp_add_inline_style( self::FORM_STYLE, '.ll-returns{' . implode( ';', $rules ) . ';}' );
		}

		$this->style_vars_printed = true;
	}

	/**
	 * Gets filemtime-based asset version with plugin version fallback.
	 *
	 * @param string $relative_path Relative plugin path.
	 * @return string
	 */
	private function get_asset_version( $relative_path ) {
		$path = LL_RETURNS_PATH . ltrim( $relative_path, '/' );

		if ( ! isset( $this->asset_versions[ $path ] ) ) {
			$this->asset_versions[ $path ] = file_exists( $path ) ? (string) filemtime( $path ) : LL_RETURNS_VERSION;
		}

		return $this->asset_versions[ $path ];
	}
}
