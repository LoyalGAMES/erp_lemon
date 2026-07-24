<?php

declare(strict_types=1);

namespace App\Services\Automation;

use App\Models\AppSetting;

final class DocumentAutomationSettingsService
{
    private const KEY = 'document_automation';

    /**
     * @return array<string, bool>
     */
    public function data(): array
    {
        $stored = AppSetting::query()
            ->where('key', self::KEY)
            ->value('value');

        $data = array_merge($this->defaults(), is_array($stored) ? $stored : []);
        $rules = is_array($stored) ? (array) ($stored['rules'] ?? []) : [];

        foreach ($this->ruleDefinitions() as $event => $actions) {
            foreach ($actions as $action => $flag) {
                if (array_key_exists($event, $rules) && is_array($rules[$event]) && array_key_exists($action, $rules[$event])) {
                    $data[$flag] = (bool) $rules[$event][$action];
                }
            }
        }

        return collect($this->defaults())
            ->mapWithKeys(fn (bool $default, string $key): array => [$key => (bool) ($data[$key] ?? $default)])
            ->all();
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->defaults());
    }

    public function enabled(string $key): bool
    {
        return (bool) ($this->data()[$key] ?? false);
    }

    public function actionEnabled(string $event, string $action): bool
    {
        $flag = $this->flagFor($event, $action);

        return $flag !== null && $this->enabled($flag);
    }

    /**
     * @return list<array{event:string,label:string,description:string,actions:list<array{action:string,label:string,description:string,enabled:bool}>}>
     */
    public function rules(): array
    {
        $data = $this->data();

        return collect($this->eventLabels())
            ->map(function (array $eventData, string $event) use ($data): array {
                $actions = collect($this->ruleDefinitions()[$event] ?? [])
                    ->map(function (string $flag, string $action) use ($data): array {
                        $actionData = $this->actionLabels()[$action] ?? [
                            'label' => $action,
                            'description' => '',
                        ];

                        return [
                            'action' => $action,
                            'label' => $actionData['label'],
                            'description' => $actionData['description'],
                            'enabled' => (bool) ($data[$flag] ?? false),
                        ];
                    })
                    ->values()
                    ->all();

                return [
                    'event' => $event,
                    'label' => $eventData['label'],
                    'description' => $eventData['description'],
                    'actions' => $actions,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, bool>
     */
    public function update(array $data): array
    {
        $payload = collect($this->defaults())
            ->mapWithKeys(fn (bool $default, string $key): array => [$key => (bool) ($data[$key] ?? false)])
            ->all();

        AppSetting::query()->updateOrCreate(
            ['key' => self::KEY],
            ['value' => $payload],
        );

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, bool>
     */
    public function updateRules(array $rules): array
    {
        $payload = collect($this->defaults())
            ->mapWithKeys(fn (bool $default, string $key): array => [$key => false])
            ->all();
        $storedRules = [];

        foreach ($this->ruleDefinitions() as $event => $actions) {
            $storedRules[$event] = [];

            foreach ($actions as $action => $flag) {
                $eventRules = is_array($rules[$event] ?? null) ? $rules[$event] : [];
                $enabled = (bool) ($eventRules[$action] ?? false);
                $payload[$flag] = $enabled;
                $storedRules[$event][$action] = $enabled;
            }
        }

        AppSetting::query()->updateOrCreate(
            ['key' => self::KEY],
            ['value' => array_merge($payload, ['rules' => $storedRules])],
        );

        return $payload;
    }

    /**
     * Defaults keep the current manual/packing behavior unchanged.
     *
     * @return array<string, bool>
     */
    private function defaults(): array
    {
        return [
            'return_create_rx_on_store' => false,
            'return_post_rx_on_store' => false,
            'return_issue_correction_after_rx_posted' => false,
            'order_create_wz_on_import' => false,
            'packing_create_wz_if_missing_on_pack' => true,
            'packing_post_wz_on_pack' => true,
            'packing_issue_invoice_on_pack' => true,
            'courier_issue_invoice_on_pickup' => false,
            'invoice_queue_ksef_after_issue' => false,
        ];
    }

    private function flagFor(string $event, string $action): ?string
    {
        return $this->ruleDefinitions()[$event][$action] ?? null;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function ruleDefinitions(): array
    {
        return [
            'return.created' => [
                'return.rx.create' => 'return_create_rx_on_store',
                'return.rx.post' => 'return_post_rx_on_store',
            ],
            'warehouse_document.rx.posted' => [
                'return.correction.create' => 'return_issue_correction_after_rx_posted',
            ],
            'order.imported' => [
                'order.wz.create' => 'order_create_wz_on_import',
            ],
            'packing.order.packed' => [
                'order.wz.create_if_missing' => 'packing_create_wz_if_missing_on_pack',
                'order.wz.post' => 'packing_post_wz_on_pack',
                'order.invoice.create_upload' => 'packing_issue_invoice_on_pack',
            ],
            'packing.courier.picked_up' => [
                'order.invoice.create_upload' => 'courier_issue_invoice_on_pickup',
            ],
            'invoice.issued' => [
                'invoice.ksef.submit' => 'invoice_queue_ksef_after_issue',
            ],
        ];
    }

    /**
     * @return array<string, array{label:string,description:string}>
     */
    private function eventLabels(): array
    {
        return [
            'return.created' => [
                'label' => 'Po dodaniu zwrotu',
                'description' => 'Akcje wykonywane bezpośrednio po zapisaniu zwrotu w module zwrotów.',
            ],
            'warehouse_document.rx.posted' => [
                'label' => 'Po przyjęciu zwrotu',
                'description' => 'Akcje wykonywane, gdy wszystkie pozycje zwrotu są przyjęte zgodnie z dyspozycją.',
            ],
            'order.imported' => [
                'label' => 'Po pobraniu zamówienia',
                'description' => 'Akcje wykonywane po imporcie zamówienia z WooCommerce i synchronizacji rezerwacji.',
            ],
            'packing.order.packed' => [
                'label' => 'Po spakowaniu zamówienia',
                'description' => 'Akcje wykonywane po kliknięciu pakowania dla zamówienia.',
            ],
            'packing.courier.picked_up' => [
                'label' => 'Po odbiorze kuriera',
                'description' => 'Akcje wykonywane po oznaczeniu paczek kuriera jako odebrane.',
            ],
            'invoice.issued' => [
                'label' => 'Po wystawieniu faktury',
                'description' => 'Akcje wykonywane po utworzeniu faktury sprzedaży lub faktury korygującej.',
            ],
        ];
    }

    /**
     * @return array<string, array{label:string,description:string}>
     */
    private function actionLabels(): array
    {
        return [
            'return.rx.create' => [
                'label' => 'Przygotuj przyjęcie',
                'description' => 'Utworzy szkic RX tylko dla pozycji wracających na stan, a pozostałe przygotuje bez ruchu magazynowego.',
            ],
            'return.rx.post' => [
                'label' => 'Potwierdź przyjęcie',
                'description' => 'Zaksięguje wymagane RX i potwierdzi pozycje przyjmowane bez zmiany stanu.',
            ],
            'return.correction.create' => [
                'label' => 'Wystaw korektę',
                'description' => 'Wystawi fakturę korygującą, jeśli istnieje faktura sprzedaży do zamówienia.',
            ],
            'order.wz.create' => [
                'label' => 'Utwórz WZ',
                'description' => 'Utworzy szkic WZ z aktywnych rezerwacji dla zamówienia.',
            ],
            'order.wz.create_if_missing' => [
                'label' => 'Utwórz WZ, jeśli go brakuje',
                'description' => 'Awaryjnie utworzy WZ przy pakowaniu, jeśli nie powstał wcześniej.',
            ],
            'order.wz.post' => [
                'label' => 'Zaksięguj WZ',
                'description' => 'Zaksięguje istniejący lub utworzony WZ.',
            ],
            'order.invoice.create_upload' => [
                'label' => 'Wystaw i wyślij fakturę',
                'description' => 'Wystawi fakturę, wygeneruje plik i wyśle go do zamówienia WooCommerce.',
            ],
            'invoice.ksef.submit' => [
                'label' => 'Dodaj fakturę do kolejki KSeF',
                'description' => 'Przygotuje XML FA(3) i doda zgłoszenie do kolejki wysyłki KSeF.',
            ],
        ];
    }
}
