<?php

declare(strict_types=1);

namespace App\Services\Gs1;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Crypt;

final class Gs1SettingsService
{
    private const KEY = 'gs1_configuration';
    private const DEFAULT_BASE_URL = 'https://mojegs1.pl/api/v2';

    /**
     * @return array{
     *     base_url:string,
     *     username:string,
     *     has_password:bool,
     *     password_hint:?string,
     *     company_prefix:string,
     *     next_item_reference:int,
     *     default_gpc_code:?string,
     *     gpc_options:list<array{code:string,label:string,description:string}>,
     *     gpc_options_text:string,
     *     target_market:string,
     *     register_products:bool,
     *     ready:bool
     * }
     */
    public function publicConfiguration(): array
    {
        $stored = $this->stored();
        $username = trim((string) ($stored['username'] ?? ''));
        $companyPrefix = $this->cleanDigits((string) ($stored['company_prefix'] ?? ''));
        $hasPassword = $this->password() !== '';

        return [
            'base_url' => $this->baseUrl(),
            'username' => $username,
            'has_password' => $hasPassword,
            'password_hint' => $this->passwordHint(),
            'company_prefix' => $companyPrefix,
            'next_item_reference' => max(0, (int) ($stored['next_item_reference'] ?? 1)),
            'default_gpc_code' => $this->nullableDigits($stored['default_gpc_code'] ?? null),
            'gpc_options' => $this->gpcOptions($stored['gpc_options'] ?? null, $stored['default_gpc_code'] ?? null),
            'gpc_options_text' => $this->gpcOptionsText($stored['gpc_options'] ?? null, $stored['default_gpc_code'] ?? null),
            'target_market' => strtoupper(trim((string) ($stored['target_market'] ?? 'PL'))) ?: 'PL',
            'register_products' => (bool) ($stored['register_products'] ?? true),
            'ready' => $username !== '' && $hasPassword && $companyPrefix !== '',
        ];
    }

    public function username(): string
    {
        return (string) ($this->stored()['username'] ?? '');
    }

    public function password(): string
    {
        $encrypted = $this->stored()['password_encrypted'] ?? null;

        if (! is_string($encrypted) || trim($encrypted) === '') {
            return '';
        }

        return Crypt::decryptString($encrypted);
    }

    public function baseUrl(): string
    {
        return $this->normalizeBaseUrl($this->stored()['base_url'] ?? self::DEFAULT_BASE_URL);
    }

    /**
     * @param array<string, mixed> $data
     * @return array{before:array<string,mixed>,after:array<string,mixed>}
     */
    public function update(array $data): array
    {
        $before = $this->publicConfiguration();
        $stored = $this->stored();

        $payload = [
            'base_url' => $this->normalizeBaseUrl($data['base_url'] ?? $stored['base_url'] ?? self::DEFAULT_BASE_URL),
            'username' => trim((string) ($data['username'] ?? $stored['username'] ?? '')),
            'password_encrypted' => $stored['password_encrypted'] ?? null,
            'company_prefix' => $this->cleanDigits((string) ($data['company_prefix'] ?? $stored['company_prefix'] ?? '')),
            'next_item_reference' => max(0, (int) ($data['next_item_reference'] ?? $stored['next_item_reference'] ?? 1)),
            'default_gpc_code' => $this->nullableDigits($data['default_gpc_code'] ?? $stored['default_gpc_code'] ?? null),
            'gpc_options' => $this->gpcOptions($data['gpc_options'] ?? $stored['gpc_options'] ?? null, $data['default_gpc_code'] ?? $stored['default_gpc_code'] ?? null),
            'target_market' => strtoupper(trim((string) ($data['target_market'] ?? $stored['target_market'] ?? 'PL'))) ?: 'PL',
            'register_products' => ! empty($data['register_products']),
        ];

        if (! empty($data['clear_password'])) {
            $payload['password_encrypted'] = null;
        }

        $newPassword = trim((string) ($data['password'] ?? ''));
        if ($newPassword !== '') {
            $payload['password_encrypted'] = Crypt::encryptString($newPassword);
        }

        AppSetting::query()->updateOrCreate(
            ['key' => self::KEY],
            ['value' => $payload],
        );

        return [
            'before' => $before,
            'after' => $this->publicConfiguration(),
        ];
    }

