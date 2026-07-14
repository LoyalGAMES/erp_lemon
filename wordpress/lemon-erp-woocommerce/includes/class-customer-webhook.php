<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Pushes WooCommerce customer changes to Lemon ERP without slowing down the
 * registration request. The existing WooCommerce consumer secret is reused
 * as the HMAC key, but is never included in configuration or webhook calls.
 */
final class Lemon_Erp_Customer_Webhook
{
    private const OPTION_KEY = 'lemon_erp_customer_webhook';

    private const DELIVERY_ACTION = 'lemon_erp_deliver_customer_webhook';

    private const ACTION_GROUP = 'lemon-erp';

    private const CONTRACT_VERSION = '1';

    private const PLUGIN_VERSION = '0.5.1';

    private const MAX_ATTEMPTS = 7;

    private const EVENT_DEBOUNCE_SECONDS = 10;

    /** @var list<int> */
    private const RETRY_DELAYS = [10, 30, 120, 300, 900, 3600];

    public function hooks(): void
    {
        add_action('rest_api_init', [$this, 'registerRestRoutes']);
        add_action('woocommerce_created_customer', [$this, 'customerCreated'], 100, 3);
        add_action('user_register', [$this, 'userRegistered'], 100, 2);
        add_action('woocommerce_update_customer', [$this, 'customerUpdated'], 100, 1);
        add_action('profile_update', [$this, 'profileUpdated'], 100, 2);
        add_action(self::DELIVERY_ACTION, [$this, 'deliver'], 10, 1);
    }

