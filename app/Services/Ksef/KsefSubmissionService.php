<?php

declare(strict_types=1);

namespace App\Services\Ksef;

use App\Models\Invoice;
use App\Models\KsefSubmission;
use App\Services\Invoices\InvoiceValidationService;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class KsefSubmissionService
{
    public function __construct(
        private readonly KsefXmlBuilder $xmlBuilder,
        private readonly KsefClient $client,
        private readonly InvoiceValidationService $validation,
    ) {
    }

    public function prepare(Invoice $invoice): KsefSubmission
    {
        return DB::transaction(function () use ($invoice): KsefSubmission {
            $invoice = Invoice::query()
                ->with(['lines', 'ksefSubmissions'])
                ->lockForUpdate()
                ->findOrFail($invoice->id);

            $this->validation->assertValidForExternalSend($invoice);

            $accepted = $invoice->ksefSubmissions
                ->first(fn (KsefSubmission $submission): bool => $submission->status === 'accepted');

            if ($accepted instanceof KsefSubmission) {
                return $accepted;
            }

            $active = $invoice->ksefSubmissions
                ->sortByDesc('id')
                ->first(fn (KsefSubmission $submission): bool => in_array($submission->status, [
                    'queued',
                    'running',
                    'submitted',
                ], true));

            if ($active instanceof KsefSubmission) {
                return $active;
            }

            $unsupportedRates = $this->xmlBuilder->unsupportedVatRates($invoice);
            if ($unsupportedRates !== []) {
                throw new RuntimeException('Faktura ma stawki VAT bez mapowania KSeF FA(3): ' . implode(', ', array_map(
                    fn (float $rate): string => rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.') . '%',
                    $unsupportedRates,
                )) . '.');
            }

            $configuration = $this->client->configurationStatus();
            $xml = $this->xmlBuilder->build($invoice);

            return $invoice->ksefSubmissions()->create([
                'environment' => $configuration['environment'],
                'api_version' => $configuration['api_version'],
                'status' => 'queued',
                'xml_payload' => $xml,
                'request_metadata' => [
                    'form_code' => KsefXmlBuilder::FORM_SYSTEM_CODE,
                    'schema_version' => KsefXmlBuilder::SCHEMA_VERSION,
                    'namespace' => KsefXmlBuilder::FA3_NAMESPACE,
                    'api_base_url' => $configuration['base_url'],
                    'has_access_token' => $configuration['has_access_token'],
                    'has_gateway_url' => $configuration['has_gateway_url'],
                    'direct_online_send_ready' => $configuration['direct_online_send_ready'],
                    'xml_sha256_base64' => base64_encode(hash('sha256', $xml, true)),
                    'xml_size' => strlen($xml),
                    'created_by' => 'sempre_erp',
                ],
            ]);
        });
    }

    public function submit(KsefSubmission $submission): KsefSubmission
    {
        $submission = KsefSubmission::query()->with('invoice')->findOrFail($submission->id);

        if (in_array($submission->status, ['submitted', 'accepted'], true)) {
            return $submission;
        }

        $submission->update([
            'status' => 'running',
            'last_error' => null,
        ]);

        try {
            $response = $this->client->send($submission);

            $referenceNumber = (string) data_get($response, 'referenceNumber', data_get($response, 'reference_number', ''));
            $ksefNumber = (string) data_get($response, 'ksefNumber', data_get($response, 'ksef_number', ''));

            $submission->update([
                'status' => $ksefNumber !== '' ? 'accepted' : 'submitted',
                'reference_number' => $referenceNumber !== '' ? $referenceNumber : null,
                'ksef_number' => $ksefNumber !== '' ? $ksefNumber : null,
                'response_metadata' => $response,
                'submitted_at' => now(),
                'accepted_at' => $ksefNumber !== '' ? now() : null,
            ]);

            if ($ksefNumber !== '') {
                $this->syncAcceptedInvoice($submission->refresh()->load('invoice'), $ksefNumber);
            }
        } catch (RuntimeException $exception) {
            $status = $this->runtimeFailureStatus($exception);

            $submission->update([
                'status' => $status,
                'last_error' => $exception->getMessage(),
                'response_metadata' => [
                    'handled_as' => $status,
                    'message' => $exception->getMessage(),
                ],
            ]);
        } catch (Throwable $exception) {
            $submission->update([
                'status' => 'failed',
                'last_error' => $exception->getMessage(),
                'response_metadata' => [
                    'handled_as' => 'failed',
                    'message' => $exception->getMessage(),
                ],
            ]);
        }

        return $submission->refresh();
    }

    public function retry(KsefSubmission $submission): KsefSubmission
    {
        $submission = KsefSubmission::query()->with('invoice')->findOrFail($submission->id);

        if (! $this->canRetry($submission)) {
            throw new RuntimeException('Tego zgłoszenia KSeF nie można ponowić.');
        }

        $metadata = $submission->request_metadata ?? [];
        $metadata['retry_count'] = (int) ($metadata['retry_count'] ?? 0) + 1;
        $metadata['last_retry_at'] = now()->toISOString();

        $submission->update([
            'status' => 'queued',
            'last_error' => null,
            'request_metadata' => $metadata,
        ]);

        return $submission->refresh();
    }

    public function refreshStatus(KsefSubmission $submission): KsefSubmission
    {
        $submission = KsefSubmission::query()->with('invoice')->findOrFail($submission->id);

        if ($submission->status === 'accepted') {
            return $submission;
        }

        if (! in_array($submission->status, ['submitted', 'running'], true)) {
            throw new RuntimeException('Status można sprawdzić tylko dla zgłoszeń wysłanych do KSeF.');
        }

        try {
            $response = $this->client->checkStatus($submission);
            $resolved = $this->resolveRemoteStatus($response);
            $ksefNumber = (string) data_get($response, 'ksefNumber', data_get($response, 'ksef_number', $submission->ksef_number ?? ''));
            $referenceNumber = (string) data_get($response, 'referenceNumber', data_get($response, 'reference_number', $submission->reference_number ?? ''));
            $metadata = $submission->response_metadata ?? [];
            $metadata['last_status_check'] = $response;
            $metadata['last_status_checked_at'] = now()->toISOString();

            $lastError = $resolved === 'rejected'
                ? (string) data_get($response, 'errorMessage', data_get($response, 'error_message', data_get($response, 'message', 'Zgłoszenie odrzucone przez KSeF.')))
                : null;

            $submission->update([
                'status' => $resolved,
                'reference_number' => $referenceNumber !== '' ? $referenceNumber : $submission->reference_number,
                'ksef_number' => $ksefNumber !== '' ? $ksefNumber : null,
                'response_metadata' => $metadata,
                'last_error' => $lastError,
                'accepted_at' => $resolved === 'accepted' ? ($submission->accepted_at ?? now()) : $submission->accepted_at,
            ]);

            if ($resolved === 'accepted' && $ksefNumber !== '') {
                $this->syncAcceptedInvoice($submission->refresh()->load('invoice'), $ksefNumber);
            }
        } catch (RuntimeException $exception) {
            $metadata = $submission->response_metadata ?? [];
            $metadata['last_status_check_error'] = [
                'checked_at' => now()->toISOString(),
                'message' => $exception->getMessage(),
            ];

            $submission->update([
                'last_error' => $exception->getMessage(),
                'response_metadata' => $metadata,
            ]);

            throw $exception;
        }

        return $submission->refresh();
    }

    /**
     * @return array{scanned:int,refreshed:int,accepted:int,rejected:int,still_pending:int,failed:int}
     */
    public function refreshPending(int $limit = 25, int $minimumAgeMinutes = 2): array
    {
        $limit = max(1, min($limit, 100));
        $threshold = now()->subMinutes(max(0, $minimumAgeMinutes));

        $submissions = KsefSubmission::query()
            ->with('invoice')
            ->whereIn('status', ['submitted', 'running'])
            ->whereNotNull('reference_number')
            ->where('updated_at', '<=', $threshold)
            ->oldest('updated_at')
            ->limit($limit)
            ->get();

        $result = [
            'scanned' => $submissions->count(),
            'refreshed' => 0,
            'accepted' => 0,
            'rejected' => 0,
            'still_pending' => 0,
            'failed' => 0,
        ];

        foreach ($submissions as $submission) {
            try {
                $refreshed = $this->refreshStatus($submission);
                $result['refreshed']++;

                match ($refreshed->status) {
                    'accepted' => $result['accepted']++,
                    'rejected' => $result['rejected']++,
                    default => $result['still_pending']++,
                };
            } catch (RuntimeException) {
                $result['failed']++;
            }
        }

        return $result;
    }

    public function canRetry(KsefSubmission $submission): bool
    {
        return in_array($submission->status, [
            'failed',
            'missing_configuration',
            'requires_configuration',
        ], true);
    }

    private function runtimeFailureStatus(RuntimeException $exception): string
    {
        $message = $exception->getMessage();

        if (str_contains($message, 'Brak tokena')) {
            return 'missing_configuration';
        }

        if (str_contains($message, 'Skonfiguruj bramkę') || str_contains($message, 'etap szyfrowania')) {
            return 'requires_configuration';
        }

        return 'failed';
    }

    private function syncAcceptedInvoice(KsefSubmission $submission, string $ksefNumber): void
    {
        $invoice = $submission->invoice;

        if ($invoice === null) {
            return;
        }

        $metadata = $invoice->metadata ?? [];
        $acceptedAt = ($submission->accepted_at ?? now())->toISOString();

        $metadata['ksef'] = array_filter([
            'number' => $ksefNumber,
            'reference_number' => $submission->reference_number,
            'submission_id' => $submission->id,
            'accepted_at' => $acceptedAt,
            'environment' => $submission->environment,
        ], fn ($value): bool => $value !== null && $value !== '');

        if (
            filled($invoice->external_order_id)
            && in_array(data_get($metadata, 'woocommerce_upload.status'), ['success', 'stale'], true)
        ) {
            data_set($metadata, 'woocommerce_upload.status', 'stale');
            data_set($metadata, 'woocommerce_upload.requires_resend', true);
            data_set($metadata, 'woocommerce_upload.stale_since', now()->toISOString());
            data_set($metadata, 'woocommerce_upload.stale_reason', 'ksef_number_accepted');
            data_set($metadata, 'woocommerce_upload.ksef_number', $ksefNumber);
            data_set($metadata, 'woocommerce_upload.ksef_reference_number', $submission->reference_number);
        }

        $invoice->update([
            'ksef_number' => $ksefNumber,
            'metadata' => $metadata,
        ]);
    }

    /**
     * @param array<string, mixed> $response
     */
    private function resolveRemoteStatus(array $response): string
    {
        $ksefNumber = (string) data_get($response, 'ksefNumber', data_get($response, 'ksef_number', ''));

        if ($ksefNumber !== '') {
            return 'accepted';
        }

        $status = strtolower((string) data_get($response, 'status', data_get($response, 'state', data_get($response, 'processingStatus', 'submitted'))));

        if (str_contains($status, 'accept') || str_contains($status, 'accepted') || str_contains($status, 'success')) {
            return 'accepted';
        }

        if (str_contains($status, 'reject') || str_contains($status, 'failed') || str_contains($status, 'error')) {
            return 'rejected';
        }

        return 'submitted';
    }
}
