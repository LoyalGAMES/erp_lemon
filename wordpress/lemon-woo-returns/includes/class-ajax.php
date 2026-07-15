<?php
/**
 * Public AJAX handlers.
 *
 * @package Lemon_Woo_Returns
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles public return form AJAX requests.
 */
class LL_Returns_Ajax {
	const LOOKUP_ACTION = 'll_returns_lookup_order';
	const SUBMIT_ACTION = 'll_returns_submit_return';

	/**
	 * Settings service.
	 *
	 * @var LL_Returns_Settings
	 */
	private $settings;

	/**
	 * Order service.
	 *
	 * @var LL_Returns_Order_Service
	 */
	private $order_service;

	/**
	 * Repository.
	 *
	 * @var LL_Returns_Return_Repository
	 */
	private $repository;

	/**
	 * ERP client.
	 *
	 * @var LL_Returns_ERP_Client
	 */
	private $erp_client;

	/**
	 * ERP status sync.
	 *
	 * @var LL_Returns_Status_Sync
	 */
	private $status_sync;

	/**
	 * Constructor.
	 *
	 * @param LL_Returns_Settings          $settings      Settings.
	 * @param LL_Returns_Order_Service     $order_service Order service.
	 * @param LL_Returns_Return_Repository $repository    Repository.
	 * @param LL_Returns_ERP_Client        $erp_client    ERP client.
	 * @param LL_Returns_Status_Sync       $status_sync   Status sync.
	 */
	public function __construct( LL_Returns_Settings $settings, LL_Returns_Order_Service $order_service, LL_Returns_Return_Repository $repository, LL_Returns_ERP_Client $erp_client, LL_Returns_Status_Sync $status_sync ) {
		$this->settings      = $settings;
		$this->order_service = $order_service;
		$this->repository    = $repository;
		$this->erp_client    = $erp_client;
		$this->status_sync   = $status_sync;
	}

	/**
	 * Registers hooks.
	 */
	public function hooks() {
		add_action( 'wp_ajax_' . self::LOOKUP_ACTION, array( $this, 'lookup_order' ) );
		add_action( 'wp_ajax_nopriv_' . self::LOOKUP_ACTION, array( $this, 'lookup_order' ) );
		add_action( 'wp_ajax_' . self::SUBMIT_ACTION, array( $this, 'submit_return' ) );
		add_action( 'wp_ajax_nopriv_' . self::SUBMIT_ACTION, array( $this, 'submit_return' ) );
	}

	/**
	 * Looks up order products.
	 */
	public function lookup_order() {
		$this->guard_public_request();
		$this->hit_rate_limit( 'lookup', 20, 15 * MINUTE_IN_SECONDS );

		$order_reference = isset( $_POST['order_reference'] ) ? sanitize_text_field( wp_unslash( $_POST['order_reference'] ) ) : '';
		$contact         = isset( $_POST['contact'] ) ? sanitize_text_field( wp_unslash( $_POST['contact'] ) ) : '';
		$order           = $this->order_service->resolve_order( $order_reference, $contact );

		if ( is_wp_error( $order ) ) {
			$this->send_error( $order->get_error_message(), 404, $order->get_error_code() );
		}

		$lookup_token = wp_generate_uuid4();
		set_transient(
			$this->get_lookup_transient_key( $lookup_token ),
			array(
				'order_reference' => $order_reference,
				'contact_hash'    => wp_hash( strtolower( $contact ) ),
			),
			30 * MINUTE_IN_SECONDS
		);

		wp_send_json_success(
			array(
				'order'        => $this->order_service->get_public_order_data( $order ),
				'lookup_token' => $lookup_token,
			)
		);
	}

