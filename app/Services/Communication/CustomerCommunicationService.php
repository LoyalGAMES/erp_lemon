<?php

declare(strict_types=1);

namespace App\Services\Communication;

use App\Mail\CustomerMessageMail;
use App\Models\Customer;
use App\Models\CustomerMessage;
use App\Models\CustomerPayment;
use App\Models\ExternalOrder;
use App\Models\ReturnCase;
use App\Services\Payments\PaymentMethodClassifier;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

final class CustomerCommunicationService
{
    public const TYPE_AUTOMATED = 'automated';

    public const TYPE_MANUAL = 'manual';

    public function __construct(
        private readonly MailSettingsService $mailSettings,
        private readonly EmailTemplateRenderer $templateRenderer,
        private readonly CustomerEmailWorkflowSettingsService $emailWorkflow,
        private readonly CustomerMailContextService $mailContext,
        private readonly CustomerMailPresentationService $mailPresentation,
        private readonly PaymentMethodClassifier $paymentMethods,
    ) {}

    public function sendManualForOrder(ExternalOrder $order, string $subject, string $body): CustomerMessage
    {
        $recipient = $this->orderRecipient($order);

        if ($recipient['email'] === null) {
            throw new RuntimeException('Zamówienie nie ma adresu e-mail klienta.');
        }

        $templateContext = $this->mailContext->forOrder($order, $recipient, 'manual_order_message');

        return $this->createAndSend([
            'customer_id' => $order->customer_id,
            'external_order_id' => $order->id,
            'type' => self::TYPE_MANUAL,
            'trigger' => 'manual_order_message',
            'recipient_email' => $recipient['email'],
            'recipient_name' => $recipient['name'],
            'subject' => $this->renderTemplate(trim($subject), $templateContext),
            'body' => $this->renderTemplate(trim($body), $templateContext),
            'metadata' => $templateContext,
        ], true);
    }

    public function sendManualForReturn(ReturnCase $returnCase, string $subject, string $body): CustomerMessage
    {
        $returnCase->loadMissing('externalOrder');
        $recipient = $this->returnRecipient($returnCase);

        if ($recipient['email'] === null) {
            throw new RuntimeException('Zwrot nie ma adresu e-mail klienta.');
        }

        $templateContext = $this->mailContext->forReturn($returnCase, $recipient, 'manual_return_message');

        return $this->createAndSend([
            'customer_id' => $returnCase->externalOrder?->customer_id,
            'return_case_id' => $returnCase->id,
            'external_order_id' => $returnCase->external_order_id,
            'type' => self::TYPE_MANUAL,
            'trigger' => 'manual_return_message',
            'recipient_email' => $recipient['email'],
            'recipient_name' => $recipient['name'],
            'subject' => $this->renderTemplate(trim($subject), $templateContext),
            'body' => $this->renderTemplate(trim($body), $templateContext),
            'metadata' => $templateContext,
        ], true);
    }

    public function sendPaymentReminderForOrder(ExternalOrder $order, string $paymentUrl): CustomerMessage
    {
        if (in_array($this->paymentMethods->category($order), [
            PaymentMethodClassifier::CASH_ON_DELIVERY,
            PaymentMethodClassifier::BANK_TRANSFER,
        ], true)) {
            throw new RuntimeException('To zamówienie nie wymaga płatności online — przypomnienie z linkiem nie zostało wysłane.');
        }

        $recipient = $this->orderRecipient($order);

        if ($recipient['email'] === null) {
            throw new RuntimeException('Zamówienie nie ma adresu e-mail klienta.');
        }

        $paymentUrl = trim($paymentUrl);
        $scheme = mb_strtolower((string) parse_url($paymentUrl, PHP_URL_SCHEME));

        if (filter_var($paymentUrl, FILTER_VALIDATE_URL) === false || ! in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException('Brak poprawnego linku do płatności dla tego zamówienia.');
        }

        $templateContext = $this->mailContext->forOrder($order, $recipient, 'manual_payment_reminder', [
            'payment_url' => $paymentUrl,
            'amount' => number_format((float) $order->total_gross, 2, ',', ' '),
        ]);
        $content = $this->renderContent(
            $this->emailWorkflow->contentFor('manual_payment_reminder') ?? [
                'subject' => 'Przypomnienie o płatności za zamówienie {{order_number}}',
                'body' => "Dzień dobry,\n\nnie odnotowaliśmy jeszcze płatności za zamówienie {{order_number}} w kwocie {{amount}} {{currency}}. Użyj bezpiecznego przycisku poniżej, aby dokończyć płatność. Jeżeli płatność została już wykonana, zignoruj tę wiadomość.",
            ],
            $templateContext,
        );

        if (! $this->emailWorkflow->isEnabled('manual_payment_reminder')) {
            throw new RuntimeException('Wysyłka przypomnienia o płatności jest wyłączona w ustawieniach maili.');
        }

        return $this->createAndSend([
            'customer_id' => $order->customer_id,
            'external_order_id' => $order->id,
            'type' => self::TYPE_MANUAL,
            'trigger' => 'manual_payment_reminder',
            'recipient_email' => $recipient['email'],
            'recipient_name' => $recipient['name'],
            'subject' => $content['subject'],
            'body' => $content['body'],
            'metadata' => $templateContext,
        ], true);
    }

