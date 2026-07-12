# Sempre ERP Print Listener

Aplikacja Windows pobiera kolejkę etykiet z Sempre ERP przez wychodzące HTTPS i
drukuje je na drukarkach zainstalowanych w Windows. Produkcyjny ERP działa na
Hetznerze, dlatego aplikacja nie oczekuje połączenia serwera do prywatnego adresu
komputera magazynu. To komputer magazynowy inicjuje każde połączenie do endpointu
`/api/print-bridge`.

## Przygotowanie ERP

1. Administrator kopiuje adres ERP i token z sekcji „Dane do instalatora” w
   ustawieniach pakowania. ERP bez osobnej konfiguracji wyprowadza silny token
   z własnego `APP_KEY`; opcjonalny `PRINT_BRIDGE_TOKEN` w `.env` nadpisuje go.
2. Stanowisko musi mieć kod (np. `station-1`) i nazwę
   drukarki identyczną z nazwą widoczną w Windows.
3. W ustawieniach nie ma już pola adresu komputera magazynowego. Produkcyjna
   kolejka korzysta wyłącznie z wychodzącego mostu `/api/print-bridge`.

## Instalacja na stanowisku pakowania

1. Pobierz `SempreERP-PrintListener-Setup.exe` z ustawień pakowania w ERP.
2. Uruchom instalator jako administrator. Dla wydania `internal` pierwszy start
   może jeszcze pokazać nieznanego wydawcę; po zezwoleniu instalator jednorazowo
   doda root do `LocalMachine\Root`, a wydawcę do
   `LocalMachine\TrustedPublisher`.
3. W instalatorze wpisz:
   - publiczny adres ERP rozpoczynający się od `https://`;
   - token zgodny z `PRINT_BRIDGE_TOKEN`;
   - kod stanowiska z ustawień pakowania;
   - nazwę workera/komputera.
4. Instalator tworzy automatyczną usługę `SempreERPPrintListener`.

Token jest zapisany w
`C:\ProgramData\Sempre ERP\Print Listener\config.ini`. Katalog i plik otrzymują
chroniony DACL oraz właściciela `Administrators`; pełny dostęp mają wyłącznie
`SYSTEM` i `Administrators`. Token nie trafia do argumentów usługi, rejestru ani
logów. Ponowne uruchomienie instalatora pozwala bezpiecznie zmienić konfigurację.

## Sprawdzenie działania

Na komputerze z drukarką lokalny, niewystawiony do sieci endpoint stanu działa
pod adresem:

```text
http://127.0.0.1:17778/health
```

Pole `connected: true` oraz niepuste `last_success_at` potwierdzają udane,
autoryzowane odpytywanie ERP. Dziennik usługi znajduje się w:

```text
C:\ProgramData\Sempre ERP\Print Listener\listener.log
```

Instalator usuwa starą przychodzącą regułę zapory i nie otwiera żadnego portu
LAN. Wymagany jest wyłącznie ruch wychodzący HTTPS z komputera do ERP.

## Drukowanie ZPL, PDF i obrazów

ZPL jest wysyłany bezpośrednio do spoolera Windows jako RAW. PDF i obrazy
wymagają przypiętego renderera `SumatraPDF.exe` 3.6.1. Instalator dołącza
oficjalnie podpisany plik i licencję, a listener przed każdym użyciem sprawdza
jego SHA-256. Kod źródłowy tej wersji jest dostępny w tagu
`3.6.1rel`: https://github.com/sumatrapdfreader/sumatrapdf/tree/3.6.1rel.

Usługa działa jako `LocalSystem`, dlatego drukarka musi być zainstalowana dla
całego komputera. Drukarki mapowane wyłącznie w profilu konkretnego użytkownika
mogą nie być widoczne dla usługi. To świadomy kompromis zgodności ze spoolerem
i drukarkami sieciowymi: konto nie zostało automatycznie zmienione na
`LocalService`, bo mogłoby utracić dostęp do części drukarek. Powierzchnia
renderera jest ograniczona przez przypięcie dokładnego, podpisanego pliku,
prywatny plik tymczasowy, limit czasu procesu i brak przychodzącego portu.

## Starszy tryb przychodzący

Bezpiecznym trybem domyślnym jest `bridge`. Tryb listenera pozostał wyłącznie do
diagnostyki w tej samej maszynie lub w sieci z prawidłowo zestawionym VPN. Nie
jest instalowany jako tryb produkcyjny i nie wolno wystawiać go do Internetu:

```bat
lemon-print-listener.exe -mode listener -listen 127.0.0.1:17777
```

Proces odmówi startu listenera na adresie innym niż loopback bez niepustego
`-token`. Tokenu nie można zapisywać w argumentach usługi Windows, dlatego
instalator nie udostępnia sieciowego trybu legacy.

Plik `start-listener.bat` jest zachowany dla zgodności ze starszym wdrożeniem,
ale nie zastępuje instalatora i outbound print bridge.

## Budowanie i wydawanie

Wymagania: Windows x64, Go z wersją określoną w `go.mod`, zweryfikowany NSIS
3.12 i Windows SDK z `signtool.exe`.

Lokalna walidacja bez publikacji:

```powershell
.\scripts\build.ps1
.\scripts\verify-artifacts.ps1
.\scripts\smoke-installer.ps1 `
  -InstallerPath .\build\unsigned\SempreERP-PrintListener-Setup.exe
```

Smoke-test uruchamia lokalny mock ERP, tworzy konfigurację z chronionym ACL,
instaluje usługę, potwierdza autoryzowany polling outbound, brak tokenu w SCM i
brak przychodzącej reguły zapory, a na końcu wszystko odinstalowuje.

Wydanie musi przejść przez `scripts\release.ps1`, który wymaga profilu
`internal` albo `public`, certyfikatu Authenticode, przypiętego SHA-256 i
timestampu RFC 3161. Niepodpisany build służy tylko
testom i pipeline go nie publikuje. Szczegóły są w
`docs\AUTHENTICODE.md` i `docs\RELEASE.md`.

ERP udostępnia pobieranie dopiero wtedy, gdy w `dist` znajdują się jednocześnie
podpisany `SempreERP-PrintListener-Setup.exe` oraz odpowiadający mu
`RELEASE-MANIFEST.json` z trybem `signed: true`, zgodnym rozmiarem i SHA-256.
Stary surowy plik `lemon-print-listener.exe` nigdy nie jest fallbackiem.

Profil `internal` dołącza publiczne `SempreERP-Internal-Root.cer` i
`SempreERP-Internal-Publisher.cer` do artefaktu workflow i osadza je w
instalatorze. Po podniesieniu uprawnień instalator dodaje je jednorazowo do
magazynów maszyny. SmartScreen/Defender pozostają aktywnymi, niezależnymi
zabezpieczeniami.
