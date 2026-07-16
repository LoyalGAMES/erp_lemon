<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

final class InvoiceEppExportMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        private readonly string $content,
        private readonly string $filename,
        private readonly string $periodLabel,
    ) {}

    public function build(): self
    {
        return $this
            ->subject('Eksport EPP faktur: '.$this->periodLabel)
            ->text('emails.invoice-epp-export-text', ['periodLabel' => $this->periodLabel])
            ->attachData($this->content, $this->filename, ['mime' => 'application/octet-stream']);
    }
}
