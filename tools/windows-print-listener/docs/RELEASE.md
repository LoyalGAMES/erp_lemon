# Procedura wydania Windows

## Model artefaktów

`scripts\build.ps1` wykonuje testy Go, `go vet`, cross-build x64, opcjonalne
podpisanie pliku aplikacji, budowę NSIS oraz tworzy w trybie walidacyjnym:

- `build\unsigned\SempreERP-PrintListener-Setup.exe`;
- `build\unsigned\RELEASE-MANIFEST.json`;
- `build\unsigned\SHA256SUMS.txt`;
- `build\windows-amd64\lemon-print-listener.exe`.

Dopiero `scripts\release.ps1` zapisuje podpisane odpowiedniki w `dist`. Dzięki
temu zwykły build deweloperski nie może podmienić pliku udostępnianego przez ERP.

Manifest zawiera wersję, pełny 40-znakowy commit, profil/kanał wydania,
tożsamość certyfikatu, środowisko Go, rozmiary i SHA-256, ale nie
zawiera ścieżek maszyny budującej. `scripts\verify-artifacts.ps1` nie ufa
ścieżkom z manifestu — mapuje oczekiwane nazwy na znane lokalizacje i ponownie
liczy rozmiar oraz hash.

## Walidacja pull requestu

Workflow `windows-print-listener.yml` używa Windows Server 2022 i 2025 jako
hostowanych baz API. Nie są one deklarowane jako rzeczywiste Windows 10/11;
czyste klienckie VM pozostają obowiązkowym testem wydania. Pipeline przypina
NSIS do wersji 3.12 i przed
rozpakowaniem weryfikuje SHA-256 oficjalnego archiwum. Następnie buduje
niepodpisany instalator testowy. Smoke-test uruchamia mock ERP na loopback,
prekonfiguruje chroniony token, instaluje usługę i potwierdza autoryzowany ruch
outbound `/api/print-bridge`, brak tokenu w argumentach SCM, brak przychodzącej
reguły zapory oraz pełną deinstalację. Niepodpisany artefakt nigdy nie jest
publikowany.

## Wydanie podpisane

1. Zaktualizuj `VERSION` zgodnie z `MAJOR.MINOR.PATCH`.
2. Uruchom workflow z gałęzi `main`, zaznacz podpisany release i wybierz profil
   `internal` albo `public`. Joby podpisu i publikacji odrzucają każdą inną ref.
3. Chronione środowisko `windows-code-signing` powinno wymagać zatwierdzenia.
4. Pipeline sprawdzi certyfikat, RSA-3072+, key usage, EKU Code Signing,
   dokładny Subject/SHA-256, ważność i — dla internal — przypięty root/łańcuch;
   następnie podpisze
   wszystkie pliki, zweryfikuje timestamp oraz wykona test instalatora.
5. Dopiero po przejściu wszystkich bramek podpisane pliki zostaną udostępnione
   jako artefakt workflow.
6. Osobny job, który nie ma dostępu do certyfikatu, pobierze zatwierdzony artefakt,
   ponownie sprawdzi SHA-256 instalatora i opublikuje komplet plików w
   trwałym katalogu
   `${DEPLOY_PATH}.deploy/shared/windows-print-listener/releases` na produkcji.
   Dopiero po pełnym uploadzie pojedyncza atomowa operacja podmienia współdzielony
   wskaźnik `CURRENT`, więc przerwane wydanie nie może zastąpić działającego.
   Każdy release ERP zawiera tylko symlink `tools/windows-print-listener/dist`
   do tego katalogu shared; kolejny deploy ani rollback nie usuwa instalatora.
   Job publikujący używa tej samej blokady co deploy aplikacji, wymaga przypiętego
   wpisu serwera w sekrecie `SSH_KNOWN_HOSTS` i nie ufa wynikowi `ssh-keyscan`.
   Endpoint pobierania odrzuca brak wskaźnika lub manifestu, `signed: false`,
   niezgodny rozmiar albo SHA-256 i nigdy nie udostępnia starszego surowego EXE.
   Dla profilu `internal` publikator przesyła i waliduje także oba publiczne
   pliki CER wskazane w manifeście; profil `public` nie może ich zawierać.

Środowisko `windows-code-signing` musi zawierać sekrety:
`WINDOWS_CODESIGN_PFX_BASE64`, `WINDOWS_CODESIGN_PFX_PASSWORD`,
`WINDOWS_CODESIGN_TIMESTAMP_URL`, `WINDOWS_CODESIGN_SUBJECT` i
`WINDOWS_CODESIGN_LEAF_SHA256`. Profil `internal` wymaga ponadto
`WINDOWS_CODESIGN_ROOT_SHA256`, `WINDOWS_CODESIGN_ROOT_CERT_BASE64` oraz
`WINDOWS_CODESIGN_LEAF_CERT_BASE64`. Środowisko
`production` musi zawierać: `SSH_PRIVATE_KEY`, `SSH_KNOWN_HOSTS`, `SSH_HOST`,
`SSH_USER`, `SSH_PORT` i `DEPLOY_PATH`. Brak któregokolwiek zatrzymuje wydanie.
`WINDOWS_CODESIGN_SUBJECT` powinien zawierać pełny podmiot certyfikatu (np.
cały `CN=..., O=..., C=...`), ponieważ pipeline porównuje go dokładnie.
Hasło PFX jest przekazywane wyłącznie do kroku `release.ps1` i usuwane z jego
środowiska przed uruchomieniem NSIS/SignTool.

Po podpisaniu workflow uruchamia Microsoft Defender dla instalatora i listenera.
Gdy Defender działa, błąd skanu, detekcja albo kwarantanna blokują wydanie. Brak
Defendera na obrazie runnera jest jawnie raportowany jako `unavailable`; pipeline
nie wyłącza ochrony ani nie obniża jej ustawień.

Nie należy publikować pliku utworzonego przez zwykłe `build.ps1`. Flaga `signed`
w manifeście, hash i wynik Authenticode są sprawdzane niezależnie przed uploadem.

## Test ręczny przed wdrożeniem

Na czystej maszynie Windows x64 w profilu sieciowym Private:

```powershell
.\scripts\smoke-installer.ps1 -RequireSignature
```

Dla `internal` smoke-test zaczyna bez wpisów Sempre ERP w
`LocalMachine\Root` i `LocalMachine\TrustedPublisher`. Najpierw potwierdza
rollback zaufania po kontrolowanym niepowodzeniu, a potem sprawdza, że podpisany
instalator sam dodał dokładnie przypięte certyfikaty. W `finally` usuwa je z
jednorazowego runnera.

Test sprawdza usługę `SempreERPPrintListener`, wersję i połączenie outbound w
lokalnym `/health`, dokładny ACL konfiguracji, brak sekretu w argumentach usługi,
brak przychodzącej reguły zapory, podpis aplikacji i deinstalatora oraz usunięcie
usługi i pliku z tokenem podczas deinstalacji.

Po instalacji produkcyjnej należy dodatkowo wydrukować jedną etykietę ZPL i PDF
na każdej wspieranej drukarce. Przypięty i zweryfikowany `SumatraPDF.exe` jest
częścią instalatora, a drukarka musi być zainstalowana dla całego komputera, aby
usługa `LocalSystem` ją widziała.
