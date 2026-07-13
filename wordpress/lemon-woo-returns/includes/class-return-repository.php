<?php
/**
 * Return request repository.
 *
 * @package Lemon_Woo_Returns
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores local return request records for audit and fallback mode.
 */
class LL_Returns_Return_Repository {
	const POST_TYPE = 'll_return_request';
	const META_PREFIX = '_ll_returns_';

	/**
	 * Registers hooks.
	 */
	public function hooks() {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'add_meta_boxes', array( $this, 'register_meta_boxes' ) );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( $this, 'register_columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
	}

	/**
	 * Registers private admin post type for return requests.
	 */
	public function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'Zgłoszenia zwrotów', 'lemon-woo-returns' ),
					'singular_name' => __( 'Zgłoszenie zwrotu', 'lemon-woo-returns' ),
					'menu_name'     => __( 'Zgłoszenia zwrotów', 'lemon-woo-returns' ),
					'add_new_item'  => __( 'Dodaj zgłoszenie zwrotu', 'lemon-woo-returns' ),
					'edit_item'     => __( 'Zgłoszenie zwrotu', 'lemon-woo-returns' ),
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => class_exists( 'WooCommerce' ) ? 'woocommerce' : true,
				'capability_type'     => 'shop_order',
				'map_meta_cap'        => true,
				'supports'            => array( 'title' ),
				'has_archive'         => false,
				'exclude_from_search' => true,
				'rewrite'             => false,
			)
		);
	}

	/**
	 * Creates a local request record.
	 *
	 * @param array  $payload Return payload.
	 * @param string $status  Internal status.
	 * @return array|WP_Error
	 */
	public function create_request( array $payload, $status = 'submitting' ) {
		$return_number = $this->generate_return_number();
		$title         = sprintf( '%s - %s', $return_number, isset( $payload['order_number'] ) ? $payload['order_number'] : $payload['order_reference'] );

		$post_id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $title,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$payload['return_reference'] = $return_number;
		$payload['local_return_id']  = $post_id;

		update_post_meta( $post_id, self::META_PREFIX . 'return_number', $return_number );
		update_post_meta( $post_id, self::META_PREFIX . 'status', sanitize_key( $status ) );
		update_post_meta( $post_id, self::META_PREFIX . 'payload', $payload );
		update_post_meta( $post_id, self::META_PREFIX . 'order_reference', sanitize_text_field( $payload['order_reference'] ) );
		update_post_meta( $post_id, self::META_PREFIX . 'order_number', sanitize_text_field( $payload['order_number'] ) );
		update_post_meta( $post_id, self::META_PREFIX . 'return_order_key', sanitize_text_field( isset( $payload['return_order_key'] ) ? $payload['return_order_key'] : '' ) );
		update_post_meta( $post_id, self::META_PREFIX . 'return_method', sanitize_key( $payload['return_method'] ) );
		update_post_meta( $post_id, self::META_PREFIX . 'contact', sanitize_text_field( $payload['customer_contact'] ) );

		return array(
			'post_id'       => $post_id,
			'return_number' => $return_number,
			'payload'       => $payload,
		);
	}

	/**
	 * Marks a request as accepted.
	 *
	 * @param int   $post_id      Post ID.
	 * @param array $erp_response ERP response.
	 */
	public function mark_accepted( $post_id, array $erp_response ) {
		$mode = isset( $erp_response['mode'] ) ? sanitize_key( $erp_response['mode'] ) : 'remote';

		update_post_meta( $post_id, self::META_PREFIX . 'status', 'pending_package' );
		update_post_meta( $post_id, self::META_PREFIX . 'erp_response', $erp_response );
		update_post_meta( $post_id, self::META_PREFIX . 'erp_mode', $mode );
		update_post_meta( $post_id, self::META_PREFIX . 'erp_last_attempt_at', current_time( 'mysql' ) );
		delete_post_meta( $post_id, self::META_PREFIX . 'erp_error' );
		delete_post_meta( $post_id, self::META_PREFIX . 'status_sync_error' );

		if ( 'remote' === $mode ) {
			update_post_meta( $post_id, self::META_PREFIX . 'erp_delivered_at', current_time( 'mysql' ) );
		} else {
			delete_post_meta( $post_id, self::META_PREFIX . 'erp_delivered_at' );
		}

		$external_id = $this->extract_external_id( $erp_response );

		if ( '' !== $external_id ) {
			update_post_meta( $post_id, self::META_PREFIX . 'erp_external_id', $external_id );
		}
	}

	/**
	 * Marks a request as failed.
	 *
	 * @param int      $post_id Post ID.
	 * @param WP_Error $error   Error.
	 */
	public function mark_failed( $post_id, WP_Error $error ) {
		update_post_meta( $post_id, self::META_PREFIX . 'status', 'erp_failed' );
		update_post_meta( $post_id, self::META_PREFIX . 'erp_last_attempt_at', current_time( 'mysql' ) );
		update_post_meta(
			$post_id,
			self::META_PREFIX . 'erp_attempt_count',
			absint( get_post_meta( $post_id, self::META_PREFIX . 'erp_attempt_count', true ) ) + 1
		);
		update_post_meta(
			$post_id,
			self::META_PREFIX . 'erp_error',
			array(
				'code'    => $error->get_error_code(),
				'message' => $error->get_error_message(),
				'data'    => $error->get_error_data(),
			)
		);
	}

	/**
	 * Marks a request rejected by ERP due to a permanent business error.
	 * Rejected requests remain in the audit log but no longer reserve quantities
	 * or enter the automatic delivery queue.
	 *
	 * @param int      $post_id Post ID.
	 * @param WP_Error $error   ERP rejection.
	 */
	public function mark_rejected( $post_id, WP_Error $error ) {
		update_post_meta( $post_id, self::META_PREFIX . 'status', 'rejected' );
		update_post_meta( $post_id, self::META_PREFIX . 'erp_last_attempt_at', current_time( 'mysql' ) );
		update_post_meta(
			$post_id,
			self::META_PREFIX . 'erp_error',
			array(
				'code'      => $error->get_error_code(),
				'message'   => $error->get_error_message(),
				'data'      => $error->get_error_data(),
				'permanent' => true,
			)
		);
	}

	/**
	 * Adds a WooCommerce order note for local visibility.
	 *
	 * @param array $payload       Return payload.
	 * @param array $record        Local record data.
	 * @param array $erp_response  ERP response.
	 */
	public function add_order_note( array $payload, array $record, array $erp_response ) {
		if ( empty( $payload['order_id'] ) || ! function_exists( 'wc_get_order' ) || 'woocommerce' !== $payload['source'] ) {
			return;
		}

		$order = wc_get_order( absint( $payload['order_id'] ) );

		if ( ! $order ) {
			return;
		}

		$lines = array();

		foreach ( $payload['items'] as $item ) {
			$lines[] = sprintf( '%s x %d', $item['name'], $item['quantity'] );
		}

		$note = sprintf(
			/* translators: 1: return number, 2: return method, 3: product list */
			__( 'Klient wygenerowal zwrot %1$s. Metoda odeslania: %2$s. Produkty: %3$s.', 'lemon-woo-returns' ),
			$record['return_number'],
			$payload['return_method'],
			implode( ', ', $lines )
		);

		if ( ! empty( $erp_response['external_id'] ) ) {
			$note .= ' ' . sprintf( __( 'ID ERP: %s.', 'lemon-woo-returns' ), $erp_response['external_id'] );
		}

		$order->add_order_note( $note );
	}

	/**
	 * Gets stored return request payload.
	 *
	 * @param int $post_id Post ID.
	 * @return array|null
	 */
	public function get_payload( $post_id ) {
		$payload = get_post_meta( $post_id, self::META_PREFIX . 'payload', true );

		return is_array( $payload ) ? $payload : null;
	}

	/**
	 * Aggregates quantities reserved by local requests that are not yet included
	 * in the current ERP/WooCommerce availability response.
	 *
	 * @param array $order Normalized order data.
	 * @return array<string, int>
	 */
	public function get_reserved_quantities_for_order( array $order ) {
		$meta_query = array(
			array(
				'key'     => self::META_PREFIX . 'status',
				'value'   => array( 'submitting', 'erp_failed', 'pending_package', 'received', 'processing', 'completed', 'wc_refund_failed' ),
				'compare' => 'IN',
			),
		);
		$identity_query = array( 'relation' => 'OR' );
		$order_key      = trim( (string) ( isset( $order['return_order_key'] ) ? $order['return_order_key'] : '' ) );

		if ( '' !== $order_key ) {
			$identity_query[] = array(
				'key'   => self::META_PREFIX . 'return_order_key',
				'value' => $order_key,
			);
		}

		foreach ( array_unique( array_filter( array( isset( $order['order_reference'] ) ? $order['order_reference'] : '', isset( $order['order_number'] ) ? $order['order_number'] : '' ) ) ) as $reference ) {
			$identity_query[] = array(
				'key'   => self::META_PREFIX . 'order_reference',
				'value' => (string) $reference,
			);
			$identity_query[] = array(
				'key'   => self::META_PREFIX . 'order_number',
				'value' => (string) $reference,
			);
		}

		if ( count( $identity_query ) > 1 ) {
			$meta_query[] = $identity_query;
		}

		$ids = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
				'meta_query'     => $meta_query,
			)
		);
		$accounted = array_fill_keys( array_map( 'strval', (array) ( isset( $order['accounted_return_references'] ) ? $order['accounted_return_references'] : array() ) ), true );
		$reserved  = array();

		foreach ( $ids as $post_id ) {
			$payload = $this->get_payload( $post_id );

			if ( ! is_array( $payload ) || ! $this->payload_matches_order( $payload, $order ) ) {
				continue;
			}

			$return_reference = (string) get_post_meta( $post_id, self::META_PREFIX . 'return_number', true );

			if ( '' !== $return_reference && isset( $accounted[ $return_reference ] ) ) {
				continue;
			}

			if ( absint( get_post_meta( $post_id, self::META_PREFIX . 'wc_refund_id', true ) ) > 0 ) {
				continue;
			}

			$erp_mode        = sanitize_key( get_post_meta( $post_id, self::META_PREFIX . 'erp_mode', true ) );
			$erp_external_id = trim( (string) get_post_meta( $post_id, self::META_PREFIX . 'erp_external_id', true ) );

			if ( 'erp' === ( isset( $order['source'] ) ? $order['source'] : '' ) && ( 'remote' === $erp_mode || '' !== $erp_external_id ) ) {
				continue;
			}

			foreach ( (array) ( isset( $payload['items'] ) ? $payload['items'] : array() ) as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}

				$key = sanitize_text_field( (string) ( isset( $item['return_item_key'] ) ? $item['return_item_key'] : ( isset( $item['id'] ) ? $item['id'] : '' ) ) );
				$qty = absint( isset( $item['quantity'] ) ? $item['quantity'] : 0 );

				if ( '' !== $key && $qty > 0 ) {
					$reserved[ $key ] = ( isset( $reserved[ $key ] ) ? $reserved[ $key ] : 0 ) + $qty;
				}
			}
		}

		return $reserved;
	}

	/**
	 * Checks both the new canonical key and legacy order identifiers.
	 *
	 * @param array $payload Stored return payload.
	 * @param array $order   Current normalized order.
	 * @return bool
	 */
	private function payload_matches_order( array $payload, array $order ) {
		$payload_key = trim( (string) ( isset( $payload['return_order_key'] ) ? $payload['return_order_key'] : '' ) );
		$order_key   = trim( (string) ( isset( $order['return_order_key'] ) ? $order['return_order_key'] : '' ) );

		if ( '' !== $payload_key && '' !== $order_key ) {
			return hash_equals( $order_key, $payload_key );
		}

		$payload_order_id = trim( (string) ( isset( $payload['order_id'] ) ? $payload['order_id'] : '' ) );
		$order_id         = trim( (string) ( isset( $order['order_id'] ) ? $order['order_id'] : '' ) );

		if ( '' !== $payload_order_id && '' !== $order_id && hash_equals( $order_id, $payload_order_id ) ) {
			return true;
		}

		$references = array_filter(
			array(
				isset( $order['order_reference'] ) ? (string) $order['order_reference'] : '',
				isset( $order['order_number'] ) ? (string) $order['order_number'] : '',
			)
		);

		return in_array( (string) ( isset( $payload['order_reference'] ) ? $payload['order_reference'] : '' ), $references, true )
			|| in_array( (string) ( isset( $payload['order_number'] ) ? $payload['order_number'] : '' ), $references, true );
	}

	/**
	 * Gets stored ERP context for status sync.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public function get_erp_context( $post_id ) {
		$response = get_post_meta( $post_id, self::META_PREFIX . 'erp_response', true );

		return array(
			'external_id' => (string) get_post_meta( $post_id, self::META_PREFIX . 'erp_external_id', true ),
			'response'    => is_array( $response ) ? $response : array(),
		);
	}

	/**
	 * Checks whether the local record still needs to be created in ERP.
	 *
	 * A create retry is safe because the ERP contract is idempotent by the
	 * stored return_reference. Business status synchronization must not run
	 * before this delivery step has succeeded.
	 *
	 * @param int $post_id Request ID.
	 * @return bool
	 */
	public function needs_erp_submission( $post_id ) {
		$status      = sanitize_key( get_post_meta( $post_id, self::META_PREFIX . 'status', true ) );
		$mode        = sanitize_key( get_post_meta( $post_id, self::META_PREFIX . 'erp_mode', true ) );
		$external_id = trim( (string) get_post_meta( $post_id, self::META_PREFIX . 'erp_external_id', true ) );

		if ( 'remote' === $mode || '' !== $external_id ) {
			return false;
		}

		if ( 'local' === $mode || in_array( $status, array( 'submitting', 'erp_failed' ), true ) ) {
			return true;
		}

		$response = get_post_meta( $post_id, self::META_PREFIX . 'erp_response', true );

		return 'pending_package' === $status && ( ! is_array( $response ) || empty( $response ) );
	}

	/**
	 * Counts records queued for first delivery or retry to ERP.
	 *
	 * @return int
	 */
	public function count_pending_erp_submissions() {
		$ids = $this->get_syncable_request_ids( -1 );

		return count( array_filter( $ids, array( $this, 'needs_erp_submission' ) ) );
	}

	/**
	 * Gets request IDs that can be synchronized with ERP.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	public function get_syncable_request_ids( $limit = 25 ) {
		$limit = (int) $limit;

		return get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => -1 === $limit ? -1 : max( 1, absint( $limit ) ),
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'     => self::META_PREFIX . 'status',
						'value'   => array( 'submitting', 'erp_failed', 'pending_package', 'received', 'processing', 'wc_refund_failed' ),
						'compare' => 'IN',
					),
				),
			)
		);
	}

	/**
	 * Finds request by local return reference or ERP external ID.
	 *
	 * @param string $return_reference Local return reference.
	 * @param string $external_id      ERP external ID.
	 * @return int
	 */
	public function find_request_id( $return_reference = '', $external_id = '' ) {
		$meta_query = array( 'relation' => 'OR' );

		if ( '' !== $return_reference ) {
			$meta_query[] = array(
				'key'   => self::META_PREFIX . 'return_number',
				'value' => sanitize_text_field( $return_reference ),
			);
		}

		if ( '' !== $external_id ) {
			$meta_query[] = array(
				'key'   => self::META_PREFIX . 'erp_external_id',
				'value' => sanitize_text_field( $external_id ),
			);
		}

		if ( count( $meta_query ) < 2 ) {
			return 0;
		}

		$ids = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'meta_query'     => $meta_query,
			)
		);

		return empty( $ids ) ? 0 : absint( $ids[0] );
	}

	/**
	 * Updates local request status from ERP.
	 *
	 * @param int    $post_id      Post ID.
	 * @param string $status       Internal status.
	 * @param string $raw_status   Raw ERP status.
	 * @param array  $erp_context  ERP context.
	 */
	public function update_status_from_erp( $post_id, $status, $raw_status, array $erp_context = array() ) {
		$status     = sanitize_key( $status );
		$raw_status = sanitize_text_field( $raw_status );
		$old_status = get_post_meta( $post_id, self::META_PREFIX . 'status', true );

		update_post_meta( $post_id, self::META_PREFIX . 'status', $status );
		update_post_meta( $post_id, self::META_PREFIX . 'erp_status_raw', $raw_status );
		update_post_meta( $post_id, self::META_PREFIX . 'status_synced_at', current_time( 'mysql' ) );

		$external_id = $this->extract_external_id( $erp_context );

		if ( '' !== $external_id ) {
			update_post_meta( $post_id, self::META_PREFIX . 'erp_external_id', $external_id );
		}

		$history = get_post_meta( $post_id, self::META_PREFIX . 'status_history', true );

		if ( ! is_array( $history ) ) {
			$history = array();
		}

		$history[] = array(
			'old_status' => $old_status,
			'status'     => $status,
			'raw_status' => $raw_status,
			'synced_at'  => current_time( 'mysql' ),
		);

		$history = array_slice( $history, -30 );
		update_post_meta( $post_id, self::META_PREFIX . 'status_history', $history );
	}

	/**
	 * Records a status synchronization error.
	 *
	 * @param int      $post_id Post ID.
	 * @param WP_Error $error   Error.
	 */
	public function record_status_sync_error( $post_id, WP_Error $error ) {
		update_post_meta(
			$post_id,
			self::META_PREFIX . 'status_sync_error',
			array(
				'code'       => $error->get_error_code(),
				'message'    => $error->get_error_message(),
				'data'       => $error->get_error_data(),
				'created_at' => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Records native WooCommerce refund creation.
	 *
	 * @param int $post_id   Post ID.
	 * @param int $refund_id Refund ID.
	 */
	public function record_refund_created( $post_id, $refund_id ) {
		update_post_meta( $post_id, self::META_PREFIX . 'wc_refund_id', absint( $refund_id ) );
		delete_post_meta( $post_id, self::META_PREFIX . 'wc_refund_error' );
	}

	/**
	 * Records native WooCommerce refund error.
	 *
	 * @param int      $post_id Post ID.
	 * @param WP_Error $error   Error.
	 */
	public function record_refund_error( $post_id, WP_Error $error ) {
		update_post_meta( $post_id, self::META_PREFIX . 'status', 'wc_refund_failed' );
		update_post_meta(
			$post_id,
			self::META_PREFIX . 'wc_refund_error',
			array(
				'code'       => $error->get_error_code(),
				'message'    => $error->get_error_message(),
				'data'       => $error->get_error_data(),
				'created_at' => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Registers detail meta box.
	 */
	public function register_meta_boxes() {
		add_meta_box(
			'll-returns-details',
			__( 'Szczegoly zwrotu', 'lemon-woo-returns' ),
			array( $this, 'render_details_meta_box' ),
			self::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * Renders detail meta box.
	 *
	 * @param WP_Post $post Post.
	 */
	public function render_details_meta_box( $post ) {
		$payload      = get_post_meta( $post->ID, self::META_PREFIX . 'payload', true );
		$status       = get_post_meta( $post->ID, self::META_PREFIX . 'status', true );
		$erp_response = get_post_meta( $post->ID, self::META_PREFIX . 'erp_response', true );
		$erp_error    = get_post_meta( $post->ID, self::META_PREFIX . 'erp_error', true );
		$sync_error   = get_post_meta( $post->ID, self::META_PREFIX . 'status_sync_error', true );
		$refund_error = get_post_meta( $post->ID, self::META_PREFIX . 'wc_refund_error', true );
		$refund_id    = absint( get_post_meta( $post->ID, self::META_PREFIX . 'wc_refund_id', true ) );
		$external_id  = get_post_meta( $post->ID, self::META_PREFIX . 'erp_external_id', true );
		$raw_status   = get_post_meta( $post->ID, self::META_PREFIX . 'erp_status_raw', true );
		$history      = get_post_meta( $post->ID, self::META_PREFIX . 'status_history', true );

		if ( ! is_array( $payload ) ) {
			echo '<p>' . esc_html__( 'Brak danych zwrotu.', 'lemon-woo-returns' ) . '</p>';
			return;
		}

		echo '<p><strong>' . esc_html__( 'Status', 'lemon-woo-returns' ) . ':</strong> ' . esc_html( $this->get_status_label( $status ) ) . '</p>';
		if ( '' !== $raw_status ) {
			echo '<p><strong>' . esc_html__( 'Status ERP', 'lemon-woo-returns' ) . ':</strong> ' . esc_html( $raw_status ) . '</p>';
		}
		if ( '' !== $external_id ) {
			echo '<p><strong>' . esc_html__( 'ID ERP', 'lemon-woo-returns' ) . ':</strong> <code>' . esc_html( $external_id ) . '</code></p>';
		}
		if ( $refund_id ) {
			echo '<p><strong>' . esc_html__( 'Zwrot WooCommerce', 'lemon-woo-returns' ) . ':</strong> <code>#' . esc_html( $refund_id ) . '</code></p>';
		}
		echo '<p><strong>' . esc_html__( 'Numer zwrotu', 'lemon-woo-returns' ) . ':</strong> <code>' . esc_html( $payload['return_reference'] ) . '</code></p>';
		echo '<p><strong>' . esc_html__( 'Zamowienie', 'lemon-woo-returns' ) . ':</strong> ' . esc_html( $payload['order_number'] ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Kontakt', 'lemon-woo-returns' ) . ':</strong> ' . esc_html( $payload['customer_contact'] ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Metoda odeslania', 'lemon-woo-returns' ) . ':</strong> ' . esc_html( $payload['return_method'] ) . '</p>';

		if ( ! empty( $payload['customer_note'] ) ) {
			echo '<p><strong>' . esc_html__( 'Uwagi klienta', 'lemon-woo-returns' ) . ':</strong><br>' . esc_html( $payload['customer_note'] ) . '</p>';
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Produkt', 'lemon-woo-returns' ) . '</th>';
		echo '<th>' . esc_html__( 'SKU', 'lemon-woo-returns' ) . '</th>';
		echo '<th>' . esc_html__( 'Ilosc', 'lemon-woo-returns' ) . '</th>';
		echo '<th>' . esc_html__( 'Powod', 'lemon-woo-returns' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $payload['items'] as $item ) {
			echo '<tr>';
			echo '<td>' . esc_html( $item['name'] ) . '</td>';
			echo '<td>' . esc_html( $item['sku'] ) . '</td>';
			echo '<td>' . esc_html( $item['quantity'] ) . '</td>';
			echo '<td>' . esc_html( $item['reason'] ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		if ( is_array( $erp_response ) && ! empty( $erp_response ) ) {
			echo '<h3>' . esc_html__( 'Odpowiedz ERP', 'lemon-woo-returns' ) . '</h3>';
			echo '<pre style="white-space:pre-wrap;">' . esc_html( wp_json_encode( $erp_response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ) . '</pre>';
		}

		if ( is_array( $erp_error ) && ! empty( $erp_error ) ) {
			echo '<h3>' . esc_html__( 'Blad ERP', 'lemon-woo-returns' ) . '</h3>';
			echo '<pre style="white-space:pre-wrap;">' . esc_html( wp_json_encode( $erp_error, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ) . '</pre>';
		}

		if ( is_array( $sync_error ) && ! empty( $sync_error ) ) {
			echo '<h3>' . esc_html__( 'Blad synchronizacji statusu', 'lemon-woo-returns' ) . '</h3>';
			echo '<pre style="white-space:pre-wrap;">' . esc_html( wp_json_encode( $sync_error, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ) . '</pre>';
		}

		if ( is_array( $refund_error ) && ! empty( $refund_error ) ) {
			echo '<h3>' . esc_html__( 'Blad zwrotu WooCommerce', 'lemon-woo-returns' ) . '</h3>';
			echo '<pre style="white-space:pre-wrap;">' . esc_html( wp_json_encode( $refund_error, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ) . '</pre>';
		}

		if ( is_array( $history ) && ! empty( $history ) ) {
			echo '<h3>' . esc_html__( 'Historia statusow', 'lemon-woo-returns' ) . '</h3>';
			echo '<table class="widefat striped"><thead><tr>';
			echo '<th>' . esc_html__( 'Data', 'lemon-woo-returns' ) . '</th>';
			echo '<th>' . esc_html__( 'Status', 'lemon-woo-returns' ) . '</th>';
			echo '<th>' . esc_html__( 'Status ERP', 'lemon-woo-returns' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( array_reverse( $history ) as $entry ) {
				echo '<tr>';
				echo '<td>' . esc_html( isset( $entry['synced_at'] ) ? $entry['synced_at'] : '' ) . '</td>';
				echo '<td>' . esc_html( isset( $entry['status'] ) ? $this->get_status_label( $entry['status'] ) : '' ) . '</td>';
				echo '<td>' . esc_html( isset( $entry['raw_status'] ) ? $entry['raw_status'] : '' ) . '</td>';
				echo '</tr>';
			}

			echo '</tbody></table>';
		}
	}

	/**
	 * Registers admin list columns.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public function register_columns( $columns ) {
		$new_columns = array();

		$new_columns['cb']            = isset( $columns['cb'] ) ? $columns['cb'] : '';
		$new_columns['title']         = __( 'Zgłoszenie', 'lemon-woo-returns' );
		$new_columns['order_number']  = __( 'Zamowienie', 'lemon-woo-returns' );
		$new_columns['return_method'] = __( 'Metoda', 'lemon-woo-returns' );
		$new_columns['return_status'] = __( 'Status', 'lemon-woo-returns' );
		$new_columns['date']          = isset( $columns['date'] ) ? $columns['date'] : __( 'Data', 'lemon-woo-returns' );

		return $new_columns;
	}

	/**
	 * Renders admin list columns.
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Post ID.
	 */
	public function render_column( $column, $post_id ) {
		if ( 'order_number' === $column ) {
			echo esc_html( get_post_meta( $post_id, self::META_PREFIX . 'order_number', true ) );
			return;
		}

		if ( 'return_method' === $column ) {
			echo esc_html( get_post_meta( $post_id, self::META_PREFIX . 'return_method', true ) );
			return;
		}

		if ( 'return_status' === $column ) {
			echo esc_html( $this->get_status_label( get_post_meta( $post_id, self::META_PREFIX . 'status', true ) ) );
		}
	}

	/**
	 * Gets human-readable internal status label.
	 *
	 * @param string $status Status key.
	 * @return string
	 */
	private function get_status_label( $status ) {
		$labels = array(
			'submitting'      => __( 'W trakcie zapisu', 'lemon-woo-returns' ),
			'pending_package' => __( 'Oczekuje na paczke', 'lemon-woo-returns' ),
			'received'        => __( 'Paczka odebrana', 'lemon-woo-returns' ),
			'processing'      => __( 'Weryfikacja zwrotu', 'lemon-woo-returns' ),
			'completed'       => __( 'Zwrot zrealizowany', 'lemon-woo-returns' ),
			'rejected'        => __( 'Zwrot odrzucony', 'lemon-woo-returns' ),
			'cancelled'       => __( 'Zwrot anulowany', 'lemon-woo-returns' ),
			'erp_failed'      => __( 'Blad ERP', 'lemon-woo-returns' ),
			'wc_refund_failed' => __( 'Blad zwrotu WooCommerce', 'lemon-woo-returns' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
	}

	/**
	 * Extracts ERP external ID from common response shapes.
	 *
	 * @param array $data ERP data.
	 * @return string
	 */
	private function extract_external_id( array $data ) {
		foreach ( array( 'external_id', 'erp_id', 'return_id', 'id' ) as $key ) {
			if ( ! empty( $data[ $key ] ) ) {
				return sanitize_text_field( (string) $data[ $key ] );
			}
		}

		if ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
			foreach ( array( 'external_id', 'erp_id', 'return_id', 'id' ) as $key ) {
				if ( ! empty( $data['data'][ $key ] ) ) {
					return sanitize_text_field( (string) $data['data'][ $key ] );
				}
			}
		}

		return '';
	}

	/**
	 * Generates unique return number.
	 *
	 * @return string
	 */
	private function generate_return_number() {
		do {
			$number = 'LLR-' . gmdate( 'Ymd' ) . '-' . strtoupper( wp_generate_password( 6, false, false ) );
			$exists = get_posts(
				array(
					'post_type'      => self::POST_TYPE,
					'fields'         => 'ids',
					'posts_per_page' => 1,
					'meta_key'       => self::META_PREFIX . 'return_number',
					'meta_value'     => $number,
				)
			);
		} while ( ! empty( $exists ) );

		return $number;
	}
}