    /**
     * Wysyła jednorazowe zaproszenie po zakupie gościnnym. Samo otwarcie
     * wiadomości ani linku nie przypisuje zamówienia do żadnego konta.
     */
    public function sendGuestAccountInvitation(
        Customer $customer,
        ExternalOrder $order,
        string $claimUrl,
    ): ?CustomerMessage {
        $claimUrl = trim($claimUrl);
        $scheme = mb_strtolower((string) parse_url($claimUrl, PHP_URL_SCHEME));

        if (filter_var($claimUrl, FILTER_VALIDATE_URL) === false || ! in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException('Nie udało się przygotować bezpiecznego linku do założenia konta.');
        }

        $message = Cache::lock($this->deduplicationLockKey('order', $order->id, 'guest_account_invitation'), 180)
            ->get(function () use ($customer, $order, $claimUrl): ?CustomerMessage {
                if ($this->alreadySentForOrder($order, 'guest_account_invitation')) {
                    return null;
                }

                $recipient = $this->customerRecipient($customer, $order);
                $templateContext = $this->mailContext->forCustomer(
                    $customer,
                    'guest_account_invitation',
                    $order,
                    [
                        'action_label' => 'Załóż konto i przypisz zamówienie',
                        'action_url' => $claimUrl,
                    ],
                );
                $content = $this->renderContent(
                    $this->emailWorkflow->contentFor('guest_account_invitation') ?? [
                        'subject' => 'Zamówienie {{order_number}} jest zapisane — załóż konto',
                        'body' => "Zamówienie zostało złożone bez zakładania konta — nic straconego. Załóż konto, aby mieć dostęp do historii zamówień i korzystać z programu lojalnościowego.\n\nPo rejestracji to zamówienie automatycznie pojawi się na Twoim koncie.",
                    ],
                    $templateContext,
                );
                $attributes = [
                    'customer_id' => $customer->id,
                    'external_order_id' => $order->id,
                    'trigger' => 'guest_account_invitation',
                    'recipient_email' => $recipient['email'],
                    'recipient_name' => $recipient['name'],
                    'subject' => $content['subject'],
                    'body' => $content['body'],
                    'metadata' => $templateContext,
                ];

                if (! $this->emailWorkflow->isEnabled('guest_account_invitation')) {
                    return $this->createWorkflowSkipped($attributes);
                }

                return $this->createAutomated($attributes);
            });

        return $message instanceof CustomerMessage ? $message : null;
    }

