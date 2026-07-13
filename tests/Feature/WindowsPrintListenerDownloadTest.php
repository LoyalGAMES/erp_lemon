<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class WindowsPrintListenerDownloadTest extends TestCase
{
    use RefreshDatabase;

    private const INSTALLER = 'SempreERP-PrintListener-Setup.exe';

    private const PUBLISHER_CERTIFICATE = 'SempreERP-Internal-Publisher.cer';

    private const ROOT_CERTIFICATE = 'SempreERP-Internal-Root.cer';

    private const RELEASE_ID = '0.2.0-20260712-1';

    private string $distPath;

    private string $releasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->distPath = storage_path('framework/testing/windows-download-'.bin2hex(random_bytes(8)));
        $this->releasePath = $this->distPath.'/releases/'.self::RELEASE_ID;
        File::ensureDirectoryExists($this->releasePath);
        config(['erp.windows_listener_dist_path' => $this->distPath]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->distPath);

        parent::tearDown();
    }

    public function test_internal_release_exposes_verified_metadata_and_one_step_installation(): void
    {
        $manifest = $this->publishRelease('internal');

        $response = $this->get(route('settings.packing'));

        $response->assertOk()
            ->assertSee('Aplikacja dla Windows 10 i 11')
            ->assertSee('Wydanie wewnętrzne')
            ->assertSee('0.2.0')
            ->assertSee($manifest['publisher_subject'])
            ->assertSee($manifest['artifacts'][0]['sha256'])
            ->assertSee($this->fingerprint($manifest['publisher_certificate_sha256']))
            ->assertSee($this->fingerprint($manifest['root_certificate_sha256']))
            ->assertSee('Pobierz instalator i uruchom go jako administrator')
            ->assertSee('Instalator jednorazowo doda zaufanie Sempre ERP')
            ->assertSee('Gdzie znaleźć ustawienia po instalacji')
            ->assertSee('Ustawienia połączenia')
            ->assertSee('Sprawdź połączenie')
            ->assertSee('Szczegóły techniczne i sumy kontrolne')
            ->assertDontSee('Jednorazowo ustanów zaufanie')
            ->assertDontSee('Nie pobieraj go razem z instalatorem')
            ->assertDontSee('Pobierz certyfikat główny')
            ->assertDontSee('Pobierz certyfikat wydawcy')
            ->assertDontSee('Trusted Root Certification Authorities')
            ->assertDontSee('Trusted Publishers')
            ->assertSee('Nie wyłączaj Microsoft Defender ani SmartScreen')
            ->assertSee('Przy pierwszym uruchomieniu Windows może pokazać')
            ->assertSee('Nieznany wydawca')
            ->assertSee('konkretną nazwę zagrożenia')
            ->assertDontSee(route('settings.packing.windows-listener.certificate.publisher'), false)
            ->assertSee(route('settings.packing.windows-listener.download'), false);
    }

    public function test_authenticated_internal_artifacts_have_safe_download_headers(): void
    {
        $manifest = $this->publishRelease('internal');
        $installerHash = $this->artifact($manifest, self::INSTALLER)['sha256'];
        $publisherHash = $this->artifact($manifest, self::PUBLISHER_CERTIFICATE)['sha256'];

        $installerResponse = $this->get(route('settings.packing.windows-listener.download'));
        $installerResponse
            ->assertOk()
            ->assertDownload(self::INSTALLER)
            ->assertHeader('Content-Type', 'application/vnd.microsoft.portable-executable')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Download-Options', 'noopen')
            ->assertHeader('Cross-Origin-Resource-Policy', 'same-origin')
            ->assertHeader('X-Checksum-Sha256', $installerHash);
        $this->assertPrivateNoStore($installerResponse->headers->get('Cache-Control'));

        $this->get(route('settings.packing.windows-listener.certificate.publisher'))
            ->assertOk()
            ->assertDownload(self::PUBLISHER_CERTIFICATE)
            ->assertHeader('Content-Type', 'application/pkix-cert')
            ->assertHeader('X-Checksum-Sha256', $publisherHash);
    }

    public function test_legacy_internal_release_keeps_manual_trust_instructions(): void
    {
        $manifest = $this->publishRelease('internal');
        unset($manifest['trust_bootstrap']);
        $this->writeManifest($manifest);

        $this->get(route('settings.packing'))
            ->assertOk()
            ->assertSee('Jednorazowo ustanów zaufanie')
            ->assertSee('Pobierz certyfikat wydawcy')
            ->assertSee(route('settings.packing.windows-listener.certificate.publisher'), false)
            ->assertDontSee('Instalator jednorazowo doda zaufanie Sempre ERP');
    }

    public function test_certificate_downloads_require_an_authenticated_settings_user(): void
    {
        $this->publishRelease('internal');
        auth()->logout();

        $this->get(route('settings.packing.windows-listener.certificate.publisher'))
            ->assertRedirect(route('login'));
    }

    public function test_public_release_exposes_only_the_installer(): void
    {
        $manifest = $this->publishRelease('public');

        $this->get(route('settings.packing'))
            ->assertOk()
            ->assertSee('Wydanie publiczne')
            ->assertSee($manifest['publisher_subject'])
            ->assertSee('Pobierz podpisany instalator')
            ->assertSee('UAC pokaże „Nieznany wydawca”, przerwij instalację')
            ->assertDontSee('Instalator jednorazowo doda zaufanie Sempre ERP')
            ->assertDontSee('Pobierz certyfikat główny')
            ->assertDontSee('Pobierz certyfikat wydawcy')
            ->assertDontSee(self::ROOT_CERTIFICATE)
            ->assertDontSee(self::PUBLISHER_CERTIFICATE);

        $this->get(route('settings.packing.windows-listener.download'))
            ->assertOk()
            ->assertDownload(self::INSTALLER);
        $this->get(route('settings.packing.windows-listener.certificate.publisher'))->assertNotFound();
    }

    public function test_tampered_missing_or_incomplete_release_is_rejected(): void
    {
        $scenarios = [
            'brak manifestu',
            'niepoprawny json',
            'brak certyfikatu głównego',
            'zmieniony certyfikat główny',
            'zmieniony certyfikat wydawcy',
            'brak certyfikatu w manifeście',
            'błędny odcisk certyfikatu głównego',
            'błędny odcisk certyfikatu wydawcy',
            'niepodpisane wydanie',
            'brak znacznika czasu',
            'różne kanały wydania',
            'nieznany profil podpisu',
            'nieznany bootstrap zaufania',
            'brak wydawcy',
            'duplikat artefaktu',
            'plik bez tabeli Authenticode',
        ];

        foreach ($scenarios as $scenario) {
            File::deleteDirectory($this->releasePath);
            File::ensureDirectoryExists($this->releasePath);
            $manifest = $this->publishRelease('internal');
            $writeManifest = true;

            switch ($scenario) {
                case 'brak manifestu':
                    unlink($this->releasePath.'/RELEASE-MANIFEST.json');
                    $writeManifest = false;
                    break;
                case 'niepoprawny json':
                    file_put_contents($this->releasePath.'/RELEASE-MANIFEST.json', '{not-json');
                    $writeManifest = false;
                    break;
                case 'brak certyfikatu głównego':
                    unlink($this->releasePath.'/'.self::ROOT_CERTIFICATE);
                    break;
                case 'zmieniony certyfikat główny':
                    file_put_contents($this->releasePath.'/'.self::ROOT_CERTIFICATE, 'tampered-root', FILE_APPEND);
                    break;
                case 'zmieniony certyfikat wydawcy':
                    file_put_contents($this->releasePath.'/'.self::PUBLISHER_CERTIFICATE, 'tampered-publisher', FILE_APPEND);
                    break;
                case 'brak certyfikatu w manifeście':
                    $manifest['artifacts'] = array_values(array_filter(
                        $manifest['artifacts'],
                        static fn (array $artifact): bool => $artifact['name'] !== self::PUBLISHER_CERTIFICATE,
                    ));
                    break;
                case 'błędny odcisk certyfikatu głównego':
                    $manifest['root_certificate_sha256'] = str_repeat('0', 64);
                    break;
                case 'błędny odcisk certyfikatu wydawcy':
                    $manifest['publisher_certificate_sha256'] = str_repeat('1', 64);
                    break;
                case 'niepodpisane wydanie':
                    $manifest['signed'] = false;
                    break;
                case 'brak znacznika czasu':
                    $manifest['timestamped'] = false;
                    break;
                case 'różne kanały wydania':
                    $manifest['release_channel'] = 'public';
                    break;
                case 'nieznany profil podpisu':
                    $manifest['signing_profile'] = 'development';
                    break;
                case 'nieznany bootstrap zaufania':
                    $manifest['trust_bootstrap'] = 'unsafe';
                    break;
                case 'brak wydawcy':
                    $manifest['publisher_subject'] = '';
                    break;
                case 'duplikat artefaktu':
                    $manifest['artifacts'][] = $manifest['artifacts'][0];
                    break;
                case 'plik bez tabeli Authenticode':
                    file_put_contents($this->releasePath.'/'.self::INSTALLER, "MZ\0unsigned");
                    $this->refreshArtifact($manifest, self::INSTALLER);
                    break;
            }

            if ($writeManifest) {
                $this->writeManifest($manifest);
            }

            $this->get(route('settings.packing'))
                ->assertOk()
                ->assertSee('Podpisany instalator nie został jeszcze opublikowany');
            $this->assertSame(
                404,
                $this->get(route('settings.packing.windows-listener.download'))->getStatusCode(),
                $scenario,
            );
            $this->assertSame(404, $this->get(route('settings.packing.windows-listener.certificate.publisher'))->getStatusCode(), $scenario);
        }
    }

    /** @return array<string, mixed> */
    private function publishRelease(string $profile): array
    {
        File::ensureDirectoryExists($this->releasePath);
        file_put_contents($this->distPath.'/CURRENT', self::RELEASE_ID."\n");

        file_put_contents($this->releasePath.'/'.self::INSTALLER, $this->signedPortableExecutable());

        $artifacts = [
            $this->fileArtifact(self::INSTALLER),
        ];
        $publisherCertificateSha256 = str_repeat('a', 64);
        $rootCertificateSha256 = null;

        if ($profile === 'internal') {
            file_put_contents($this->releasePath.'/'.self::ROOT_CERTIFICATE, "0\x82\x01Sempre ERP internal root certificate");
            file_put_contents($this->releasePath.'/'.self::PUBLISHER_CERTIFICATE, "0\x82\x01Sempre ERP internal publisher certificate");
            $artifacts[] = $this->fileArtifact(self::ROOT_CERTIFICATE);
            $artifacts[] = $this->fileArtifact(self::PUBLISHER_CERTIFICATE);
            $rootCertificateSha256 = $artifacts[1]['sha256'];
            $publisherCertificateSha256 = $artifacts[2]['sha256'];
        }

        $manifest = [
            'product' => 'Sempre ERP Print Listener',
            'version' => '0.2.0',
            'target' => 'windows/amd64',
            'signed' => true,
            'timestamped' => true,
            'release_channel' => $profile,
            'signing_profile' => $profile,
            'publisher_subject' => 'CN=Sempre ERP Print Listener, O=Sempre Sp. z o.o., C=PL',
            'publisher_certificate_sha256' => $publisherCertificateSha256,
            'artifacts' => $artifacts,
        ];

        if ($rootCertificateSha256 !== null) {
            $manifest['root_certificate_sha256'] = $rootCertificateSha256;
            $manifest['trust_bootstrap'] = 'installer';
        }

        $this->writeManifest($manifest);

        return $manifest;
    }

    /** @param array<string, mixed> $manifest */
    private function writeManifest(array $manifest): void
    {
        file_put_contents(
            $this->releasePath.'/RELEASE-MANIFEST.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );
    }

    /** @return array{name:string,size:int,sha256:string} */
    private function fileArtifact(string $name): array
    {
        $path = $this->releasePath.'/'.$name;

        return [
            'name' => $name,
            'size' => filesize($path),
            'sha256' => hash_file('sha256', $path),
        ];
    }

    /** @param array<string, mixed> $manifest */
    private function refreshArtifact(array &$manifest, string $name): void
    {
        foreach ($manifest['artifacts'] as &$artifact) {
            if ($artifact['name'] === $name) {
                $artifact = $this->fileArtifact($name);
                break;
            }
        }
        unset($artifact);
    }

    /** @param array<string, mixed> $manifest
     * @return array{name:string,size:int,sha256:string}
     */
    private function artifact(array $manifest, string $name): array
    {
        foreach ($manifest['artifacts'] as $artifact) {
            if ($artifact['name'] === $name) {
                return $artifact;
            }
        }

        throw new \RuntimeException("Missing test artifact {$name}");
    }

    private function signedPortableExecutable(): string
    {
        $dosHeader = str_repeat("\0", 64);
        $dosHeader = substr_replace($dosHeader, 'MZ', 0, 2);
        $dosHeader = substr_replace($dosHeader, pack('V', 64), 60, 4);

        $optionalHeaderSize = 240;
        $peHeader = "PE\0\0".pack('vvVVVvv', 0x8664, 1, 0, 0, 0, $optionalHeaderSize, 0x0022);
        $optionalHeader = str_repeat("\0", $optionalHeaderSize);
        $optionalHeader = substr_replace($optionalHeader, pack('v', 0x20B), 0, 2);
        $certificateOffset = 64 + strlen($peHeader) + $optionalHeaderSize;
        $optionalHeader = substr_replace($optionalHeader, pack('VV', $certificateOffset, 8), 144, 8);
        $certificate = pack('Vvv', 8, 0x0200, 0x0002);

        return $dosHeader.$peHeader.$optionalHeader.$certificate;
    }

    private function fingerprint(string $hash): string
    {
        return strtoupper(implode(':', str_split($hash, 2)));
    }

    private function assertPrivateNoStore(?string $cacheControl): void
    {
        $this->assertNotNull($cacheControl);
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringNotContainsString('public', $cacheControl);
    }
}
