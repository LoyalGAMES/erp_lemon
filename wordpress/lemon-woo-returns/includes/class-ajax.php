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

		$items = isset( $request['items'] ) && is_array( $request['items'] ) ? $request['items'] : array();
		$items = $this->order_service->validate_return_items( $order, $items );

		if ( is_wp_error( $items ) ) {
			$this->send_error( $items->get_error_message(), 400, $items->get_error_code() );
		}

		$return_method = isset( $request['return_method'] ) ? sanitize_key( wp_unslash( $request['return_method'] ) ) : 'own_shipping';

		if ( ! in_array( $return_method, array( 'own_shipping', 'wygodne_zwroty' ), true ) ) {
			$return_method = 'own_shipping';
		}

		$payload = array(
			'source'             => $order['source'],
			'order_id'           => $order['order_id'],
			'order_reference'    => $order['order_reference'],
			'order_number'       => $order['order_number'],
			'currency'           => $order['currency'],
			'customer_contact'   => $contact,
			'customer_email'     => $order['customer_email'],
			'customer_phone'     => $order['customer_phone'],
			'customer_user_id'   => get_current_user_id(),
			'return_method'      => $return_method,
			'customer_note'      => isset( $request['customer_note'] ) ? sanitize_textarea_field( wp_unslash( $request['customer_note'] ) ) : '',
			'items'              => $items,
			'created_at'         => current_time( 'mysql' ),
			'site_url'           => home_url(),
		);

		$payload = apply_filters( 'll_returns_before_create_return_payload', $payload, $request, $order );
		$record  = $this->repository->create_request( $payload, 'submitting' );

		if ( is_wp_error( $record ) ) {
			$this->send_error( $record->get_error_message(), 500, $record->get_error_code() );
		}

		$erp_response = $this->erp_client->create_return( $record['payload'] );

		if ( is_wp_error( $erp_response ) ) {
			$this->repository->mark_failed( $record['post_id'], $erp_response );
			$this->send_error( $erp_response->get_error_message(), 502, $erp_response->get_error_code() );
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
	 * Verifies the lookup token when present.
	 *
	 * @param string $token           Lookup token.
	 * @param string $order_reference Order reference.
	 * @param string $contact         Contact.
	 */
	private function verify_lookup_token( $token, $order_reference, $contact ) {
		if ( '' === $token ) {
			return;
		}

		$stored = get_transient( $this->get_lookup_transient_key( $token ) );

		if ( ! is_array( $stored ) ) {
			$this->send_error( __( 'Sesja formularza wygasla. Wyszukaj zamowienie ponownie.', 'lemon-woo-returns' ), 403, 'll_returns_lookup_expired' );
		}

		if ( $stored['order_reference'] !== $order_reference || $stored['contact_hash'] !== wp_hash( strtolower( $contact ) ) ) {
			$this->send_error( __( 'Dane formularza nie pasuja do wyszukanego zamowienia.', 'lemon-woo-returns' ), 403, 'll_returns_lookup_mismatch' );
		}
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
