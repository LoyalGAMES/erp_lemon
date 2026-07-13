<?php

declare(strict_types=1);

namespace App\Services\Communication;

use App\Models\AppSetting;

final class CustomerEmailWorkflowSettingsService
{
    private const KEY = 'customer_email_workflow';

    /** @return array<string, array<string, mixed>> */
    public function data(): array
    {
        $stored = AppSetting::query()->where('key', self::KEY)->value('value');
        $stored = is_array($stored) ? $stored : [];

        return collect($this->definitions())
            ->mapWithKeys(function (array $definition, string $code) use ($stored): array {
                $override = is_array($stored[$code] ?? null) ? $stored[$code] : [];

                return [$code => [
                    'code' => $code,
                    'context' => $definition['context'],
                    'context_label' => $definition['context'] === 'return' ? 'Zwroty i wymiany' : 'Zamówienia',
                    'scenario' => $definition['scenario'] ?? $definition['context'],
                    'name' => $definition['name'],
                    'stage' => $this->text($override['stage'] ?? $definition['stage'], $definition['stage'], 160),
                    'description' => $definition['description'],
                    'subject' => $this->text($override['subject'] ?? $definition['subject'], $definition['subject'], 160),
                    'body' => $this->text($override['body'] ?? $definition['body'], $definition['body'], 5000),
                    'editable_content' => true,
                    'reminder_delay_minutes' => $this->minutes(
                        $override['reminder_delay_minutes'] ?? $definition['reminder_delay_minutes'] ?? 0,
                        (int) ($definition['reminder_delay_minutes'] ?? 0),
                    ),
                    'bank_transfer_delay_minutes' => $this->minutes(
                        $override['bank_transfer_delay_minutes'] ?? $definition['bank_transfer_delay_minutes'] ?? 0,
                        (int) ($definition['bank_transfer_delay_minutes'] ?? 0),
                    ),
                    'enabled' => array_key_exists('enabled', $override)
                        ? (bool) $override['enabled']
                        : (bool) $definition['enabled'],
                ]];
            })
            ->all();
    }

    /**
     * @param  array<string, mixed>  $rows
     * @return array<string, array{enabled:bool,stage:string,subject:string,body:string,reminder_delay_minutes:int,bank_transfer_delay_minutes:int}>
     */
    public function update(array $rows): array
    {
        $payload = [];
        $current = $this->data();

        foreach ($this->definitions() as $code => $definition) {
            $row = is_array($rows[$code] ?? null) ? $rows[$code] : ($current[$code] ?? []);
            $payload[$code] = [
                'enabled' => array_key_exists('enabled', $row) ? (bool) $row['enabled'] : (bool) $definition['enabled'],
                'stage' => $this->text($row['stage'] ?? null, $definition['stage'], 160),
                'subject' => $this->text($row['subject'] ?? null, $definition['subject'], 160),
                'body' => $this->text($row['body'] ?? null, $definition['body'], 5000),
                'reminder_delay_minutes' => $this->minutes(
                    $row['reminder_delay_minutes'] ?? null,
                    (int) ($definition['reminder_delay_minutes'] ?? 0),
                ),
                'bank_transfer_delay_minutes' => $this->minutes(
                    $row['bank_transfer_delay_minutes'] ?? null,
                    (int) ($definition['bank_transfer_delay_minutes'] ?? 0),
                ),
            ];
        }

        AppSetting::query()->updateOrCreate(['key' => self::KEY], ['value' => $payload]);

        return $payload;
    }

    public function isEnabled(string $trigger): bool
    {
        return (bool) ($this->data()[$trigger]['enabled'] ?? true);
    }

    public function unpaidReminderDelayMinutes(string $paymentCategory): int
    {
        $workflow = $this->data()['order_on_hold'] ?? [];

        if ($paymentCategory === 'bank_transfer') {
            return max(5, (int) ($workflow['bank_transfer_delay_minutes'] ?? 1440));
        }

        return max(5, (int) ($workflow['reminder_delay_minutes'] ?? 30));
    }

    /** @return array{subject:string,body:string}|null */
    public function contentFor(string $trigger): ?array
    {
        $workflow = $this->data();

        return isset($workflow[$trigger]) ? [
            'subject' => $workflow[$trigger]['subject'],
            'body' => $workflow[$trigger]['body'],
        ] : null;
    }

