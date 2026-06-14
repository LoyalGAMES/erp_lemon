<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;

class WordpressIntegration extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'sales_channel_id',
        'name',
        'base_url',
        'consumer_key_encrypted',
        'consumer_secret_encrypted',
        'wp_api_username',
        'wp_api_password_encrypted',
        'order_import_enabled',
        'stock_export_enabled',
        'invoice_upload_enabled',
        'last_successful_sync_at',
        'settings',
    ];

    protected $casts = [
        'order_import_enabled' => 'boolean',
        'stock_export_enabled' => 'boolean',
        'invoice_upload_enabled' => 'boolean',
        'last_successful_sync_at' => 'datetime',
        'settings' => 'array',
    ];

    public function salesChannel(): BelongsTo
    {
        return $this->belongsTo(SalesChannel::class);
    }

    public function maskedConsumerKey(): string
    {
        $key = Crypt::decryptString($this->consumer_key_encrypted);

        return substr($key, 0, 6).str_repeat('*', max(4, strlen($key) - 10)).substr($key, -4);
    }

    public function hasWordpressMediaCredentials(): bool
    {
        return filled($this->wp_api_username) && filled($this->wp_api_password_encrypted);
    }

    public function wordpressApiPassword(): string
    {
        return Crypt::decryptString((string) $this->wp_api_password_encrypted);
    }

    /**
     * @return array{mode:string}
     */
    public function invoiceDeliverySettings(): array
    {
        $mode = (string) data_get($this->settings, 'invoice_delivery.mode', 'lemon_plugin');

        return [
            'mode' => in_array($mode, ['lemon_plugin', 'media_library'], true) ? $mode : 'lemon_plugin',
        ];
    }

    /**
     * @return array{enabled:bool,endpoint:?string,method:string,auth:string,url_key:?string,base64_key:?string,filename_key:?string}
     */
    public function shippingLabelSettings(): array
    {
        return array_merge([
            'enabled' => false,
            'endpoint' => null,
            'method' => 'POST',
            'auth' => 'woocommerce',
            'url_key' => null,
            'base64_key' => null,
            'filename_key' => null,
        ], (array) data_get($this->settings, 'shipping_labels', []));
    }

    public function shippingLabelsEnabled(): bool
    {
        $settings = $this->shippingLabelSettings();

        return (bool) $settings['enabled'] && filled($settings['endpoint']);
    }

    /**
     * @return array{ready_to_ship:string,shipped:string,packing_rollback:string}
     */
    public function orderStatusSettings(): array
    {
        return array_merge([
            'ready_to_ship' => 'ready-to-ship',
            'shipped' => 'completed',
            'packing_rollback' => 'processing',
        ], (array) data_get($this->settings, 'order_statuses', []));
    }

    /**
     * @return array{page_limit:int,overlap_minutes:int}
     */
    public function orderImportSettings(): array
    {
        $settings = array_merge([
            'page_limit' => 200,
            'overlap_minutes' => 30,
        ], (array) data_get($this->settings, 'order_import', []));

        return [
            'page_limit' => max(1, min(1000, (int) $settings['page_limit'])),
            'overlap_minutes' => max(0, min(1440, (int) $settings['overlap_minutes'])),
        ];
    }

    /**
     * @return list<string|null>
     */
    public function productImportLanguages(): array
    {
        $languages = collect((array) data_get($this->settings, 'product_import.languages', ['pl', 'en']))
            ->map(fn ($language): ?string => $language === null ? null : trim((string) $language))
            ->map(fn (?string $language): ?string => $language === '' ? null : mb_strtolower($language))
            ->unique()
            ->values()
            ->all();

        return $languages !== [] ? $languages : [null];
    }
}
