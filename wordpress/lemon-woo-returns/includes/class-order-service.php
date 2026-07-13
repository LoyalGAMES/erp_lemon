<?php
/**
 * Order lookup service.
 *
 * @package Lemon_Woo_Returns
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves returnable order data from ERP or WooCommerce fallback.
 */
class LL_Returns_Order_Service {
	/**
	 * Settings service.
	 *
	 * @var LL_Returns_Settings
	 */
	private $settings;

	/**
	 * ERP client.
	 *
	 * @var LL_Returns_ERP_Client
	 */
	private $erp_client;

	/**
	 * Local return repository.
	 *
	 * @var LL_Returns_Return_Repository|null
	 */
	private $repository;

	/**
	 * Constructor.
	 *
	 * @param LL_Returns_Settings               $settings   Settings.
	 * @param LL_Returns_ERP_Client             $erp_client ERP client.
	 * @param LL_Returns_Return_Repository|null $repository Repository.
	 */
	public function __construct( LL_Returns_Settings $settings, LL_Returns_ERP_Client $erp_client, $repository = null ) {
		$this->settings   = $settings;
		$this->erp_client = $erp_client;
		$this->repository = $repository;
	}

	/**
	 * Resolves order data.
	 *
	 * @param string $order_reference Order number/reference.
	 * @param string $contact         Customer email or phone.
	 * @return array|WP_Error
	 */
	public function resolve_order( $order_reference, $contact ) {
		$order_reference = $this->clean_order_reference( $order_reference );
		$contact         = $this->clean_contact( $contact );

		if ( '' === $order_reference || '' === $contact ) {
			return new WP_Error( 'll_returns_missing_lookup_fields', __( 'Podaj numer zamowienia oraz e-mail lub telefon.', 'lemon-woo-returns' ) );
		}

		$pre = apply_filters( 'll_returns_resolve_order', null, $order_reference, $contact );

		if ( null !== $pre ) {
			$order = is_wp_error( $pre ) ? $pre : $this->normalize_order_data( $pre );
			return is_wp_error( $order ) ? $order : $this->apply_local_reservations( $order );
		}

		if ( $this->erp_client->has_lookup_endpoint() ) {
			$erp_order = $this->erp_client->lookup_order( $order_reference, $contact );

			if ( is_wp_error( $erp_order ) ) {
				return $erp_order;
			}

			$order = $this->normalize_order_data( $erp_order );
			return is_wp_error( $order ) ? $order : $this->apply_local_reservations( $order );
		}

		$order = $this->resolve_woocommerce_order( $order_reference, $contact );

		return is_wp_error( $order ) ? $order : $this->apply_local_reservations( $order );
	}

	/**
	 * Prepares a public order payload for the browser.
	 *
	 * @param array $order Order data.
	 * @return array
	 */
	public function get_public_order_data( array $order ) {
		$items = array();

		foreach ( $order['items'] as $item ) {
			$items[] = array(
				'id'       => $item['id'],
				'name'     => $item['name'],
				'sku'      => $item['sku'],
				'quantity' => $item['quantity'],
				'image'    => $item['image'],
				'price'    => $item['price'],
			);
		}

		return array(
			'order_id'        => $order['order_id'],
			'order_reference' => $order['order_reference'],
			'order_number'    => $order['order_number'],
			'currency'        => $order['currency'],
			'items'           => $items,
		);
	}