    private function text(mixed $value, string $fallback, int $limit): string
    {
        $text = trim((string) $value);

        return mb_substr($text !== '' ? $text : $fallback, 0, $limit);
    }

    private function minutes(mixed $value, int $fallback): int
    {
        if (! is_numeric($value)) {
            return max(0, min(10080, $fallback));
        }

        return max(0, min(10080, (int) $value));
    }

    /** @return array<string, array<string, mixed>> */
    private function definitions(): array
    {
        return [
            'order_created' => $this->mail(
                'order', 'payment', 'Potwierdzenie złożenia zamówienia',
                'Bezpośrednio po zapisaniu nowego zamówienia',
                'Pierwsza wiadomość po złożeniu zamówienia. Zawiera produkty, kwotę, dostawę i — jeśli jest dostępny — przycisk płatności.',
                'Dziękujemy za zamówienie {{order_number}}',
                "Otrzymaliśmy Twoje zamówienie i zapisaliśmy wszystkie szczegóły.\n\n{{payment_instruction}}\n\nO kolejnym etapie poinformujemy Cię osobną wiadomością.",
            ),
            'order_on_hold' => $this->mail(
                'order', 'payment', 'Zamówienie wstrzymane / oczekuje na płatność',
                'Po skonfigurowanym opóźnieniu, jeśli zamówienie nadal jest nieopłacone',
                'Domyślnie po 30 min dla płatności online i po 24 h dla przelewu tradycyjnego. Przed wysyłką ERP ponownie sprawdza status oraz wpłaty. Pobranie jest wykluczone.',
                'Zamówienie {{order_number}} czeka na płatność',
                "Zamówienie jest zapisane, ale jego realizacja pozostaje wstrzymana do czasu zaksięgowania płatności.\n\n{{payment_instruction}}\n\nJeśli płatność została już wykonana, nie musisz nic robić — potwierdzenie wyślemy po jej odnotowaniu.",
                reminderDelayMinutes: 30,
                bankTransferDelayMinutes: 1440,
            ),
            'order_received' => $this->mail(
                'order', 'order', 'Zamówienie przyjęte do realizacji',
                'Po opłaceniu lub przekazaniu do realizacji',
                'Potwierdza rozpoczęcie realizacji i poprawnie opisuje płatność online, przelew albo pobranie.',
                'Realizujemy zamówienie {{order_number}}',
                "Zamówienie trafiło do realizacji. Teraz kompletujemy produkty i przygotowujemy je do bezpiecznej wysyłki.\n\n{{payment_instruction}}\n\nDam Ci znać, gdy paczka będzie gotowa.",
            ),
            'order_payment_received' => $this->mail(
                'order', 'order', 'Wpłata ręczna zaksięgowana',
                'Po dodaniu wpłaty w szczegółach zamówienia',
                'Potwierdza klientowi ręcznie zaksięgowaną wpłatę, niezależnie od statusu WooCommerce.',
                'Zaksięgowaliśmy wpłatę za zamówienie {{order_number}}',
                "Wpłata w wysokości {{amount}} {{currency}} została zaksięgowana w zamówieniu. Jeśli zamówienie nie było jeszcze w realizacji, obsługa przekaże je teraz do przygotowania.\n\nDziękujemy — o kolejnych etapach poinformujemy osobno.",
            ),
            'manual_payment_reminder' => $this->mail(
                'order', 'payment', 'Ponowienie prośby o płatność',
                'Po ręcznym użyciu akcji w zamówieniu',
                'Konfigurowalna treść ręcznie wysyłanego przypomnienia z bezpiecznym linkiem do płatności.',
                'Dokończ płatność za zamówienie {{order_number}}',
                "Nie odnotowaliśmy jeszcze płatności za zamówienie {{order_number}} w kwocie {{amount}} {{currency}}. Użyj przycisku poniżej, aby wrócić do bezpiecznej płatności.\n\nJeżeli płatność została już wykonana, zignoruj tę wiadomość.",
            ),
            'order_updated' => $this->mail(
                'order', 'order', 'Zmiana produktów lub wartości zamówienia',
                'Po zapisaniu zmian pozycji zamówienia',
                'Wysyła klientowi aktualne produkty i kwotę po zmianie zamówienia przez obsługę.',
                'Zaktualizowaliśmy zamówienie {{order_number}}',
                "Zgodnie z ustaleniami zmieniliśmy zawartość zamówienia. Poniżej znajdziesz aktualną listę produktów i podsumowanie kwoty.\n\nJeśli coś się nie zgadza, odpowiedz na tę wiadomość.",
            ),
            'order_partial_created' => $this->mail(
                'order', 'split', 'Zamówienie podzielone na przesyłki',
                'Po podziale zamówienia',
                'Wyjaśnia klientowi, że produkty mogą dotrzeć osobno, i podaje numer wydzielonej części.',
                'Zamówienie {{order_number}} wyślemy w częściach',
                "Aby nie opóźniać dostawy, podzieliliśmy zamówienie na osobne przesyłki. Numer wydzielonej części to {{child_order_number}}.\n\nKażda paczka otrzyma własne potwierdzenie wysyłki i numer śledzenia.",
            ),
            'order_packed' => $this->mail(
                'order', 'invoice', 'Zamówienie spakowane',
                'Po zakończeniu pakowania',
                'Paczka jest gotowa, dokument sprzedaży może zostać dołączony, a klient widzi, co zapakowano.',
                'Zamówienie {{order_number}} jest już spakowane',
                "Twoje zamówienie zostało starannie spakowane i czeka na odbiór przez przewoźnika.\n\nGdy kurier przejmie paczkę, otrzymasz numer śledzenia i bezpośredni link do przesyłki.",
            ),
            'order_courier_picked_up' => $this->mail(
                'order', 'shipment', 'Paczka w drodze',
                'Po potwierdzeniu odbioru przez kuriera',
                'Wysyłane po odczytaniu statusu przewoźnika lub ręcznym potwierdzeniu odbioru. Zawiera tracking.',
                'Zamówienie {{order_number}} jest w drodze',
                "Przewoźnik odebrał paczkę z zamówieniem. Od teraz możesz śledzić jej drogę bezpośrednio u kuriera.\n\nStatus śledzenia może pojawić się z kilkuminutowym opóźnieniem.",
            ),
            'order_delivered' => $this->mail(
                'order', 'shipment', 'Przesyłka dostarczona',
                'Po potwierdzeniu doręczenia w trackingu',
                'Domyka komunikację po doręczeniu i podaje łatwy kontakt w razie problemu.',
                'Zamówienie {{order_number}} zostało dostarczone',
                "Według informacji przewoźnika przesyłka została dostarczona. Mamy nadzieję, że wszystko dotarło w idealnym stanie.\n\nJeżeli paczki nie ma lub coś się nie zgadza, odpowiedz na tę wiadomość — pomożemy.",
            ),
            'order_packing_rollback' => $this->mail(
                'order', 'order', 'Paczka cofnięta do przygotowania',
                'Po cofnięciu zakończonego pakowania',
                'Sprostowanie, gdy po mailu „spakowane” paczka wraca do realizacji.',
                'Jeszcze pracujemy nad zamówieniem {{order_number}}',
                "Paczka wróciła na krótko do etapu przygotowania. Może to wynikać z dodatkowej kontroli jakości lub zmiany w zamówieniu.\n\nNie musisz nic robić. Wyślemy kolejną wiadomość, gdy zamówienie ponownie będzie gotowe.",
            ),
            'order_cancelled' => $this->mail(
                'order', 'order', 'Zamówienie anulowane',
                'Po zmianie statusu na cancelled',
                'Potwierdza anulowanie i informuje, co stanie się z ewentualną płatnością.',
                'Zamówienie {{order_number}} zostało anulowane',
                "Zamówienie zostało anulowane i nie będzie dalej realizowane. Jeśli płatność została pobrana, zwrot środków zostanie rozliczony tą samą metodą płatności.\n\nW razie pytań odpowiedz na tę wiadomość.",
            ),
            'order_payment_failed' => $this->mail(
                'order', 'payment', 'Płatność nieudana',
                'Po zmianie płatności online na failed',
                'Wyjaśnia brak płatności online i prowadzi klienta do ponownej próby. Nie jest wysyłany dla pobrania ani przelewu tradycyjnego.',
                'Płatność za zamówienie {{order_number}} nie powiodła się',
                "Nie udało się potwierdzić płatności. Zamówienie nie trafi do realizacji, dopóki płatność nie zostanie ukończona.\n\nSpróbuj ponownie przyciskiem poniżej lub skontaktuj się z nami, jeśli problem się powtarza.",
            ),
            'order_refunded' => $this->mail(
                'order', 'order', 'Zamówienie zwrócone',
                'Po zmianie statusu zamówienia na refunded',
                'Potwierdza rozliczenie całego zamówienia po stronie sklepu.',
                'Rozliczyliśmy zwrot zamówienia {{order_number}}',
                "Zamówienie zostało oznaczone jako zwrócone. Środki są rozliczane zgodnie z metodą pierwotnej płatności; czas zaksięgowania zależy od banku lub operatora płatności.\n\nZachowaj tę wiadomość jako potwierdzenie.",
            ),
            'return_waiting_for_package' => $this->mail(
                'return', 'return', 'Zgłoszenie zwrotu przyjęte',
                'Po utworzeniu zgłoszenia zwrotu',
                'Potwierdza numer sprawy i pokazuje produkty objęte zgłoszeniem.',
                'Przyjęliśmy zgłoszenie zwrotu {{return_number}}',
                "Zgłoszenie zostało zapisane. Przygotuj wskazane poniżej produkty do odesłania na adres zwrotu podany w wiadomości i zachowaj potwierdzenie nadania.\n\nPoinformujemy Cię, gdy magazyn zaksięguje przyjęcie paczki.",
            ),
            'return_approved' => $this->mail(
                'return', 'return', 'Zwrot zaakceptowany',
                'Po zaakceptowaniu zgłoszenia przez obsługę',
                'Potwierdza, że zgłoszenie spełnia warunki i może zostać odesłane.',
                'Zwrot {{return_number}} został zaakceptowany',
                "Zgłoszenie zwrotu zostało zaakceptowane. Możesz wysłać wskazane produkty zgodnie z otrzymaną instrukcją.\n\nO dalszym etapie poinformujemy Cię jedną zbiorczą wiadomością — po przyjęciu paczki albo po rozpoczęciu jej rozliczenia.",
            ),
            'return_rejected' => $this->mail(
                'return', 'return', 'Zwrot odrzucony',
                'Po odrzuceniu zgłoszenia przez obsługę',
                'Komunikuje decyzję i zachęca do kontaktu w celu wyjaśnienia.',
                'Decyzja w sprawie zwrotu {{return_number}}',
                "Nie możemy zaakceptować tego zgłoszenia zwrotu w obecnej formie. Jeśli potrzebujesz wyjaśnienia albo chcesz uzupełnić informacje, odpowiedz na tę wiadomość.\n\nNasz zespół pomoże znaleźć właściwe rozwiązanie.",
            ),
            'return_label_ready' => $this->mail(
                'return', 'return', 'Etykieta zwrotna gotowa',
                'Po wygenerowaniu etykiety zwrotnej',
                'Wysyła instrukcję nadania; plik etykiety jest dołączany do wiadomości.',
                'Etykieta do zwrotu {{return_number}} jest gotowa',
                "W załączniku znajdziesz etykietę zwrotną. Umieść produkty w bezpiecznym opakowaniu, usuń stare oznaczenia z paczki i naklej nową etykietę w widocznym miejscu.\n\nZachowaj potwierdzenie nadania do zakończenia zwrotu.",
            ),
            'return_received_warehouse' => $this->mail(
                'return', 'return', 'Paczka zwrotna przyjęta w magazynie',
                '10 minut po zaksięgowaniu ostatniego dokumentu RX, jeśli rozliczenie jeszcze nie ruszyło',
                'Wysyłane po zaksięgowaniu wszystkich dokumentów przyjęcia zwrotu, tylko gdy w tym czasie nie wystawiono korekty ani nie rozpoczęto wypłaty.',
                'Paczka zwrotna {{return_number}} dotarła do nas',
                "Paczka została przyjęta przez magazyn. Teraz sprawdzamy zgodność produktów ze zgłoszeniem i przygotowujemy rozliczenie.\n\nO wyniku weryfikacji i wypłacie środków poinformujemy osobno.",
            ),
            'return_correction_issued' => $this->mail(
                'return', 'return', 'Dokument korekty wystawiony',
                'Po wystawieniu korekty, przed wypłatą',
                'Precyzyjnie oddziela wystawienie dokumentu od faktycznej wypłaty środków.',
                'Wystawiliśmy korektę dla zwrotu {{return_number}}',
                "Paczka została przyjęta, wskazane produkty zweryfikowane, a dokument korygujący {{invoice_number}} wystawiony. To etap księgowy poprzedzający wypłatę.\n\nO przekazaniu środków do banku lub operatora płatności poinformujemy osobno.",
            ),
            'return_payout_queued' => $this->mail(
                'return', 'return', 'Wypłata zwrotu rozpoczęta',
                'Po dodaniu do kolejki wypłat',
                'Informuje o zleceniu wypłaty, ale nie obiecuje jeszcze zaksięgowania środków.',
                'Rozpoczęliśmy wypłatę za zwrot {{return_number}}',
                'Paczka została przyjęta, wskazane produkty zweryfikowane, dokument korygujący {{invoice_number}} wystawiony, a zwrot środków zlecony. Bank lub operator płatności może potrzebować kilku dni roboczych na zaksięgowanie kwoty.',
            ),
            'return_refunded' => $this->mail(
                'return', 'return', 'Wypłata zwrotu zakończona',
                'Dopiero po potwierdzeniu wypłaty',
                'Końcowy mail wysyłany wyłącznie po potwierdzeniu, że środki zostały wypłacone.',
                'Zwrot {{return_number}} został rozliczony',
                'Paczka została przyjęta, wskazane produkty zweryfikowane, dokument korygujący {{invoice_number}} wystawiony, a środki wysłane zgodnie z metodą płatności. Data ich pojawienia się na rachunku zależy od banku.',
            ),
            'exchange_payment_requested' => $this->mail(
                'return', 'return', 'Dopłata do wymiany',
                'Po zaakceptowaniu wymiany z dopłatą',
                'Wysyłane, gdy wymiana wymaga dopłaty klienta.',
                'Dopłata do wymiany {{return_number}}',
                "Wymiana wymaga dopłaty w wysokości {{amount}} {{currency}}. Użyj przycisku poniżej, aby bezpiecznie opłacić różnicę.\n\nPo zaksięgowaniu płatności przygotujemy produkt wymienny do wysyłki.",
            ),
            'exchange_payment_received' => $this->mail(
                'return', 'return', 'Dopłata do wymiany zaksięgowana',
                'Po zaksięgowaniu wpłaty klienta',
                'Potwierdza dopłatę i rozpoczęcie przygotowania produktu wymiennego.',
                'Dopłata do wymiany {{return_number}} została zaksięgowana',
                "Otrzymaliśmy dopłatę w wysokości {{amount}} {{currency}}. Rozpoczynamy przygotowanie produktu wymiennego.\n\nKiedy przesyłka będzie gotowa, otrzymasz osobną wiadomość z numerem śledzenia.",
            ),
            'exchange_label_ready' => $this->mail(
                'return', 'shipment', 'Produkt wymienny przygotowany do wysyłki',
                'Po wygenerowaniu przesyłki wymiennej',
                'Potwierdza przygotowanie produktu i przekazuje numer śledzenia przesyłki wymiennej.',
                'Przygotowaliśmy wysyłkę wymiany {{return_number}}',
                "Produkt wymienny został przygotowany do wysyłki. Przesyłkę możesz śledzić przyciskiem poniżej; pierwsze zdarzenie pojawi się po jej zeskanowaniu przez przewoźnika.\n\nJeśli masz pytania dotyczące wymiany, odpowiedz na tę wiadomość.",
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function mail(
        string $context,
        string $scenario,
        string $name,
        string $stage,
        string $description,
        string $subject,
        string $body,
        bool $enabled = true,
        int $reminderDelayMinutes = 0,
        int $bankTransferDelayMinutes = 0,
    ): array {
        return compact('context', 'scenario', 'name', 'stage', 'description', 'subject', 'body', 'enabled') + [
            'reminder_delay_minutes' => $reminderDelayMinutes,
            'bank_transfer_delay_minutes' => $bankTransferDelayMinutes,
        ];
    }
}
