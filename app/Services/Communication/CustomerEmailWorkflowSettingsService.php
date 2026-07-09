<?php

declare(strict_types=1);

namespace App\Services\Communication;

use App\Models\AppSetting;

final class CustomerEmailWorkflowSettingsService
{
    private const KEY = 'customer_email_workflow';

    /**
     * @return array<string, array{
     *     code:string,
     *     context:string,
     *     context_label:string,
     *     name:string,
     *     stage:string,
     *     description:string,
     *     subject:string,
     *     body:string,
     *     editable_content:bool,
     *     enabled:bool
     * }>
     */
    public function data(): array
    {
        $stored = AppSetting::query()
            ->where('key', self::KEY)
            ->value('value');
        $stored = is_array($stored) ? $stored : [];

        return collect($this->definitions())
            ->mapWithKeys(function (array $definition, string $code) use ($stored): array {
                $override = is_array($stored[$code] ?? null) ? $stored[$code] : [];

                return [$code => [
                    'code' => $code,
                    'context' => $definition['context'],
                    'context_label' => $this->contextLabel($definition['context']),
                    'name' => $definition['name'],
                    'stage' => $this->text($override['stage'] ?? $definition['stage'], $definition['stage'], 160),
                    'description' => $definition['description'],
                    'subject' => $this->text($override['subject'] ?? $definition['subject'], $definition['subject'], 160),
                    'body' => $this->text($override['body'] ?? $definition['body'], $definition['body'], 5000),
                    'editable_content' => (bool) ($definition['editable_content'] ?? true),
                    'enabled' => array_key_exists('enabled', $override)
                        ? (bool) $override['enabled']
                        : (bool) $definition['enabled'],
                ]];
            })
            ->all();
    }

    /**
     * @param array<string, mixed> $rows
     * @return array<string, array{enabled:bool,stage:string,subject:string,body:string}>
     */
    public function update(array $rows): array
    {
        $payload = [];
        $current = $this->data();

        foreach ($this->definitions() as $code => $definition) {
            $row = is_array($rows[$code] ?? null)
                ? $rows[$code]
                : ($current[$code] ?? []);
            $payload[$code] = [
                'enabled' => array_key_exists('enabled', $row)
                    ? (bool) $row['enabled']
                    : (bool) $definition['enabled'],
                'stage' => $this->text($row['stage'] ?? null, $definition['stage'], 160),
                'subject' => $this->text($row['subject'] ?? null, $definition['subject'], 160),
                'body' => $this->text($row['body'] ?? null, $definition['body'], 5000),
            ];
        }

        AppSetting::query()->updateOrCreate(
            ['key' => self::KEY],
            ['value' => $payload],
        );

        return $payload;
    }

    public function isEnabled(string $trigger): bool
    {
        $workflow = $this->data();

        return (bool) ($workflow[$trigger]['enabled'] ?? true);
    }

    /**
     * @return array{subject:string,body:string}|null
     */
    public function contentFor(string $trigger): ?array
    {
        $workflow = $this->data();

        if (! isset($workflow[$trigger])) {
            return null;
        }

        return [
            'subject' => $workflow[$trigger]['subject'],
            'body' => $workflow[$trigger]['body'],
        ];
    }

    private function text(mixed $value, string $fallback, int $limit): string
    {
        $text = trim((string) $value);

        return mb_substr($text !== '' ? $text : $fallback, 0, $limit);
    }

    private function contextLabel(string $context): string
    {
        return match ($context) {
            'return' => 'Zwroty',
            'warehouse' => 'Magazyn i WooCommerce',
            default => 'Zamówienia',
        };
    }