    /**
     * Potwierdzenie konta jest deduplikowane po kliencie, niezależnie od tego,
     * czy konto powstało podczas checkoutu, bez zamówienia, czy przez claim.
     */
    public function sendCustomerAccountCreated(
        Customer $customer,
        ?ExternalOrder $order = null,
        ?string $accountUrl = null,
    ): ?CustomerMessage {
        $message = Cache::lock($this->deduplicationLockKey('customer', $customer->id, 'customer_account_created'), 180)
            ->get(function () use ($customer, $order, $accountUrl): ?CustomerMessage {
                if ($this->alreadySentForCustomer($customer, 'customer_account_created')) {
                    return null;
                }

                $recipient = $this->customerRecipient($customer, $order);
                $templateContext = $this->mailContext->forCustomer(
                    $customer,
                    'customer_account_created',
                    $order,
                    array_filter([
                        'account_url' => $accountUrl,
                        'action_url' => $accountUrl,
                        'action_label' => 'Przejdź do swojego konta',
                    ], static fn (mixed $value): bool => $value !== null && $value !== ''),
                );
                $content = $this->renderContent(
                    $this->emailWorkflow->contentFor('customer_account_created') ?? [
                        'subject' => 'Twoje konto w {{brand_name}} zostało utworzone',
                        'body' => "Twoje konto jest już aktywne. Możesz zalogować się, sprawdzać historię zamówień i korzystać z programu lojalnościowego.\n\nDla bezpieczeństwa nigdy nie wysyłamy hasła w wiadomości e-mail.",
                    ],
                    $templateContext,
                );
                $attributes = [
                    'customer_id' => $customer->id,
                    'external_order_id' => $order?->id,
                    'trigger' => 'customer_account_created',
                    'recipient_email' => $recipient['email'],
                    'recipient_name' => $recipient['name'],
                    'subject' => $content['subject'],
                    'body' => $content['body'],
                    'metadata' => $templateContext,
                ];

                if (! $this->emailWorkflow->isEnabled('customer_account_created')) {
                    return $this->createWorkflowSkipped($attributes);
                }

                return $this->createAutomated($attributes);
            });

        return $message instanceof CustomerMessage ? $message : null;
    }

    /**
     * Wysyłka automatyczna nie przerywa operacji magazynowych ani importu.
     */
    public function sendOrderStatus(ExternalOrder $order, string $trigger, array $context = []): ?CustomerMessage
    {
        if ($this->orderTriggerCanRepeat($trigger)) {
            return $this->sendOrderStatusWhileLocked($order, $trigger, $context);
        }

        $message = Cache::lock($this->deduplicationLockKey('order', $order->id, $trigger), 180)
            ->get(fn (): ?CustomerMessage => $this->sendOrderStatusWhileLocked($order, $trigger, $context));

        return $message instanceof CustomerMessage ? $message : null;
    }

    /**
     * Persist an automated message without performing network I/O. This is an
     * outbox primitive for business operations that must commit the intent to
     * notify in the same transaction as their local state change.
     *
     * @param  array<string,mixed>  $context
     */
    public function queueOrderStatus(
        ExternalOrder $order,
        string $trigger,
        array $context = [],
        ?string $idempotencyKey = null,
    ): ?CustomerMessage {
        $idempotencyKey = trim((string) $idempotencyKey);

        if ($idempotencyKey !== '') {
            $existing = CustomerMessage::query()
                ->where('external_order_id', $order->id)
                ->where('type', self::TYPE_AUTOMATED)
                ->where('trigger', $trigger)
                ->get()
                ->first(fn (CustomerMessage $message): bool => hash_equals(
                    $idempotencyKey,
                    (string) data_get($message->metadata, 'outbox_idempotency_key', ''),
                ));

            if ($existing instanceof CustomerMessage) {
                return $existing;
            }

            $context['outbox_idempotency_key'] = $idempotencyKey;
        } elseif (! $this->orderTriggerCanRepeat($trigger) && $this->alreadySentForOrder($order, $trigger)) {
            return null;
        }

        $recipient = $this->orderRecipient($order);
        $templateContext = $this->mailContext->forOrder($order, $recipient, $trigger, $context);
        $content = $this->renderContent(
            $this->emailWorkflow->contentFor($trigger) ?? $this->orderStatusContent($order, $trigger, $templateContext),
            $templateContext,
        );
        $attributes = [
            'customer_id' => $order->customer_id,
            'external_order_id' => $order->id,
            'type' => self::TYPE_AUTOMATED,
            'trigger' => $trigger,
            'recipient_email' => $recipient['email'],
            'recipient_name' => $recipient['name'],
            'subject' => $content['subject'],
            'body' => $content['body'],
            'metadata' => $templateContext,
        ];

        if (! $this->emailWorkflow->isEnabled($trigger)) {
            return $this->createWorkflowSkipped($attributes);
        }

        if (blank($recipient['email'])) {
            return CustomerMessage::query()->create(array_merge($attributes, [
                'direction' => 'outgoing',
                'status' => 'skipped',
                'recipient_email' => null,
                'error_message' => 'Brak adresu e-mail klienta.',
            ]));
        }

        return CustomerMessage::query()->create(array_merge($attributes, [
            'direction' => 'outgoing',
            'status' => 'pending',
        ]));
    }

