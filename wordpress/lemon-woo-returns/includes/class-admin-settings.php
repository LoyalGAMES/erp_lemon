<?php
/**
 * Admin settings page.
 *
 * @package Lemon_Woo_Returns
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders and saves return settings.
 */
class LL_Returns_Admin_Settings {
	const OPTION_GROUP = 'll_returns_settings_group';

	/**
	 * Settings service.
	 *
	 * @var LL_Returns_Settings
	 */
	private $settings;

	/**
	 * Local return repository.
	 *
	 * @var LL_Returns_Return_Repository
	 */
	private $repository;

	/**
	 * Constructor.
	 *
	 * @param LL_Returns_Settings          $settings   Settings.
	 * @param LL_Returns_Return_Repository $repository Repository.
	 */
	public function __construct( LL_Returns_Settings $settings, LL_Returns_Return_Repository $repository ) {
		$this->settings   = $settings;
		$this->repository = $repository;
	}

	/**
	 * Registers hooks.
	 */
	public function hooks() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_filter( 'option_page_capability_' . self::OPTION_GROUP, array( $this, 'get_settings_capability' ) );
	}

	/**
	 * Registers admin menu.
	 */
	public function register_menu() {
		$capability = $this->get_settings_capability();

		if ( class_exists( 'WooCommerce' ) ) {
			add_submenu_page(
				'woocommerce',
				__( 'Ustawienia zwrotów', 'lemon-woo-returns' ),
				__( 'Ustawienia zwrotów', 'lemon-woo-returns' ),
				$capability,
				'll-returns-settings',
				array( $this, 'render_page' )
			);

			return;
		}

		add_options_page(
			__( 'Ustawienia zwrotów', 'lemon-woo-returns' ),
			__( 'Ustawienia zwrotów', 'lemon-woo-returns' ),
			$capability,
			'll-returns-settings',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Registers settings and fields.
	 */
	public function register_settings() {
		register_setting(
			self::OPTION_GROUP,
			LL_Returns_Settings::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this->settings, 'sanitize_settings' ),
			)
		);

		add_settings_section(
			'll_returns_general',
			__( 'Formularz', 'lemon-woo-returns' ),
			'__return_false',
			'll-returns-settings'
		);

		add_settings_section(
			'll_returns_erp',
			__( 'Integracja ERP', 'lemon-woo-returns' ),
			'__return_false',
			'll-returns-settings'
		);

		add_settings_section(
			'll_returns_texts',
			__( 'Teksty i tłumaczenia formularza', 'lemon-woo-returns' ),
			'__return_false',
			'll-returns-settings'
		);

		add_settings_section(
			'll_returns_style',
			__( 'Wygląd formularza', 'lemon-woo-returns' ),
			'__return_false',
			'll-returns-settings'
		);

		add_settings_section(
			'll_returns_refunds',
			__( 'Natywny zwrot WooCommerce', 'lemon-woo-returns' ),
			'__return_false',
			'll-returns-settings'
		);

		$this->add_field( 'enabled', __( 'Aktywny formularz', 'lemon-woo-returns' ), 'render_enabled_field', 'll_returns_general' );
		$this->add_field( 'form_title', __( 'Tytul formularza', 'lemon-woo-returns' ), 'render_text_field', 'll_returns_general' );
		$this->add_field( 'form_intro', __( 'Wstep formularza', 'lemon-woo-returns' ), 'render_text_field', 'll_returns_general' );
		$this->add_field( 'success_message', __( 'Komunikat po zgloszeniu', 'lemon-woo-returns' ), 'render_text_field', 'll_returns_general' );
		$this->add_field( 'own_shipping_instructions', __( 'Instrukcja wlasnej przesylki', 'lemon-woo-returns' ), 'render_textarea_field', 'll_returns_general' );
		$this->add_field( 'wygodne_zwroty_url', __( 'Link Wygodne Zwroty', 'lemon-woo-returns' ), 'render_url_field', 'll_returns_general' );
		$this->add_field( 'allowed_order_statuses', __( 'Statusy zamowien', 'lemon-woo-returns' ), 'render_statuses_field', 'll_returns_general' );
		$this->add_field( 'return_reasons', __( 'Powody zwrotu', 'lemon-woo-returns' ), 'render_textarea_field', 'll_returns_general' );

		$this->add_field( 'form_texts', __( 'Napisy formularza', 'lemon-woo-returns' ), 'render_form_texts_field', 'll_returns_texts' );

		$this->add_field( 'style_text_color', __( 'Kolor tekstu', 'lemon-woo-returns' ), 'render_color_field', 'll_returns_style' );
		$this->add_field( 'style_muted_color', __( 'Kolor pomocniczy', 'lemon-woo-returns' ), 'render_color_field', 'll_returns_style' );
		$this->add_field( 'style_line_color', __( 'Kolor obramowania', 'lemon-woo-returns' ), 'render_color_field', 'll_returns_style' );
		$this->add_field( 'style_soft_color', __( 'Tło delikatne', 'lemon-woo-returns' ), 'render_color_field', 'll_returns_style' );
		$this->add_field( 'style_accent_color', __( 'Kolor akcentu', 'lemon-woo-returns' ), 'render_color_field', 'll_returns_style' );
		$this->add_field( 'style_accent_dark', __( 'Tekst na akcencie', 'lemon-woo-returns' ), 'render_color_field', 'll_returns_style' );
		$this->add_field( 'style_danger_color', __( 'Kolor błędu', 'lemon-woo-returns' ), 'render_color_field', 'll_returns_style' );
		$this->add_field( 'style_success_color', __( 'Kolor sukcesu', 'lemon-woo-returns' ), 'render_color_field', 'll_returns_style' );
		$this->add_field( 'style_background', __( 'Tło formularza', 'lemon-woo-returns' ), 'render_color_or_transparent_field', 'll_returns_style' );
		$this->add_field( 'style_shell_background', __( 'Tło panelu', 'lemon-woo-returns' ), 'render_color_field', 'll_returns_style' );
		$this->add_field( 'style_input_background', __( 'Tło pól', 'lemon-woo-returns' ), 'render_color_field', 'll_returns_style' );
		$this->add_field( 'style_selected_bg', __( 'Tło zaznaczenia', 'lemon-woo-returns' ), 'render_color_field', 'll_returns_style' );
		$this->add_field( 'style_error_bg', __( 'Tło błędu', 'lemon-woo-returns' ), 'render_color_field', 'll_returns_style' );
		$this->add_field( 'style_success_bg', __( 'Tło sukcesu', 'lemon-woo-returns' ), 'render_color_field', 'll_returns_style' );
		$this->add_field( 'style_shell_width', __( 'Maksymalna szerokość', 'lemon-woo-returns' ), 'render_css_size_field', 'll_returns_style' );
		$this->add_field( 'style_shell_padding', __( 'Padding panelu', 'lemon-woo-returns' ), 'render_css_size_field', 'll_returns_style' );
		$this->add_field( 'style_radius', __( 'Zaokrąglenie kart', 'lemon-woo-returns' ), 'render_css_size_field', 'll_returns_style' );
		$this->add_field( 'style_field_radius', __( 'Zaokrąglenie pól', 'lemon-woo-returns' ), 'render_css_size_field', 'll_returns_style' );
		$this->add_field( 'style_button_radius', __( 'Zaokrąglenie przycisków', 'lemon-woo-returns' ), 'render_css_size_field', 'll_returns_style' );
		$this->add_field( 'style_image_fit', __( 'Kadrowanie zdjęć produktów', 'lemon-woo-returns' ), 'render_image_fit_field', 'll_returns_style' );

		$this->add_field( 'erp_lookup_url', __( 'Endpoint wyszukiwania zamowienia', 'lemon-woo-returns' ), 'render_url_field', 'll_returns_erp' );
		$this->add_field( 'erp_return_url', __( 'Endpoint tworzenia zwrotu', 'lemon-woo-returns' ), 'render_url_field', 'll_returns_erp' );
		$this->add_field( 'erp_status_url', __( 'Endpoint statusu zwrotu', 'lemon-woo-returns' ), 'render_url_field', 'll_returns_erp' );
		$this->add_field( 'erp_api_token', __( 'Token API', 'lemon-woo-returns' ), 'render_token_field', 'll_returns_erp' );
		$this->add_field( 'erp_webhook_secret', __( 'Token webhooka ERP', 'lemon-woo-returns' ), 'render_webhook_secret_field', 'll_returns_erp' );
		$this->add_field( 'erp_completed_statuses', __( 'Statusy finalne ERP', 'lemon-woo-returns' ), 'render_textarea_field', 'll_returns_erp' );
		$this->add_field( 'erp_timeout', __( 'Timeout ERP', 'lemon-woo-returns' ), 'render_number_field', 'll_returns_erp' );

		$this->add_field( 'refund_restock_items', __( 'Przywroc stany magazynowe', 'lemon-woo-returns' ), 'render_yes_no_field', 'll_returns_refunds' );
		$this->add_field( 'mark_order_refunded', __( 'Oznacz zamowienie jako zwrocone', 'lemon-woo-returns' ), 'render_yes_no_field', 'll_returns_refunds' );
	}

	/**
	 * Renders admin page.
	 */
	public function render_page() {
		if ( ! current_user_can( $this->get_settings_capability() ) ) {
			wp_die( esc_html__( 'Brak uprawnien.', 'lemon-woo-returns' ) );
		}

		$return_endpoint_configured = '' !== $this->settings->get( 'erp_return_url', '' );
		$api_token_configured       = '' !== $this->settings->get( 'erp_api_token', '' );
		$pending_delivery_count     = $this->repository->count_pending_erp_submissions();
		?>
			<div class="wrap">
				<h1><?php esc_html_e( 'Ustawienia zwrotów', 'lemon-woo-returns' ); ?></h1>
				<?php if ( isset( $_GET['ll_returns_synced'] ) ) : ?>
					<div class="notice notice-success is-dismissible"><p>
					<?php
					printf(
						/* translators: %d: synchronized count */
						esc_html__( 'Wysłano lub zsynchronizowano zgłoszenia: %d.', 'lemon-woo-returns' ),
						absint( $_GET['ll_returns_synced'] )
					);
					?>
					</p></div>
				<?php endif; ?>
				<?php if ( ! $return_endpoint_configured || ! $api_token_configured ) : ?>
					<div class="notice notice-error"><p>
						<strong><?php esc_html_e( 'Integracja zwrotów z ERP jest nieaktywna.', 'lemon-woo-returns' ); ?></strong>
						<?php esc_html_e( ' Zgłoszenia pozostaną tylko w WooCommerce, a ERP nie wyśle klientowi potwierdzenia.', 'lemon-woo-returns' ); ?>
						<?php if ( ! $return_endpoint_configured ) : ?>
							<?php esc_html_e( ' Uzupełnij endpoint tworzenia zwrotu.', 'lemon-woo-returns' ); ?>
						<?php endif; ?>
						<?php if ( ! $api_token_configured ) : ?>
							<?php esc_html_e( ' Uzupełnij token API zgodny z tokenem w ERP.', 'lemon-woo-returns' ); ?>
						<?php endif; ?>
					</p></div>
				<?php elseif ( $pending_delivery_count > 0 ) : ?>
					<div class="notice notice-warning"><p>
						<?php
						printf(
							/* translators: %d: number of local return records waiting for ERP */
							esc_html__( 'Zgłoszenia oczekujące na wysłanie do ERP: %d. Użyj przycisku poniżej albo poczekaj na automatyczną próbę.', 'lemon-woo-returns' ),
							absint( $pending_delivery_count )
						);
						?>
					</p></div>
				<?php else : ?>
					<div class="notice notice-success"><p><?php esc_html_e( 'Integracja tworzenia zwrotów w ERP jest skonfigurowana.', 'lemon-woo-returns' ); ?></p></div>
				<?php endif; ?>
				<p><?php esc_html_e( 'Formularz wstawisz shortcodeem [ll_return_form] albo widgetem Elementor "Formularz zwrotu".', 'lemon-woo-returns' ); ?></p>
			<p>
				<strong><?php esc_html_e( 'Webhook ERP:', 'lemon-woo-returns' ); ?></strong>
				<code><?php echo esc_html( rest_url( LL_Returns_Status_Sync::REST_NAMESPACE . LL_Returns_Status_Sync::REST_ROUTE ) ); ?></code>
			</p>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" style="margin:12px 0 20px;">
				<input type="hidden" name="action" value="<?php echo esc_attr( LL_Returns_Status_Sync::MANUAL_SYNC_ACTION ); ?>">
				<?php wp_nonce_field( LL_Returns_Status_Sync::MANUAL_SYNC_ACTION ); ?>
				<?php submit_button( __( 'Wyślij oczekujące i synchronizuj z ERP teraz', 'lemon-woo-returns' ), 'secondary', 'submit', false ); ?>
			</form>
			<form action="options.php" method="post">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( 'll-returns-settings' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Gets settings capability.
	 *
	 * @return string
	 */
	public function get_settings_capability() {
		return apply_filters( 'll_returns_settings_capability', 'manage_woocommerce' );
	}

	/**
	 * Adds settings field.
	 *
	 * @param string $field    Field key.
	 * @param string $label    Label.
	 * @param string $callback Callback method.
	 * @param string $section  Section ID.
	 */
	private function add_field( $field, $label, $callback, $section ) {
		add_settings_field(
			$field,
			$label,
			array( $this, $callback ),
			'll-returns-settings',
			$section,
			array( 'field' => $field )
		);
	}

	/**
	 * Renders enabled checkbox.
	 *
	 * @param array $args Field args.
	 */
	public function render_enabled_field( $args ) {
		$field = $args['field'];
		$value = $this->settings->get( $field, 'yes' );

		echo '<label><input type="checkbox" name="' . esc_attr( LL_Returns_Settings::OPTION_NAME . '[' . $field . ']' ) . '" value="yes" ' . checked( 'yes', $value, false ) . '> ';
		echo esc_html__( 'Pokazuj formularz na stronie.', 'lemon-woo-returns' );
		echo '</label>';
	}

	/**
	 * Renders text input.
	 *
	 * @param array $args Field args.
	 */
	public function render_text_field( $args ) {
		$field = $args['field'];
		$value = $this->settings->get( $field, '' );

		echo '<input type="text" class="regular-text" name="' . esc_attr( LL_Returns_Settings::OPTION_NAME . '[' . $field . ']' ) . '" value="' . esc_attr( $value ) . '">';
	}

	/**
	 * Renders URL input.
	 *
	 * @param array $args Field args.
	 */
	public function render_url_field( $args ) {
		$field = $args['field'];
		$value = $this->settings->get( $field, '' );

		echo '<input type="url" class="large-text" name="' . esc_attr( LL_Returns_Settings::OPTION_NAME . '[' . $field . ']' ) . '" value="' . esc_attr( $value ) . '">';

		if ( 'erp_lookup_url' === $field ) {
			echo '<p class="description">' . esc_html__( 'Opcjonalnie. Bez tego endpointu formularz uzywa lokalnych danych WooCommerce.', 'lemon-woo-returns' ) . '</p>';
		}

		if ( 'erp_return_url' === $field ) {
			echo '<p class="description">' . esc_html__( 'Po wyborze formy odeslania tutaj zostanie wyslany zwrot oczekujacy na paczke.', 'lemon-woo-returns' ) . '</p>';
		}

		if ( 'erp_status_url' === $field ) {
			echo '<p class="description">' . esc_html__( 'Opcjonalnie. Cron odpytuje ten endpoint co 15 minut dla zgłoszeń oczekujących na finalny status.', 'lemon-woo-returns' ) . '</p>';
		}
	}

	/**
	 * Renders textarea input.
	 *
	 * @param array $args Field args.
	 */
	public function render_textarea_field( $args ) {
		$field = $args['field'];
		$value = $this->settings->get( $field, '' );

		echo '<textarea class="large-text" rows="4" name="' . esc_attr( LL_Returns_Settings::OPTION_NAME . '[' . $field . ']' ) . '">' . esc_textarea( $value ) . '</textarea>';

		if ( 'erp_completed_statuses' === $field ) {
			echo '<p class="description">' . esc_html__( 'Jeden status na linie. Gdy ERP zwroci jeden z tych statusow, wtyczka utworzy natywny zwrot WooCommerce.', 'lemon-woo-returns' ) . '</p>';
		}

		if ( 'return_reasons' === $field ) {
			echo '<p class="description">' . esc_html__( 'Jeden powód na linię, format: klucz|Etykieta, np. wrong_size|Nieodpowiedni rozmiar.', 'lemon-woo-returns' ) . '</p>';
		}
	}

	/**
	 * Renders editable form text fields.
	 */
	public function render_form_texts_field() {
		$labels = LL_Returns_Settings::get_form_text_field_labels();

		echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px 18px;max-width:980px;">';

		foreach ( $labels as $field => $label ) {
			$value = $this->settings->get( $field, LL_Returns_Settings::get_default_form_texts()[ $field ] );

			echo '<label style="display:grid;gap:4px;">';
			echo '<span><strong>' . esc_html( $label ) . '</strong></span>';
			echo '<input type="text" class="regular-text" style="width:100%;" name="' . esc_attr( LL_Returns_Settings::OPTION_NAME . '[' . $field . ']' ) . '" value="' . esc_attr( $value ) . '">';
			echo '</label>';
		}

		echo '</div>';
		echo '<p class="description">' . esc_html__( 'Te napisy trafiają także do elementów tworzonych dynamicznie po odczytaniu zamówienia.', 'lemon-woo-returns' ) . '</p>';
	}

	/**
	 * Renders color input.
	 *
	 * @param array $args Field args.
	 */
	public function render_color_field( $args ) {
		$field = $args['field'];
		$value = $this->settings->get( $field, LL_Returns_Settings::get_default_form_styles()[ $field ] );

		echo '<input type="text" class="regular-text" name="' . esc_attr( LL_Returns_Settings::OPTION_NAME . '[' . $field . ']' ) . '" value="' . esc_attr( $value ) . '" placeholder="#111827">';
	}

	/**
	 * Renders color input that accepts transparent.
	 *
	 * @param array $args Field args.
	 */
	public function render_color_or_transparent_field( $args ) {
		$this->render_color_field( $args );
		echo '<p class="description">' . esc_html__( 'Wpisz kolor HEX albo transparent.', 'lemon-woo-returns' ) . '</p>';
	}

	/**
	 * Renders CSS size input.
	 *
	 * @param array $args Field args.
	 */
	public function render_css_size_field( $args ) {
		$field = $args['field'];
		$value = $this->settings->get( $field, LL_Returns_Settings::get_default_form_styles()[ $field ] );

		echo '<input type="text" class="regular-text" name="' . esc_attr( LL_Returns_Settings::OPTION_NAME . '[' . $field . ']' ) . '" value="' . esc_attr( $value ) . '" placeholder="8px">';
		echo '<p class="description">' . esc_html__( 'Dozwolone wartości CSS typu 8px, 1rem, 100%, 860px, clamp(...) albo padding 20px 32px.', 'lemon-woo-returns' ) . '</p>';
	}

	/**
	 * Renders object-fit select.
	 *
	 * @param array $args Field args.
	 */
	public function render_image_fit_field( $args ) {
		$field = $args['field'];
		$value = $this->settings->get( $field, 'contain' );
		$options = array(
			'cover'      => __( 'Wypełnij i przytnij', 'lemon-woo-returns' ),
			'contain'    => __( 'Pokaż całe zdjęcie', 'lemon-woo-returns' ),
			'fill'       => __( 'Rozciągnij', 'lemon-woo-returns' ),
			'none'       => __( 'Oryginalny rozmiar', 'lemon-woo-returns' ),
			'scale-down' => __( 'Zmniejsz bez rozciągania', 'lemon-woo-returns' ),
		);

		echo '<select name="' . esc_attr( LL_Returns_Settings::OPTION_NAME . '[' . $field . ']' ) . '">';

		foreach ( $options as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '" ' . selected( $key, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}

		echo '</select>';
	}

	/**
	 * Renders number input.
	 *
	 * @param array $args Field args.
	 */
	public function render_number_field( $args ) {
		$field = $args['field'];
		$value = absint( $this->settings->get( $field, 12 ) );

		echo '<input type="number" min="3" max="60" step="1" name="' . esc_attr( LL_Returns_Settings::OPTION_NAME . '[' . $field . ']' ) . '" value="' . esc_attr( $value ) . '"> ';
		echo esc_html__( 'sekund', 'lemon-woo-returns' );
	}

	/**
	 * Renders token input.
	 */
	public function render_token_field( $args = array() ) {
		$has_token = '' !== $this->settings->get( 'erp_api_token', '' );

		echo '<input type="password" class="regular-text" autocomplete="new-password" name="' . esc_attr( LL_Returns_Settings::OPTION_NAME . '[erp_api_token]' ) . '" value="" placeholder="' . esc_attr( $has_token ? '********' : '' ) . '">';
		echo '<p class="description">' . esc_html__( 'Pozostaw puste, aby zachowac obecny token. Token jest wysylany jako Bearer i X-API-Key.', 'lemon-woo-returns' ) . '</p>';

		if ( $has_token ) {
			echo '<label><input type="checkbox" name="' . esc_attr( LL_Returns_Settings::OPTION_NAME . '[erp_api_token_clear]' ) . '" value="yes"> ';
			echo esc_html__( 'Usun zapisany token', 'lemon-woo-returns' );
			echo '</label>';
		}
	}

	/**
	 * Renders webhook secret input.
	 */
	public function render_webhook_secret_field( $args = array() ) {
		$has_secret = '' !== $this->settings->get( 'erp_webhook_secret', '' );

		echo '<input type="password" class="regular-text" autocomplete="new-password" name="' . esc_attr( LL_Returns_Settings::OPTION_NAME . '[erp_webhook_secret]' ) . '" value="" placeholder="' . esc_attr( $has_secret ? '********' : '' ) . '">';
		echo '<p class="description">' . esc_html__( 'ERP moze wysylac statusy na webhook z naglowkiem X-Lemon-Returns-Token albo Authorization: Bearer. Pozostaw puste, aby zachowac obecny token.', 'lemon-woo-returns' ) . '</p>';

		if ( $has_secret ) {
			echo '<label><input type="checkbox" name="' . esc_attr( LL_Returns_Settings::OPTION_NAME . '[erp_webhook_secret_clear]' ) . '" value="yes"> ';
			echo esc_html__( 'Usun zapisany token webhooka', 'lemon-woo-returns' );
			echo '</label>';
		}
	}

	/**
	 * Renders yes/no checkbox.
	 *
	 * @param array $args Field args.
	 */
	public function render_yes_no_field( $args ) {
		$field = $args['field'];
		$value = $this->settings->get( $field, 'yes' );

		echo '<label><input type="checkbox" name="' . esc_attr( LL_Returns_Settings::OPTION_NAME . '[' . $field . ']' ) . '" value="yes" ' . checked( 'yes', $value, false ) . '> ';

		if ( 'refund_restock_items' === $field ) {
			echo esc_html__( 'Tak, zwracaj produkty na stan przy natywnym zwrocie WooCommerce.', 'lemon-woo-returns' );
		} elseif ( 'mark_order_refunded' === $field ) {
			echo esc_html__( 'Tak, po statusie "Zwrot zrealizowany" ustawiaj status zamowienia WooCommerce na "Zwrócone".', 'lemon-woo-returns' );
		}

		echo '</label>';
	}

	/**
	 * Renders allowed order statuses.
	 */
	public function render_statuses_field( $args = array() ) {
		$selected = $this->settings->get_allowed_order_statuses();
		$statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array(
			'wc-processing' => __( 'Processing', 'woocommerce' ),
			'wc-completed'  => __( 'Completed', 'woocommerce' ),
		);

		echo '<select multiple size="6" name="' . esc_attr( LL_Returns_Settings::OPTION_NAME . '[allowed_order_statuses][]' ) . '">';

		foreach ( $statuses as $status => $label ) {
			$slug = sanitize_key( str_replace( 'wc-', '', $status ) );
			echo '<option value="' . esc_attr( $slug ) . '" ' . selected( in_array( $slug, $selected, true ), true, false ) . '>' . esc_html( $label ) . '</option>';
		}

		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Dotyczy fallbacku WooCommerce, gdy nie korzystasz z endpointu wyszukiwania ERP.', 'lemon-woo-returns' ) . '</p>';
	}
}