    /**
     * @return array<string, array{
     *     context:string,
     *     name:string,
     *     stage:string,
     *     description:string,
     *     subject:string,
     *     body:string,
     *     editable_content?:bool,
     *     enabled:bool
     * }>
     */
    private function definitions(): array
    {
        return [
            'order_created' => [
                'context' => 'order',
                'name' => 'Zamówienie złożone',
                'stage' => 'Po utworzeniu zamówienia w sklepie',
                'description' => 'Wysyłane, gdy do ERP wpada nowe zamówienie, także przed płatnością.',
                'subject' => 'Zamówienie {{order_number}} zostało złożone',
                'body' => "Dzień dobry,\n\notrzymaliśmy zamówienie {{order_number}}. Zamówienie czeka na potwierdzenie płatności lub dalszą obsługę.",
                'enabled' => true,
            ],
            'order_received' => [
                'context' => 'order',
                'name' => 'Zamówienie przyjęte do realizacji',
                'stage' => 'Po opłaceniu lub przekazaniu do realizacji',
                'description' => 'Wysyłane, gdy zamówienie trafia do kompletacji.',
                'subject' => 'Zamówienie {{order_number}} przyjęte do realizacji',
                'body' => "Dzień dobry,\n\nzamówienie {{order_number}} zostało przyjęte do realizacji. Rozpoczynamy kompletowanie produktów.",
                'enabled' => true,
            ],
            'order_packed' => [
                'context' => 'order',
                'name' => 'Zamówienie spakowane',
                'stage' => 'Po kliknięciu pakowania',
                'description' => 'Wysyłane po spakowaniu zamówienia i wygenerowaniu dokumentów/etykiety.',
                'subject' => 'Zamówienie {{order_number}} zostało spakowane',
                'body' => "Dzień dobry,\n\nzamówienie {{order_number}} zostało spakowane i czeka na odbiór przez kuriera.",
                'enabled' => true,
            ],
            'order_courier_picked_up' => [
                'context' => 'order',
                'name' => 'Paczka odebrana przez kuriera',
                'stage' => 'Po oznaczeniu odbioru przez kuriera',
                'description' => 'Wysyłane po potwierdzeniu odbioru paczki przez kuriera lub tracking.',
                'subject' => 'Paczka zamówienia {{order_number}} została odebrana przez kuriera',
                'body' => "Dzień dobry,\n\npaczka zamówienia {{order_number}} została odebrana przez kuriera i jest w drodze.\n\nNumer śledzenia: {{tracking_number}}",
                'enabled' => true,
            ],
            'order_partial_created' => [
                'context' => 'order',
                'name' => 'Zamówienie częściowe',
                'stage' => 'Po podziale zamówienia',
                'description' => 'Wysyłane, gdy ERP wydziela część produktów do osobnej wysyłki.',
                'subject' => 'Zamówienie {{order_number}} zostało podzielone',
                'body' => "Dzień dobry,\n\nczęść produktów z zamówienia {{order_number}} została wydzielona do osobnej wysyłki.\n\nNumer zamówienia częściowego: {{child_order_number}}.",
                'enabled' => true,
            ],
            'order_ready_for_shipment' => [
                'context' => 'warehouse',
                'name' => 'WooCommerce: gotowe do wysyłki',
                'stage' => 'Po spakowaniu zamówienia',
                'description' => 'Pośredni mail WooCommerce wywoływany zmianą statusu na „gotowe do wysyłki”. Wyłączenie zatrzymuje zmianę statusu WooCommerce z ERP, bo to ona uruchamia wiadomość sklepu.',
                'subject' => '',
                'body' => '',
                'editable_content' => false,
                'enabled' => true,
            ],
            'order_shipped' => [
                'context' => 'warehouse',
                'name' => 'WooCommerce: zamówienie wysłane',
                'stage' => 'Po oznaczeniu odbioru przez kuriera',
                'description' => 'Pośredni mail WooCommerce wywoływany zmianą statusu na status wysłany/zakończony. Wyłączenie zatrzymuje zmianę statusu WooCommerce z ERP.',
                'subject' => '',
                'body' => '',
                'editable_content' => false,
                'enabled' => true,
            ],
            'order_packing_rollback' => [
                'context' => 'warehouse',
                'name' => 'WooCommerce: cofnięcie pakowania',
                'stage' => 'Po cofnięciu paczki do pakowania',
                'description' => 'Pośredni mail WooCommerce, jeśli sklep wysyła wiadomość po powrocie zamówienia na status realizacji. Wyłączenie zatrzymuje zmianę statusu WooCommerce z ERP przy cofnięciu pakowania.',
                'subject' => '',
                'body' => '',
                'editable_content' => false,
                'enabled' => true,
            ],
            'return_waiting_for_package' => [
                'context' => 'return',
                'name' => 'Zwrot przyjęty, oczekuje na paczkę',
                'stage' => 'Po przyjęciu zgłoszenia zwrotu',
                'description' => 'Wysyłane po utworzeniu/przyjęciu zwrotu do kolejki oczekującej na magazyn.',
                'subject' => 'Zwrot {{return_number}} został przyjęty do obsługi',
                'body' => "Dzień dobry,\n\nzgłoszenie zwrotu {{return_number}} zostało przyjęte. Zwrot oczekuje teraz na dostarczenie paczki do magazynu.",
                'enabled' => true,
            ],
            'return_received_warehouse' => [
                'context' => 'return',
                'name' => 'Zwrot przyjęty przez magazyn',
                'stage' => 'Po zaksięgowaniu przyjęcia zwrotu',
                'description' => 'Wysyłane, gdy magazyn przyjmie produkty zwracane przez klienta.',
                'subject' => 'Zwrot {{return_number}} został przyjęty przez magazyn',
                'body' => "Dzień dobry,\n\npaczka zwrotna {{return_number}} została przyjęta przez magazyn. Rozpoczynamy rozliczenie zwrotu.",
                'enabled' => true,
            ],
            'return_refunded' => [
                'context' => 'return',
                'name' => 'Zwrot rozliczony',
                'stage' => 'Po wypłacie środków',
                'description' => 'Wysyłane po rozliczeniu zwrotu i wypłacie środków.',
                'subject' => 'Zwrot {{return_number}} został rozliczony',
                'body' => "Dzień dobry,\n\nzwrot {{return_number}} został rozliczony. Środki zostały przekazane do wypłaty zgodnie z metodą płatności.\n\nNumer dokumentu rozliczeniowego: {{invoice_number}}",
                'enabled' => true,
            ],
            'return_payout_queued' => [
                'context' => 'return',
                'name' => 'Zwrot przekazany do wypłaty',
                'stage' => 'Po dodaniu do kolejki wypłat',
                'description' => 'Wysyłane, gdy zwrot trafi do wypłaty, ale środki nie muszą być jeszcze finalnie zaksięgowane.',
                'subject' => 'Zwrot {{return_number}} przekazano do wypłaty',
                'body' => "Dzień dobry,\n\nzwrot {{return_number}} został przekazany do wypłaty. Środki zostaną rozliczone zgodnie z metodą płatności.\n\nNumer dokumentu rozliczeniowego: {{invoice_number}}",
                'enabled' => true,
            ],
            'exchange_payment_requested' => [
                'context' => 'return',
                'name' => 'Dopłata do wymiany',
                'stage' => 'Po zaakceptowaniu wymiany z dopłatą',
                'description' => 'Wysyłane, gdy wymiana wymaga dopłaty klienta.',
                'subject' => 'Dopłata do wymiany {{return_number}}',
                'body' => "Dzień dobry,\n\nwymiana w zgłoszeniu {{return_number}} wymaga dopłaty w wysokości {{amount}} {{currency}}.\n\nLink do płatności: {{payment_url}}\n\nPo zaksięgowaniu płatności nadamy przesyłkę z produktem wymiennym.",
                'enabled' => true,
            ],
        ];
    }
}
