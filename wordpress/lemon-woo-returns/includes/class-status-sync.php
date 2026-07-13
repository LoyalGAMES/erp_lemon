<?php
/**
 * ERP status synchronization.
 *
 * @package Lemon_Woo_Returns
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Synchronizes local return request statuses with ERP and triggers WooCommerce refunds.
 */
class LL_Returns_Status_Sync {
	const CRON_HOOK = 'll_returns_sync_statuses';
	const MANUAL_SYNC_ACTION = 'll_returns_sync_statuses_now';
	const REST_NAMESPACE = 'lemon-returns/v1';
	const REST_ROUTE = '/status';

	/**
	 * Settings service.
	 *
	 * @var LL_Returns_Settings
	 */
	private $settings;

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
	 * Refund service.
	 *
	 * @var LL_Returns_Refund_Service
	 */
	private $refund_service;

	/**
	 * Constructor.
	 *
	 * @param LL_Returns_Settings          $settings       Settings.
	 * @param LL_Returns_Return_Repository $repository     Repository.
	 * @param LL_Returns_ERP_Client        $erp_client     ERP client.
	 * @param LL_Returns_Refund_Service    $refund_service Refund service.
	 */
	public function __construct( LL_Returns_Settings $settings, LL_Returns_Return_Repository $repository, LL_Returns_ERP_Client $erp_client, LL_Returns_Refund_Service $refund_service ) {
		$this->settings       = $settings;
		$this->repository     = $repository;
		$this->erp_client     = $erp_client;
		$this->refund_service = $refund_service;
	}

	/**
	 * Registers hooks.
	 */
	public function hooks() {
		add_filter( 'cron_schedules', array( $this, 'register_cron_schedule' ) );
		add_action( 'init', array( $this, 'maybe_schedule_sync' ) );
		add_action( self::CRON_HOOK, array( $this, 'sync_pending_requests' ) );
		add_action( 'admin_post_' . self::MANUAL_SYNC_ACTION, array( $this, 'handle_manual_sync' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_route' ) );
	}

	/**
	 * Clears the scheduled status sync.
	 */
	public static function clear_schedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Adds a 15-minute schedule.
	 *
	 * @param array $schedules Schedules.
	 * @return array
	 */
	public function register_cron_schedule( $schedules ) {
		if ( ! isset( $schedules['ll_returns_15_minutes'] ) ) {
			$schedules['ll_returns_15_minutes'] = array(
				'interval' => 15 * MINUTE_IN_SECONDS,
				'display'  => __( 'Co 15 minut', 'lemon-woo-returns' ),
			);
		}

		return $schedules;
	}

	/**
	 * Schedules delivery retries and status sync when an ERP endpoint is configured.
	 */
	public function maybe_schedule_sync() {
		if ( ! $this->erp_client->has_return_endpoint() && ! $this->erp_client->has_status_endpoint() ) {
			return;
		}

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'll_returns_15_minutes', self::CRON_HOOK );
		}
	}