    public function incrementNextItemReference(): void
    {
        $stored = $this->stored();
        $stored['next_item_reference'] = max(0, (int) ($stored['next_item_reference'] ?? 1)) + 1;

        AppSetting::query()->updateOrCreate(
            ['key' => self::KEY],
            ['value' => $stored],
        );
    }

    public function gpcLabelForCode(?string $code): ?string
    {
        $digits = $this->nullableDigits($code);

        if ($digits === null) {
            return null;
        }

        foreach ($this->publicConfiguration()['gpc_options'] as $option) {
            if ($option['code'] === $digits) {
                return $option['label'];
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function stored(): array
    {
        $stored = AppSetting::query()
            ->where('key', self::KEY)
            ->value('value');

        return is_array($stored) ? $stored : [];
    }

    private function passwordHint(): ?string
    {
        $password = $this->password();

        if ($password === '') {
            return null;
        }

        return str_repeat('*', min(8, max(4, strlen($password) - 3))) . substr($password, -3);
    }

    private function cleanDigits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    private function nullableDigits(mixed $value): ?string
    {
        $digits = $this->cleanDigits((string) ($value ?? ''));

        return $digits !== '' ? $digits : null;
    }

    /**
     * @return list<array{code:string,label:string,description:string}>
     */
    private function gpcOptions(mixed $value, mixed $defaultGpcCode = null): array
    {
        $options = [];

        if (is_string($value)) {
            $options = $this->parseGpcOptionsText($value);
        } elseif (is_array($value)) {
            $options = collect($value)
                ->filter(fn ($row): bool => is_array($row))
                ->map(fn (array $row): ?array => $this->normalizeGpcOption(
                    (string) ($row['code'] ?? ''),
                    (string) ($row['label'] ?? ''),
                    (string) ($row['description'] ?? ''),
                ))
                ->filter()
                ->values()
                ->all();
        }

        return $this->mergeGpcOptions($this->defaultGpcOptions($defaultGpcCode), $options);
    }

    private function gpcOptionsText(mixed $value, mixed $defaultGpcCode = null): string
    {
        return collect($this->gpcOptions($value, $defaultGpcCode))
            ->map(fn (array $option): string => trim($option['code'] . ' | ' . $option['label'] . ($option['description'] !== '' ? ' | ' . $option['description'] : '')))
            ->implode("\n");
    }

    /**
     * @return list<array{code:string,label:string,description:string}>
     */
    private function parseGpcOptionsText(string $value): array
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = preg_replace('/^\x{FEFF}/u', '', $value) ?? $value;

        return collect(explode("\n", $value))
            ->map(fn (string $line): string => trim(preg_replace('/^\x{FEFF}/u', '', $line) ?? $line))
            ->filter()
            ->map(function (string $line): ?array {
                $parts = preg_split('/\s*[|;]\s*/u', $line, 3) ?: [];
                $code = (string) ($parts[0] ?? '');
                $label = (string) ($parts[1] ?? '');
                $description = (string) ($parts[2] ?? '');

                if ($label === '' && preg_match('/^(\d{8})\s*[-–—]\s*(.+)$/u', $line, $matches)) {
                    $code = $matches[1];
                    $label = $matches[2];
                }

                if ($label === '' && preg_match('/^(\d{8})\s+(.+)$/u', $line, $matches)) {
                    $code = $matches[1];
                    $label = $matches[2];
                }

                return $this->normalizeGpcOption($code, $label, $description);
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array{code:string,label:string,description:string}|null
     */
    private function normalizeGpcOption(string $code, string $label, string $description = ''): ?array
    {
        $code = $this->cleanDigits($code);
        $label = trim($label);
        $description = trim($description);

        if (! preg_match('/^\d{8}$/', $code) || $label === '') {
            return null;
        }

        return [
            'code' => $code,
            'label' => mb_substr($label, 0, 255, 'UTF-8'),
            'description' => mb_substr($description, 0, 500),
        ];
    }

    private function normalizeBaseUrl(mixed $value): string
    {
        $url = rtrim(trim((string) $value), '/');

        if ($url === '') {
            return self::DEFAULT_BASE_URL;
        }

        $url = preg_replace('#/api/v2(?:/.*)?$#u', '/api/v2', $url) ?? $url;

        if (! str_ends_with($url, '/api/v2')) {
            $url .= '/api/v2';
        }

        return $url;
    }

    /**
     * @return list<array{code:string,label:string,description:string}>
     */
    private function defaultGpcOptions(mixed $defaultGpcCode = null): array
    {
        $options = [
            ['code' => '63000000', 'label' => 'Obuwie', 'description' => 'Segment GPC dla obuwia.'],
            ['code' => '63010000', 'label' => 'Obuwie', 'description' => 'Rodzina GPC dla obuwia.'],
            ['code' => '63010100', 'label' => 'Obuwie sportowe', 'description' => 'Klasa GPC dla obuwia sportowego.'],
            ['code' => '10001070', 'label' => 'Obuwie sportowe - uniwersalne', 'description' => 'Brick GPC dla uniwersalnego obuwia sportowego.'],
            ['code' => '10001071', 'label' => 'Obuwie sportowe - specjalistyczne', 'description' => 'Brick GPC dla specjalistycznego obuwia sportowego.'],
            ['code' => '63010200', 'label' => 'Akcesoria obuwnicze', 'description' => 'Klasa GPC dla akcesoriów obuwniczych.'],
            ['code' => '10000400', 'label' => 'Środki czyszczące do butów/preparaty konserwujące', 'description' => 'Brick GPC dla preparatów do obuwia.'],
            ['code' => '10000432', 'label' => 'Farby do butów/barwniki', 'description' => 'Brick GPC dla farb i barwników do obuwia.'],
            ['code' => '10000433', 'label' => 'Obuwie - elementy zapasowe/akcesoria', 'description' => 'Brick GPC dla części i akcesoriów do obuwia.'],
            ['code' => '10000700', 'label' => 'Opakowania mix akcesoriów obuwniczych', 'description' => 'Brick GPC dla opakowań mieszanych akcesoriów obuwniczych.'],
            ['code' => '10001074', 'label' => 'Wkładki do butów', 'description' => 'Brick GPC dla wkładek do butów.'],
            ['code' => '63010300', 'label' => 'Obuwie uniwersalne', 'description' => 'Klasa GPC dla obuwia uniwersalnego.'],
            ['code' => '10001076', 'label' => 'Buty z cholewką ponad kostkę - uniwersalne (trzewiki, botki, kozaki)', 'description' => 'Brick GPC dla wysokich butów uniwersalnych.'],
            ['code' => '10001077', 'label' => 'Buty - uniwersalne', 'description' => 'Brick GPC dla obuwia uniwersalnego.'],
            ['code' => '63010400', 'label' => 'Obuwie domowe', 'description' => 'Klasa GPC dla obuwia domowego.'],
            ['code' => '10001078', 'label' => 'Obuwie domowe - pełne', 'description' => 'Brick GPC dla pełnego obuwia domowego.'],
            ['code' => '10001079', 'label' => 'Obuwie domowe - częściowo odkryte', 'description' => 'Brick GPC dla częściowo odkrytego obuwia domowego.'],
            ['code' => '63010500', 'label' => 'Obuwie ochronne', 'description' => 'Klasa GPC dla obuwia ochronnego.'],
            ['code' => '10001080', 'label' => 'Robocze buty ochronne z cholewką ponad kostkę', 'description' => 'Brick GPC dla roboczych wysokich butów ochronnych.'],
            ['code' => '10001081', 'label' => 'Robocze ochronne nakładki na buty', 'description' => 'Brick GPC dla ochronnych nakładek na buty.'],
            ['code' => '10001082', 'label' => 'Robocze obuwie ochronne', 'description' => 'Brick GPC dla roboczego obuwia ochronnego.'],
            ['code' => '67000000', 'label' => 'Odzież', 'description' => 'Segment GPC dla odzieży.'],
            ['code' => '67010000', 'label' => 'Odzież', 'description' => 'Rodzina GPC dla odzieży.'],
            ['code' => '67010100', 'label' => 'Akcesoria odzieżowe', 'description' => 'Klasa GPC dla akcesoriów odzieżowych.'],
            ['code' => '10001326', 'label' => 'Paski/szelki/pasy do smokingu (fraka)', 'description' => 'Brick GPC dla pasków, szelek i pasów.'],
            ['code' => '10001327', 'label' => 'Poszetki/chusteczki', 'description' => 'Brick GPC dla poszetek i chusteczek.'],
            ['code' => '10001328', 'label' => 'Rękawice, rękawiczki, mitenki', 'description' => 'Brick GPC dla rękawiczek i mitenek.'],
            ['code' => '10001329', 'label' => 'Nakrycia głowy', 'description' => 'Brick GPC dla nakryć głowy.'],
            ['code' => '10001330', 'label' => 'Szaliki/krawaty/odzież do noszenia na szyi', 'description' => 'Brick GPC dla dodatków noszonych na szyi.'],
            ['code' => '10001331', 'label' => 'Ozdoby odzieżowe/akcesoria kwiatowe/naszywki/sprzączki', 'description' => 'Brick GPC dla ozdób i akcesoriów odzieżowych.'],
            ['code' => '10001354', 'label' => 'Opakowania mix akcesoriów odzieżowych', 'description' => 'Brick GPC dla opakowań mieszanych akcesoriów odzieżowych.'],
            ['code' => '10006903', 'label' => 'Odzież/taśma odzieżowa', 'description' => 'Brick GPC dla taśmy odzieżowej.'],
            ['code' => '67010200', 'label' => 'Ubrania', 'description' => 'Klasa GPC dla ubrań.'],
            ['code' => '10001332', 'label' => 'Kombinezony', 'description' => 'Brick GPC dla kombinezonów.'],
            ['code' => '10001333', 'label' => 'Sukienki', 'description' => 'Brick GPC dla sukienek.'],
            ['code' => '10001355', 'label' => 'Opakowania mix ubrań', 'description' => 'Brick GPC dla opakowań mieszanych ubrań.'],
            ['code' => '67010300', 'label' => 'Dolne elementy garderoby/doły', 'description' => 'Klasa GPC dla dolnych elementów garderoby.'],
            ['code' => '10001334', 'label' => 'Spódnice', 'description' => 'Brick GPC dla spódnic.'],
            ['code' => '10001335', 'label' => 'Spodnie/szorty', 'description' => 'Brick GPC dla spodni i szortów.'],
            ['code' => '10001356', 'label' => 'Dolne elementy garderoby opakowania mix', 'description' => 'Brick GPC dla opakowań mieszanych dolnych elementów garderoby.'],
            ['code' => '67010800', 'label' => 'Górne elementy garderoby/góry', 'description' => 'Klasa GPC dla górnych elementów garderoby.'],
            ['code' => '10001350', 'label' => 'Żakiety/marynarki/kardigany/kamizelki', 'description' => 'Brick GPC dla żakietów, marynarek, kardiganów i kamizelek.'],
            ['code' => '10001351', 'label' => 'Swetry/pulowery', 'description' => 'Brick GPC dla swetrów i pulowerów.'],
            ['code' => '10001352', 'label' => 'Koszule/bluzki/koszulki polo/T-shirt', 'description' => 'Brick GPC dla koszul, bluzek, polo i T-shirtów.'],
            ['code' => '10001361', 'label' => 'Górne elementy garderoby opakowania mix', 'description' => 'Brick GPC dla opakowań mieszanych górnych elementów garderoby.'],
            ['code' => '67011100', 'label' => 'Opakowania mix odzieży', 'description' => 'Klasa GPC dla opakowań mieszanych odzieży.'],
            ['code' => '10002102', 'label' => 'Opakowania mix odzieży', 'description' => 'Brick GPC dla opakowań mieszanych odzieży.'],
            ['code' => '67020000', 'label' => 'Odzież do spania', 'description' => 'Segment GPC dla odzieży do spania.'],
            ['code' => '67020100', 'label' => 'Odzież do spania', 'description' => 'Klasa GPC dla odzieży do spania.'],
            ['code' => '10001338', 'label' => 'Szlafroki (podomki, poranniki)', 'description' => 'Brick GPC dla szlafroków.'],
            ['code' => '10001339', 'label' => 'Koszule nocne/koszulki do spania', 'description' => 'Brick GPC dla koszul nocnych i koszulek do spania.'],
            ['code' => '10001340', 'label' => 'Nakrycia głowy do spania', 'description' => 'Brick GPC dla nakryć głowy do spania.'],
            ['code' => '10001341', 'label' => 'Spodnie do spania/szorty', 'description' => 'Brick GPC dla spodni i szortów do spania.'],
            ['code' => '10001358', 'label' => 'Opakowania mix odzieży do spania', 'description' => 'Brick GPC dla opakowań mieszanych odzieży do spania.'],
            ['code' => '67030000', 'label' => 'Odzież sportowa', 'description' => 'Segment GPC dla odzieży sportowej.'],
            ['code' => '67030100', 'label' => 'Odzież sportowa', 'description' => 'Klasa GPC dla odzieży sportowej.'],
            ['code' => '10001342', 'label' => 'Odzież sportowa - na całe ciało', 'description' => 'Brick GPC dla sportowej odzieży na całe ciało.'],
            ['code' => '10001343', 'label' => 'Odzież sportowa - dolne elementy garderoby', 'description' => 'Brick GPC dla dolnych elementów odzieży sportowej.'],
            ['code' => '10001344', 'label' => 'Odzież sportowa - górne elementy garderoby', 'description' => 'Brick GPC dla górnych elementów odzieży sportowej.'],
            ['code' => '10001359', 'label' => 'Opakowania mix odzieży sportowej', 'description' => 'Brick GPC dla opakowań mieszanych odzieży sportowej.'],
            ['code' => '10003707', 'label' => 'Odzież sportowa - rękawice', 'description' => 'Brick GPC dla rękawic sportowych.'],
            ['code' => '10003708', 'label' => 'Odzież sportowa - nakrycia głowy', 'description' => 'Brick GPC dla sportowych nakryć głowy.'],
            ['code' => '10003709', 'label' => 'Odzież sportowa wyroby pończosznicze', 'description' => 'Brick GPC dla sportowych wyrobów pończoszniczych.'],
            ['code' => '10004113', 'label' => 'Odzież sportowa do noszenia na szyi', 'description' => 'Brick GPC dla sportowych elementów noszonych na szyi.'],
            ['code' => '10004114', 'label' => 'Odzież sportowa - pasy', 'description' => 'Brick GPC dla pasów sportowych.'],
            ['code' => '10004115', 'label' => 'Odzież sportowa - naszywki/sprzączki', 'description' => 'Brick GPC dla sportowych naszywek i sprzączek.'],
            ['code' => '10006840', 'label' => 'Odzież sportowa - inne', 'description' => 'Brick GPC dla pozostałej odzieży sportowej.'],
            ['code' => '67040000', 'label' => 'Bielizna', 'description' => 'Segment GPC dla bielizny.'],
            ['code' => '67040100', 'label' => 'Bielizna', 'description' => 'Klasa GPC dla bielizny.'],
            ['code' => '10001345', 'label' => 'Biustonosze/gorsety/gorsety z pasem do pończoch', 'description' => 'Brick GPC dla biustonoszy i gorsetów.'],
            ['code' => '10001346', 'label' => 'Bielizna do noszenia na całym ciele', 'description' => 'Brick GPC dla bielizny noszonej na całym ciele.'],
            ['code' => '10001347', 'label' => 'Majtki/figi/bokserki', 'description' => 'Brick GPC dla majtek, fig i bokserek.'],
            ['code' => '10001348', 'label' => 'Skarpety', 'description' => 'Brick GPC dla skarpet.'],
            ['code' => '10001349', 'label' => 'Podkoszulki/koszulki na ramiączkach/podkoszulki na ramiączkach', 'description' => 'Brick GPC dla podkoszulków i koszulek na ramiączkach.'],
            ['code' => '10001360', 'label' => 'Opakowania mix bielizny', 'description' => 'Brick GPC dla opakowań mieszanych bielizny.'],
            ['code' => '10002424', 'label' => 'Halki/półhalki/krótkie halki', 'description' => 'Brick GPC dla halek.'],
            ['code' => '10002425', 'label' => 'Rajstopy/pończochy/podkolanówki', 'description' => 'Brick GPC dla rajstop, pończoch i podkolanówek.'],
            ['code' => '10002426', 'label' => 'Pasy do pończoch/podwiązki', 'description' => 'Brick GPC dla pasów do pończoch i podwiązek.'],
            ['code' => '67050000', 'label' => 'Odzież ochronna', 'description' => 'Segment GPC dla odzieży ochronnej.'],
            ['code' => '67050100', 'label' => 'Odzież ochronna', 'description' => 'Klasa GPC dla odzieży ochronnej.'],
            ['code' => '10000732', 'label' => 'Karmienie niemowląt - śliniaki', 'description' => 'Brick GPC dla śliniaków.'],
            ['code' => '10001394', 'label' => 'Ubrania ochronne', 'description' => 'Brick GPC dla ubrań ochronnych.'],
            ['code' => '10001395', 'label' => 'Rękawice ochronne', 'description' => 'Brick GPC dla rękawic ochronnych.'],
            ['code' => '10001397', 'label' => 'Ubrania ochronne - doły', 'description' => 'Brick GPC dla dolnych elementów ubrań ochronnych.'],
            ['code' => '10001398', 'label' => 'Ubrania ochronne - góry', 'description' => 'Brick GPC dla górnych elementów ubrań ochronnych.'],
            ['code' => '10003586', 'label' => 'Okulary ochronne/gogle', 'description' => 'Brick GPC dla okularów ochronnych i gogli.'],
            ['code' => '10003704', 'label' => 'Ubrania ochronne - akcesoria', 'description' => 'Brick GPC dla akcesoriów odzieży ochronnej.'],
            ['code' => '10003705', 'label' => 'Opakowania mix odzieży ochronnej', 'description' => 'Brick GPC dla opakowań mieszanych odzieży ochronnej.'],
            ['code' => '10005105', 'label' => 'Środowiskowe środki ochrony dróg oddechowych - zasilane', 'description' => 'Brick GPC dla zasilanych środków ochrony dróg oddechowych.'],
            ['code' => '10005106', 'label' => 'Środowiskowe środki ochrony dróg oddechowych - bez zasilania', 'description' => 'Brick GPC dla niezasilanych środków ochrony dróg oddechowych.'],
            ['code' => '10005107', 'label' => 'Środki ochrony słuchu - zasilane', 'description' => 'Brick GPC dla zasilanych środków ochrony słuchu.'],
            ['code' => '10005108', 'label' => 'Środki ochrony słuchu - bez zasilania', 'description' => 'Brick GPC dla niezasilanych środków ochrony słuchu.'],
            ['code' => '10005109', 'label' => 'Kaski - zasilane', 'description' => 'Brick GPC dla zasilanych kasków.'],
            ['code' => '10005110', 'label' => 'Kaski - bez zasilania', 'description' => 'Brick GPC dla niezasilanych kasków.'],
            ['code' => '10005111', 'label' => 'Kaski/usztywnione nakrycia głowy', 'description' => 'Brick GPC dla kasków i usztywnionych nakryć głowy.'],
            ['code' => '10005112', 'label' => 'Osłony/ochrona twarzy', 'description' => 'Brick GPC dla osłon i ochrony twarzy.'],
            ['code' => '10005115', 'label' => 'Środki ochrony indywidualnej- części zamienne/akcesoria', 'description' => 'Brick GPC dla części i akcesoriów środków ochrony indywidualnej.'],
            ['code' => '10005116', 'label' => 'Środki ochrony indywidualnej- pozostałe', 'description' => 'Brick GPC dla pozostałych środków ochrony indywidualnej.'],
            ['code' => '10005117', 'label' => 'Środki ochrony indywidualnej - opakowania mix', 'description' => 'Brick GPC dla opakowań mieszanych środków ochrony indywidualnej.'],
            ['code' => '10005894', 'label' => 'Rękawiczki', 'description' => 'Brick GPC dla rękawiczek.'],
            ['code' => '10007933', 'label' => 'Ochraniacze na kolana', 'description' => 'Brick GPC dla ochraniaczy na kolana.'],
            ['code' => '10008243', 'label' => 'Otulacz/becik/rożek', 'description' => 'Brick GPC dla otulaczy, becików i rożków.'],
            ['code' => '67060000', 'label' => 'Stroje kąpielowe', 'description' => 'Segment GPC dla strojów kąpielowych.'],
            ['code' => '67060100', 'label' => 'Stroje kąpielowe', 'description' => 'Klasa GPC dla strojów kąpielowych.'],
            ['code' => '10006964', 'label' => 'Stroje plażowe/okrycia', 'description' => 'Brick GPC dla strojów plażowych i okryć.'],
            ['code' => '10006965', 'label' => 'Stroje kąpielowe - inne', 'description' => 'Brick GPC dla pozostałych strojów kąpielowych.'],
            ['code' => '10008065', 'label' => 'Strój kąpielowy - góra', 'description' => 'Brick GPC dla górnej części stroju kąpielowego.'],
            ['code' => '10008066', 'label' => 'Strój kąpielowy - dół', 'description' => 'Brick GPC dla dolnej części stroju kąpielowego.'],
            ['code' => '10008067', 'label' => 'Strój kąpielowy - jednoczęściowy', 'description' => 'Brick GPC dla jednoczęściowego stroju kąpielowego.'],
            ['code' => '10008068', 'label' => 'Strój kąpielowy - dwuczęściowy', 'description' => 'Brick GPC dla dwuczęściowego stroju kąpielowego.'],
        ];

        $default = $this->normalizeGpcOption((string) ($defaultGpcCode ?? ''), 'Domyślny kod GPC z konfiguracji');

        if ($default !== null && ! collect($options)->contains(fn (array $option): bool => $option['code'] === $default['code'])) {
            array_unshift($options, $default);
        }

        return $options;
    }

    /**
     * @param list<array{code:string,label:string,description:string}> $defaults
     * @param list<array{code:string,label:string,description:string}> $configured
     * @return list<array{code:string,label:string,description:string}>
     */
    private function mergeGpcOptions(array $defaults, array $configured): array
    {
        $merged = collect($defaults)
            ->keyBy('code');

        foreach ($configured as $option) {
            $existing = $merged->get($option['code']);

            if (is_array($existing) && $this->looksLikeTruncatedGpcLabel($option['label'], $existing['label'])) {
                if ($option['description'] !== '') {
                    $existing['description'] = $option['description'];
                    $merged->put($option['code'], $existing);
                }

                continue;
            }

            $merged->put($option['code'], $option);
        }

        return $merged
            ->values()
            ->all();
    }

    private function looksLikeTruncatedGpcLabel(string $label, string $canonicalLabel): bool
    {
        $label = trim($label);
        $canonicalLabel = trim($canonicalLabel);

        if ($label === '' || mb_strlen($label, 'UTF-8') >= mb_strlen($canonicalLabel, 'UTF-8')) {
            return false;
        }

        return str_starts_with(
            mb_strtolower($canonicalLabel, 'UTF-8'),
            mb_strtolower($label, 'UTF-8'),
        );
    }
}
