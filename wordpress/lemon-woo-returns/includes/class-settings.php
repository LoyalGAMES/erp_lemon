<?php
/**
 * Returns settings service.
 *
 * @package Lemon_Woo_Returns
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and normalizes return form settings.
 */
class LL_Returns_Settings {
	const OPTION_NAME = 'll_returns_settings';

	/**
	 * Request-level settings cache.
	 *
	 * @var array|null
	 */
	private $settings_cache = null;

	/**
	 * Gets default settings.
	 *
	 * @return array
	 */
	public static function get_default_settings() {
		return array_merge(
			array(
				'enabled'                   => 'yes',
				'wygodne_zwroty_url'       => 'https://wygodnezwroty.pl/semprelove?_campaign=merchant;banner;semprelove',
				'erp_lookup_url'           => '',
				'erp_return_url'           => '',
				'erp_status_url'           => '',
				'erp_api_token'            => '',
				'erp_webhook_secret'       => '',
				'erp_timeout'              => 12,
				'erp_completed_statuses'   => "Zwrot zrealizowany\nzrealizowany\ncompleted\ncomplete\nrealized\nreturn_completed",
				'refund_restock_items'     => 'yes',
				'mark_order_refunded'      => 'yes',
				'allowed_order_statuses'   => array( 'processing', 'completed' ),
				'form_title'               => 'Wygeneruj zwrot',
				'form_intro'               => 'Podaj numer zamówienia oraz e-mail lub telefon użyty przy zakupie.',
				'own_shipping_instructions' => 'Odeślij paczkę własną przesyłką zgodnie z regulaminem sklepu. Numer zwrotu dołącz do paczki.',
				'success_message'          => 'Zgłoszenie zwrotu zostało przyjęte. Magazyn będzie oczekiwał na paczkę.',
				'return_reasons'           => self::get_default_return_reasons_text(),
			),
			self::get_default_form_texts(),
			self::get_default_form_styles()
		);
	}

	/**
	 * Gets default form text strings.
	 *
	 * @return array
	 */
	public static function get_default_form_texts() {
		return array(
			'step_lookup'                 => 'Zamówienie',
			'step_items'                  => 'Produkty',
			'step_shipping'               => 'Odesłanie',
			'steps_aria_label'            => 'Etapy zwrotu',
			'order_reference_label'       => 'Numer zamówienia',
			'contact_label'               => 'E-mail lub telefon',
			'lookup_button'               => 'Pokaż produkty',
			'items_title'                 => 'Produkty w zamówieniu',
			'back_button'                 => 'Wróć',
			'next_button'                 => 'Dalej',
			'shipping_aria_label'         => 'Forma odesłania',
			'own_shipping_title'          => 'Własna przesyłka',
			'wygodne_zwroty_title'        => 'Wygodne Zwroty',
			'wygodne_zwroty_description'  => 'Po zapisaniu zwrotu przejdziesz do formularza przewoźnika.',
			'customer_note_label'         => 'Uwagi do zwrotu',
			'submit_button'               => 'Zgłoś zwrot',
			'success_title'               => 'Zwrot został zgłoszony',
			'loading'                     => 'Przetwarzanie...',
			'lookup_error'                => 'Nie udało się odczytać zamówienia.',
			'select_product_error'        => 'Wybierz przynajmniej jeden produkt do zwrotu.',
			'quantity_error'              => 'Sprawdź liczbę zwracanych sztuk.',
			'submit_error'                => 'Nie udało się zgłosić zwrotu.',
			'return_number_label'         => 'Numer zwrotu',
			'wygodne_zwroty_button'       => 'Przejdź do Wygodnych Zwrotów',
			'qty_label'                   => 'Szt.',
			'reason_label'                => 'Powód zwrotu',
			'sku_label'                   => 'SKU',
			'order_summary_prefix'        => '#',
		);
	}

