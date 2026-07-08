<?php

declare(strict_types=1);

namespace App\Services\Communication;

use App\Mail\CustomerMessageMail;
use App\Models\CustomerMessage;
use App\Models\ExternalOrder;
use App\Models\ReturnCase;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

final class CustomerCommunicationService
{
    public const TYPE_AUTOMATED = 'automated';
    public const TYPE_MANUAL = 'manual';

    public function __construct(
        private readonly MailSettingsService $mailSettings,
    ) {
    }

    public function sendManualForOrder(ExternalOrder $order, string $subject, string $body): CustomerMessage
    {
        $recipient = $this->orderRecipient($order);

        if ($recipient['email'] === null) {
            throw new RuntimeException('Zamówienie nie ma adresu e-mail klienta.');
        }

        return $this->createAndSend([
            'external_order_id' => $order->id,
            'type' => self::TYPE_MANUAL,
            'trigger' => 'manual_order_message',
            'recipient_email' => $recipient['email'],
            'recipient_name' => $recipient['name'],
            'subject' => trim($subject),
            'body' => trim($body),
            'metadata' => [
                'order_number' => $this->orderNumber($order),
            ],
        ], true);
    }

    public function sendManualForReturn(ReturnCase $returnCase, string $subject, string $body): CustomerMessage
    {
        $returnCase->loadMissing('externalOrder');
        $recipient = $this->returnRecipient($returnCase);

        if ($recipient['email'] === null) {
            throw new RuntimeException('Zwrot nie ma adresu e-mail klienta.');
        }

        return $this->createAndSend([
            'return_case_id' => $returnCase->id,
            'external_order_id' => $returnCase->external_order_id,
            'type' => self::TYPE_MANUAL,
            'trigger' => 'manual_return_message',
            'recipient_email' => $recipient['email'],
            'recipient_name' => $recipient['name'],
            'subject' => trim($subject),
            'body' => trim($body),
            'metadata' => [
                'return_number' => $returnCase->number,
                'order_number' => $returnCase->externalOrder instanceof ExternalOrder
                    ? $this->orderNumber($returnCase->externalOrder)
                    : null,
            ],
        ], true);
    }

    /**
     * Wysyłka automatyczna nie przerywa operacji magazynowych ani importu.
     */
    public function sendOrderStatus(ExternalOrder $order, string $trigger, array $context = []): ?CustomerMessage
    {
        if ($this->alreadySentForOrder($order, $trigger)) {
            return null;
        }

        $recipient = $this->orderRecipient($order);
        $content = $this->orderStatusContent($order, $trigger, $context);

        return $this->createAutomated([
            'external_order_id' => $order->id,
            'trigger' => $trigger,
            'recipient_email' => $recipient['email'],
            'recipient_name' => $recipient['name'],
            'subject' => $content['subject'],
            'body' => $content['body'],
            'metadata' => array_merge($context, [
                'order_number' => $this->orderNumber($order),
            ]),
        ]);
    }

    /**
     * Wysyłka automatyczna nie przerywa obsługi zwrotu.
     */
    public function sendReturnStatus(ReturnCase $returnCase, string $trigger, array $context = []): ?CustomerMessage
    {
        if ($this->alreadySentForReturn($returnCase, $trigger)) {
            return null;
        }

        $returnCase->loadMissing('externalOrder');
        $recipient = $this->returnRecipient($returnCase);
        $content = $this->returnStatusContent($returnCase, $trigger, $context);

        return $this->createAutomated([
            'return_case_id' => $returnCase->id,
            'external_order_id' => $returnCase->external_order_id,
            'trigger' => $trigger,
            'recipient_email' => $recipient['email'],
            'recipient_name' => $recipient['name'],
            'subject' => $content['subject'],
            'body' => $content['body'],
            'metadata' => array_merge($context, [
                'return_number' => $returnCase->number,
                'order_number' => $returnCase->externalOrder instanceof ExternalOrder
                    ? $this->orderNumber($returnCase->externalOrder)
                    : null,
            ]),
        ]);
    }

    /**
     * @param array<string, mixed> $attributes
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
     * @param array<string, mixed> $attributes
     */
    private function createAndSend(array $attributes, bool $throwOnFailure): CustomerMessage
    {
        $message = CustomerMessage::query()->create(array_merge($attributes, [
            'direction' => 'outgoing',
            'status' => 'pending',
        ]));

        try {
            $this->mailSettings->apply();

            Mail::to($message->recipient_email, $message->recipient_name ?: null)
                ->send(new CustomerMessageMail($message));

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

    private function alreadySentForOrder(ExternalOrder $order, string $trigger): bool
    {
        if ($trigger === 'order_partial_created') {
            return false;
        }

        return CustomerMessage::query()
            ->where('external_order_id', $order->id)
            ->where('type', self::TYPE_AUTOMATED)
            ->where('trigger', $trigger)
            ->whereIn('status', ['pending', 'sent', 'skipped'])
            ->exists();
    }

    private function alreadySentForReturn(ReturnCase $returnCase, string $trigger): bool
    {
        return CustomerMessage::query()
            ->where('return_case_id', $returnCase->id)
            ->where('type', self::TYPE_AUTOMATED)
            ->where('trigger', $trigger)
            ->whereIn('status', ['pending', 'sent', 'skipped'])
            ->exists();
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
     * @param array<string, mixed>|null $data
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
     * @param array<string, mixed> $context
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
            'order_courier_picked_up' => [
                'subject' => "Paczka zamówienia {$number} została odebrana przez kuriera",
                'body' => "Dzień dobry,\n\npaczka zamówienia {$number} została odebrana przez kuriera i jest w drodze.{$trackingLine}",
            ],
            'order_partial_created' => [
                'subject' => "Zamówienie {$number} zostało podzielone",
                'body' => "Dzień dobry,\n\nczęść produktów z zamówienia {$number} została wydzielona do osobnej wysyłki. Numer zamówienia częściowego: ".($context['child_order_number'] ?? 'w przygotowaniu').".",
            ],
            default => [
                'subject' => "Aktualizacja zamówienia {$number}",
                'body' => "Dzień dobry,\n\nstatus zamówienia {$number} został zaktualizowany.",
            ],
        };
    }

    /**
     * @param array<string, mixed> $context
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
}
