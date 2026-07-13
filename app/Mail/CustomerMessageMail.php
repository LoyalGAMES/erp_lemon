<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\CustomerMessage;
use App\Models\InvoiceFile;
use App\Models\ShippingLabel;
use App\Services\Communication\CustomerMailPresentationService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mime\Email;

class CustomerMessageMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly CustomerMessage $customerMessage,
        public readonly ?array $mailLayoutOverride = null,
    ) {}

    public function build(): self
    {
        $viewData = app(CustomerMailPresentationService::class)->data(
            $this->customerMessage,
            $this->mailLayoutOverride,
        );
        $mailLayout = $viewData['mailLayout'];
        $fromAddress = $mailLayout['from_address'] ?: (string) config('mail.from.address', 'noreply@example.com');
        $fromName = $mailLayout['from_name'] ?: (string) config('mail.from.name', config('app.name', 'Sempre ERP'));
        $replyToAddress = $mailLayout['reply_to_address'] ?: $fromAddress;
        $replyToName = $mailLayout['brand_name'] ?: $fromName;
        $messageSubject = $viewData['messageSubject'];

        $mail = $this
            ->from($fromAddress, $fromName)
            ->replyTo($replyToAddress, $replyToName)
            ->subject($messageSubject)
            ->view('emails.customer-message', $viewData)
            ->text('emails.customer-message-text', $viewData)
            ->withSymfonyMessage(function (Email $email): void {
                $headers = $email->getHeaders();
                $headers->addTextHeader('X-Entity-Ref-ID', 'customer-message-'.$this->customerMessage->id);
                $headers->addTextHeader('X-Auto-Response-Suppress', 'All');

                if ($this->customerMessage->type === 'automated') {
                    $headers->addTextHeader('Auto-Submitted', 'auto-generated');
                }
            });

        return $this->attachBusinessFiles($mail);
    }

    private function attachBusinessFiles(self $mail): self
    {
        $metadata = (array) $this->customerMessage->metadata;

        foreach (array_slice((array) ($metadata['attachment_invoice_file_ids'] ?? []), 0, 3) as $fileId) {
            $file = InvoiceFile::query()->find((int) $fileId);
            $disk = $file instanceof InvoiceFile && filled($file->disk) ? $file->disk : 'local';

            if (! $file instanceof InvoiceFile || ! Storage::disk($disk)->exists($file->path)) {
                continue;
            }

            $mail->attachFromStorageDisk(
                $disk,
                $file->path,
                basename($file->path),
                ['mime' => $file->mime_type ?: 'application/octet-stream'],
            );
        }

        foreach (array_slice((array) ($metadata['attachment_shipping_label_ids'] ?? []), 0, 2) as $labelId) {
            $label = ShippingLabel::query()->find((int) $labelId);
            $disk = $label instanceof ShippingLabel && filled($label->disk) ? $label->disk : 'local';

            if (! $label instanceof ShippingLabel || ! Storage::disk($disk)->exists($label->path)) {
                continue;
            }

            $mail->attachFromStorageDisk(
                $disk,
                $label->path,
                $label->filename(),
                ['mime' => $label->mime_type ?: 'application/pdf'],
            );
        }

        return $mail;
    }
}