	/**
	 * Gets labels for configurable form text fields.
	 *
	 * @return array
	 */
	public static function get_form_text_field_labels() {
		return array(
			'step_lookup'                => __( 'Krok: zamówienie', 'lemon-woo-returns' ),
			'step_items'                 => __( 'Krok: produkty', 'lemon-woo-returns' ),
			'step_shipping'              => __( 'Krok: odesłanie', 'lemon-woo-returns' ),
			'steps_aria_label'           => __( 'Etykieta dostępności kroków', 'lemon-woo-returns' ),
			'order_reference_label'      => __( 'Pole: numer zamówienia', 'lemon-woo-returns' ),
			'contact_label'              => __( 'Pole: e-mail lub telefon', 'lemon-woo-returns' ),
			'lookup_button'              => __( 'Przycisk: pokaż produkty', 'lemon-woo-returns' ),
			'items_title'                => __( 'Nagłówek listy produktów', 'lemon-woo-returns' ),
			'back_button'                => __( 'Przycisk: wróć', 'lemon-woo-returns' ),
			'next_button'                => __( 'Przycisk: dalej', 'lemon-woo-returns' ),
			'shipping_aria_label'        => __( 'Etykieta dostępności formy odesłania', 'lemon-woo-returns' ),
			'own_shipping_title'         => __( 'Opcja: własna przesyłka', 'lemon-woo-returns' ),
			'wygodne_zwroty_title'       => __( 'Opcja: Wygodne Zwroty', 'lemon-woo-returns' ),
			'wygodne_zwroty_description' => __( 'Opis Wygodnych Zwrotów', 'lemon-woo-returns' ),
			'customer_note_label'        => __( 'Pole: uwagi do zwrotu', 'lemon-woo-returns' ),
			'submit_button'              => __( 'Przycisk: zgłoś zwrot', 'lemon-woo-returns' ),
			'success_title'              => __( 'Nagłówek sukcesu', 'lemon-woo-returns' ),
			'loading'                    => __( 'Tekst ładowania', 'lemon-woo-returns' ),
			'lookup_error'               => __( 'Błąd odczytu zamówienia', 'lemon-woo-returns' ),
			'select_product_error'       => __( 'Błąd braku produktu', 'lemon-woo-returns' ),
			'quantity_error'             => __( 'Błąd ilości', 'lemon-woo-returns' ),
			'submit_error'               => __( 'Błąd zgłoszenia', 'lemon-woo-returns' ),
			'return_number_label'        => __( 'Etykieta numeru zwrotu', 'lemon-woo-returns' ),
			'wygodne_zwroty_button'      => __( 'Przycisk Wygodnych Zwrotów', 'lemon-woo-returns' ),
			'qty_label'                  => __( 'Etykieta ilości', 'lemon-woo-returns' ),
			'reason_label'               => __( 'Etykieta powodu', 'lemon-woo-returns' ),
			'sku_label'                  => __( 'Etykieta SKU', 'lemon-woo-returns' ),
			'order_summary_prefix'       => __( 'Prefiks numeru zamówienia', 'lemon-woo-returns' ),
		);
	}

	/**
	 * Gets default style tokens for the form.
	 *
	 * @return array
	 */
	public static function get_default_form_styles() {
		return array(
			'style_text_color'       => '#111827',
			'style_muted_color'      => '#6b7280',
			'style_line_color'       => '#d9dee7',
			'style_soft_color'       => '#f7f8fa',
			'style_accent_color'     => '#b9ee38',
			'style_accent_dark'      => '#202a08',
			'style_danger_color'     => '#b42318',
			'style_success_color'    => '#087f5b',
			'style_background'       => 'transparent',
			'style_shell_background' => '#ffffff',
			'style_input_background' => '#ffffff',
			'style_selected_bg'      => '#fbfcf5',
			'style_error_bg'         => '#fff7f5',
			'style_success_bg'       => '#f1fcf6',
			'style_shell_width'      => '860px',
			'style_shell_padding'    => 'clamp(20px, 4vw, 40px)',
			'style_radius'           => '8px',
			'style_field_radius'     => '8px',
			'style_button_radius'    => '8px',
			'style_image_fit'        => 'contain',
		);
	}

