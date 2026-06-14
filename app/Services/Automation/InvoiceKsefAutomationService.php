<?php

declare(strict_types=1);

namespace App\Services\Automation;

use App\Jobs\SubmitInvoiceToKsefJob;
use App\Models\Invoice;
use App\Models\KsefSubmission;
use App\Services\Ksef\KsefEligibilityService;
use App\Services\Ksef\KsefSubmissionService;
use RuntimeException;
use Throwable;

final class InvoiceKsefAutomationService
{
    public function __construct(
        private readonly DocumentAutomationSettingsService $settings,
        private readonly KsefSubmissionService $submissions,
        private readonly KsefEligibilityService $eligibility,
    ) {}

    public function queueAfterInvoiceIssued(Invoice $invoice): ?KsefSubmission
    {
        if (! $this->settings->actionEnabled('invoice.issued', 'invoice.ksef.submit')) {
            return null;
        }

        if (! $this->eligibility->shouldSend($invoice)) {
            return null;
        }

        try {
            $submission = $this->submissions->prepare($invoice);
        } catch (RuntimeException $exception) {
            $this->storeWarning($invoice, $exception->getMessage());

            return null;
        }

        if (! in_array($submission->status, ['submitted', 'accepted'], true)) {
            SubmitInvoiceToKsefJob::dispatch($submission->id);
        }

        return $submission;
    }

    private function storeWarning(Invoice $invoice, string $message): void
    {
        try {
            $invoice = Invoice::query()->findOrFail($invoice->id);
            $warnings = (array) data_get($invoice->metadata, 'automation_warnings', []);
            $warnings[] = [
                'type' => 'invoice_ksef_queue_after_issue',
                'message' => $message,
                'created_at' => now()->toISOString(),
            ];

            $invoice->update([
                'metadata' => array_merge($invoice->metadata ?? [], [
                    'automation_warnings' => array_slice($warnings, -10),
                ]),
            ]);
        } catch (Throwable) {
            // Faktura została już wystawiona; błąd zapisu ostrzeżenia nie może jej blokować.
        }
    }
}
