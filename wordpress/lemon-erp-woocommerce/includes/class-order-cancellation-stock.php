<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Persists the ERP stock-disposition decision before WooCommerce changes an
 * order to `cancelled`, then prevents WooCommerce from restoring stock when
 * the operator explicitly selected the no-restock path.
 */
final class Lemon_Erp_Order_Cancellation_Stock
{
    private const PLUGIN_VERSION = '0.5.9';

    private const CONTRACT = 1;

    private const RESTORE_STOCK_META = '_lemon_erp_cancellation_restore_stock';

    private const CANCELLATION_UUID_META = '_lemon_erp_cancellation_uuid';

    private const DECISION_STATE_META = '_lemon_erp_cancellation_stock_state';

    private const STATE_ARMED = 'armed';

    private const STATE_APPLIED = 'applied';

    /** @var list<string> */
    private const STOCK_RESTORING_STATUSES = ['cancelled', 'pending'];

    public function hooks(): void
    {
        add_action('rest_api_init', [$this, 'registerRestRoutes']);
        add_action('woocommerce_order_status_changed', [$this, 'orderStatusChanged'], PHP_INT_MAX, 4);
        add_filter('woocommerce_can_restore_order_stock', [$this, 'canRestoreOrderStock'], PHP_INT_MAX, 2);
    }

