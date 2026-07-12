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

Manifest zawiera wersję, commit, środowisko Go, rozmiary i SHA-256, ale nie
zawiera ścieżek maszyny budującej. `scripts\verify-artifacts.ps1` nie ufa
ścieżkom z manifestu — mapuje oczekiwane nazwy na znane lokalizacje i ponownie
liczy rozmiar oraz hash.

## Walidacja pull requestu

Workflow `windows-print-listener.yml` przypina NSIS do wersji 3.12 i przed
rozpakowaniem weryfikuje SHA-256 oficjalnego archiwum. Następnie buduje
niepodpisany instalator testowy. Smoke-test uruchamia mock ERP na loopback,
prekonfiguruje chroniony token, instaluje usługę i potwierdza autoryzowany ruch
outbound `/api/print-bridge`, brak tokenu w argumentach SCM, brak przychodzącej
reguły zapory oraz pełną deinstalację. Niepodpisany artefakt nigdy nie jest
publikowany.

## Wydanie podpisane

1. Zaktualizuj `VERSION` zgodnie z `MAJOR.MINOR.PATCH`.
2. Uruchom ręcznie workflow `Windows Print Listener` z opcją podpisanego release.
3. Chronione środowisko `windows-code-signing` powinno wymagać zatwierdzenia.
4. Pipeline sprawdzi certyfikat, EKU Code Signing, podmiot i ważność, podpisze
   wszystkie pliki, zweryfikuje timestamp oraz wykona test instalatora.
5. Dopiero po przejściu wszystkich bramek podpisane pliki zostaną udostępnione
   jako artefakt workflow.
6. Osobny job, który nie ma dostępu do certyfikatu, pobierze zatwierdzony artefakt,
   ponownie sprawdzi SHA-256 instalatora i opublikuje komplet plików w
   wersjonowanym katalogu `tools/windows-print-listener/dist/releases` na
   produkcji. Dopiero po pełnym uploadzie pojedyncza atomowa operacja podmienia
   wskaźnik `dist/CURRENT`, więc przerwane wydanie nie może zastąpić działającego.
   Zwykły deploy ERP zachowuje całe `dist`. Job publikujący wymaga przypiętego
   wpisu serwera w sekrecie `SSH_KNOWN_HOSTS` i nie ufa wynikowi `ssh-keyscan`.
   Endpoint pobierania odrzuca brak wskaźnika lub manifestu, `signed: false`,
   niezgodny rozmiar albo SHA-256 i nigdy nie udostępnia starszego surowego EXE.

Nie należy publikować pliku utworzonego przez zwykłe `build.ps1`. Flaga `signed`
w manifeście, hash i wynik Authenticode są sprawdzane niezależnie przed uploadem.

## Test ręczny przed wdrożeniem

Na czystej maszynie Windows x64 w profilu sieciowym Private:

```powershell
.\scripts\smoke-installer.ps1 -RequireSignature
```

Test sprawdza usługę `SempreERPPrintListener`, wersję i połączenie outbound w
lokalnym `/health`, dokładny ACL konfiguracji, brak sekretu w argumentach usługi,
brak przychodzącej reguły zapory, podpis aplikacji i deinstalatora oraz usunięcie
usługi i pliku z tokenem podczas deinstalacji.

Po instalacji produkcyjnej należy dodatkowo wydrukować jedną etykietę ZPL i PDF
na każdej wspieranej drukarce. PDF wymaga dostępnego `SumatraPDF.exe`, a drukarka
musi być zainstalowana dla całego komputera, aby usługa `LocalSystem` ją widziała.