    /** Deliver a message that was committed through queueOrderStatus(). */
    public function deliverQueued(CustomerMessage $message): CustomerMessage
    {
        $message = $message->fresh() ?? $message;

        if (! in_array((string) $message->status, ['pending', 'held', 'failed'], true)) {
            return $message;
        }

        if (! $this->mailSettings->apply()) {
            $deliveryIssue = (string) ($this->mailSettings->data()['delivery_issue']
                ?? 'Wybrana metoda wysyłki nie jest gotowa.');
            $message->update([
                'status' => 'held',
                'failed_at' => null,
                'error_message' => $deliveryIssue.' Wiadomość oczekuje na ręczne ponowienie po poprawieniu ustawień.',
            ]);

            return $message->refresh();
        }

        $this->retryMessage($message);

        return $message->refresh();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function sendOrderStatusWhileLocked(ExternalOrder $order, string $trigger, array $context): ?CustomerMessage
    {
        $paymentCategory = $this->paymentMethods->category($order);

        if ($trigger === 'order_on_hold' && ! ($context['scheduled_unpaid_reminder'] ?? false)) {
            return null;
        }

        if ($trigger === 'order_on_hold' && $paymentCategory === PaymentMethodClassifier::CASH_ON_DELIVERY) {
            return null;
        }

        if ($trigger === 'order_payment_failed' && in_array($paymentCategory, [
            PaymentMethodClassifier::CASH_ON_DELIVERY,
            PaymentMethodClassifier::BANK_TRANSFER,
        ], true)) {
            return null;
        }

        if ($this->alreadySentForOrder($order, $trigger)) {
            return null;
        }

        $recipient = $this->orderRecipient($order);
        $templateContext = $this->mailContext->forOrder($order, $recipient, $trigger, $context);
        $content = $this->renderContent(
            $this->emailWorkflow->contentFor($trigger) ?? $this->orderStatusContent($order, $trigger, $templateContext),
            $templateContext,
        );
        $attributes = [
            'customer_id' => $order->customer_id,
            'external_order_id' => $order->id,
            'trigger' => $trigger,
            'recipient_email' => $recipient['email'],
            'recipient_name' => $recipient['name'],
            'subject' => $content['subject'],
            'body' => $content['body'],
            'metadata' => $templateContext,
        ];

        if (! $this->emailWorkflow->isEnabled($trigger)) {
            return $this->createWorkflowSkipped($attributes);
        }

        return $this->createAutomated($attributes);
    }

    /**
     * Wysyłka automatyczna nie przerywa obsługi zwrotu.
     */
    public function sendReturnStatus(ReturnCase $returnCase, string $trigger, array $context = []): ?CustomerMessage
    {
        if ($this->returnTriggerCanRepeat($trigger)) {
            return $this->sendReturnStatusWhileLocked($returnCase, $trigger, $context);
        }

        $message = Cache::lock($this->deduplicationLockKey('return', $returnCase->id, $trigger), 180)
            ->get(fn (): ?CustomerMessage => $this->sendReturnStatusWhileLocked($returnCase, $trigger, $context));

        return $message instanceof CustomerMessage ? $message : null;
    }

    public function sendReturnSettlement(
        ReturnCase $returnCase,
        ?CustomerPayment $payment,
        ?string $invoiceNumber = null,
    ): ?CustomerMessage {
        $trigger = ! $payment instanceof CustomerPayment
            ? 'return_correction_issued'
            : match (mb_strtolower((string) $payment->status)) {
                'paid', 'settled' => 'return_refunded',
                'pending' => 'return_payout_queued',
                default => null,
            };

        if ($trigger === null) {
            return null;
        }

        return $this->sendReturnStatus($returnCase, $trigger, [
            'invoice_number' => $invoiceNumber,
            'payment_reference' => $payment?->reference,
            'payment_status' => $payment?->status,
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function sendReturnStatusWhileLocked(ReturnCase $returnCase, string $trigger, array $context): ?CustomerMessage
    {
        if ($this->alreadySentForReturn($returnCase, $trigger)) {
            return null;
        }

        $returnCase->loadMissing('externalOrder');
        $recipient = $this->returnRecipient($returnCase);
        $templateContext = $this->mailContext->forReturn($returnCase, $recipient, $trigger, $context);
        $content = $this->renderContent(
            $this->emailWorkflow->contentFor($trigger) ?? $this->returnStatusContent($returnCase, $trigger, $templateContext),
            $templateContext,
        );
        $attributes = [
            'customer_id' => $returnCase->externalOrder?->customer_id,
            'return_case_id' => $returnCase->id,
            'external_order_id' => $returnCase->external_order_id,
            'trigger' => $trigger,
            'recipient_email' => $recipient['email'],
            'recipient_name' => $recipient['name'],
            'subject' => $content['subject'],
            'body' => $content['body'],
            'metadata' => $templateContext,
        ];

        if (! $this->emailWorkflow->isEnabled($trigger)) {
            return $this->createWorkflowSkipped($attributes);
        }

        return $this->createAutomated($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createAutomated(array $attributes): CustomerMessage
    {
        if (blank($attributes['recipient_email'] ?? null)) {
            return CustomerMessage::query()->create(array_merge($attributes, [
                'direction' => 'outgoing',
                'type' => self::TYPE_AUTOMATED,
                'status' => 'skipped',
                'recipient_email' => null,
                'error_message' => 'Brak adresu e-mail klienta.',
            ]));
        }

        try {
            return $this->createAndSend(array_merge($attributes, [
                'type' => self::TYPE_AUTOMATED,
            ]), false);
        } catch (Throwable $exception) {
            return CustomerMessage::query()->create(array_merge($attributes, [
                'direction' => 'outgoing',
                'type' => self::TYPE_AUTOMATED,
                'status' => 'failed',
                'failed_at' => now(),
                'error_message' => $exception->getMessage(),
            ]));
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createWorkflowSkipped(array $attributes): CustomerMessage
    {
        return CustomerMessage::query()->create(array_merge($attributes, [
            'direction' => 'outgoing',
            'type' => self::TYPE_AUTOMATED,
            'status' => 'skipped',
            'error_message' => 'Wysyłka wyłączona w workflow maili.',
        ]));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createAndSend(array $attributes, bool $throwOnFailure): CustomerMessage
    {
        $message = CustomerMessage::query()->create(array_merge($attributes, [
            'direction' => 'outgoing',
            'status' => 'pending',
        ]));

        if (! $this->mailSettings->apply()) {
            $deliveryIssue = (string) ($this->mailSettings->data()['delivery_issue']
                ?? 'Wybrana metoda wysyłki nie jest gotowa.');
            $message->update([
                'status' => 'held',
                'error_message' => $deliveryIssue.' Wiadomość oczekuje na ręczne ponowienie po poprawieniu ustawień.',
            ]);

            if ($throwOnFailure) {
                throw new RuntimeException($deliveryIssue.' Wiadomość została zapisana jako oczekująca i nie została wysłana.');
            }

            return $message->refresh();
        }

        try {
            $layoutSnapshot = $this->captureDeliverySnapshot($message);

            Mail::to($message->recipient_email, $message->recipient_name ?: null)
                ->send(new CustomerMessageMail($message, $layoutSnapshot));

            $message->update([
                'status' => 'sent',
                'sent_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $message->update([
                'status' => 'failed',
                'failed_at' => now(),
                'error_message' => $exception->getMessage(),
            ]);

            if ($throwOnFailure) {
                throw new RuntimeException('Nie udało się wysłać wiadomości: '.$exception->getMessage(), 0, $exception);
            }
        }

        return $message->refresh();
    }

    /**
     * Ponawia niewysłane wiadomości na wyraźne żądanie operatora.
     *
     * @return array{selected:int,sent:int,failed:int}
     */
    public function retryUnsent(int $limit = 100): array
    {
        if (! $this->mailSettings->apply()) {
            $deliveryIssue = (string) ($this->mailSettings->data()['delivery_issue']
                ?? 'Wybrana metoda wysyłki nie jest gotowa.');

            throw new RuntimeException('Nie można ponowić wysyłki. '.$deliveryIssue);
        }

        $limit = max(1, min(500, $limit));
        $timeout = (int) ($this->mailSettings->data()['timeout'] ?? 15);
        $lockSeconds = max(300, min(14400, $limit * ($timeout + 5)));
        $result = Cache::lock('customer-mail:retry-unsent', $lockSeconds)
            ->get(fn (): array => $this->retryUnsentWhileLocked($limit));

        if (! is_array($result)) {
            throw new RuntimeException('Ponawianie niewysłanych wiadomości już trwa. Poczekaj na zakończenie bieżącej operacji.');
        }

        return $result;
    }

    /** @return array{selected:int,sent:int,failed:int} */
    private function retryUnsentWhileLocked(int $limit): array
    {
        $messages = CustomerMessage::query()
            ->where('direction', 'outgoing')
            ->where(function ($query): void {
                $query->whereIn('status', ['held', 'failed'])
                    ->orWhere(function ($query): void {
                        $query->where('status', 'pending')
                            ->where('updated_at', '<=', now()->subMinutes(5));
                    });
            })
            ->oldest('created_at')
            ->limit($limit)
            ->get();

        $sent = 0;
        $failed = 0;

        foreach ($messages as $message) {
            if ($this->retryMessage($message)) {
                $sent++;
            } else {
                $failed++;
            }
        }

        return [
            'selected' => $messages->count(),
            'sent' => $sent,
            'failed' => $failed,
        ];
    }

    private function retryMessage(CustomerMessage $message): bool
    {
        $message->update([
            'status' => 'pending',
            'failed_at' => null,
            'error_message' => null,
        ]);

        try {
            $layoutSnapshot = $this->captureDeliverySnapshot($message);

            Mail::to($message->recipient_email, $message->recipient_name ?: null)
                ->send(new CustomerMessageMail($message, $layoutSnapshot));

            $message->update([
                'status' => 'sent',
                'sent_at' => now(),
                'failed_at' => null,
                'error_message' => null,
            ]);

            return true;
        } catch (Throwable $exception) {
            $message->update([
                'status' => 'failed',
                'failed_at' => now(),
                'error_message' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Persist the exact body and non-secret presentation settings used by this
     * delivery attempt. A failed message gets a fresh snapshot when retried,
     * so a later successful preview always mirrors what was actually sent.
     *
     * @return array<string, string>
     */
    private function captureDeliverySnapshot(CustomerMessage $message): array
    {
        $layout = $this->mailPresentation->snapshotLayout();
        $capturedAt = now();

        $message->forceFill([
            'delivery_snapshot' => [
                'version' => 1,
                'captured_at' => $capturedAt->toIso8601String(),
                'recipient_email' => (string) $message->recipient_email,
                'recipient_name' => (string) $message->recipient_name,
                'subject' => $message->renderedSubject(),
                'layout' => $layout,
            ],
            'rendered_html_snapshot' => $this->mailPresentation->html($message, $layout),
            'rendered_text_snapshot' => $this->mailPresentation->text($message, $layout),
        ])->save();

        return $layout;
    }

    private function alreadySentForOrder(ExternalOrder $order, string $trigger): bool
    {
        if ($this->orderTriggerCanRepeat($trigger)) {
            return false;
        }

        return CustomerMessage::query()
            ->where('external_order_id', $order->id)
            ->where('type', self::TYPE_AUTOMATED)
            ->where('trigger', $trigger)
            ->whereIn('status', ['held', 'pending', 'sent', 'failed', 'skipped'])
            ->exists();
    }

    private function alreadySentForReturn(ReturnCase $returnCase, string $trigger): bool
    {
        if ($this->returnTriggerCanRepeat($trigger)) {
            return false;
        }

        return CustomerMessage::query()
            ->where('return_case_id', $returnCase->id)
            ->where('type', self::TYPE_AUTOMATED)
            ->where('trigger', $trigger)
            ->whereIn('status', ['held', 'pending', 'sent', 'failed', 'skipped'])
            ->exists();
    }

    private function alreadySentForCustomer(Customer $customer, string $trigger): bool
    {
        return CustomerMessage::query()
            ->where('customer_id', $customer->id)
            ->where('type', self::TYPE_AUTOMATED)
            ->where('trigger', $trigger)
            ->whereIn('status', ['held', 'pending', 'sent', 'failed', 'skipped'])
            ->exists();
    }

    private function orderTriggerCanRepeat(string $trigger): bool
    {
        return in_array($trigger, [
            'order_partial_created',
            'order_updated',
            'order_payment_received',
            'order_packed',
            'order_packing_rollback',
        ], true);
    }

    private function returnTriggerCanRepeat(string $trigger): bool
    {
        return in_array($trigger, [
            'exchange_payment_requested',
            'exchange_payment_received',
            'exchange_label_ready',
        ], true);
    }

    private function deduplicationLockKey(string $entity, int $entityId, string $trigger): string
    {
        return sprintf('customer-mail:%s:%d:%s', $entity, $entityId, hash('sha256', $trigger));
    }

    /**
     * @return array{email:?string,name:?string}
     */
    private function orderRecipient(ExternalOrder $order): array
    {
        $email = trim((string) (data_get($order->billing_data, 'email') ?: data_get($order->shipping_data, 'email')));

        return [
            'email' => filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null,
            'name' => $this->personName($order->billing_data) ?: $this->personName($order->shipping_data),
        ];
    }

    /**
     * @return array{email:?string,name:?string}
     */
    private function customerRecipient(Customer $customer, ?ExternalOrder $order = null): array
    {
        $email = trim((string) $customer->email);

        if ($email === '' && $order instanceof ExternalOrder) {
            return $this->orderRecipient($order);
        }

        $name = trim((string) ($customer->display_name
            ?: trim($customer->first_name.' '.$customer->last_name)));

        return [
            'email' => filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null,
            'name' => $name !== '' ? $name : null,
        ];
    }

    /**
     * @return array{email:?string,name:?string}
     */
    private function returnRecipient(ReturnCase $returnCase): array
    {
        $email = trim((string) $returnCase->customer_email);

        if ($email === '' && $returnCase->externalOrder instanceof ExternalOrder) {
            $email = (string) $this->orderRecipient($returnCase->externalOrder)['email'];
        }

        return [
            'email' => filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null,
            'name' => $returnCase->externalOrder instanceof ExternalOrder
                ? $this->orderRecipient($returnCase->externalOrder)['name']
                : null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    private function personName(?array $data): ?string
    {
        $data ??= [];
        $name = trim(implode(' ', array_filter([
            $data['first_name'] ?? null,
            $data['last_name'] ?? null,
            $data['company'] ?? null,
        ])));

        return $name !== '' ? $name : null;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{subject:string,body:string}
     */
    private function orderStatusContent(ExternalOrder $order, string $trigger, array $context): array
    {
        $number = $this->orderNumber($order);
        $trackingNumber = trim((string) ($context['tracking_number'] ?? ''));
        $trackingLine = $trackingNumber !== '' ? "\n\nNumer śledzenia: {$trackingNumber}" : '';

        return match ($trigger) {
            'order_created' => [
                'subject' => "Zamówienie {$number} zostało złożone",
                'body' => "Dzień dobry,\n\notrzymaliśmy zamówienie {$number}. Zamówienie czeka na potwierdzenie płatności lub dalszą obsługę.",
            ],
            'order_received' => [
                'subject' => "Zamówienie {$number} przyjęte do realizacji",
                'body' => "Dzień dobry,\n\nzamówienie {$number} zostało przyjęte do realizacji. Rozpoczynamy kompletowanie produktów.",
            ],
            'order_packed' => [
                'subject' => "Zamówienie {$number} zostało spakowane",
                'body' => "Dzień dobry,\n\nzamówienie {$number} zostało spakowane i czeka na odbiór przez kuriera.",
            ],
            'order_cancelled_problem' => [
                'subject' => "Zamówienie {$number} zostało anulowane",
                'body' => "Dzień dobry,\n\nTwoje zamówienie zostało anulowane, poniżej znajduje się notatka dodana podczas szykowania Twojego zamówienia:\n\n".($context['problem_note'] ?? '')."\n\nJeśli Twoje zamówienie zostało opłacone, środki zostaną zwrócone tą samą metodą płatności.",
            ],
            'order_courier_picked_up' => [
                'subject' => "Paczka zamówienia {$number} została odebrana przez kuriera",
                'body' => "Dzień dobry,\n\npaczka zamówienia {$number} została odebrana przez kuriera i jest w drodze.{$trackingLine}",
            ],
            'order_partial_created' => [
                'subject' => "Zamówienie {$number} zostało podzielone",
                'body' => "Dzień dobry,\n\nczęść produktów z zamówienia {$number} została wydzielona do osobnej wysyłki. Numer zamówienia częściowego: ".($context['child_order_number'] ?? 'w przygotowaniu').'.',
            ],
            default => [
                'subject' => "Aktualizacja zamówienia {$number}",
                'body' => "Dzień dobry,\n\nstatus zamówienia {$number} został zaktualizowany.",
            ],
        };
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{subject:string,body:string}
     */
    private function returnStatusContent(ReturnCase $returnCase, string $trigger, array $context): array
    {
        $number = $returnCase->number;
        $invoiceNumber = trim((string) ($context['invoice_number'] ?? ''));
        $invoiceLine = $invoiceNumber !== '' ? "\n\nNumer dokumentu rozliczeniowego: {$invoiceNumber}" : '';
        $amount = trim((string) ($context['amount'] ?? ''));
        $currency = trim((string) ($context['currency'] ?? 'PLN'));
        $paymentUrl = trim((string) ($context['payment_url'] ?? ''));
        $paymentLine = $paymentUrl !== ''
            ? "\n\nLink do płatności: {$paymentUrl}"
            : "\n\nDane do płatności lub instrukcję dopłaty przekażemy w osobnej informacji od obsługi sklepu.";

        return match ($trigger) {
            'return_waiting_for_package' => [
                'subject' => "Zwrot {$number} został przyjęty do obsługi",
                'body' => "Dzień dobry,\n\nzgłoszenie zwrotu {$number} zostało przyjęte. Zwrot oczekuje teraz na dostarczenie paczki do magazynu.",
            ],
            'return_received_warehouse' => [
                'subject' => "Zwrot {$number} został przyjęty przez magazyn",
                'body' => "Dzień dobry,\n\npaczka zwrotna {$number} została przyjęta przez magazyn. Rozpoczynamy rozliczenie zwrotu.",
            ],
            'return_refunded' => [
                'subject' => "Zwrot {$number} został rozliczony",
                'body' => "Dzień dobry,\n\nzwrot {$number} został rozliczony. Środki zostały przekazane do wypłaty zgodnie z metodą płatności.{$invoiceLine}",
            ],
            'return_payout_queued' => [
                'subject' => "Zwrot {$number} przekazano do wypłaty",
                'body' => "Dzień dobry,\n\nzwrot {$number} został przekazany do wypłaty. Środki zostaną rozliczone zgodnie z metodą płatności.{$invoiceLine}",
            ],
            'exchange_payment_requested' => [
                'subject' => "Dopłata do wymiany {$number}",
                'body' => "Dzień dobry,\n\nwymiana w zgłoszeniu {$number} wymaga dopłaty".($amount !== '' ? " w wysokości {$amount} {$currency}" : '').". Po opłaceniu dopłaty wyślemy produkt wymienny.{$paymentLine}",
            ],
            default => [
                'subject' => "Aktualizacja zwrotu {$number}",
                'body' => "Dzień dobry,\n\nstatus zwrotu {$number} został zaktualizowany.",
            ],
        };
    }

    private function orderNumber(ExternalOrder $order): string
    {
        return (string) ($order->external_number ?: $order->external_id ?: $order->id);
    }

    /**
     * @param  array{subject:string,body:string}  $content
     * @param  array<string, mixed>  $context
     * @return array{subject:string,body:string}
     */
    private function renderContent(array $content, array $context): array
    {
        return [
            'subject' => $this->renderTemplate($content['subject'], $context),
            'body' => $this->renderTemplate($content['body'], $context),
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function renderTemplate(string $template, array $context): string
    {
        return $this->templateRenderer->render($template, $context);
    }
}
