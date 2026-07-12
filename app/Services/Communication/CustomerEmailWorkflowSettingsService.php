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
                    'enabled' => array_key_exists('enabled', $override)
                        ? (bool) $override['enabled']
                        : (bool) $definition['enabled'],
                ]];
            })
            ->all();
    }

    /**
     * @param  array<string, mixed>  $rows
     * @return array<string, array{enabled:bool,stage:string,subject:string,body:string}>
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
            ];
        }

        AppSetting::query()->updateOrCreate(['key' => self::KEY], ['value' => $payload]);

        return $payload;
    }

    public function isEnabled(string $trigger): bool
    {
        return (bool) ($this->data()[$trigger]['enabled'] ?? true);
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

    /** @return array<string, array<string, mixed>> */
    private function definitions(): array
    {
        return [
            'order_created' => $this->mail(
                'order', 'payment', 'Potwierdzenie złożenia zamówienia',
                'Nowe zamówienie oczekujące na płatność',
                'Pierwsza wiadomość po złożeniu zamówienia. Zawiera produkty, kwotę, dostawę i — jeśli jest dostępny — przycisk płatności.',
                'Dziękujemy za zamówienie {{order_number}}',
                "Otrzymaliśmy Twoje zamówienie i zapisaliśmy wszystkie szczegóły. Jeżeli wybrana płatność nie została jeszcze ukończona, możesz bezpiecznie wrócić do niej przyciskiem poniżej.\n\nO kolejnym etapie poinformujemy Cię osobną wiadomością.",
            ),
            'order_on_hold' => $this->mail(
                'order', 'payment', 'Zamówienie wstrzymane / oczekuje na płatność',
                'Po przejściu zamówienia na status on-hold',
                'Informuje, że realizacja jeszcze nie ruszyła i wyjaśnia klientowi kolejny krok.',
                'Zamówienie {{order_number}} czeka na płatność',
                "Zamówienie jest zapisane, ale jego realizacja pozostaje wstrzymana do czasu zaksięgowania płatności.\n\nJeśli płatność została już wykonana, nie musisz nic robić — potwierdzenie wyślemy po jej odnotowaniu.",
            ),
            'order_received' => $this->mail(
                'order', 'order', 'Płatność potwierdzona / przyjęte do realizacji',
                'Po opłaceniu lub przekazaniu do realizacji',
                'Potwierdza płatność albo rozpoczęcie realizacji i pokazuje pełne podsumowanie zamówienia.',
                'Płatność przyjęta — realizujemy zamówienie {{order_number}}',
                "Płatność została potwierdzona, a zamówienie trafiło do realizacji. Teraz kompletujemy produkty i przygotowujemy je do bezpiecznej wysyłki.\n\nDam Ci znać, gdy paczka będzie gotowa.",
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
                'Po zmianie statusu na failed',
                'Wyjaśnia brak płatności i prowadzi klienta do ponownej próby.',
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
                "Zgłoszenie zostało zapisane. Przygotuj produkty do odesłania zgodnie z instrukcją zwrotu i zachowaj potwierdzenie nadania.\n\nPoinformujemy Cię, gdy paczka dotrze do magazynu.",
            ),
            'return_approved' => $this->mail(
                'return', 'return', 'Zwrot zaakceptowany',
                'Po zaakceptowaniu zgłoszenia przez obsługę',
                'Potwierdza, że zgłoszenie spełnia warunki i może zostać odesłane.',
                'Zwrot {{return_number}} został zaakceptowany',
                "Zgłoszenie zwrotu zostało zaakceptowane. Możesz wysłać wskazane produkty zgodnie z otrzymaną instrukcją.\n\nO przyjęciu paczki i rozpoczęciu rozliczenia poinformujemy w kolejnych wiadomościach.",
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
                'Po zaksięgowaniu przyjęcia zwrotu',
                'Wysyłane, gdy magazyn przyjmie produkty zwracane przez klienta.',
                'Paczka zwrotna {{return_number}} dotarła do nas',
                "Paczka została przyjęta przez magazyn. Teraz sprawdzamy zgodność produktów ze zgłoszeniem i przygotowujemy rozliczenie.\n\nO wyniku weryfikacji i wypłacie środków poinformujemy osobno.",
            ),
            'return_correction_issued' => $this->mail(
                'return', 'return', 'Dokument korekty wystawiony',
                'Po wystawieniu korekty, przed wypłatą',
                'Precyzyjnie oddziela wystawienie dokumentu od faktycznej wypłaty środków.',
                'Wystawiliśmy korektę dla zwrotu {{return_number}}',
                "Weryfikacja zwrotu została zakończona i wystawiliśmy dokument korygujący {{invoice_number}}. To etap księgowy poprzedzający wypłatę.\n\nOsobno potwierdzimy przekazanie środków do banku lub operatora płatności.",
            ),
            'return_payout_queued' => $this->mail(
                'return', 'return', 'Wypłata zwrotu rozpoczęta',
                'Po dodaniu do kolejki wypłat',
                'Informuje o zleceniu wypłaty, ale nie obiecuje jeszcze zaksięgowania środków.',
                'Rozpoczęliśmy wypłatę za zwrot {{return_number}}',
                "Zleciliśmy zwrot środków zgodnie z metodą płatności. Bank lub operator płatności może potrzebować kilku dni roboczych na zaksięgowanie kwoty.\n\nDokument rozliczeniowy: {{invoice_number}}.",
            ),
            'return_refunded' => $this->mail(
                'return', 'return', 'Wypłata zwrotu zakończona',
                'Dopiero po potwierdzeniu wypłaty',
                'Końcowy mail wysyłany wyłącznie po potwierdzeniu, że środki zostały wypłacone.',
                'Zwrot {{return_number}} został rozliczony',
                "Rozliczenie zwrotu zostało zakończone, a środki wysłane zgodnie z metodą płatności. Data ich pojawienia się na rachunku zależy od banku.\n\nDokument rozliczeniowy: {{invoice_number}}.",
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
    ): array {
        return compact('context', 'scenario', 'name', 'stage', 'description', 'subject', 'body', 'enabled');
    }
}