	/**
	 * Creates return request.
	 */
	public function submit_return() {
		$this->guard_public_request();
		$this->hit_rate_limit( 'submit', 10, 15 * MINUTE_IN_SECONDS );

		$payload_json = isset( $_POST['payload'] ) ? wp_unslash( $_POST['payload'] ) : '';
		$request      = json_decode( $payload_json, true );

		if ( ! is_array( $request ) ) {
			$this->send_error( __( 'Niepoprawne dane formularza.', 'lemon-woo-returns' ), 400, 'll_returns_invalid_payload' );
		}

		$order_reference = isset( $request['order_reference'] ) ? sanitize_text_field( wp_unslash( $request['order_reference'] ) ) : '';
		$contact         = isset( $request['contact'] ) ? sanitize_text_field( wp_unslash( $request['contact'] ) ) : '';
		$lookup_token    = isset( $request['lookup_token'] ) ? sanitize_text_field( wp_unslash( $request['lookup_token'] ) ) : '';

		$this->verify_lookup_token( $lookup_token, $order_reference, $contact );

		$order = $this->order_service->resolve_order( $order_reference, $contact );

		if ( is_wp_error( $order ) ) {
			$this->send_error( $order->get_error_message(), 404, $order->get_error_code() );
		}

		$return_method = isset( $request['return_method'] ) ? sanitize_key( wp_unslash( $request['return_method'] ) ) : 'own_shipping';

		if ( ! in_array( $return_method, array( 'own_shipping', 'wygodne_zwroty' ), true ) ) {
			$return_method = 'own_shipping';
		}

		$refund_method       = isset( $order['refund_method'] ) ? $order['refund_method'] : 'unavailable';
		$refund_bank_account = '';
		$refund_recipient    = '';

		if ( ! in_array( $refund_method, array( 'cashback', 'bank_transfer' ), true ) ) {
			$this->send_error( __( 'Nie udalo sie rozpoznac metody platnosci zamowienia. Skontaktuj sie z obsluga sklepu.', 'lemon-woo-returns' ), 422, 'll_returns_unknown_payment_method' );
		}

		if ( 'bank_transfer' === $refund_method ) {
			$refund_bank_account = isset( $request['refund_bank_account'] ) ? preg_replace( '/\D+/', '', (string) wp_unslash( $request['refund_bank_account'] ) ) : '';

			if ( 28 === strlen( $refund_bank_account ) && 0 === strpos( $refund_bank_account, '48' ) ) {
				$refund_bank_account = substr( $refund_bank_account, 2 );
			}

			if ( 26 !== strlen( $refund_bank_account ) ) {
				$this->send_error( __( 'Podaj poprawny 26-cyfrowy numer rachunku do zwrotu.', 'lemon-woo-returns' ), 422, 'll_returns_invalid_bank_account' );
			}

			$refund_recipient = isset( $request['refund_recipient_name'] ) ? sanitize_text_field( wp_unslash( $request['refund_recipient_name'] ) ) : '';
		}

		$order_lock = $this->acquire_order_lock( isset( $order['return_order_key'] ) ? $order['return_order_key'] : $order['order_id'] );

		if ( is_wp_error( $order_lock ) ) {
			$this->send_error( $order_lock->get_error_message(), 409, $order_lock->get_error_code() );
		}

		$locked_error = null;
		$record       = null;

		try {
			// Re-read availability while holding the canonical order-family lock.
			$order = $this->order_service->resolve_order( $order_reference, $contact );

			if ( is_wp_error( $order ) ) {
				$locked_error = $order;
			} else {
				$items = isset( $request['items'] ) && is_array( $request['items'] ) ? $request['items'] : array();
				$items = $this->order_service->validate_return_items( $order, $items );

				if ( is_wp_error( $items ) ) {
					$locked_error = $items;
				} else {
					$payload = array(
						'source'             => $order['source'],
						'order_id'           => $order['order_id'],
						'wc_order_id'        => isset( $order['wc_order_id'] ) ? $order['wc_order_id'] : $order['order_id'],
						'return_order_key'   => isset( $order['return_order_key'] ) ? $order['return_order_key'] : $order['order_id'],
						'order_reference'    => $order['order_reference'],
						'order_number'       => $order['order_number'],
						'currency'           => $order['currency'],
						'customer_contact'   => $contact,
						'customer_email'     => $order['customer_email'],
						'customer_phone'     => $order['customer_phone'],
						'customer_user_id'   => get_current_user_id(),
						'return_method'      => $return_method,
						'refund_method'      => $refund_method,
						'refund_bank_account' => $refund_bank_account,
						'refund_recipient_name' => $refund_recipient,
						'customer_note'      => isset( $request['customer_note'] ) ? sanitize_textarea_field( wp_unslash( $request['customer_note'] ) ) : '',
						'items'              => $items,
						'created_at'         => current_time( 'mysql' ),
						'site_url'           => home_url(),
					);

					$payload = apply_filters( 'll_returns_before_create_return_payload', $payload, $request, $order );
					$record  = $this->repository->create_request( $payload, 'submitting' );

					if ( is_wp_error( $record ) ) {
						$locked_error = $record;
					}
				}
			}
		} finally {
			$this->release_order_lock( $order_lock );
		}

		if ( is_wp_error( $locked_error ) ) {
			$this->send_error( $locked_error->get_error_message(), 409, $locked_error->get_error_code() );
		}

		$erp_response = $this->erp_client->create_return( $record['payload'] );

		if ( is_wp_error( $erp_response ) ) {
			if ( ! $this->is_retryable_erp_error( $erp_response ) ) {
				$this->repository->mark_rejected( $record['post_id'], $erp_response );
				$error_data = $erp_response->get_error_data();
				$error_status = is_array( $error_data ) && isset( $error_data['status'] ) ? absint( $error_data['status'] ) : 409;

				$this->send_error( $erp_response->get_error_message(), $error_status, $erp_response->get_error_code() );
			}

			$this->repository->mark_failed( $record['post_id'], $erp_response );
			wp_send_json_success(
				array(
					'return_number'      => $record['return_number'],
					'return_method'      => $return_method,
					'wygodne_zwroty_url' => '',
					'queued_for_erp'     => true,
					'success_message'    => __( 'Zgloszenie zostalo zapisane i oczekuje na synchronizacje z ERP. Nie wysylaj go ponownie.', 'lemon-woo-returns' ),
				)
			);
		}

		if ( ! is_array( $erp_response ) ) {
			$erp_response = array();
		}

		$this->repository->mark_accepted( $record['post_id'], $erp_response );
		$this->repository->add_order_note( $record['payload'], $record, $erp_response );
		$this->status_sync->apply_erp_response_to_request( $record['post_id'], $erp_response );

		wp_send_json_success(
			array(
				'return_number'       => $record['return_number'],
				'return_method'       => $return_method,
				'wygodne_zwroty_url'  => 'wygodne_zwroty' === $return_method ? $this->settings->get_wygodne_zwroty_url() : '',
				'success_message'     => $this->settings->get( 'success_message', '' ),
				'erp_response'        => $erp_response,
			)
		);
	}

