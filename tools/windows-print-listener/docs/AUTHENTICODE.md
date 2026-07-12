# Podpis Authenticode

Pipeline obsługuje dwa jawne profile:

- `public` — certyfikat code signing wystawiony przez publicznie zaufane CA lub
  usługę/HSM;
- `internal` — oddzielny certyfikat wydawcy wystawiony bezpośrednio przez
  samopodpisany wewnętrzny root CA. Ten profil wolno stosować wyłącznie na
  zarządzanych komputerach, na których root i wydawca zostały wcześniej
  wdrożone niezależnym, administracyjnym kanałem.

Podpis nie jest obejściem ochrony Windows. Zapewnia identyfikację wydawcy i
integralność pliku, ale sam w sobie nie gwarantuje natychmiastowej reputacji
Microsoft Defender SmartScreen. Należy podpisywać kolejne wydania tym samym,
zaufanym identyfikatorem wydawcy i nie instruować użytkowników, aby wyłączali
SmartScreen, wyłączali antywirusa lub ignorowali ostrzeżenia.

## Sekrety środowiska wydawniczego

Workflow korzysta z chronionego środowiska GitHub `windows-code-signing` i
następujących sekretów:

- `WINDOWS_CODESIGN_PFX_BASE64` — PFX zakodowany Base64;
- `WINDOWS_CODESIGN_PFX_PASSWORD` — hasło PFX;
- `WINDOWS_CODESIGN_TIMESTAMP_URL` — URL zaufanej usługi RFC 3161;
- `WINDOWS_CODESIGN_SUBJECT` — pełny, dokładny Subject certyfikatu wydawcy;
- `WINDOWS_CODESIGN_LEAF_SHA256` — dokładny SHA-256 DER certyfikatu wydawcy;
- dla profilu `internal`: `WINDOWS_CODESIGN_ROOT_SHA256`,
  `WINDOWS_CODESIGN_ROOT_CERT_BASE64` i
  `WINDOWS_CODESIGN_LEAF_CERT_BASE64`.

PFX jest zapisywany wyłącznie w katalogu tymczasowym runnera. Skrypt
`release.ps1` importuje certyfikat jako nieeksportowalny do magazynu bieżącego
użytkownika, podpisuje artefakty po thumbprincie i usuwa certyfikat w bloku
`finally`. Hasło PFX nie jest przekazywane w argumentach `signtool.exe`.
W profilu `internal` pipeline waliduje CA/key usage root, CA=false, Digital
Signature i EKU Code Signing wydawcy, RSA-3072+, ważność, dokładne SHA-256 oraz
łańcuch. Na podniesionym, jednorazowym runnerze root trafia przez bezinteraktywne
`certutil.exe` tymczasowo do `LocalMachine\Root`, a wydawca do
`LocalMachine\TrustedPublisher`; oba wpisy są usuwane w `finally`. Użycie
magazynu maszyny omija blokujące okno potwierdzenia chronionego magazynu root
bieżącego użytkownika.

Instalator nigdy nie dodaje własnego certyfikatu do magazynów zaufania. Byłoby
to kołowym „samozaufaniem”. Na stanowiskach magazynowych publiczny root należy
wdrożyć wcześniej do `LocalMachine\Root`, a certyfikat wydawcy do
`LocalMachine\TrustedPublisher`, najlepiej przez GPO/Intune/WDAC.

Zalecane jest zastąpienie PFX usługą podpisu opartą o HSM, gdy organizacja taką
posiada. W takim wariancie należy zachować te same bramki: SHA-256, timestamp
RFC 3161, kontrolę oczekiwanego podmiotu i końcowe `signtool verify`.

## Zakres podpisu

Pipeline podpisuje:

1. `lemon-print-listener.exe` przed pakowaniem;
2. `uninstall.exe` w trakcie kompilacji NSIS przez `!uninstfinalize`;
3. końcowy `SempreERP-PrintListener-Setup.exe` przez `!finalize`.

Każdy podpis używa digestu SHA-256 i timestampu RFC 3161 z SHA-256. Końcowa
weryfikacja uruchamia zarówno `Get-AuthenticodeSignature`, jak i:

```powershell
signtool.exe verify /pa /all /tw /v .\dist\SempreERP-PrintListener-Setup.exe
```

Manifest podpisanego wydania zawiera równe `release_channel` i
`signing_profile` (`internal` albo `public`), pełny Subject, SHA-256 wydawcy i
`timestamped: true`. Profil `internal` dodatkowo zawiera SHA-256 root i dwa
publiczne artefakty `SempreERP-Internal-Root.cer` oraz
`SempreERP-Internal-Publisher.cer`. Pliki CER nie zawierają kluczy prywatnych.

Dokumentacja Microsoft:

- SignTool: https://learn.microsoft.com/windows/win32/seccrypto/signtool
- weryfikacja podpisu: https://learn.microsoft.com/windows/win32/seccrypto/using-signtool-to-verify-a-file-signature

## Rotacja i incydenty

- Ustaw alert na wygaśnięcie certyfikatu z co najmniej 60-dniowym wyprzedzeniem.
- Zmieniaj certyfikat tylko przez chronione środowisko z zatwierdzeniem release.
- Po podejrzeniu wycieku natychmiast unieważnij certyfikat w CA, usuń sekrety,
  zablokuj publikację i wydaj nową wersję innym certyfikatem.
- Zachowuj manifest release, SHA-256 i log weryfikacji dla każdego wydania.