	/**
	 * Validates submitted items against resolved order data.
	 *
	 * @param array $order           Resolved order.
	 * @param array $submitted_items Submitted items.
	 * @return array|WP_Error
	 */
	public function validate_return_items( array $order, array $submitted_items ) {
		$available = array();

		foreach ( $order['items'] as $item ) {
			$available[ (string) $item['id'] ] = $item;
		}

		$validated = array();
		$seen      = array();

		foreach ( $submitted_items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$item_id = isset( $item['id'] ) ? sanitize_text_field( wp_unslash( $item['id'] ) ) : '';

			if ( '' === $item_id || ! isset( $available[ $item_id ] ) ) {
				continue;
			}

			if ( isset( $seen[ $item_id ] ) ) {
				return new WP_Error( 'll_returns_duplicate_item', __( 'Ta sama pozycja zostala wybrana wiecej niz raz. Wyszukaj zamowienie ponownie.', 'lemon-woo-returns' ) );
			}

			$seen[ $item_id ] = true;

			$max_qty = absint( $available[ $item_id ]['quantity'] );
			$qty     = isset( $item['quantity'] ) ? absint( $item['quantity'] ) : 0;

			if ( $qty < 1 || $qty > $max_qty ) {
				return new WP_Error( 'll_returns_invalid_quantity', __( 'Wybrana liczba sztuk przekracza liczbe produktow w zamowieniu.', 'lemon-woo-returns' ) );
			}

			$reason = isset( $item['reason'] ) ? sanitize_key( wp_unslash( $item['reason'] ) ) : 'other';

			$validated[] = array(
				'id'           => $item_id,
				'name'         => $available[ $item_id ]['name'],
				'sku'          => $available[ $item_id ]['sku'],
				'quantity'     => $qty,
				'max_quantity' => $max_qty,
				'price'        => $available[ $item_id ]['price'],
				'reason'       => $reason,
				'return_item_key' => isset( $available[ $item_id ]['return_item_key'] ) ? $available[ $item_id ]['return_item_key'] : $item_id,
				'wc_order_item_id' => isset( $available[ $item_id ]['wc_order_item_id'] ) ? $available[ $item_id ]['wc_order_item_id'] : $item_id,
			);
		}

		if ( empty( $validated ) ) {
			return new WP_Error( 'll_returns_no_items', __( 'Wybierz przynajmniej jeden produkt do zwrotu.', 'lemon-woo-returns' ) );
		}

		return $validated;
	}

	/**
	 * Resolves order from local WooCommerce data.
	 *
	 * @param string $order_reference Order number/reference.
	 * @param string $contact         Customer email or phone.
	 * @return array|WP_Error
	 */
	private function resolve_woocommerce_order( $order_reference, $contact ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return new WP_Error( 'll_returns_woocommerce_missing', __( 'WooCommerce nie jest aktywny, a endpoint ERP nie jest skonfigurowany.', 'lemon-woo-returns' ) );
		}

		$order = $this->find_woocommerce_order( $order_reference );

		if ( ! $order ) {
			return new WP_Error( 'll_returns_order_not_found', __( 'Nie znaleziono zamowienia dla podanych danych.', 'lemon-woo-returns' ) );
		}

		if ( ! $this->contact_matches_order( $order, $contact ) ) {
			return new WP_Error( 'll_returns_order_contact_mismatch', __( 'Nie znaleziono zamowienia dla podanych danych.', 'lemon-woo-returns' ) );
		}

		if ( ! in_array( $order->get_status(), $this->settings->get_allowed_order_statuses(), true ) ) {
			return new WP_Error( 'll_returns_order_status_not_allowed', __( 'To zamowienie nie jest dostepne do zwrotu przez formularz.', 'lemon-woo-returns' ) );
		}

		$items = array();

		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			$qty      = absint( $item->get_quantity() );
			$refunded = method_exists( $order, 'get_qty_refunded_for_item' ) ? absint( abs( $order->get_qty_refunded_for_item( $item_id ) ) ) : 0;
			$qty      = max( 0, $qty - $refunded );

			if ( $qty < 1 ) {
				continue;
			}

			$product = $item->get_product();
			$image   = '';
			$sku     = '';

			if ( $product ) {
				$sku      = (string) $product->get_sku();
				$image_id = $product->get_image_id();
				$image    = $image_id ? wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' ) : '';
			}

			$line_qty = max( 1, absint( $item->get_quantity() ) );
			$price    = (float) $item->get_total() / $line_qty;