    public function registerRestRoutes(): void
    {
        // WooCommerce authenticates ck_/cs_ credentials only for REST
        // namespaces rooted at `wc/` or prefixed with `wc-`.
        register_rest_route('wc-lemon-erp/v1', '/customer-webhook/configure', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'status'],
                'permission_callback' => [$this, 'canConfigure'],
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'configure'],
                'permission_callback' => [$this, 'canConfigure'],
                'args' => [
                    'delivery_url' => [
                        'required' => true,
                        'type' => 'string',
                    ],
                    'consumer_key' => [
                        'required' => true,
                        'type' => 'string',
                    ],
                ],
            ],
            [
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => [$this, 'disable'],
                'permission_callback' => [$this, 'canConfigure'],
            ],
        ]);
    }

    public function canConfigure(WP_REST_Request $request): bool
    {
        return current_user_can('manage_woocommerce');
    }

    public function status(WP_REST_Request $request): WP_REST_Response
    {
        $settings = $this->storedSettings();

        return new WP_REST_Response([
            'configured' => $settings !== null,
            'delivery_url' => $settings['delivery_url'] ?? null,
            'configured_at' => $settings['configured_at'] ?? null,
            'plugin_version' => self::PLUGIN_VERSION,
            'contract_version' => self::CONTRACT_VERSION,
            'events' => ['customer.created', 'customer.updated'],
            'authentication' => 'woocommerce_consumer_secret_hmac_sha256',
        ]);
    }

    public function configure(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $deliveryUrl = $this->validDeliveryUrl((string) $request->get_param('delivery_url'));

        if ($deliveryUrl === null) {
            return new WP_Error(
                'lemon_erp_customer_webhook_url_invalid',
                __('Adres webhooka klientów musi być poprawnym publicznym adresem HTTPS.', 'lemon-erp-woocommerce'),
                ['status' => 422],
            );
        }

        $consumerKey = trim((string) $request->get_param('consumer_key'));
        $apiKey = $this->apiKeyForConfiguration($consumerKey);

        if ($apiKey instanceof WP_Error) {
            return $apiKey;
        }

        $settings = [
            'delivery_url' => $deliveryUrl,
            'key_id' => (int) $apiKey['key_id'],
            'configured_at' => gmdate('c'),
        ];

        update_option(self::OPTION_KEY, $settings, false);

        return new WP_REST_Response([
            'configured' => true,
            'delivery_url' => $deliveryUrl,
            'configured_at' => $settings['configured_at'],
            'plugin_version' => self::PLUGIN_VERSION,
            'contract_version' => self::CONTRACT_VERSION,
            'events' => ['customer.created', 'customer.updated'],
            'authentication' => 'woocommerce_consumer_secret_hmac_sha256',
        ], 200);
    }

    public function disable(WP_REST_Request $request): WP_REST_Response
    {
        delete_option(self::OPTION_KEY);

        return new WP_REST_Response([
            'configured' => false,
            'plugin_version' => self::PLUGIN_VERSION,
            'contract_version' => self::CONTRACT_VERSION,
        ], 200);
    }

    /**
     * @param  array<string, mixed>  $newCustomerData
     */
    public function customerCreated(int $customerId, array $newCustomerData = [], bool $passwordGenerated = false): void
    {
        if ($this->isWooCustomer($customerId)) {
            $this->enqueue('customer.created', $customerId);
        }
    }

    /**
     * Covers accounts created by extensions which call wp_insert_user()
     * directly instead of wc_create_new_customer().
     *
     * @param  array<string, mixed>  $userdata
     */
    public function userRegistered(int $customerId, array $userdata = []): void
    {
        if ($this->isWooCustomer($customerId)) {
            $this->enqueue('customer.created', $customerId);
        }
    }

    public function customerUpdated(int $customerId): void
    {
        if ($this->isWooCustomer($customerId)) {
            $this->enqueue('customer.updated', $customerId);
        }
    }

    public function profileUpdated(int $customerId, mixed $oldUserData = null): void
    {
        $this->customerUpdated($customerId);
    }

    /**
     * Action Scheduler / WP-Cron callback.
     *
     * @param  array<string, mixed>  $event
     */
    public function deliver(array $event): void
    {
        $event = $this->validEvent($event);

        if ($event === null) {
            return;
        }

        $configuration = $this->deliveryConfiguration();

        if ($configuration === null) {
            $this->log('warning', 'Webhook klienta pominięty: konfiguracja lub klucz WooCommerce nie są już dostępne.', $event);

            return;
        }

        $payload = [
            'event' => $event['event'],
            'event_id' => $event['event_id'],
            'occurred_at' => $event['occurred_at'],
            'store_url' => home_url('/'),
            'customer_id' => (int) $event['customer_id'],
        ];
        $body = wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (! is_string($body) || $body === '') {
            $this->retry($event, 'Nie udało się zakodować danych klienta do JSON.');

            return;
        }

        $timestamp = (string) time();
        $signature = base64_encode(hash_hmac('sha256', $timestamp.'.'.$body, $configuration['secret'], true));
        $response = wp_safe_remote_post($configuration['delivery_url'], [
            'timeout' => 10,
            'redirection' => 0,
            'reject_unsafe_urls' => true,
            'sslverify' => true,
            'limit_response_size' => 262144,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json; charset=utf-8',
                'X-Lemon-Webhook-Version' => self::CONTRACT_VERSION,
                'X-Lemon-Webhook-Event' => $event['event'],
                'X-Lemon-Webhook-Id' => $event['event_id'],
                'X-Lemon-Webhook-Timestamp' => $timestamp,
                'X-Lemon-Webhook-Signature' => $signature,
            ],
            'body' => $body,
        ]);

        if (is_wp_error($response)) {
            $this->retry($event, $response->get_error_message());

            return;
        }

        $status = (int) wp_remote_retrieve_response_code($response);

        if ($status < 200 || $status >= 300) {
            $this->retry($event, sprintf('ERP zwrócił HTTP %d.', $status), $status);

            return;
        }

        $this->log('info', 'Webhook klienta dostarczony do ERP.', $event, ['http_status' => $status]);
    }

    private function enqueue(string $eventName, int $customerId): void
    {
        if ($customerId <= 0 || $this->storedSettings() === null) {
            return;
        }

        if (! $this->isWooCustomer($customerId)) {
            return;
        }

        $debounceKey = 'lemon_erp_customer_'.md5($eventName.':'.$customerId);

        if (get_transient($debounceKey) !== false) {
            return;
        }

        set_transient($debounceKey, '1', self::EVENT_DEBOUNCE_SECONDS);

        $event = [
            'event' => $eventName,
            'event_id' => wp_generate_uuid4(),
            'customer_id' => $customerId,
            'occurred_at' => gmdate('c'),
            'attempt' => 1,
        ];

        $this->schedule($event, 0);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function schedule(array $event, int $delay): void
    {
        if (function_exists('as_enqueue_async_action') && $delay <= 0) {
            as_enqueue_async_action(self::DELIVERY_ACTION, [$event], self::ACTION_GROUP, true);

            return;
        }

        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(time() + max(1, $delay), self::DELIVERY_ACTION, [$event], self::ACTION_GROUP, true);

            return;
        }

        wp_schedule_single_event(time() + max(1, $delay), self::DELIVERY_ACTION, [$event]);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function retry(array $event, string $reason, ?int $status = null): void
    {
        $attempt = (int) $event['attempt'];

        if ($attempt >= self::MAX_ATTEMPTS) {
            $this->log('error', 'Webhook klienta odrzucony po ostatniej próbie: '.$reason, $event, ['http_status' => $status]);

            return;
        }

        $delay = self::RETRY_DELAYS[min($attempt - 1, count(self::RETRY_DELAYS) - 1)];
        $event['attempt'] = $attempt + 1;
        $this->schedule($event, $delay);
        $this->log('warning', 'Webhook klienta nie został dostarczony; zaplanowano ponowienie: '.$reason, $event, [
            'http_status' => $status,
            'retry_in_seconds' => $delay,
        ]);
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    private function apiKeyForConfiguration(string $consumerKey): array|WP_Error
    {
        global $wpdb;

        if (! function_exists('wc_api_hash') || ! preg_match('/^ck_[A-Za-z0-9]{20,128}$/', $consumerKey)) {
            return new WP_Error(
                'lemon_erp_customer_webhook_key_invalid',
                __('Podaj poprawny Consumer Key używany przez tę integrację WooCommerce.', 'lemon-erp-woocommerce'),
                ['status' => 422],
            );
        }

        $table = $wpdb->prefix.'woocommerce_api_keys';
        $apiKey = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT key_id, user_id, permissions, consumer_secret FROM {$table} WHERE consumer_key = %s LIMIT 1",
                wc_api_hash($consumerKey),
            ),
            ARRAY_A,
        );

        if (! is_array($apiKey)
            || (int) ($apiKey['user_id'] ?? 0) !== get_current_user_id()
            || (string) ($apiKey['permissions'] ?? '') !== 'read_write'
            || trim((string) ($apiKey['consumer_secret'] ?? '')) === ''
        ) {
            return new WP_Error(
                'lemon_erp_customer_webhook_key_forbidden',
                __('Consumer Key musi należeć do bieżącego użytkownika i mieć uprawnienia Odczyt/Zapis.', 'lemon-erp-woocommerce'),
                ['status' => 403],
            );
        }

        return $apiKey;
    }

    /**
     * @return array{delivery_url:string,key_id:int,configured_at:string}|null
     */
    private function storedSettings(): ?array
    {
        $settings = get_option(self::OPTION_KEY, []);

        if (! is_array($settings)
            || ! isset($settings['delivery_url'], $settings['key_id'])
            || (int) $settings['key_id'] <= 0
            || $this->validDeliveryUrl((string) $settings['delivery_url']) === null
        ) {
            return null;
        }

        return [
            'delivery_url' => (string) $settings['delivery_url'],
            'key_id' => (int) $settings['key_id'],
            'configured_at' => (string) ($settings['configured_at'] ?? ''),
        ];
    }

    /**
     * @return array{delivery_url:string,secret:string}|null
     */
    private function deliveryConfiguration(): ?array
    {
        global $wpdb;

        $settings = $this->storedSettings();

        if ($settings === null) {
            return null;
        }

        $table = $wpdb->prefix.'woocommerce_api_keys';
        $apiKey = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT key_id, permissions, consumer_secret FROM {$table} WHERE key_id = %d LIMIT 1",
                $settings['key_id'],
            ),
            ARRAY_A,
        );

        if (! is_array($apiKey)
            || (string) ($apiKey['permissions'] ?? '') !== 'read_write'
            || trim((string) ($apiKey['consumer_secret'] ?? '')) === ''
        ) {
            return null;
        }

        return [
            'delivery_url' => $settings['delivery_url'],
            'secret' => (string) $apiKey['consumer_secret'],
        ];
    }

    private function validDeliveryUrl(string $url): ?string
    {
        $url = esc_url_raw(trim($url), ['https']);
        $parts = wp_parse_url($url);

        if ($url === ''
            || ! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || trim((string) ($parts['host'] ?? '')) === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
            || (function_exists('wp_http_validate_url') && wp_http_validate_url($url) === false)
        ) {
            return null;
        }

        return $url;
    }

    private function isWooCustomer(int $customerId): bool
    {
        $user = get_userdata($customerId);

        if (! $user instanceof WP_User) {
            return false;
        }

        $roles = array_map('sanitize_key', (array) $user->roles);
        $customerRoles = (array) apply_filters('lemon_erp_customer_webhook_roles', ['customer']);
        $customerRoles = array_map('sanitize_key', $customerRoles);

        return array_intersect($roles, $customerRoles) !== [];
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>|null
     */
    private function validEvent(array $event): ?array
    {
        $eventName = (string) ($event['event'] ?? '');
        $eventId = (string) ($event['event_id'] ?? '');
        $customerId = (int) ($event['customer_id'] ?? 0);
        $attempt = max(1, (int) ($event['attempt'] ?? 1));

        if (! in_array($eventName, ['customer.created', 'customer.updated'], true)
            || $customerId <= 0
            || ! wp_is_uuid($eventId, 4)
        ) {
            return null;
        }

        return [
            'event' => $eventName,
            'event_id' => $eventId,
            'customer_id' => $customerId,
            'occurred_at' => (string) ($event['occurred_at'] ?? gmdate('c')),
            'attempt' => $attempt,
        ];
    }

    /**
     * @param  array<string, mixed>  $event
     * @param  array<string, mixed>  $extra
     */
    private function log(string $level, string $message, array $event, array $extra = []): void
    {
        if (! function_exists('wc_get_logger')) {
            return;
        }

        $context = [
            'source' => 'lemon-erp-customer-webhook',
            'event' => (string) ($event['event'] ?? ''),
            'event_id' => (string) ($event['event_id'] ?? ''),
            'customer_id' => (int) ($event['customer_id'] ?? 0),
            'attempt' => (int) ($event['attempt'] ?? 0),
        ];
        $context = array_merge(
            $context,
            array_filter($extra, static fn (mixed $value): bool => $value !== null),
        );

        wc_get_logger()->log($level, $message, $context);
    }
}