	/**
	 * Gets default return reasons as editable text.
	 *
	 * @return string
	 */
	public static function get_default_return_reasons_text() {
		return "wrong_size|Nieodpowiedni rozmiar\ndifferent_expect|Produkt nie spełnia oczekiwań\ndefect|Wada produktu\nchanged_mind|Zmiana decyzji\nother|Inny powód";
	}

	/**
	 * Gets settings merged with defaults.
	 *
	 * @return array
	 */
	public function get_settings() {
		if ( null !== $this->settings_cache ) {
			return $this->settings_cache;
		}

		$settings = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$defaults = self::get_default_settings();
		$settings = wp_parse_args( $settings, $defaults );

		$settings['enabled']                 = 'yes' === $settings['enabled'] ? 'yes' : 'no';
		$settings['wygodne_zwroty_url']     = esc_url_raw( $settings['wygodne_zwroty_url'] );
		$settings['erp_lookup_url']         = esc_url_raw( $settings['erp_lookup_url'] );
		$settings['erp_return_url']         = esc_url_raw( $settings['erp_return_url'] );
		$settings['erp_status_url']         = esc_url_raw( $settings['erp_status_url'] );
		$settings['erp_api_token']          = is_string( $settings['erp_api_token'] ) ? $settings['erp_api_token'] : '';
		$settings['erp_webhook_secret']     = is_string( $settings['erp_webhook_secret'] ) ? $settings['erp_webhook_secret'] : '';
		$settings['erp_timeout']            = max( 3, min( 60, absint( $settings['erp_timeout'] ) ) );
		$settings['erp_completed_statuses'] = is_string( $settings['erp_completed_statuses'] ) ? sanitize_textarea_field( $settings['erp_completed_statuses'] ) : $defaults['erp_completed_statuses'];
		$settings['refund_restock_items']   = 'yes' === $settings['refund_restock_items'] ? 'yes' : 'no';
		$settings['mark_order_refunded']    = 'yes' === $settings['mark_order_refunded'] ? 'yes' : 'no';
		$settings['allowed_order_statuses'] = $this->normalize_statuses( $settings['allowed_order_statuses'] );

		foreach ( array_merge( array( 'form_title', 'form_intro', 'success_message' ), array_keys( self::get_default_form_texts() ) ) as $field ) {
			$settings[ $field ] = is_string( $settings[ $field ] ) ? sanitize_text_field( $settings[ $field ] ) : $defaults[ $field ];
		}

		$settings['own_shipping_instructions'] = is_string( $settings['own_shipping_instructions'] ) ? wp_kses_post( $settings['own_shipping_instructions'] ) : $defaults['own_shipping_instructions'];
		$settings['return_reasons']            = is_string( $settings['return_reasons'] ) ? sanitize_textarea_field( $settings['return_reasons'] ) : $defaults['return_reasons'];

		foreach ( self::get_default_form_styles() as $field => $default ) {
			$settings[ $field ] = $this->sanitize_style_setting( $field, isset( $settings[ $field ] ) ? $settings[ $field ] : $default );
		}

		$this->settings_cache = $settings;

		return $this->settings_cache;
	}

	/**
	 * Gets one setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		$settings = $this->get_settings();

		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
	}

	/**
	 * Checks whether the public form is enabled.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		return 'yes' === $this->get( 'enabled', 'yes' );
	}

	/**
	 * Gets Wygodne Zwroty URL.
	 *
	 * @return string
	 */
	public function get_wygodne_zwroty_url() {
		return $this->get( 'wygodne_zwroty_url', '' );
	}

	/**
	 * Gets order statuses accepted by the fallback WooCommerce lookup.
	 *
	 * @return array
	 */
	public function get_allowed_order_statuses() {
		return $this->get( 'allowed_order_statuses', array() );
	}

	/**
	 * Gets normalized ERP statuses treated as completed returns.
	 *
	 * @return array
	 */
	public function get_completed_erp_statuses() {
		$raw      = $this->get( 'erp_completed_statuses', '' );
		$parts    = preg_split( '/[\r\n,]+/', (string) $raw );
		$statuses = array();

		foreach ( $parts as $part ) {
			$key = $this->normalize_erp_status_key( $part );

			if ( '' !== $key ) {
				$statuses[] = $key;
			}
		}

		return array_values( array_unique( $statuses ) );
	}

