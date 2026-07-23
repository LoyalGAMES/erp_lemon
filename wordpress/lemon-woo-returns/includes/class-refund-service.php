<?php
/**
 * WooCommerce refund service.
 *
 * @package Lemon_Woo_Returns
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates native WooCommerce refunds from completed ERP return requests.
 */
class LL_Returns_Refund_Service {
	/**
	 * Settings service.
	 *
	 * @var LL_Returns_Settings
	 */
	private $settings;

	/**
	 * Return repository.
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
	 * Creates a WooCommerce refund for a local return request.
	 *
	 * @param int   $request_id Return request post ID.
	 * @param array $erp_context Final ERP response with optional delivery refund.
	 * @return int|WP_Error Refund ID or error.
	 */
	public function create_refund_for_request( $request_id, array $erp_context = array() ) {
		$existing_refund_id = absint( get_post_meta( $request_id, LL_Returns_Return_Repository::META_PREFIX . 'wc_refund_id', true ) );

		if ( $existing_refund_id ) {
			return $existing_refund_id;
		}

		if ( ! function_exists( 'wc_get_order' ) || ! function_exists( 'wc_create_refund' ) ) {
			return new WP_Error( 'll_returns_wc_refunds_missing', __( 'WooCommerce refund API nie jest dostepne.', 'lemon-woo-returns' ) );
		}

		$payload = $this->repository->get_payload( $request_id );

		if ( ! is_array( $payload ) ) {
			return new WP_Error( 'll_returns_refund_payload_missing', __( 'Brak danych zgloszenia zwrotu.', 'lemon-woo-returns' ) );
		}

		$order_id = ! empty( $payload['wc_order_id'] )
			? absint( $payload['wc_order_id'] )
			: ( ! empty( $payload['order_id'] ) ? absint( $payload['order_id'] ) : 0 );
		$order    = $order_id ? wc_get_order( $order_id ) : false;

		if ( ! $order ) {
			return new WP_Error( 'll_returns_refund_order_missing', __( 'Nie znaleziono zamowienia WooCommerce do utworzenia zwrotu.', 'lemon-woo-returns' ) );
		}

		$line_items    = array();
		$refund_amount = 0.0;

		foreach ( $payload['items'] as $returned_item ) {
			$item_id = isset( $returned_item['wc_order_item_id'] )
				? absint( $returned_item['wc_order_item_id'] )
				: ( isset( $returned_item['id'] ) ? absint( $returned_item['id'] ) : 0 );

			if ( ! $item_id ) {
				continue;
			}

			$order_item = $order->get_item( $item_id );

			if ( ! $order_item ) {
				continue;
			}

			$ordered_qty = max( 1, absint( $order_item->get_quantity() ) );
			$already_refunded_qty = method_exists( $order, 'get_qty_refunded_for_item' ) ? absint( abs( $order->get_qty_refunded_for_item( $item_id ) ) ) : 0;
			$available_qty = max( 0, $ordered_qty - $already_refunded_qty );
			$refund_qty    = min( absint( $returned_item['quantity'] ), $available_qty );

			if ( $refund_qty < 1 ) {
				continue;
			}

			$line_total = (float) $order_item->get_total();
			$refund_total = round( ( $line_total / $ordered_qty ) * $refund_qty, wc_get_price_decimals() );
			$refund_taxes = array();
			$item_taxes   = $order_item->get_taxes();

			if ( isset( $item_taxes['total'] ) && is_array( $item_taxes['total'] ) ) {
				foreach ( $item_taxes['total'] as $tax_rate_id => $tax_total ) {
					$tax_refund = round( ( (float) $tax_total / $ordered_qty ) * $refund_qty, wc_get_price_decimals() );

					if ( 0.0 !== $tax_refund ) {
						$refund_taxes[ $tax_rate_id ] = $tax_refund;
					}
				}
			}

			$line_items[ $item_id ] = array(
				'qty'          => $refund_qty,
				'refund_total' => wc_format_decimal( $refund_total ),
				'refund_tax'   => $refund_taxes,
			);

			$refund_amount += $refund_total + array_sum( $refund_taxes );
		}

		if ( empty( $line_items ) ) {
			return new WP_Error( 'll_returns_refund_no_lines', __( 'Nie ma pozycji zamowienia dostepnych do zwrotu WooCommerce.', 'lemon-woo-returns' ) );
		}

		$shipping_refund        = isset( $erp_context['shipping_refund'] ) && is_array( $erp_context['shipping_refund'] )
			? $erp_context['shipping_refund']
			: array();
		$shipping_refund_amount = isset( $shipping_refund['gross_amount'] ) && is_numeric( $shipping_refund['gross_amount'] )
			? max( 0.0, (float) $shipping_refund['gross_amount'] )
			: ( isset( $erp_context['shipping_refund_amount'] ) && is_numeric( $erp_context['shipping_refund_amount'] )
				? max( 0.0, (float) $erp_context['shipping_refund_amount'] )
				: 0.0 );

		if ( $shipping_refund_amount > 0 ) {
			$refund_currency = isset( $shipping_refund['currency'] ) ? strtoupper( (string) $shipping_refund['currency'] ) : '';

			if ( '' !== $refund_currency && strtoupper( (string) $order->get_currency() ) !== $refund_currency ) {
				return new WP_Error(
					'll_returns_refund_currency_mismatch',
					__( 'Waluta zwrotu kosztu dostawy z ERP nie zgadza sie z waluta zamowienia WooCommerce.', 'lemon-woo-returns' )
				);
			}

			$remaining_refund_amount = method_exists( $order, 'get_remaining_refund_amount' )
				? max( 0.0, (float) $order->get_remaining_refund_amount() )
				: (float) $order->get_total();
			$shipping_refund_amount = min(
				round( $shipping_refund_amount, wc_get_price_decimals() ),
				max( 0.0, $remaining_refund_amount - $refund_amount )
			);

			if ( $shipping_refund_amount > 0 ) {
				$shipping_item = $this->find_shipping_item( $order, $shipping_refund );

				if ( is_array( $shipping_item ) ) {
					$shipping_line_items = $this->shipping_refund_line_item(
						$shipping_item['item'],
						$shipping_refund,
						$shipping_refund_amount
					);
					$line_items[ $shipping_item['id'] ] = $shipping_line_items;
				}
			}

			$refund_amount += $shipping_refund_amount;
		}

		$return_reference = isset( $payload['return_reference'] ) ? $payload['return_reference'] : get_post_meta( $request_id, LL_Returns_Return_Repository::META_PREFIX . 'return_number', true );
		$reason           = sprintf( __( 'Zwrot zrealizowany w ERP: %s', 'lemon-woo-returns' ), $return_reference );

		$refund = wc_create_refund(
			array(
				'amount'         => wc_format_decimal( max( 0, $refund_amount ) ),
				'reason'         => $reason,
				'order_id'       => $order->get_id(),
				'line_items'     => $line_items,
				'refund_payment' => false,
				'restock_items'  => 'yes' === $this->settings->get( 'refund_restock_items', 'yes' ),
			)
		);

		if ( is_wp_error( $refund ) ) {
			$this->repository->record_refund_error( $request_id, $refund );
			return $refund;
		}

		$refund_id = $refund && method_exists( $refund, 'get_id' ) ? $refund->get_id() : 0;
		$this->repository->record_refund_created( $request_id, $refund_id );

		$order->add_order_note(
			sprintf(
				/* translators: 1: return reference, 2: refund ID */
				__( 'Zgloszenie zwrotu %1$s zostalo zrealizowane w ERP. Utworzono natywny zwrot WooCommerce #%2$d.', 'lemon-woo-returns' ),
				$return_reference,
				$refund_id
			)
		);

		if ( $shipping_refund_amount > 0 ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: refunded delivery amount */
					__( 'Zwrot obejmuje koszt dostawy przekazany przez ERP: %s.', 'lemon-woo-returns' ),
					wc_format_decimal( $shipping_refund_amount ) . ' ' . $order->get_currency()
				)
			);
		}

		$refreshed_order = wc_get_order( $order->get_id() );

		if ( 'yes' === $this->settings->get( 'mark_order_refunded', 'yes' ) && $this->is_fully_refunded( $refreshed_order ? $refreshed_order : $order ) ) {
			$order->update_status(
				'refunded',
				sprintf(
					/* translators: %s: return reference */
					__( 'Zamowienie oznaczone jako zwrocone po realizacji zgloszenia %s w ERP.', 'lemon-woo-returns' ),
					$return_reference
				),
				true
			);
		}

		return $refund_id;
	}

	/**
	 * Finds the shipping order item identified by ERP, with a safe fallback to
	 * the first delivery line for older ERP payloads.
	 *
	 * @param WC_Order $order           WooCommerce order.
	 * @param array    $shipping_refund Shipping refund context.
	 * @return array{id:int,item:WC_Order_Item_Shipping}|null
	 */
	private function find_shipping_item( $order, array $shipping_refund ) {
		$item_id = isset( $shipping_refund['wc_order_item_id'] ) ? absint( $shipping_refund['wc_order_item_id'] ) : 0;

		if ( $item_id ) {
			$item = $order->get_item( $item_id );

			if ( $item && $this->is_shipping_item( $item ) ) {
				return array(
					'id'   => $item_id,
					'item' => $item,
				);
			}
		}

		foreach ( $order->get_items( 'shipping' ) as $shipping_item_id => $shipping_item ) {
			return array(
				'id'   => absint( $shipping_item_id ),
				'item' => $shipping_item,
			);
		}

		return null;
	}

	/**
	 * Checks the WooCommerce item type without relying on a specific WC version.
	 *
	 * @param object $item Order item.
	 * @return bool
	 */
	private function is_shipping_item( $item ) {
		if ( method_exists( $item, 'is_type' ) ) {
			return $item->is_type( 'shipping' );
		}

		return method_exists( $item, 'get_type' ) && 'shipping' === $item->get_type();
	}

	/**
	 * Builds an itemized WooCommerce shipping refund, including tax allocation.
	 *
	 * @param WC_Order_Item_Shipping $shipping_item   Original shipping item.
	 * @param array                  $shipping_refund ERP refund context.
	 * @param float                  $refund_gross    Final clamped gross amount.
	 * @return array
	 */
	private function shipping_refund_line_item( $shipping_item, array $shipping_refund, $refund_gross ) {
		$decimals     = wc_get_price_decimals();
		$refund_gross = round( max( 0.0, (float) $refund_gross ), $decimals );
		$source_gross = isset( $shipping_refund['gross_amount'] ) && is_numeric( $shipping_refund['gross_amount'] )
			? max( 0.0, (float) $shipping_refund['gross_amount'] )
			: $refund_gross;
		$refund_net   = isset( $shipping_refund['net_amount'] ) && is_numeric( $shipping_refund['net_amount'] )
			? max( 0.0, (float) $shipping_refund['net_amount'] )
			: null;
		$refund_tax   = isset( $shipping_refund['tax_amount'] ) && is_numeric( $shipping_refund['tax_amount'] )
			? max( 0.0, (float) $shipping_refund['tax_amount'] )
			: null;

		if ( $source_gross > 0 && $source_gross !== $refund_gross ) {
			$scale = $refund_gross / $source_gross;
			$refund_net = null !== $refund_net ? $refund_net * $scale : null;
			$refund_tax = null !== $refund_tax ? $refund_tax * $scale : null;
		}

		$item_taxes      = method_exists( $shipping_item, 'get_taxes' ) ? $shipping_item->get_taxes() : array();
		$original_taxes  = isset( $item_taxes['total'] ) && is_array( $item_taxes['total'] ) ? $item_taxes['total'] : array();
		$taxable_amounts = array_filter(
			$original_taxes,
			static function ( $amount ) {
				return abs( (float) $amount ) > 0.000001;
			}
		);

		if ( null === $refund_tax ) {
			$original_net   = method_exists( $shipping_item, 'get_total' ) ? max( 0.0, (float) $shipping_item->get_total() ) : 0.0;
			$original_tax   = array_sum( array_map( 'floatval', $taxable_amounts ) );
			$original_gross = $original_net + $original_tax;
			$refund_tax     = $original_gross > 0 ? $refund_gross * ( $original_tax / $original_gross ) : 0.0;
		}

		$refund_tax = round( min( $refund_gross, max( 0.0, (float) $refund_tax ) ), $decimals );
		$refund_net = round( $refund_gross - $refund_tax, $decimals );
		$refund_taxes = $this->allocate_shipping_taxes( $taxable_amounts, $refund_tax, $decimals );

		if ( $refund_tax > 0 && empty( $refund_taxes ) ) {
			$refund_net = $refund_gross;
		}

		return array(
			'qty'          => 1,
			'refund_total' => wc_format_decimal( $refund_net ),
			'refund_tax'   => $refund_taxes,
		);
	}

	/**
	 * Splits the ERP tax amount proportionally over WooCommerce shipping rates.
	 *
	 * @param array $original_taxes Original shipping taxes keyed by rate ID.
	 * @param float $refund_tax     Tax to refund.
	 * @param int   $decimals       Store price precision.
	 * @return array
	 */
	private function allocate_shipping_taxes( array $original_taxes, $refund_tax, $decimals ) {
		$total_weight = array_sum( array_map( 'abs', array_map( 'floatval', $original_taxes ) ) );

		if ( $refund_tax <= 0 || $total_weight <= 0 ) {
			return array();
		}

		$allocated = array();
		$remaining = round( (float) $refund_tax, $decimals );
		$last_key  = array_key_last( $original_taxes );

		foreach ( $original_taxes as $tax_rate_id => $original_tax ) {
			$amount = $tax_rate_id === $last_key
				? $remaining
				: round( (float) $refund_tax * ( abs( (float) $original_tax ) / $total_weight ), $decimals );
			$allocated[ $tax_rate_id ] = $amount;
			$remaining                 = round( $remaining - $amount, $decimals );
		}

		return $allocated;
	}

	/**
	 * Checks whether every physical WooCommerce line has been refunded in full.
	 * A partial return must leave the order in its current business status so the
	 * remaining products can still be returned through the form.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return bool
	 */
	private function is_fully_refunded( $order ) {
		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			$ordered  = absint( $item->get_quantity() );
			$refunded = method_exists( $order, 'get_qty_refunded_for_item' ) ? absint( abs( $order->get_qty_refunded_for_item( $item_id ) ) ) : 0;

			if ( $ordered > $refunded ) {
				return false;
			}
		}

		return true;
	}
}