	/**
	 * Validates public request basics.
	 */
	private function guard_public_request() {
		if ( ! $this->settings->is_enabled() ) {
			$this->send_error( __( 'Formularz zwrotow jest obecnie wylaczony.', 'lemon-woo-returns' ), 403, 'll_returns_disabled' );
		}

		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'll_returns_form' ) ) {
			$this->send_error( __( 'Sesja formularza wygasla. Odswiez strone i sprobuj ponownie.', 'lemon-woo-returns' ), 403, 'll_returns_bad_nonce' );
		}
	}

	/**
	 * Verifies and consumes the required one-time lookup token.
	 *
	 * @param string $token           Lookup token.
	 * @param string $order_reference Order reference.
	 * @param string $contact         Contact.
	 */
	private function verify_lookup_token( $token, $order_reference, $contact ) {
		if ( '' === $token ) {
			$this->send_error( __( 'Sesja formularza wygasla. Wyszukaj zamowienie ponownie.', 'lemon-woo-returns' ), 403, 'll_returns_lookup_expired' );
		}

		$stored = get_transient( $this->get_lookup_transient_key( $token ) );

		if ( ! is_array( $stored ) ) {
			$this->send_error( __( 'Sesja formularza wygasla. Wyszukaj zamowienie ponownie.', 'lemon-woo-returns' ), 403, 'll_returns_lookup_expired' );
		}

		if ( $stored['order_reference'] !== $order_reference || $stored['contact_hash'] !== wp_hash( strtolower( $contact ) ) ) {
			$this->send_error( __( 'Dane formularza nie pasuja do wyszukanego zamowienia.', 'lemon-woo-returns' ), 403, 'll_returns_lookup_mismatch' );
		}

		delete_transient( $this->get_lookup_transient_key( $token ) );
	}

	/**
	 * Acquires an atomic short-lived lock for one canonical order family.
	 *
	 * @param string $order_key Canonical order key.
	 * @return array{option:string,owner:string}|WP_Error
	 */
	private function acquire_order_lock( $order_key ) {
		global $wpdb;

		$option  = 'll_returns_lock_' . md5( (string) $order_key );
		$owner   = wp_generate_uuid4();
		$value   = array(
			'owner'   => $owner,
			'expires' => time() + 45,
		);

		if ( add_option( $option, $value, '', 'no' ) ) {
			return array( 'option' => $option, 'owner' => $owner );
		}

		$current = get_option( $option, array() );
		$current_expires = is_array( $current ) ? absint( isset( $current['expires'] ) ? $current['expires'] : 0 ) : absint( $current );

		if ( $current_expires < time() ) {
			$deleted = $wpdb->delete(
				$wpdb->options,
				array(
					'option_name'  => $option,
					'option_value' => maybe_serialize( $current ),
				),
				array( '%s', '%s' )
			);

			if ( 1 === $deleted ) {
				wp_cache_delete( $option, 'options' );
			}

			if ( 1 === $deleted && add_option( $option, $value, '', 'no' ) ) {
				return array( 'option' => $option, 'owner' => $owner );
			}
		}

		return new WP_Error( 'll_returns_order_locked', __( 'Inne zgloszenie dla tego zamowienia jest wlasnie zapisywane. Wyszukaj zamowienie ponownie za chwile.', 'lemon-woo-returns' ) );
	}

	/**
	 * Releases a lock acquired by acquire_order_lock().
	 *
	 * @param array{option:string,owner:string} $lock Lock handle.
	 */
	private function release_order_lock( array $lock ) {
		$current = get_option( $lock['option'], array() );

		if ( is_array( $current ) && isset( $current['owner'] ) && hash_equals( (string) $current['owner'], $lock['owner'] ) ) {
			delete_option( $lock['option'] );
		}
	}

	/**
	 * Distinguishes temporary transport/configuration failures from permanent
	 * validation conflicts. Only temporary failures stay in the retry queue.
	 *
	 * @param WP_Error $error ERP error.
	 * @return bool
	 */
	private function is_retryable_erp_error( WP_Error $error ) {
		if ( 'll_returns_erp_rejected' === $error->get_error_code() ) {
			return false;
		}

		$data   = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? absint( $data['status'] ) : 0;

		return ! in_array( $status, array( 400, 409, 410, 422 ), true );
	}

	/**
	 * Adds a simple IP-based rate limit.
	 *
	 * @param string $bucket Bucket name.
	 * @param int    $limit  Request limit.
	 * @param int    $window Window in seconds.
	 */
	private function hit_rate_limit( $bucket, $limit, $window ) {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$key = 'll_returns_rate_' . md5( $bucket . '|' . $ip );
		$hit = absint( get_transient( $key ) );

		if ( $hit >= $limit ) {
			$this->send_error( __( 'Za duzo prob. Sprobuj ponownie za kilka minut.', 'lemon-woo-returns' ), 429, 'll_returns_rate_limited' );
		}

		set_transient( $key, $hit + 1, $window );
	}

	/**
	 * Gets lookup token transient key.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	private function get_lookup_transient_key( $token ) {
		return 'll_returns_lookup_' . md5( $token );
	}

	/**
	 * Sends a JSON error.
	 *
	 * @param string $message Message.
	 * @param int    $status  HTTP status.
	 * @param string $code    Error code.
	 */
	private function send_error( $message, $status = 400, $code = 'll_returns_error' ) {
		wp_send_json_error(
			array(
				'message' => $message,
				'code'    => $code,
			),
			$status
		);
	}
}