    public function registerRestRoutes(): void
    {
        register_rest_route('wc-lemon-erp/v1', '/orders/cancellation-stock/capabilities', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'capabilities'],
            'permission_callback' => [$this, 'canManageWooCommerce'],
        ]);

        register_rest_route('wc-lemon-erp/v1', '/orders/(?P<order_id>\d+)/cancellation-stock', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'configure'],
            'permission_callback' => [$this, 'canManageOrder'],
            'args' => [
                'order_id' => [
                    'required' => true,
                    'validate_callback' => fn ($value): bool => is_numeric($value) && (int) $value > 0,
                ],
                'restore_stock' => [
                    'required' => true,
                    'validate_callback' => fn ($value): bool => is_bool($value) || in_array($value, [0, 1, '0', '1', 'false', 'true'], true),
                ],
                'cancellation_uuid' => [
                    'required' => true,
                    'type' => 'string',
                    'validate_callback' => fn ($value): bool => preg_match(
                        '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
                        (string) $value,
                    ) === 1,
                ],
            ],
        ]);
    }

    public function canManageWooCommerce(WP_REST_Request $request): bool
    {
        return current_user_can('manage_woocommerce') || current_user_can('edit_shop_orders');
    }

    public function canManageOrder(WP_REST_Request $request): bool
    {
        $orderId = (int) $request['order_id'];

        return current_user_can('manage_woocommerce')
            || current_user_can('edit_shop_orders')
            || current_user_can('edit_shop_order', $orderId);
    }

    public function capabilities(WP_REST_Request $request): WP_REST_Response
    {
        return new WP_REST_Response([
            'available' => true,
            'plugin_version' => self::PLUGIN_VERSION,
            'stock_disposition_contract' => self::CONTRACT,
            'configuration_endpoint' => '/wp-json/wc-lemon-erp/v1/orders/{order_id}/cancellation-stock',
        ], 200);
    }

    public function configure(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $orderId = (int) $request['order_id'];
        $order = wc_get_order($orderId);

        if (! $order instanceof WC_Order) {
            return new WP_Error(
                'lemon_erp_order_not_found',
                __('Nie znaleziono zamówienia.', 'lemon-erp-woocommerce'),
                ['status' => 404],
            );
        }

        $params = $request->get_json_params();

        if (! is_array($params)) {
            $params = $request->get_params();
        }

        if (! array_key_exists('restore_stock', $params)) {
            return new WP_Error(
                'lemon_erp_restore_stock_required',
                __('Brakuje jawnej decyzji o przywróceniu stanu.', 'lemon-erp-woocommerce'),
                ['status' => 422],
            );
        }

        $restoreStock = rest_sanitize_boolean($params['restore_stock']);
        $cancellationUuid = strtolower(sanitize_text_field((string) ($params['cancellation_uuid'] ?? '')));
        $existingUuid = strtolower((string) $order->get_meta(self::CANCELLATION_UUID_META, true));
        $existingDisposition = (string) $order->get_meta(self::RESTORE_STOCK_META, true);
        $existingState = (string) $order->get_meta(self::DECISION_STATE_META, true);
        $requestedDisposition = $restoreStock ? 'yes' : 'no';

        // A marker already applied to a previous cancellation must not affect
        // a later lifecycle of the same WooCommerce order. The status-change
        // hook normally clears it on reopening; this branch also repairs an
        // order that was reopened while the plugin was temporarily inactive.
        if (! $order->has_status(self::STOCK_RESTORING_STATUSES) && $existingState === self::STATE_APPLIED) {
            $this->clearDecision($order);
            $existingUuid = '';
            $existingDisposition = '';
            $existingState = '';
        }

        if ($existingUuid !== '' && $existingUuid !== $cancellationUuid) {
            return new WP_Error(
                'lemon_erp_cancellation_stock_conflict',
                __('Zamówienie ma już inną decyzję magazynową anulacji.', 'lemon-erp-woocommerce'),
                ['status' => 409],
            );
        }

        if ($existingUuid === $cancellationUuid
            && $existingDisposition !== ''
            && $existingDisposition !== $requestedDisposition
        ) {
            return new WP_Error(
                'lemon_erp_cancellation_stock_disposition_conflict',
                __('Nie można zmienić wcześniej potwierdzonej decyzji magazynowej tej anulacji.', 'lemon-erp-woocommerce'),
                ['status' => 409],
            );
        }

        // If WooCommerce was cancelled without our marker, its standard stock
        // restoration already ran and setting the marker now would be a false
        // confirmation. The same UUID remains safely retryable after a lost
        // HTTP response from the original status update.
        if ($order->has_status(self::STOCK_RESTORING_STATUSES) && $existingUuid === '') {
            return new WP_Error(
                'lemon_erp_cancellation_stock_too_late',
                __('WooCommerce jest już w stanie, który mógł przywrócić magazyn bez zapisanej decyzji ERP.', 'lemon-erp-woocommerce'),
                ['status' => 409],
            );
        }

        // `armed` only proves that ERP wrote the decision during preflight.
        // If the plugin was not loaded during the later status transition,
        // WooCommerce may already have restored stock. Only the filter/status
        // hook can promote the marker to `applied`, so never manufacture that
        // confirmation during a retry.
        if ($order->has_status(self::STOCK_RESTORING_STATUSES) && $existingState !== self::STATE_APPLIED) {
            return new WP_Error(
                'lemon_erp_cancellation_stock_unconfirmed',
                __('Nie można potwierdzić, czy decyzja magazynowa zadziałała podczas anulowania. Sprawdź stan ręcznie.', 'lemon-erp-woocommerce'),
                ['status' => 409],
            );
        }

        $order->update_meta_data(self::RESTORE_STOCK_META, $requestedDisposition);
        $order->update_meta_data(self::CANCELLATION_UUID_META, $cancellationUuid);
        $order->update_meta_data(
            self::DECISION_STATE_META,
            $order->has_status(self::STOCK_RESTORING_STATUSES) ? self::STATE_APPLIED : self::STATE_ARMED,
        );
        $order->save_meta_data();

        return new WP_REST_Response([
            'confirmed' => true,
            'order_id' => $orderId,
            'cancellation_uuid' => $cancellationUuid,
            'restore_stock' => $restoreStock,
            'decision_state' => $order->has_status(self::STOCK_RESTORING_STATUSES) ? self::STATE_APPLIED : self::STATE_ARMED,
            'stock_disposition_contract' => self::CONTRACT,
            'plugin_version' => self::PLUGIN_VERSION,
        ], 200);
    }

    public function canRestoreOrderStock(mixed $canRestore, mixed $order): mixed
    {
        if (! (bool) $canRestore) {
            return $canRestore;
        }

        if (! $order instanceof WC_Order) {
            $order = wc_get_order($order);
        }

        if (! $order instanceof WC_Order) {
            return $canRestore;
        }

        $uuid = strtolower((string) $order->get_meta(self::CANCELLATION_UUID_META, true));
        $state = (string) $order->get_meta(self::DECISION_STATE_META, true);

        if ((string) $order->get_meta(self::RESTORE_STOCK_META, true) !== 'no'
            || ! $order->has_status(self::STOCK_RESTORING_STATUSES)
            || ! $this->validCancellationUuid($uuid)
            || ! in_array($state, ['', self::STATE_ARMED, self::STATE_APPLIED], true)
        ) {
            return $canRestore;
        }

        if ($state !== self::STATE_APPLIED) {
            $order->update_meta_data(self::DECISION_STATE_META, self::STATE_APPLIED);
            $order->save_meta_data();
        }

        return false;
    }

    public function orderStatusChanged(
        mixed $orderId,
        mixed $fromStatus,
        mixed $toStatus,
        mixed $order,
    ): void {
        if (! $order instanceof WC_Order) {
            $order = wc_get_order($orderId);
        }

        if (! $order instanceof WC_Order) {
            return;
        }

        $fromStatus = (string) $fromStatus;
        $toStatus = (string) $toStatus;

        if (in_array($fromStatus, self::STOCK_RESTORING_STATUSES, true)
            && ! in_array($toStatus, self::STOCK_RESTORING_STATUSES, true)
        ) {
            $this->clearDecision($order);

            return;
        }

        if (! in_array($toStatus, self::STOCK_RESTORING_STATUSES, true)
            || (string) $order->get_meta(self::RESTORE_STOCK_META, true) === ''
            || ! $this->validCancellationUuid((string) $order->get_meta(self::CANCELLATION_UUID_META, true))
        ) {
            return;
        }

        $order->update_meta_data(self::DECISION_STATE_META, self::STATE_APPLIED);
        $order->save_meta_data();
    }

    private function clearDecision(WC_Order $order): void
    {
        $order->delete_meta_data(self::RESTORE_STOCK_META);
        $order->delete_meta_data(self::CANCELLATION_UUID_META);
        $order->delete_meta_data(self::DECISION_STATE_META);
        $order->save_meta_data();
    }

    private function validCancellationUuid(string $uuid): bool
    {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $uuid,
        ) === 1;
    }
}
