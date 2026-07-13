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
	 * @param int $request_id Return request post ID.
	 * @return int|WP_Error Refund ID or error.
	 */
	public function create_refund_for_request( $request_id ) {
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