	/**
	 * Maps a raw ERP status to an internal request status.
	 *
	 * @param string $status Raw ERP status.
	 * @return string
	 */
	public function map_erp_status_to_internal_status( $status ) {
		$key = $this->normalize_erp_status_key( $status );

		if ( '' === $key ) {
			return 'pending_package';
		}

		if ( in_array( $key, $this->get_completed_erp_statuses(), true ) ) {
			return 'completed';
		}

		$map = array(
			'pending-package'      => 'pending_package',
			'pending-package-erp'  => 'pending_package',
			'oczekuje-na-paczke'   => 'pending_package',
			'oczekuje-na-przesylke' => 'pending_package',
			'received'             => 'received',
			'paczka-odebrana'      => 'received',
			'przesylka-odebrana'   => 'received',
			'processing'           => 'processing',
			'w-realizacji'         => 'processing',
			'weryfikacja'          => 'processing',
			'rejected'             => 'rejected',
			'odrzucony'            => 'rejected',
			'cancelled'            => 'cancelled',
			'canceled'             => 'cancelled',
			'anulowany'            => 'cancelled',
		);

		$map = apply_filters( 'll_returns_erp_status_map', $map, $status, $key );

		return isset( $map[ $key ] ) ? $map[ $key ] : str_replace( '-', '_', sanitize_key( $key ) );
	}

	/**
	 * Normalizes ERP status for matching.
	 *
	 * @param string $status Raw status.
	 * @return string
	 */
	public function normalize_erp_status_key( $status ) {
		$status = remove_accents( wp_strip_all_tags( (string) $status ) );
		$status = strtolower( $status );
		$status = str_replace( '_', '-', $status );
		$status = preg_replace( '/[^a-z0-9-]+/', '-', $status );

		return trim( $status, '-' );
	}

	/**
	 * Gets return reasons used in the product step.
	 *
	 * @return array
	 */
	public function get_return_reasons() {
		$reasons = $this->parse_return_reasons( $this->get( 'return_reasons', self::get_default_return_reasons_text() ) );

		if ( empty( $reasons ) ) {
			$reasons = $this->parse_return_reasons( self::get_default_return_reasons_text() );
		}

		return apply_filters( 'll_returns_return_reasons', $reasons );
	}

	/**
	 * Gets visible form text strings.
	 *
	 * @param array $overrides Optional non-empty per-instance overrides.
	 * @return array
	 */
	public function get_form_texts( $overrides = array() ) {
		$texts = array_merge(
			self::get_default_form_texts(),
			array(
				'form_title'                => $this->get( 'form_title', '' ),
				'form_intro'                => $this->get( 'form_intro', '' ),
				'own_shipping_instructions' => wp_strip_all_tags( $this->get( 'own_shipping_instructions', '' ) ),
				'success_message'           => $this->get( 'success_message', '' ),
			)
		);

		foreach ( array_keys( self::get_default_form_texts() ) as $key ) {
			$texts[ $key ] = $this->get( $key, $texts[ $key ] );
		}

		if ( is_array( $overrides ) ) {
			foreach ( $overrides as $key => $value ) {
				if ( array_key_exists( $key, $texts ) && '' !== trim( (string) $value ) ) {
					$texts[ $key ] = sanitize_text_field( $value );
				}
			}
		}

		return apply_filters( 'll_returns_form_texts', $texts, $overrides, $this );
	}

	/**
	 * Gets form style settings.
	 *
	 * @return array
	 */
	public function get_form_styles() {
		$styles = array();

		foreach ( self::get_default_form_styles() as $field => $default ) {
			$styles[ $field ] = $this->get( $field, $default );
		}

		return apply_filters( 'll_returns_form_styles', $styles, $this );
	}

	/**
	 * Clears cached settings.
	 */
	public function flush_caches() {
		$this->settings_cache = null;
	}

