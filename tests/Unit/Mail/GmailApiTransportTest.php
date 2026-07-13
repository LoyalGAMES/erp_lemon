<?php

declare(strict_types=1);

namespace Tests\Unit\Mail;

use App\Mail\Transport\GmailApiTransport;
use App\Models\GoogleMailConnection;
use App\Services\Communication\GoogleWorkspaceOAuthService;
use App\Services\Communication\MailSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

final class GmailApiTransportTest extends TestCase
{
    use RefreshDatabase;

    private const SEND_URL = 'https://gmail.googleapis.com/upload/gmail/v1/users/me/messages/send?uploadType=media';

    public function test_it_sends_the_complete_mime_message_to_the_media_upload_endpoint(): void
    {
        $this->storeConnection('current-access-token');

        Http::fake([
            self::SEND_URL => Http::response(['id' => 'gmail-message-123'], 200),
        ]);

        $email = (new Email)
            ->from(new Address('sender@example.test', 'Sempre Sender'))
            ->to(new Address('customer@example.test', 'Jan Customer'))
            ->replyTo(new Address('reply@example.test', 'Customer Service'))
            ->bcc(new Address('audit@example.test', 'Audit'))
            ->subject('Complete Gmail API message')
            ->text('PLAIN-BODY-MARKER')
            ->html('<html><body><strong>HTML-BODY-MARKER</strong></body></html>')
            ->attach('ATTACHMENT-CONTENT-MARKER', 'document.txt', 'text/plain');

        $sentMessage = $this->transport()->send($email);

        $this->assertNotNull($sentMessage);
        $this->assertSame('gmail-message-123', $sentMessage->getMessageId());
        $this->assertStringContainsString('Gmail API message id: gmail-message-123', $sentMessage->getDebug());

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            $rawMessage = $request->body();
            $headerEnd = strpos($rawMessage, "\r\n\r\n");

            $this->assertSame('POST', $request->method());
            $this->assertSame(self::SEND_URL, $request->url());
            $this->assertTrue($request->hasHeader('Authorization', 'Bearer current-access-token'));
            $this->assertTrue($request->hasHeader('Content-Type', 'message/rfc822'));
            $this->assertIsInt($headerEnd);

            $headers = substr($rawMessage, 0, $headerEnd);

            $this->assertMatchesRegularExpression('/^From: .*sender@example\.test/m', $headers);
            $this->assertMatchesRegularExpression('/^To: .*customer@example\.test/m', $headers);
            $this->assertMatchesRegularExpression('/^Reply-To: .*reply@example\.test/m', $headers);
            $this->assertMatchesRegularExpression('/^Bcc: .*audit@example\.test/m', $headers);
            $this->assertSame(1, preg_match_all('/^Bcc:/m', $headers));
            $this->assertMatchesRegularExpression('/^Subject: Complete Gmail API message/m', $headers);
            $this->assertStringContainsString('multipart/mixed', $rawMessage);
            $this->assertStringContainsString('multipart/alternative', $rawMessage);
            $this->assertStringContainsString('PLAIN-BODY-MARKER', $rawMessage);
            $this->assertStringContainsString('HTML-BODY-MARKER', $rawMessage);
            $this->assertStringContainsString('filename=document.txt', $rawMessage);
            $this->assertStringContainsString(base64_encode('ATTACHMENT-CONTENT-MARKER'), $rawMessage);

            return true;
        });
    }

    public function test_a_401_response_forces_exactly_one_token_refresh_and_one_retry(): void
    {
        $this->storeConnection('expired-access-token', 'persistent-refresh-token');
        config([
            'services.google_workspace.client_id' => 'google-client-id',
            'services.google_workspace.client_secret' => 'google-client-secret',
        ]);

        $sendAuthorizations = [];
        $refreshRequests = 0;

        Http::fake(function (Request $request) use (&$sendAuthorizations, &$refreshRequests) {
            if ($request->url() === 'https://oauth2.googleapis.com/token') {
                $refreshRequests++;

                $this->assertSame('google-client-id', $request['client_id']);
                $this->assertSame('google-client-secret', $request['client_secret']);
                $this->assertSame('refresh_token', $request['grant_type']);
                $this->assertSame('persistent-refresh-token', $request['refresh_token']);

                return Http::response([
                    'access_token' => 'refreshed-access-token',
                    'expires_in' => 3600,
                ], 200);
            }

            $this->assertSame(self::SEND_URL, $request->url());
            $sendAuthorizations[] = $request->header('Authorization')[0] ?? null;

            return count($sendAuthorizations) === 1
                ? Http::response(['error' => ['message' => 'expired']], 401)
                : Http::response(['id' => 'gmail-after-refresh'], 200);
        });

        $sentMessage = $this->transport()->send($this->simpleEmail());

        $this->assertNotNull($sentMessage);
        $this->assertSame('gmail-after-refresh', $sentMessage->getMessageId());
        $this->assertSame(1, $refreshRequests);
        $this->assertSame([
            'Bearer expired-access-token',
            'Bearer refreshed-access-token',
        ], $sendAuthorizations);

        $connection = GoogleMailConnection::query()->firstOrFail();
        $this->assertSame('refreshed-access-token', $connection->accessToken());
        $this->assertSame('persistent-refresh-token', $connection->refreshToken());
    }

    /**
     * @param  array<string, mixed>  $responseBody
     */
    #[DataProvider('nonRetryableErrorProvider')]
    public function test_rate_limit_and_server_failures_are_not_retried_and_expose_only_a_redacted_error(
        int $status,
        array $responseBody,
        string $expectedMessage,
    ): void {
        $this->storeConnection('current-access-token');

        Http::fake([
            self::SEND_URL => Http::response($responseBody, $status),
        ]);

        try {
            $this->transport()->send($this->simpleEmail());
            $this->fail('The Gmail API failure should throw a transport exception.');
        } catch (TransportException $exception) {
            $this->assertSame($expectedMessage, $exception->getMessage());
            $this->assertStringNotContainsString('TOP-SECRET-PROVIDER-DETAIL', $exception->getMessage());
        }

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::SEND_URL);
    }

    /**
     * @return array<string, array{int, array<string, mixed>, string}>
     */
    public static function nonRetryableErrorProvider(): array
    {
        return [
            'rate limit' => [
                429,
                ['error' => ['message' => 'TOP-SECRET-PROVIDER-DETAIL']],
                'Google Workspace osiągnął limit wysyłki. Spróbuj ponownie później.',
            ],
            'temporary server failure' => [
                503,
                ['error' => ['message' => 'TOP-SECRET-PROVIDER-DETAIL']],
                'Gmail API jest chwilowo niedostępne. Wiadomość nie została ponowiona automatycznie.',
            ],
        ];
    }

    private function transport(): GmailApiTransport
    {
        $mailSettings = app(MailSettingsService::class);

        return new GmailApiTransport(
            new GoogleWorkspaceOAuthService($mailSettings),
            $mailSettings,
        );
    }

    private function simpleEmail(): Email
    {
        return (new Email)
            ->from('sender@example.test')
            ->to('customer@example.test')
            ->subject('Gmail API test')
            ->text('Test body');
    }

    private function storeConnection(
        string $accessToken,
        string $refreshToken = 'refresh-token',
    ): GoogleMailConnection {
        return GoogleMailConnection::query()->create([
            'purpose' => GoogleMailConnection::PURPOSE_TRANSACTIONAL_MAIL,
            'google_subject' => 'google-subject',
            'email' => 'sender@example.test',
            'access_token_encrypted' => Crypt::encryptString($accessToken),
            'refresh_token_encrypted' => Crypt::encryptString($refreshToken),
            'access_token_expires_at' => now()->addHour(),
            'granted_scopes' => [GoogleWorkspaceOAuthService::GMAIL_SEND_SCOPE],
            'connected_at' => now(),
            'refreshed_at' => now(),
        ]);
    }
}
