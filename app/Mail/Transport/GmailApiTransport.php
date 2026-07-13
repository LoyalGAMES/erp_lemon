<?php

declare(strict_types=1);

namespace App\Mail\Transport;

use App\Services\Communication\GoogleWorkspaceOAuthService;
use App\Services\Communication\MailSettingsService;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;

final class GmailApiTransport extends AbstractTransport
{
    private const SEND_URL = 'https://gmail.googleapis.com/upload/gmail/v1/users/me/messages/send?uploadType=media';

    public function __construct(
        private readonly GoogleWorkspaceOAuthService $oauth,
        private readonly MailSettingsService $mailSettings,
    ) {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $rawMessage = $this->rawMessage($message);

        try {
            $response = $this->sendRequest($rawMessage, $this->oauth->accessToken());

            if ($response->status() === 401) {
                $response = $this->sendRequest($rawMessage, $this->oauth->accessToken(forceRefresh: true));
            }
        } catch (TransportException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            throw new TransportException($exception->getMessage(), 0, $exception);
        } catch (\Throwable $exception) {
            throw new TransportException(
                'Nie udało się połączyć z Gmail API.',
                0,
                $exception,
            );
        }

        if (! $response->successful()) {
            throw new TransportException($this->errorMessage($response));
        }

        $messageId = trim((string) $response->json('id', ''));

        if ($messageId !== '') {
            $message->setMessageId($messageId);
            $message->appendDebug('Gmail API message id: '.$messageId);
        }
    }

    public function __toString(): string
    {
        return 'gmail-api';
    }

    private function sendRequest(string $rawMessage, string $accessToken): Response
    {
        return Http::withToken($accessToken)
            ->acceptJson()
            ->timeout($this->timeout())
            ->withBody($rawMessage, 'message/rfc822')
            ->post(self::SEND_URL);
    }

    private function rawMessage(SentMessage $message): string
    {
        $rawMessage = $message->toString();
        $email = MessageConverter::toEmail($message->getOriginalMessage());
        $bcc = $email->getBcc();

        if ($bcc === []) {
            return $rawMessage;
        }

        $headerEmail = (new Email)->bcc(...$bcc);
        $bccHeader = $headerEmail->getHeaders()->get('Bcc')?->toString();
        $separator = strpos($rawMessage, "\r\n\r\n");

        if (! is_string($bccHeader) || $bccHeader === '' || $separator === false) {
            return $rawMessage;
        }

        return substr($rawMessage, 0, $separator)
            ."\r\n".$bccHeader
            .substr($rawMessage, $separator);
    }

    private function errorMessage(Response $response): string
    {
        return match ($response->status()) {
            400 => 'Gmail API odrzuciło wiadomość. Sprawdź adres nadawcy i format maila.',
            401 => 'Autoryzacja Google Workspace wygasła. Połącz konto ponownie.',
            403 => 'Google Workspace zablokował wysyłkę. Sprawdź zakres gmail.send, alias nadawcy i zasady administratora.',
            429 => 'Google Workspace osiągnął limit wysyłki. Spróbuj ponownie później.',
            500, 502, 503, 504 => 'Gmail API jest chwilowo niedostępne. Wiadomość nie została ponowiona automatycznie.',
            default => 'Gmail API zwróciło błąd wysyłki (HTTP '.$response->status().').',
        };
    }

    private function timeout(): int
    {
        return max(3, min(120, (int) ($this->mailSettings->data()['timeout'] ?? 15)));
    }
}