	/**
	 * Sanitizes settings saved from wp-admin.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		if ( ! is_array( $input ) ) {
			$input = array();
		}

		$previous = get_option( self::OPTION_NAME, array() );
		$defaults = self::get_default_settings();
		$output   = array();

		$output['enabled']                   = ! empty( $input['enabled'] ) ? 'yes' : 'no';
		$output['wygodne_zwroty_url']       = isset( $input['wygodne_zwroty_url'] ) ? esc_url_raw( wp_unslash( $input['wygodne_zwroty_url'] ) ) : $defaults['wygodne_zwroty_url'];
		$output['erp_lookup_url']           = isset( $input['erp_lookup_url'] ) ? esc_url_raw( wp_unslash( $input['erp_lookup_url'] ) ) : '';
		$output['erp_return_url']           = isset( $input['erp_return_url'] ) ? esc_url_raw( wp_unslash( $input['erp_return_url'] ) ) : '';
		$output['erp_status_url']           = isset( $input['erp_status_url'] ) ? esc_url_raw( wp_unslash( $input['erp_status_url'] ) ) : '';
		$output['erp_timeout']              = isset( $input['erp_timeout'] ) ? max( 3, min( 60, absint( $input['erp_timeout'] ) ) ) : $defaults['erp_timeout'];
		$output['erp_completed_statuses']   = isset( $input['erp_completed_statuses'] ) ? sanitize_textarea_field( wp_unslash( $input['erp_completed_statuses'] ) ) : $defaults['erp_completed_statuses'];
		$output['refund_restock_items']     = ! empty( $input['refund_restock_items'] ) ? 'yes' : 'no';
		$output['mark_order_refunded']      = ! empty( $input['mark_order_refunded'] ) ? 'yes' : 'no';
		$output['allowed_order_statuses']   = $this->normalize_statuses( isset( $input['allowed_order_statuses'] ) ? wp_unslash( $input['allowed_order_statuses'] ) : array() );
		$output['form_title']               = isset( $input['form_title'] ) ? sanitize_text_field( wp_unslash( $input['form_title'] ) ) : $defaults['form_title'];
		$output['form_intro']               = isset( $input['form_intro'] ) ? sanitize_text_field( wp_unslash( $input['form_intro'] ) ) : $defaults['form_intro'];
		$output['success_message']          = isset( $input['success_message'] ) ? sanitize_text_field( wp_unslash( $input['success_message'] ) ) : $defaults['success_message'];
		$output['own_shipping_instructions'] = isset( $input['own_shipping_instructions'] ) ? wp_kses_post( wp_unslash( $input['own_shipping_instructions'] ) ) : $defaults['own_shipping_instructions'];
		$output['return_reasons']           = isset( $input['return_reasons'] ) ? sanitize_textarea_field( wp_unslash( $input['return_reasons'] ) ) : $defaults['return_reasons'];

		foreach ( array_keys( self::get_default_form_texts() ) as $field ) {
			$output[ $field ] = isset( $input[ $field ] ) ? sanitize_text_field( wp_unslash( $input[ $field ] ) ) : $defaults[ $field ];
		}

		foreach ( self::get_default_form_styles() as $field => $default ) {
			$output[ $field ] = $this->sanitize_style_setting( $field, isset( $input[ $field ] ) ? wp_unslash( $input[ $field ] ) : $default );
		}

		if ( ! empty( $input['erp_api_token_clear'] ) ) {
			$output['erp_api_token'] = '';
		} elseif ( isset( $input['erp_api_token'] ) && '' !== trim( wp_unslash( $input['erp_api_token'] ) ) ) {
			$output['erp_api_token'] = sanitize_text_field( wp_unslash( $input['erp_api_token'] ) );
		} else {
			$output['erp_api_token'] = isset( $previous['erp_api_token'] ) && is_string( $previous['erp_api_token'] ) ? $previous['erp_api_token'] : '';
		}

		if ( ! empty( $input['erp_webhook_secret_clear'] ) ) {
			$output['erp_webhook_secret'] = '';
		} elseif ( isset( $input['erp_webhook_secret'] ) && '' !== trim( wp_unslash( $input['erp_webhook_secret'] ) ) ) {
			$output['erp_webhook_secret'] = sanitize_text_field( wp_unslash( $input['erp_webhook_secret'] ) );
		} else {
			$output['erp_webhook_secret'] = isset( $previous['erp_webhook_secret'] ) && is_string( $previous['erp_webhook_secret'] ) ? $previous['erp_webhook_secret'] : '';
		}

		$this->flush_caches();

		return $output;
	}

	/**
	 * Normalizes WooCommerce status slugs.
	 *
	 * @param mixed $statuses Raw statuses.
	 * @return array
	 */
	private function normalize_statuses( $statuses ) {
		if ( ! is_array( $statuses ) ) {
			$statuses = array();
		}

		$normalized = array();

		foreach ( $statuses as $status ) {
			$status = sanitize_key( str_replace( 'wc-', '', (string) $status ) );

			if ( '' !== $status ) {
				$normalized[] = $status;
			}
		}

		$normalized = array_values( array_unique( $normalized ) );

		if ( empty( $normalized ) ) {
			$normalized = self::get_default_settings()['allowed_order_statuses'];
		}

		return $normalized;
	}

