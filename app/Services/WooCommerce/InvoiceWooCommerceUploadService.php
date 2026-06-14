<?php

declare(strict_types=1);

namespace App\Services\WooCommerce;

use App\Models\IntegrationSyncLog;
use App\Models\Invoice;
use App\Models\InvoiceFile;
use App\Models\WordpressIntegration;
use App\Services\Invoices\InvoiceValidationService;
use Illuminate\Http\Client\RequestException;
use RuntimeException;
use Throwable;

final class InvoiceWooCommerceUploadService
{
    public function __construct(
        private readonly WooCommerceClient $client,
        private readonly InvoiceValidationService $validation,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function upload(Invoice $invoice): array
    {
        $invoice = Invoice::query()
            ->with(['externalOrder.salesChannel', 'files', 'ksefSubmissions'])
            ->findOrFail($invoice->id);

        $order = $invoice->externalOrder;

        if ($order === null) {
            throw new RuntimeException('Faktura nie jest powiązana z zamówieniem.');
        }

        $this->validation->assertValidForExternalSend($invoice);

        $integration = WordpressIntegration::query()
            ->where('sales_channel_id', $order->sales_channel_id)
            ->where('invoice_upload_enabled', true)
            ->first();

        if ($integration === null) {
            throw new RuntimeException('Brak aktywnej integracji WooCommerce z włączonym uploadem faktur.');
        }

        $file = $this->invoiceFile($invoice);
        $ksefData = $this->ksefData($invoice);
        $delivery = $integration->invoiceDeliverySettings();
        $mediaId = null;
        $fileUrl = '';
        $mediaReused = false;

        $invoiceData = [
            'invoice_number' => $invoice->number,
            'invoice_id' => $invoice->id,
            'invoice_type' => $invoice->type,
            'invoice_status' => $invoice->status,
            'order_id' => $order->external_id,
            'gross_total' => (string) $invoice->gross_total,
            'currency' => $invoice->currency,
            'issued_at' => $invoice->issued_at?->toISOString(),
            'file_type' => $file->type,
            'file_sha256' => $file->sha256,
            'ksef_number' => $ksefData['number'],
            'ksef_reference_number' => $ksefData['reference_number'],
            'ksef_accepted_at' => $ksefData['accepted_at'],
        ];

        try {
            $absolutePath = storage_path('app/'.$file->path);

            if ($delivery['mode'] === 'lemon_plugin') {
                $mediaResponse = $this->client->upsertOrderInvoiceViaLemonPlugin(
                    $integration,
                    (string) $order->external_id,
                    $invoiceData,
                    $absolutePath,
                );
                $fileUrl = (string) ($mediaResponse['file_url'] ?? '');
                $noteResponse = ['id' => $mediaResponse['note_id'] ?? null];
                $metaResponse = $mediaResponse;
                $file->update([
                    'metadata' => array_merge($file->metadata ?? [], [
                        'wordpress_invoice_delivery' => 'lemon_plugin',
                        'wordpress_source_url' => $fileUrl,
                        'wordpress_file_sha256' => $file->sha256,
                        'wordpress_uploaded_at' => now()->toISOString(),
                    ]),
                ]);
            } else {
                $existingMedia = $this->existingUploadedMedia($file);
                $mediaId = $existingMedia['media_id'];
                $fileUrl = $existingMedia['file_url'];
                $mediaReused = $mediaId !== null && $fileUrl !== '';

                if ($mediaReused) {
                    $mediaResponse = [
                        'id' => $mediaId,
                        'source_url' => $fileUrl,
                        'reused' => true,
                    ];
                } else {
                    $mediaResponse = $this->client->uploadMedia(
                        $integration,
                        $absolutePath,
                        basename($file->path),
                        $file->mime_type ?? 'application/pdf',
                    );

                    $fileUrl = (string) ($mediaResponse['source_url'] ?? '');
                    $mediaId = $mediaResponse['id'] ?? null;

                    $file->update([
                        'metadata' => array_merge($file->metadata ?? [], [
                            'wordpress_invoice_delivery' => 'media_library',
                            'wordpress_media_id' => $mediaId,
                            'wordpress_source_url' => $fileUrl,
                            'wordpress_file_sha256' => $file->sha256,
                            'wordpress_uploaded_at' => now()->toISOString(),
                        ]),
                    ]);
                }

                $metaResponse = $this->client->updateOrderInvoiceMeta($integration, (string) $order->external_id, array_merge($invoiceData, [
                    'file_url' => $fileUrl,
                    'media_id' => $mediaId,
                ]));

                $ksefNotePart = $ksefData['number'] !== ''
                    ? ' Nr KSeF: '.$ksefData['number'].'.'
                    : '';

                $noteResponse = $this->client->createOrderNote(
                    $integration,
                    (string) $order->external_id,
                    sprintf(
                        'Sempre ERP: %s %s na kwotę %s %s.%s Plik faktury: %s',
                        $invoice->type === 'correction' ? 'wystawiono fakturę korygującą' : 'wystawiono fakturę',
                        $invoice->number,
                        number_format((float) $invoice->gross_total, 2, ',', ' '),
                        $invoice->currency,
                        $ksefNotePart,
                        $fileUrl !== '' ? $fileUrl : 'zapisany w ERP',
                    ),
                );
            }
        } catch (Throwable $exception) {
            $failureMessage = $this->failureMessage($exception);

            $this->recordFailure($invoice, $integration, $exception, [
                'media_id' => $mediaId,
                'file_url' => $fileUrl,
                'file_sha256' => $file->sha256,
                'media_reused' => $mediaReused,
                'error' => $failureMessage,
            ]);

            throw $exception instanceof RuntimeException
                ? $exception
                : new RuntimeException($failureMessage, (int) $exception->getCode(), $exception);
        }

        $metadata = array_merge($invoice->metadata ?? [], [
            'woocommerce_upload' => [
                'status' => 'success',
                'requires_resend' => false,
                'uploaded_at' => now()->toISOString(),
                'integration_id' => $integration->id,
                'order_id' => $order->external_id,
                'invoice_type' => $invoice->type,
                'delivery_mode' => $delivery['mode'],
                'note_id' => $noteResponse['id'] ?? null,
                'media_id' => $mediaId,
                'file_url' => $fileUrl,
                'file_sha256' => $file->sha256,
                'media_reused' => $mediaReused,
                'ksef_number' => $ksefData['number'],
                'ksef_reference_number' => $ksefData['reference_number'],
            ],
        ]);

        $invoice->update(['metadata' => $metadata]);

        IntegrationSyncLog::query()->create([
            'sales_channel_id' => $order->sales_channel_id,
            'wordpress_integration_id' => $integration->id,
            'direction' => 'out',
            'operation' => 'upload_invoice',
            'status' => 'success',
            'external_resource' => 'order',
            'external_id' => (string) $order->external_id,
            'request_payload' => $invoiceData,
            'response_payload' => [
                'order_id' => $metaResponse['id'] ?? null,
                'note_id' => $noteResponse['id'] ?? null,
                'media_id' => $mediaId,
                'file_url' => $fileUrl,
                'file_sha256' => $file->sha256,
                'media_reused' => $mediaReused,
                'delivery_mode' => $delivery['mode'],
                'ksef_number' => $ksefData['number'],
                'ksef_reference_number' => $ksefData['reference_number'],
            ],
            'attempts' => 1,
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        return [
            'media' => $mediaResponse,
            'order' => $metaResponse,
            'note' => $noteResponse,
        ];
    }

    private function invoiceFile(Invoice $invoice): InvoiceFile
    {
        $file = $invoice->files->firstWhere('type', 'pdf')
            ?? $invoice->files->firstWhere('type', 'html')
            ?? $invoice->files->first();

        if ($file === null) {
            throw new RuntimeException('Faktura nie ma wygenerowanego pliku.');
        }

        return $file;
    }

    /**
     * @return array{number:string,reference_number:string,accepted_at:?string}
     */
    private function ksefData(Invoice $invoice): array
    {
        $accepted = $invoice->ksefSubmissions
            ->sortByDesc('id')
            ->first(fn ($submission): bool => $submission->status === 'accepted');
        $acceptedAt = $accepted?->accepted_at?->toISOString()
            ?? data_get($invoice->metadata, 'ksef.accepted_at');

        return [
            'number' => (string) ($invoice->ksef_number ?: $accepted?->ksef_number ?: ''),
            'reference_number' => (string) ($accepted?->reference_number ?: data_get($invoice->metadata, 'ksef.reference_number', '')),
            'accepted_at' => is_string($acceptedAt) ? $acceptedAt : null,
        ];
    }

    /**
     * @return array{media_id:mixed,file_url:string}
     */
    private function existingUploadedMedia(InvoiceFile $file): array
    {
        $metadata = $file->metadata ?? [];

        if (
            filled(data_get($metadata, 'wordpress_media_id'))
            && filled(data_get($metadata, 'wordpress_source_url'))
            && data_get($metadata, 'wordpress_file_sha256') === $file->sha256
        ) {
            return [
                'media_id' => data_get($metadata, 'wordpress_media_id'),
                'file_url' => (string) data_get($metadata, 'wordpress_source_url'),
            ];
        }

        return [
            'media_id' => null,
            'file_url' => '',
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function recordFailure(
        Invoice $invoice,
        WordpressIntegration $integration,
        Throwable $exception,
        array $context = [],
    ): void {
        $errorMessage = (string) ($context['error'] ?? $this->failureMessage($exception));

        $invoice->update([
            'metadata' => array_merge($invoice->metadata ?? [], [
                'woocommerce_upload' => array_filter([
                    'status' => 'failed',
                    'requires_resend' => true,
                    'failed_at' => now()->toISOString(),
                    'integration_id' => $integration->id,
                    'order_id' => $invoice->externalOrder?->external_id,
                    'invoice_type' => $invoice->type,
                    'media_id' => $context['media_id'] ?? null,
                    'file_url' => $context['file_url'] ?? null,
                    'file_sha256' => $context['file_sha256'] ?? null,
                    'media_reused' => $context['media_reused'] ?? null,
                    'error' => $errorMessage,
                ], fn ($value): bool => $value !== null && $value !== ''),
            ]),
        ]);

        IntegrationSyncLog::query()->create([
            'sales_channel_id' => $invoice->externalOrder?->sales_channel_id,
            'wordpress_integration_id' => $integration->id,
            'direction' => 'out',
            'operation' => 'upload_invoice',
            'status' => 'failed',
            'external_resource' => 'order',
            'external_id' => (string) $invoice->externalOrder?->external_id,
            'request_payload' => [
                'invoice_number' => $invoice->number,
                'invoice_id' => $invoice->id,
                'invoice_type' => $invoice->type,
                'order_id' => $invoice->externalOrder?->external_id,
                'gross_total' => (string) $invoice->gross_total,
                'currency' => $invoice->currency,
            ],
            'response_payload' => $context,
            'error_message' => mb_substr($errorMessage, 0, 1000),
            'attempts' => 1,
            'started_at' => now(),
            'finished_at' => now(),
        ]);
    }

    private function failureMessage(Throwable $exception): string
    {
        if ($exception instanceof RequestException && $exception->response !== null) {
            return trim('HTTP '.$exception->response->status().': '.$exception->response->body());
        }

        return $exception->getMessage();
    }
}
