<?php
/**
 * Returns shortcodes.
 *
 * @package Lemon_Woo_Returns
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers public shortcodes.
 */
class LL_Returns_Shortcodes {
	/**
	 * Settings service.
	 *
	 * @var LL_Returns_Settings
	 */
	private $settings;

	/**
	 * Assets.
	 *
	 * @var LL_Returns_Assets
	 */
	private $assets;

	/**
	 * Constructor.
	 *
	 * @param LL_Returns_Settings $settings Settings.
	 * @param LL_Returns_Assets   $assets   Assets.
	 */
	public function __construct( LL_Returns_Settings $settings, LL_Returns_Assets $assets ) {
		$this->settings = $settings;
		$this->assets   = $assets;
	}

	/**
	 * Registers hooks.
	 */
	public function hooks() {
		add_shortcode( 'll_return_form', array( $this, 'render_return_form' ) );
	}

	/**
	 * Renders return form.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render_return_form( $atts = array() ) {
		if ( ! $this->settings->is_enabled() ) {
			return '';
		}

		$defaults = array_fill_keys( array_merge( array_keys( LL_Returns_Settings::get_default_form_texts() ), array( 'form_title', 'form_intro', 'success_message', 'own_shipping_instructions' ) ), '' );
		$defaults['title'] = '';
		$defaults['intro'] = '';

		$atts = shortcode_atts(
			$defaults,
			$atts,
			'll_return_form'
		);

		$overrides = $atts;

		if ( '' !== $atts['title'] ) {
			$overrides['form_title'] = $atts['title'];
		}

		if ( '' !== $atts['intro'] ) {
			$overrides['form_intro'] = $atts['intro'];
		}

		$texts       = $this->settings->get_form_texts( $overrides );
		$title       = $texts['form_title'];
		$intro       = $texts['form_intro'];
		$root_config = array(
			'ownShippingInstructions' => $texts['own_shipping_instructions'],
			'successMessage'          => $texts['success_message'],
			'i18n'                    => array(
				'loading'             => $texts['loading'],
				'lookupError'         => $texts['lookup_error'],
				'selectProduct'       => $texts['select_product_error'],
				'quantityError'       => $texts['quantity_error'],
				'submitError'         => $texts['submit_error'],
				'returnNumber'        => $texts['return_number_label'],
				'wygodneZwrotyButton' => $texts['wygodne_zwroty_button'],
				'qty'                 => $texts['qty_label'],
				'reason'              => $texts['reason_label'],
				'sku'                 => $texts['sku_label'],
				'orderSummaryPrefix'  => $texts['order_summary_prefix'],
			),
		);

		$this->assets->enqueue_form_assets();

		ob_start();

		?>
		<div class="ll-returns" data-ll-returns-form data-ll-returns-config="<?php echo esc_attr( wp_json_encode( $root_config ) ); ?>">
			<div class="ll-returns__shell">
				<header class="ll-returns__header">
					<?php if ( '' !== $title ) : ?>
						<h2 class="ll-returns__title"><?php echo esc_html( $title ); ?></h2>
					<?php endif; ?>

					<?php if ( '' !== $intro ) : ?>
						<p class="ll-returns__intro"><?php echo esc_html( $intro ); ?></p>
					<?php endif; ?>
				</header>

				<ol class="ll-returns__steps" aria-label="<?php echo esc_attr( $texts['steps_aria_label'] ); ?>">
					<li class="ll-returns__step is-active" data-ll-step-indicator="lookup"><?php echo esc_html( $texts['step_lookup'] ); ?></li>
					<li class="ll-returns__step" data-ll-step-indicator="items"><?php echo esc_html( $texts['step_items'] ); ?></li>
					<li class="ll-returns__step" data-ll-step-indicator="payment"><?php echo esc_html( $texts['step_payment'] ); ?></li>
					<li class="ll-returns__step" data-ll-step-indicator="shipping"><?php echo esc_html( $texts['step_shipping'] ); ?></li>
				</ol>

				<div class="ll-returns__notice" data-ll-returns-notice hidden></div>

				<section class="ll-returns__panel is-active" data-ll-returns-view="lookup">
					<form class="ll-returns__lookup-form" data-ll-returns-lookup-form>
						<div class="ll-returns__field-grid">
							<label class="ll-returns__field">
								<span><?php echo esc_html( $texts['order_reference_label'] ); ?></span>
								<input type="text" name="order_reference" autocomplete="off" required>
							</label>

							<label class="ll-returns__field">
								<span><?php echo esc_html( $texts['contact_label'] ); ?></span>
								<input type="text" name="contact" autocomplete="email" required>
							</label>
						</div>

						<div class="ll-returns__actions">
							<button type="submit" class="ll-returns__button ll-returns__button--primary">
								<?php echo esc_html( $texts['lookup_button'] ); ?>
							</button>
						</div>
					</form>
				</section>

				<section class="ll-returns__panel" data-ll-returns-view="items" hidden>
					<div class="ll-returns__section-head">
						<div>
							<h3><?php echo esc_html( $texts['items_title'] ); ?></h3>
							<p data-ll-returns-order-summary></p>
						</div>
					</div>

					<form data-ll-returns-items-form>
						<div class="ll-returns__items" data-ll-returns-items></div>

						<div class="ll-returns__actions ll-returns__actions--split">
							<button type="button" class="ll-returns__button ll-returns__button--ghost" data-ll-returns-back="lookup">
								<?php echo esc_html( $texts['back_button'] ); ?>
							</button>
							<button type="submit" class="ll-returns__button ll-returns__button--primary">
								<?php echo esc_html( $texts['next_button'] ); ?>
							</button>
						</div>
					</form>
				</section>

				<section class="ll-returns__panel" data-ll-returns-view="payment" hidden>
					<form data-ll-returns-payment-form>
						<div class="ll-returns__section-head">
							<div>
								<h3><?php echo esc_html( $texts['payment_title'] ); ?></h3>
								<p data-ll-payment-method-summary></p>
							</div>
						</div>

						<div class="ll-returns__payment-option" data-ll-cashback-option>
							<strong><?php echo esc_html( $texts['cashback_title'] ); ?></strong>
							<small><?php echo esc_html( $texts['cashback_description'] ); ?></small>
						</div>

						<div class="ll-returns__payment-option" data-ll-bank-option hidden>
							<strong><?php echo esc_html( $texts['bank_transfer_title'] ); ?></strong>
							<small><?php echo esc_html( $texts['bank_transfer_description'] ); ?></small>
							<div class="ll-returns__field-grid ll-returns__bank-fields">
								<label class="ll-returns__field">
									<span><?php echo esc_html( $texts['bank_recipient_label'] ); ?></span>
									<input type="text" name="refund_recipient_name" maxlength="255" autocomplete="name">
								</label>
								<label class="ll-returns__field">
									<span><?php echo esc_html( $texts['bank_account_label'] ); ?></span>
									<input type="text" name="refund_bank_account" maxlength="34" inputmode="numeric" autocomplete="off" placeholder="PL00 0000 0000 0000 0000 0000 0000">
								</label>
							</div>
						</div>

						<div class="ll-returns__payment-option ll-returns__payment-option--warning" data-ll-payment-unavailable hidden>
							<strong>Nie udało się rozpoznać płatności</strong>
							<small>Nie możemy bezpiecznie dobrać sposobu zwrotu pieniędzy. Skontaktuj się z obsługą sklepu.</small>
						</div>

						<div class="ll-returns__actions ll-returns__actions--split">
							<button type="button" class="ll-returns__button ll-returns__button--ghost" data-ll-returns-back="items"><?php echo esc_html( $texts['back_button'] ); ?></button>
							<button type="submit" class="ll-returns__button ll-returns__button--primary"><?php echo esc_html( $texts['next_button'] ); ?></button>
						</div>
					</form>
				</section>

				<section class="ll-returns__panel" data-ll-returns-view="shipping" hidden>
					<form data-ll-returns-shipping-form>
						<div class="ll-returns__shipping-options" role="radiogroup" aria-label="<?php echo esc_attr( $texts['shipping_aria_label'] ); ?>">
							<label class="ll-returns__shipping-option">
								<input type="radio" name="return_method" value="own_shipping" checked>
								<span>
									<strong><?php echo esc_html( $texts['own_shipping_title'] ); ?></strong>
									<small data-ll-own-shipping-copy></small>
								</span>
							</label>

							<label class="ll-returns__shipping-option">
								<input type="radio" name="return_method" value="wygodne_zwroty">
								<span>
									<strong><?php echo esc_html( $texts['wygodne_zwroty_title'] ); ?></strong>
									<small><?php echo esc_html( $texts['wygodne_zwroty_description'] ); ?></small>
								</span>
							</label>
						</div>

						<label class="ll-returns__field ll-returns__field--full">
							<span><?php echo esc_html( $texts['customer_note_label'] ); ?></span>
							<textarea name="customer_note" rows="4"></textarea>
						</label>

						<div class="ll-returns__actions ll-returns__actions--split">
							<button type="button" class="ll-returns__button ll-returns__button--ghost" data-ll-returns-back="payment">
								<?php echo esc_html( $texts['back_button'] ); ?>
							</button>
							<button type="submit" class="ll-returns__button ll-returns__button--primary">
								<?php echo esc_html( $texts['submit_button'] ); ?>
							</button>
						</div>
					</form>
				</section>

				<section class="ll-returns__panel ll-returns__success" data-ll-returns-view="success" hidden>
					<h3><?php echo esc_html( $texts['success_title'] ); ?></h3>
					<p data-ll-returns-success-message></p>
					<p class="ll-returns__return-number" data-ll-returns-return-number></p>
					<div class="ll-returns__actions" data-ll-returns-success-actions></div>
				</section>
			</div>
		</div>
		<?php

		return ob_get_clean();
	}
}