			$items[] = array(
				'id'                => (string) $item_id,
				'return_item_key'   => (string) $item_id,
				'wc_order_item_id'  => (string) $item_id,
				'name'              => wp_strip_all_tags( $item->get_name() ),
				'sku'               => $sku,
				'quantity'          => $qty,
				'image'             => $image ? $image : '',
				'price'             => round( $price, wc_get_price_decimals() ),
			);
		}

		if ( empty( $items ) ) {
			return new WP_Error( 'll_returns_no_returnable_items', __( 'W tym zamowieniu nie ma produktow dostepnych do zwrotu.', 'lemon-woo-returns' ) );
		}

		return array(
			'source'          => 'woocommerce',
			'order_id'        => (string) $order->get_id(),
			'wc_order_id'     => (string) $order->get_id(),
			'return_order_key' => 'woocommerce:' . (string) $order->get_id(),
			'order_reference' => $order_reference,
			'order_number'    => (string) $order->get_order_number(),
			'currency'        => $order->get_currency(),
			'customer_email'  => (string) $order->get_billing_email(),
			'customer_phone'  => (string) $order->get_billing_phone(),
			'accounted_return_references' => array(),
			'items'           => $items,
		);
	}

	/**
	 * Finds WooCommerce order by common references.
	 *
	 * @param string $order_reference Order number/reference.
	 * @return WC_Order|false
	 */
	private function find_woocommerce_order( $order_reference ) {
		$pre = apply_filters( 'll_returns_find_woocommerce_order', null, $order_reference );

		if ( null !== $pre ) {
			return $pre;
		}

		if ( function_exists( 'wc_get_order_id_by_order_key' ) ) {
			$order_id = wc_get_order_id_by_order_key( $order_reference );

			if ( $order_id ) {
				$order = wc_get_order( $order_id );

				if ( $order ) {
					return $order;
				}
			}
		}

		if ( ctype_digit( $order_reference ) ) {
			$order = wc_get_order( absint( $order_reference ) );

			if ( $order ) {
				return $order;
			}
		}

		if ( function_exists( 'wc_get_orders' ) ) {
			$orders = wc_get_orders(
				array(
					'limit'      => 1,
					'return'     => 'objects',
					'type'       => 'shop_order',
					'meta_key'   => '_order_number',
					'meta_value' => $order_reference,
				)
			);

			if ( ! empty( $orders ) ) {
				return $orders[0];
			}
		}

		return false;
	}

	/**
	 * Checks customer email or phone against an order.
	 *
	 * @param WC_Order $order   Order.
	 * @param string   $contact Customer email or phone.
	 * @return bool
	 */
	private function contact_matches_order( $order, $contact ) {
		$contact = $this->clean_contact( $contact );

		if ( is_email( $contact ) ) {
			return strtolower( $contact ) === strtolower( (string) $order->get_billing_email() );
		}

		$contact_phone = $this->normalize_phone( $contact );
		$order_phone   = $this->normalize_phone( (string) $order->get_billing_phone() );

		if ( method_exists( $order, 'get_shipping_phone' ) && '' === $order_phone ) {
			$order_phone = $this->normalize_phone( (string) $order->get_shipping_phone() );
		}

		if ( '' === $contact_phone || '' === $order_phone ) {
			return false;
		}

		if ( $contact_phone === $order_phone ) {
			return true;
		}

		return strlen( $contact_phone ) >= 9 && strlen( $order_phone ) >= 9 && substr( $contact_phone, -9 ) === substr( $order_phone, -9 );
	}

	/**
	 * Normalizes order data from filters or ERP.
	 *
	 * @param array $data Raw data.
	 * @return array|WP_Error
	 */
	private function normalize_order_data( $data ) {
		if ( ! is_array( $data ) || empty( $data['items'] ) || ! is_array( $data['items'] ) ) {
			return new WP_Error( 'll_returns_invalid_order_payload', __( 'ERP nie zwrocilo poprawnych danych zamowienia.', 'lemon-woo-returns' ) );
		}

		$items = array();

		foreach ( $data['items'] as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$item_id = isset( $item['id'] ) ? sanitize_text_field( (string) $item['id'] ) : '';
			$name    = isset( $item['name'] ) ? sanitize_text_field( (string) $item['name'] ) : '';
			$qty     = isset( $item['quantity'] ) ? absint( $item['quantity'] ) : 0;

			if ( '' === $item_id || '' === $name || $qty < 1 ) {
				continue;
			}

			$items[] = array(
				'id'               => $item_id,
				'return_item_key'  => isset( $item['return_item_key'] ) ? sanitize_text_field( (string) $item['return_item_key'] ) : $item_id,
				'wc_order_item_id' => isset( $item['wc_order_item_id'] ) ? sanitize_text_field( (string) $item['wc_order_item_id'] ) : $item_id,
				'name'             => $name,
				'sku'              => isset( $item['sku'] ) ? sanitize_text_field( (string) $item['sku'] ) : '',
				'quantity'         => $qty,
				'image'            => isset( $item['image'] ) ? esc_url_raw( (string) $item['image'] ) : '',
				'price'            => isset( $item['price'] ) ? (float) $item['price'] : 0,
			);
		}

		if ( empty( $items ) ) {
			return new WP_Error( 'll_returns_no_returnable_items', __( 'W tym zamowieniu nie ma produktow dostepnych do zwrotu.', 'lemon-woo-returns' ) );
		}

		$order_reference = isset( $data['order_reference'] ) ? sanitize_text_field( (string) $data['order_reference'] ) : '';
		$order_number    = isset( $data['order_number'] ) ? sanitize_text_field( (string) $data['order_number'] ) : $order_reference;
		$order_id        = isset( $data['order_id'] ) ? sanitize_text_field( (string) $data['order_id'] ) : $order_reference;
		$return_order_key = isset( $data['return_order_key'] )
			? sanitize_text_field( (string) $data['return_order_key'] )
			: 'erp:' . $order_id;
		$accounted_references = array();

		foreach ( (array) ( isset( $data['accounted_return_references'] ) ? $data['accounted_return_references'] : array() ) as $reference ) {
			$reference = sanitize_text_field( (string) $reference );

			if ( '' !== $reference ) {
				$accounted_references[] = $reference;
			}
		}

		return array(
			'source'          => isset( $data['source'] ) ? sanitize_key( (string) $data['source'] ) : 'erp',
			'order_id'        => $order_id,
			'wc_order_id'     => isset( $data['wc_order_id'] ) ? sanitize_text_field( (string) $data['wc_order_id'] ) : $order_id,
			'return_order_key' => $return_order_key,
			'order_reference' => $order_reference,
			'order_number'    => $order_number,
			'currency'        => isset( $data['currency'] ) ? sanitize_text_field( (string) $data['currency'] ) : '',
			'customer_email'  => isset( $data['customer_email'] ) ? sanitize_email( (string) $data['customer_email'] ) : '',
			'customer_phone'  => isset( $data['customer_phone'] ) ? sanitize_text_field( (string) $data['customer_phone'] ) : '',
			'accounted_return_references' => array_values( array_unique( $accounted_references ) ),
			'items'           => $items,
		);
	}

	/**
	 * Removes quantities already reserved in local requests but not yet reflected
	 * by the selected order source.
	 *
	 * @param array $order Normalized order.
	 * @return array|WP_Error
	 */
	private function apply_local_reservations( array $order ) {
		if ( ! $this->repository || ! method_exists( $this->repository, 'get_reserved_quantities_for_order' ) ) {
			return $order;
		}

		$reserved = $this->repository->get_reserved_quantities_for_order( $order );
		$items    = array();

		foreach ( $order['items'] as $item ) {
			$key               = isset( $item['return_item_key'] ) ? (string) $item['return_item_key'] : (string) $item['id'];
			$item['quantity']  = max( 0, absint( $item['quantity'] ) - absint( isset( $reserved[ $key ] ) ? $reserved[ $key ] : 0 ) );

			if ( $item['quantity'] > 0 ) {
				$items[] = $item;
			}
		}

		if ( empty( $items ) ) {
			return new WP_Error( 'll_returns_no_returnable_items', __( 'W tym zamowieniu nie ma produktow dostepnych do zwrotu.', 'lemon-woo-returns' ) );
		}

		$order['items'] = $items;

		return $order;
	}

	/**
	 * Cleans order reference.
	 *
	 * @param string $order_reference Raw reference.
	 * @return string
	 */
	private function clean_order_reference( $order_reference ) {
		$order_reference = trim( sanitize_text_field( wp_unslash( $order_reference ) ) );

		return ltrim( $order_reference, "# \t\n\r\0\x0B" );
	}

	/**
	 * Cleans customer contact.
	 *
	 * @param string $contact Raw contact.
	 * @return string
	 */
	private function clean_contact( $contact ) {
		return trim( sanitize_text_field( wp_unslash( $contact ) ) );
	}

	/**
	 * Keeps only phone digits.
	 *
	 * @param string $phone Phone.
	 * @return string
	 */
	private function normalize_phone( $phone ) {
		return preg_replace( '/\D+/', '', (string) $phone );
	}
}
