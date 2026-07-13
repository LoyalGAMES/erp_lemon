<?php
/**
 * ERP HTTP client.
 *
 * @package Lemon_Woo_Returns
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles configurable ERP requests.
 */
class LL_Returns_ERP_Client {
	/**
	 * Settings service.
	 *
	 * @var LL_Returns_Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param LL_Returns_Settings $settings Settings.
	 */
	public function __construct( LL_Returns_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Checks whether remote order lookup is configured.
	 *
	 * @return bool
	 */
	public function has_lookup_endpoint() {
		return '' !== $this->settings->get( 'erp_lookup_url', '' );
	}

	/**
	 * Checks whether remote return creation is configured.
	 *
	 * @return bool
	 */
	public function has_return_endpoint() {
		return '' !== $this->settings->get( 'erp_return_url', '' );
	}

	/**
	 * Checks whether remote status sync is configured.
	 *
	 * @return bool
	 */
	public function has_status_endpoint() {
		return '' !== $this->settings->get( 'erp_status_url', '' );
	}

	/**
	 * Looks up an order in ERP.
	 *
	 * @param string $order_reference Order number/reference.
	 * @param string $contact         Customer email or phone.
	 * @return array|WP_Error
	 */
	public function lookup_order( $order_reference, $contact ) {
		$pre = apply_filters( 'll_returns_erp_lookup_order', null, $order_reference, $contact );

		if ( null !== $pre ) {
			return $pre;
		}

		if ( ! $this->has_lookup_endpoint() ) {
			return new WP_Error( 'll_returns_no_lookup_endpoint', __( 'Endpoint ERP do wyszukiwania zamowien nie jest skonfigurowany.', 'lemon-woo-returns' ) );
		}

		$payload = array(
			'order_reference' => $order_reference,
			'contact'         => $contact,
			'site_url'        => home_url(),
		);

		$response = $this->post_json( $this->settings->get( 'erp_lookup_url', '' ), $payload, 'lookup' );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$order = $response;

		if ( isset( $response['order'] ) && is_array( $response['order'] ) ) {
			$order = $response['order'];
		} elseif ( isset( $response['data']['order'] ) && is_array( $response['data']['order'] ) ) {
			$order = $response['data']['order'];
		}

		return apply_filters( 'll_returns_erp_lookup_order_response', $order, $response, $order_reference, $contact );
	}

	/**
	 * Creates a return request in ERP.
	 *
	 * @param array $payload Return payload.
	 * @return array|WP_Error
	 */
	public function create_return( array $payload ) {
		$pre = apply_filters( 'll_returns_erp_create_return', null, $payload );

		if ( null !== $pre ) {
			return $pre;
		}

		if ( ! $this->has_return_endpoint() ) {
			return array(
				'mode'        => 'local',
				'external_id' => '',
				'message'     => __( 'Zwrot zapisany lokalnie. Endpoint ERP nie jest skonfigurowany.', 'lemon-woo-returns' ),
			);
		}

		$payload = apply_filters( 'll_returns_erp_return_payload', $payload );

		return $this->post_json( $this->settings->get( 'erp_return_url', '' ), $payload, 'create_return' );
	}

	/**
	 * Gets current return status from ERP.
	 *
	 * @param array $payload     Return payload.
	 * @param array $erp_context Stored ERP context.
	 * @return array|WP_Error
	 */
	public function get_return_status( array $payload, array $erp_context = array() ) {
		$pre = apply_filters( 'll_returns_erp_get_return_status', null, $payload, $erp_context );

		if ( null !== $pre ) {
			return $pre;
		}

		if ( ! $this->has_status_endpoint() ) {
			return new WP_Error( 'll_returns_no_status_endpoint', __( 'Endpoint ERP do synchronizacji statusow nie jest skonfigurowany.', 'lemon-woo-returns' ) );
		}

		$request = array(
			'return_reference' => isset( $payload['return_reference'] ) ? $payload['return_reference'] : '',
			'local_return_id'  => isset( $payload['local_return_id'] ) ? $payload['local_return_id'] : '',
			'order_reference'  => isset( $payload['order_reference'] ) ? $payload['order_reference'] : '',
			'external_id'      => isset( $erp_context['external_id'] ) ? $erp_context['external_id'] : '',
			'site_url'         => home_url(),
		);

		$request = apply_filters( 'll_returns_erp_status_payload', $request, $payload, $erp_context );

		return $this->post_json( $this->settings->get( 'erp_status_url', '' ), $request, 'status' );
	}

	/**
	 * Sends a JSON POST request.
	 *
	 * @param string $url     Endpoint URL.
	 * @param array  $payload Request payload.
	 * @param string $purpose Request purpose.
	 * @return array|WP_Error
	 */
	private function post_json( $url, array $payload, $purpose ) {
		$token   = $this->settings->get( 'erp_api_token', '' );
		$headers = array(
			'Accept'                   => 'application/json',
			'Content-Type'             => 'application/json; charset=utf-8',
			'X-Lemon-Returns-Version'  => LL_RETURNS_VERSION,
			'X-Lemon-Returns-Purpose'  => $purpose,
		);

		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
			$headers['X-API-Key']     = $token;
		}

		$args = array(
			'timeout' => (int) $this->settings->get( 'erp_timeout', 12 ),
			'headers' => $headers,
			'body'    => wp_json_encode( $payload ),
		);

		$args     = apply_filters( 'll_returns_erp_request_args', $args, $url, $payload, $purpose );
		$response = wp_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = array();

		if ( '' !== trim( $body ) ) {
			$data = json_decode( $body, true );

			if ( ! is_array( $data ) ) {
				return new WP_Error( 'll_returns_erp_invalid_json', __( 'ERP zwrocilo niepoprawna odpowiedz JSON.', 'lemon-woo-returns' ) );
			}
		}

		if ( $code < 200 || $code >= 300 ) {
			$message = isset( $data['message'] ) ? sanitize_text_field( $data['message'] ) : sprintf( __( 'ERP zwrocilo blad HTTP %d.', 'lemon-woo-returns' ), $code );

			return new WP_Error( 'll_returns_erp_http_error', $message, array( 'status' => $code, 'response' => $data ) );
		}

		if ( isset( $data['success'] ) && false === (bool) $data['success'] ) {
			$message = isset( $data['message'] ) ? sanitize_text_field( $data['message'] ) : __( 'ERP odrzucilo operacje.', 'lemon-woo-returns' );

			return new WP_Error( 'll_returns_erp_rejected', $message, array( 'response' => $data ) );
		}

		return apply_filters( 'll_returns_erp_response', $data, $response, $payload, $purpose );
	}
}
