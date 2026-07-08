<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\CustomerMessage;
use App\Services\Communication\MailSettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Symfony\Component\Mime\Email;

class CustomerMessageMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly CustomerMessage $customerMessage,
    ) {
    }

    public function build(): self
    {
        $mailLayout = app(MailSettingsService::class)->data();
        $fromAddress = $mailLayout['from_address'] ?: (string) config('mail.from.address', 'noreply@example.com');
        $fromName = $mailLayout['from_name'] ?: (string) config('mail.from.name', config('app.name', 'Sempre ERP'));
        $replyToAddress = $mailLayout['support_email'] ?: $fromAddress;
        $replyToName = $mailLayout['brand_name'] ?: $fromName;

        return $this
            ->from($fromAddress, $fromName)
            ->replyTo($replyToAddress, $replyToName)
            ->subject($this->customerMessage->subject)
            ->view('emails.customer-message', [
                'customerMessage' => $this->customerMessage,
                'messageBody' => $this->customerMessage->body,
                'mailLayout' => $mailLayout,
            ])
            ->text('emails.customer-message-text', [
                'customerMessage' => $this->customerMessage,
                'messageBody' => $this->customerMessage->body,
                'mailLayout' => $mailLayout,
            ])
            ->withSymfonyMessage(function (Email $email): void {
                $headers = $email->getHeaders();
                $headers->addTextHeader('X-Entity-Ref-ID', 'customer-message-'.$this->customerMessage->id);
                $headers->addTextHeader('X-Auto-Response-Suppress', 'All');

                if ($this->customerMessage->type === 'automated') {
                    $headers->addTextHeader('Auto-Submitted', 'auto-generated');
                }
            });
    }
}
