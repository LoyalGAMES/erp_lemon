<?php
/**
 * Elementor return form widget.
 *
 * @package Lemon_Woo_Returns
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Widget_Base;

if ( ! class_exists( 'LL_Returns_Form_Widget' ) && class_exists( Widget_Base::class ) ) {
	/**
	 * Elementor wrapper for the return form shortcode.
	 */
	class LL_Returns_Form_Widget extends Widget_Base {
		/**
		 * Gets widget name.
		 *
		 * @return string
		 */
		public function get_name() {
			return 'll-returns-form';
		}

		/**
		 * Gets widget title.
		 *
		 * @return string
		 */
		public function get_title() {
			return __( 'Formularz zwrotu', 'lemon-woo-returns' );
		}

		/**
		 * Gets widget icon.
		 *
		 * @return string
		 */
		public function get_icon() {
			return 'eicon-form-horizontal';
		}

		/**
		 * Gets widget categories.
		 *
		 * @return array
		 */
		public function get_categories() {
			return array( 'lemon-elementor' );
		}

		/**
		 * Gets keywords.
		 *
		 * @return array
		 */
		public function get_keywords() {
			return array( 'zwrot', 'returns', 'woocommerce', 'erp', 'formularz' );
		}

		/**
		 * Gets style dependencies.
		 *
		 * @return array
		 */
		public function get_style_depends() {
			return array( LL_Returns_Assets::FORM_STYLE );
		}

		/**
		 * Gets script dependencies.
		 *
		 * @return array
		 */
		public function get_script_depends() {
			return array( LL_Returns_Assets::FORM_SCRIPT );
		}

		/**
		 * Registers widget controls.
		 */
		protected function register_controls() {
			$this->start_controls_section(
				'section_content',
				array(
					'label' => __( 'Tresc', 'lemon-woo-returns' ),
				)
			);

			$this->add_control(
				'title',
				array(
					'label'       => __( 'Tytul', 'lemon-woo-returns' ),
					'type'        => Controls_Manager::TEXT,
					'default'     => '',
					'placeholder' => __( 'Domyslny z ustawien wtyczki', 'lemon-woo-returns' ),
					'label_block' => true,
				)
			);

			$this->add_control(
				'intro',
				array(
					'label'       => __( 'Wstep', 'lemon-woo-returns' ),
					'type'        => Controls_Manager::TEXTAREA,
					'default'     => '',
					'placeholder' => __( 'Domyslny z ustawien wtyczki', 'lemon-woo-returns' ),
				)
			);

			$this->end_controls_section();

			$this->register_text_controls();
			$this->register_style_controls();
		}

		/**
		 * Registers text override controls.
		 */
		private function register_text_controls() {
			$this->start_controls_section(
				'section_text_overrides',
				array(
					'label' => __( 'Teksty i tłumaczenia', 'lemon-woo-returns' ),
				)
			);

			$labels = LL_Returns_Settings::get_form_text_field_labels();

			foreach ( $labels as $key => $label ) {
				$this->add_control(
					$key,
					array(
						'label'       => $label,
						'type'        => Controls_Manager::TEXT,
						'default'     => '',
						'placeholder' => __( 'Z ustawień wtyczki', 'lemon-woo-returns' ),
						'label_block' => true,
					)
				);
			}

			$this->add_control(
				'own_shipping_instructions',
				array(
					'label'       => __( 'Instrukcja własnej przesyłki', 'lemon-woo-returns' ),
					'type'        => Controls_Manager::TEXTAREA,
					'default'     => '',
					'placeholder' => __( 'Z ustawień wtyczki', 'lemon-woo-returns' ),
				)
			);

			$this->add_control(
				'success_message',
				array(
					'label'       => __( 'Komunikat po zgłoszeniu', 'lemon-woo-returns' ),
					'type'        => Controls_Manager::TEXTAREA,
					'default'     => '',
					'placeholder' => __( 'Z ustawień wtyczki', 'lemon-woo-returns' ),
				)
			);

			$this->end_controls_section();
		}

		/**
		 * Registers style controls.
		 */
		private function register_style_controls() {
			$this->start_controls_section(
				'section_style_colors',
				array(
					'label' => __( 'Kolory', 'lemon-woo-returns' ),
					'tab'   => Controls_Manager::TAB_STYLE,
				)
			);

			$this->add_color_var_control( 'style_text_color', __( 'Tekst', 'lemon-woo-returns' ), '--ll-returns-text' );
			$this->add_color_var_control( 'style_muted_color', __( 'Tekst pomocniczy', 'lemon-woo-returns' ), '--ll-returns-muted' );
			$this->add_color_var_control( 'style_line_color', __( 'Obramowanie', 'lemon-woo-returns' ), '--ll-returns-line' );
			$this->add_color_var_control( 'style_soft_color', __( 'Tło delikatne', 'lemon-woo-returns' ), '--ll-returns-soft' );
			$this->add_color_var_control( 'style_accent_color', __( 'Akcent', 'lemon-woo-returns' ), '--ll-returns-accent' );
			$this->add_color_var_control( 'style_accent_dark', __( 'Tekst na akcencie', 'lemon-woo-returns' ), '--ll-returns-accent-dark' );
			$this->add_color_var_control( 'style_background', __( 'Tło formularza', 'lemon-woo-returns' ), '--ll-returns-background' );
			$this->add_color_var_control( 'style_shell_background', __( 'Tło panelu', 'lemon-woo-returns' ), '--ll-returns-shell-background' );
			$this->add_color_var_control( 'style_input_background', __( 'Tło pól', 'lemon-woo-returns' ), '--ll-returns-input-background' );
			$this->add_color_var_control( 'style_selected_bg', __( 'Tło zaznaczenia', 'lemon-woo-returns' ), '--ll-returns-selected-bg' );
			$this->add_color_var_control( 'style_danger_color', __( 'Błąd', 'lemon-woo-returns' ), '--ll-returns-danger' );
			$this->add_color_var_control( 'style_error_bg', __( 'Tło błędu', 'lemon-woo-returns' ), '--ll-returns-error-bg' );
			$this->add_color_var_control( 'style_success_color', __( 'Sukces', 'lemon-woo-returns' ), '--ll-returns-success' );
			$this->add_color_var_control( 'style_success_bg', __( 'Tło sukcesu', 'lemon-woo-returns' ), '--ll-returns-success-bg' );

			$this->end_controls_section();

			$this->start_controls_section(
				'section_style_layout',
				array(
					'label' => __( 'Układ', 'lemon-woo-returns' ),
					'tab'   => Controls_Manager::TAB_STYLE,
				)
			);

			$this->add_responsive_control(
				'shell_width',
				array(
					'label'      => __( 'Maksymalna szerokość', 'lemon-woo-returns' ),
					'type'       => Controls_Manager::SLIDER,
					'size_units' => array( 'px', '%', 'vw' ),
					'range'      => array(
						'px' => array( 'min' => 320, 'max' => 1400 ),
						'%'  => array( 'min' => 20, 'max' => 100 ),
						'vw' => array( 'min' => 20, 'max' => 100 ),
					),
					'selectors'  => array( '{{WRAPPER}} .ll-returns' => '--ll-returns-shell-width: {{SIZE}}{{UNIT}};' ),
				)
			);

			$this->add_responsive_control(
				'shell_padding',
				array(
					'label'      => __( 'Padding panelu', 'lemon-woo-returns' ),
					'type'       => Controls_Manager::DIMENSIONS,
					'size_units' => array( 'px', 'em', 'rem', '%' ),
					'selectors'  => array( '{{WRAPPER}} .ll-returns' => '--ll-returns-shell-padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
				)
			);

			$this->add_radius_control( 'radius', __( 'Zaokrąglenie kart', 'lemon-woo-returns' ), '--ll-returns-radius' );
			$this->add_radius_control( 'field_radius', __( 'Zaokrąglenie pól', 'lemon-woo-returns' ), '--ll-returns-field-radius' );
			$this->add_radius_control( 'button_radius', __( 'Zaokrąglenie przycisków', 'lemon-woo-returns' ), '--ll-returns-button-radius' );

			$this->add_control(
				'image_fit',
				array(
					'label'     => __( 'Zdjęcia produktów', 'lemon-woo-returns' ),
					'type'      => Controls_Manager::SELECT,
					'default'   => '',
					'options'   => array(
						''           => __( 'Z ustawień wtyczki', 'lemon-woo-returns' ),
						'cover'      => __( 'Wypełnij i przytnij', 'lemon-woo-returns' ),
						'contain'    => __( 'Pokaż całe zdjęcie', 'lemon-woo-returns' ),
						'fill'       => __( 'Rozciągnij', 'lemon-woo-returns' ),
						'none'       => __( 'Oryginalny rozmiar', 'lemon-woo-returns' ),
						'scale-down' => __( 'Zmniejsz bez rozciągania', 'lemon-woo-returns' ),
					),
					'selectors' => array( '{{WRAPPER}} .ll-returns' => '--ll-returns-image-fit: {{VALUE}};' ),
				)
			);

			$this->end_controls_section();

			$this->start_controls_section(
				'section_style_typography',
				array(
					'label' => __( 'Typografia', 'lemon-woo-returns' ),
					'tab'   => Controls_Manager::TAB_STYLE,
				)
			);

			$this->add_group_control(
				Group_Control_Typography::get_type(),
				array(
					'name'     => 'title_typography',
					'label'    => __( 'Tytuł', 'lemon-woo-returns' ),
					'selector' => '{{WRAPPER}} .ll-returns__title',
				)
			);

			$this->add_group_control(
				Group_Control_Typography::get_type(),
				array(
					'name'     => 'intro_typography',
					'label'    => __( 'Wstęp', 'lemon-woo-returns' ),
					'selector' => '{{WRAPPER}} .ll-returns__intro',
				)
			);

			$this->add_group_control(
				Group_Control_Typography::get_type(),
				array(
					'name'     => 'label_typography',
					'label'    => __( 'Etykiety pól', 'lemon-woo-returns' ),
					'selector' => '{{WRAPPER}} .ll-returns__field, {{WRAPPER}} .ll-returns__compact-field',
				)
			);

			$this->add_group_control(
				Group_Control_Typography::get_type(),
				array(
					'name'     => 'button_typography',
					'label'    => __( 'Przyciski', 'lemon-woo-returns' ),
					'selector' => '{{WRAPPER}} .ll-returns__button',
				)
			);

			$this->end_controls_section();
		}

		/**
		 * Adds a color control mapped to a CSS variable.
		 *
		 * @param string $name  Control name.
		 * @param string $label Control label.
		 * @param string $var   CSS variable.
		 */
		private function add_color_var_control( $name, $label, $var ) {
			$this->add_control(
				$name,
				array(
					'label'     => $label,
					'type'      => Controls_Manager::COLOR,
					'selectors' => array( '{{WRAPPER}} .ll-returns' => $var . ': {{VALUE}};' ),
				)
			);
		}

		/**
		 * Adds a single radius slider mapped to a CSS variable.
		 *
		 * @param string $name  Control name.
		 * @param string $label Control label.
		 * @param string $var   CSS variable.
		 */
		private function add_radius_control( $name, $label, $var ) {
			$this->add_responsive_control(
				$name,
				array(
					'label'      => $label,
					'type'       => Controls_Manager::SLIDER,
					'size_units' => array( 'px', 'em', 'rem' ),
					'range'      => array( 'px' => array( 'min' => 0, 'max' => 60 ) ),
					'selectors'  => array( '{{WRAPPER}} .ll-returns' => $var . ': {{SIZE}}{{UNIT}};' ),
				)
			);
		}

		/**
		 * Renders widget.
		 */
		protected function render() {
			$settings = $this->get_settings_for_display();
			$atts     = array();

			if ( ! empty( $settings['title'] ) ) {
				$atts[] = 'title="' . esc_attr( $settings['title'] ) . '"';
			}

			if ( ! empty( $settings['intro'] ) ) {
				$atts[] = 'intro="' . esc_attr( $settings['intro'] ) . '"';
			}

			foreach ( array_merge( array_keys( LL_Returns_Settings::get_default_form_texts() ), array( 'success_message', 'own_shipping_instructions' ) ) as $key ) {
				if ( ! empty( $settings[ $key ] ) ) {
					$atts[] = $key . '="' . esc_attr( $settings[ $key ] ) . '"';
				}
			}

			echo do_shortcode( '[ll_return_form ' . implode( ' ', $atts ) . ']' );
		}
	}
}
