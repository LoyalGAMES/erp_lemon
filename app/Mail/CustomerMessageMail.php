<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\CustomerMessage;
use App\Services\Communication\MailSettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

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
        return $this
            ->subject($this->customerMessage->subject)
            ->view('emails.customer-message', [
                'customerMessage' => $this->customerMessage,
                'messageBody' => $this->customerMessage->body,
                'mailLayout' => app(MailSettingsService::class)->data(),
            ]);
    }
}
