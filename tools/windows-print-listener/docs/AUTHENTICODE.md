# Podpis Authenticode

Wydanie produkcyjne Sempre ERP Print Listener wymaga ważnego certyfikatu code
signing wystawionego dla organizacji przez zaufane CA albo certyfikatu w
zatwierdzonej usłudze/HSM. Certyfikat samopodpisany nadaje się wyłącznie do
testów wewnętrznych i nie może być publikowany użytkownikom.

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
- `WINDOWS_CODESIGN_SUBJECT` — oczekiwany fragment Subject certyfikatu.

PFX jest zapisywany wyłącznie w katalogu tymczasowym runnera. Skrypt
`release.ps1` importuje certyfikat jako nieeksportowalny do magazynu bieżącego
użytkownika, podpisuje artefakty po thumbprincie i usuwa certyfikat w bloku
`finally`. Hasło PFX nie jest przekazywane w argumentach `signtool.exe`.

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

Dokumentacja Microsoft:

- SignTool: https://learn.microsoft.com/windows/win32/seccrypto/signtool
- weryfikacja podpisu: https://learn.microsoft.com/windows/win32/seccrypto/using-signtool-to-verify-a-file-signature

## Rotacja i incydenty

- Ustaw alert na wygaśnięcie certyfikatu z co najmniej 60-dniowym wyprzedzeniem.
- Zmieniaj certyfikat tylko przez chronione środowisko z zatwierdzeniem release.
- Po podejrzeniu wycieku natychmiast unieważnij certyfikat w CA, usuń sekrety,
  zablokuj publikację i wydaj nową wersję innym certyfikatem.
- Zachowuj manifest release, SHA-256 i log weryfikacji dla każdego wydania.