	/**
	 * Parses editable return reasons.
	 *
	 * @param string $raw Raw textarea value.
	 * @return array
	 */
	private function parse_return_reasons( $raw ) {
		$reasons = array();
		$lines   = preg_split( '/\r\n|\r|\n/', (string) $raw );

		foreach ( $lines as $line ) {
			$line = trim( $line );

			if ( '' === $line ) {
				continue;
			}

			$parts = preg_split( '/[|=]/', $line, 2 );
			$key   = isset( $parts[0] ) ? sanitize_key( trim( $parts[0] ) ) : '';
			$label = isset( $parts[1] ) ? sanitize_text_field( trim( $parts[1] ) ) : '';

			if ( '' === $label && '' !== $key ) {
				$label = sanitize_text_field( trim( $parts[0] ) );
				$key   = sanitize_key( sanitize_title( $label ) );
			}

			if ( '' !== $key && '' !== $label ) {
				$reasons[ $key ] = $label;
			}
		}

		return $reasons;
	}

	/**
	 * Sanitizes a style setting.
	 *
	 * @param string $field Style field key.
	 * @param mixed  $value Raw value.
	 * @return string
	 */
	private function sanitize_style_setting( $field, $value ) {
		$value = trim( (string) $value );

		if ( 'style_image_fit' === $field ) {
			return in_array( $value, array( 'cover', 'contain', 'fill', 'none', 'scale-down' ), true ) ? $value : self::get_default_form_styles()[ $field ];
		}

		if ( false !== strpos( $field, 'color' ) || false !== strpos( $field, 'background' ) || false !== strpos( $field, '_bg' ) || 'style_accent_dark' === $field ) {
			if ( 'transparent' === strtolower( $value ) ) {
				return 'transparent';
			}

			$hex = sanitize_hex_color( $value );
			return $hex ? $hex : self::get_default_form_styles()[ $field ];
		}

		if ( $this->is_css_size_value( $value ) ) {
			return $value;
		}

		if ( 'style_shell_padding' === $field ) {
			$parts = preg_split( '/\s+/', $value );

			if ( count( $parts ) >= 1 && count( $parts ) <= 4 ) {
				foreach ( $parts as $part ) {
					if ( ! $this->is_css_size_value( $part ) ) {
						return self::get_default_form_styles()[ $field ];
					}
				}

				return implode( ' ', $parts );
			}
		}

		return self::get_default_form_styles()[ $field ];
	}

	/**
	 * Checks whether a value is a constrained CSS size token.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	private function is_css_size_value( $value ) {
		return 1 === preg_match( '/^-?\d+(\.\d+)?(px|rem|em|%|vw|vh)$/', $value ) || 1 === preg_match( '/^clamp\([a-z0-9.,% \\-]+\\)$/i', $value );
	}
}