	/**
	 * Synchronizes requests awaiting ERP status changes.
	 *
	 * @param int $limit Max records.
	 * @return int Number of synchronized records.
	 */
	public function sync_pending_requests( $limit = 25 ) {
		if ( ! $this->erp_client->has_return_endpoint() && ! $this->erp_client->has_status_endpoint() ) {
			return 0;
		}

		$count = 0;
		$ids   = $this->repository->get_syncable_request_ids( absint( $limit ) );

		foreach ( $ids as $request_id ) {
			$payload = $this->repository->get_payload( $request_id );

			if ( ! is_array( $payload ) ) {
				continue;
			}

			if ( $this->repository->needs_erp_submission( $request_id ) ) {
				if ( ! $this->erp_client->has_return_endpoint() ) {
					continue;
				}

				$response = $this->erp_client->create_return( $payload );

				if ( is_wp_error( $response ) ) {
					if ( $this->is_retryable_erp_error( $response ) ) {
						$this->repository->mark_failed( $request_id, $response );
					} else {
						$this->repository->mark_rejected( $request_id, $response );
					}
					continue;
				}

				if ( ! is_array( $response ) ) {
					$response = array();
				}

				$this->repository->mark_accepted( $request_id, $response );
				$this->apply_erp_response_to_request( $request_id, $response );
				$count++;
				continue;
			}

			if ( ! $this->erp_client->has_status_endpoint() ) {
				continue;
			}

			$response = $this->erp_client->get_return_status( $payload, $this->repository->get_erp_context( $request_id ) );

			if ( is_wp_error( $response ) ) {
				$this->repository->record_status_sync_error( $request_id, $response );
				continue;
			}

			if ( $this->apply_erp_response_to_request( $request_id, $response ) ) {
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Checks whether an ERP create error may succeed during a later retry.
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
	 * Applies an ERP response to one request.
	 *
	 * @param int   $request_id   Request ID.
	 * @param array $erp_response ERP response.
	 * @return bool
	 */
	public function apply_erp_response_to_request( $request_id, array $erp_response ) {
		$status = $this->extract_status( $erp_response );

		if ( '' === $status ) {
			return false;
		}

		return $this->apply_status( $request_id, $status, $erp_response );
	}

	/**
	 * Applies a raw ERP status.
	 *
	 * @param int    $request_id  Request ID.
	 * @param string $raw_status  Raw ERP status.
	 * @param array  $erp_context ERP context.
	 * @return bool
	 */
	public function apply_status( $request_id, $raw_status, array $erp_context = array() ) {
		$internal_status = $this->settings->map_erp_status_to_internal_status( $raw_status );
		$this->repository->update_status_from_erp( $request_id, $internal_status, $raw_status, $erp_context );

		if ( 'completed' !== $internal_status ) {
			return true;
		}

		$refund = $this->refund_service->create_refund_for_request( $request_id );

		if ( is_wp_error( $refund ) ) {
			$this->repository->record_refund_error( $request_id, $refund );
		}

		return true;
	}

	/**
	 * Handles manual sync from wp-admin.
	 */
	public function handle_manual_sync() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Brak uprawnien.', 'lemon-woo-returns' ) );
		}

		check_admin_referer( self::MANUAL_SYNC_ACTION );

		$count    = $this->sync_pending_requests( 200 );
		$redirect = wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=ll-returns-settings' );

		wp_safe_redirect( add_query_arg( 'll_returns_synced', absint( $count ), $redirect ) );
		exit;
	}

	/**
	 * Registers REST webhook endpoint for ERP push updates.
	 */
	public function register_rest_route() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_status_webhook' ),
				'permission_callback' => array( $this, 'verify_status_webhook' ),
			)
		);
	}

	/**
	 * Verifies ERP webhook request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function verify_status_webhook( $request ) {
		$secret = $this->settings->get( 'erp_webhook_secret', '' );

		if ( '' === $secret ) {
			return false;
		}

		$provided = $request->get_header( 'x-lemon-returns-token' );

		if ( '' === $provided ) {
			$authorization = $request->get_header( 'authorization' );

			if ( preg_match( '/Bearer\s+(.+)/i', $authorization, $matches ) ) {
				$provided = trim( $matches[1] );
			}
		}

		return is_string( $provided ) && hash_equals( $secret, $provided );
	}

	/**
	 * Handles ERP webhook status update.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_status_webhook( $request ) {
		$params = $request->get_json_params();

		if ( ! is_array( $params ) ) {
			$params = $request->get_body_params();
		}

		$status           = isset( $params['status'] ) ? sanitize_text_field( wp_unslash( $params['status'] ) ) : '';
		$return_reference = isset( $params['return_reference'] ) ? sanitize_text_field( wp_unslash( $params['return_reference'] ) ) : '';
		$external_id      = isset( $params['external_id'] ) ? sanitize_text_field( wp_unslash( $params['external_id'] ) ) : '';

		if ( '' === $status ) {
			return new WP_Error( 'll_returns_webhook_missing_status', __( 'Brak statusu ERP.', 'lemon-woo-returns' ), array( 'status' => 400 ) );
		}

		$request_id = $this->repository->find_request_id( $return_reference, $external_id );

		if ( ! $request_id ) {
			return new WP_Error( 'll_returns_webhook_request_not_found', __( 'Nie znaleziono zgloszenia zwrotu.', 'lemon-woo-returns' ), array( 'status' => 404 ) );
		}

		$this->apply_status( $request_id, $status, $params );

		return rest_ensure_response(
			array(
				'success'    => true,
				'request_id' => $request_id,
				'status'     => $this->settings->map_erp_status_to_internal_status( $status ),
			)
		);
	}

	/**
	 * Extracts status from common ERP response shapes.
	 *
	 * @param array $erp_response ERP response.
	 * @return string
	 */
	private function extract_status( array $erp_response ) {
		if ( isset( $erp_response['status'] ) && '' !== $erp_response['status'] ) {
			return sanitize_text_field( $erp_response['status'] );
		}

		if ( isset( $erp_response['return_status'] ) && '' !== $erp_response['return_status'] ) {
			return sanitize_text_field( $erp_response['return_status'] );
		}

		if ( isset( $erp_response['data']['status'] ) && '' !== $erp_response['data']['status'] ) {
			return sanitize_text_field( $erp_response['data']['status'] );
		}

		return '';
	}
}
