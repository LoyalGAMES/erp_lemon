# Lemon ERP Windows Print Bridge

> **Wycofane:** ten skrypt pozostaje wyłącznie jako materiał migracyjny. Nowe
> wdrożenia muszą używać podpisanego **SempreERP-PrintListener-Setup.exe** z
> katalogu **tools/windows-print-listener**. Instalator uruchamia natywną usługę outbound,
> chroni token ACL i nie wymaga obchodzenia polityk wykonywania PowerShell.

This bridge runs on a Windows computer that has the Zebra printer installed.
It polls Lemon ERP for pending label print jobs and prints them on the Windows
printer name stored in the packing station settings.

## ERP setup

1. Set `PRINT_BRIDGE_TOKEN` in the ERP `.env`.
2. Run migrations.
3. In packing settings, set the station printer name exactly as it appears in Windows, for example `Zebra ZD421`.

## Windows setup

1. Copy `config.example.json` to `config.json`.
2. Set `baseUrl`, `token`, `station`, and `workerName`.
3. Install the Zebra printer driver in Windows.
4. For PDF labels, install SumatraPDF and set `sumatraPath`.
5. Start the bridge:

```powershell
powershell.exe -NoProfile -File .\print-bridge.ps1 -ConfigPath .\config.json
```

To start it automatically at user logon:

```powershell
powershell.exe -NoProfile -File .\install-scheduled-task.ps1 -ConfigPath .\config.json
```

## Label formats

ZPL labels are sent directly to the Windows spooler as RAW data. PDF and image
labels are printed through SumatraPDF in silent mode. If courier APIs can return
ZPL, prefer ZPL for Zebra printers because it avoids PDF rendering differences.
