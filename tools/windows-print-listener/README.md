# Lemon Print Listener

Prosta aplikacja Windows do nasłuchu wydruków etykiet z Lemon ERP.

## Uruchomienie

1. Uruchom `lemon-print-listener.exe` na komputerze z drukarką Zebra.
2. Domyślny adres aplikacji to `http://ADRES-KOMPUTERA:17777`.
3. W ERP, w ustawieniach stanowiska pakowania, wpisz:
   - nazwę drukarki dokładnie taką jak w Windows, np. `Zebra ZD421`,
   - adres aplikacji Windows, np. `http://192.168.1.25:17777`.

Po wpisaniu adresu aplikacji Windows kliknij `Pobierz` przy polu drukarki.
ERP pobierze listę drukarek z tego komputera i pozwoli wybrać właściwą nazwę
z listy.

Możesz też uruchomić gotowy plik:

```bat
start-listener.bat
```

## PDF i ZPL

ZPL jest wysyłany bezpośrednio do spoolera Windows jako RAW.

PDF i obrazy wymagają `SumatraPDF.exe`. Aplikacja sama szuka go:

- obok `lemon-print-listener.exe`,
- w `Program Files\SumatraPDF`,
- w zmiennej `PATH`.

Można też wskazać ścieżkę:

```bat
lemon-print-listener.exe -listen 0.0.0.0:17777 -sumatra "C:\Program Files\SumatraPDF\SumatraPDF.exe"
```

## Test

W przeglądarce na komputerze z ERP lub z sieci magazynu otwórz:

```text
http://192.168.1.25:17777/health
```

Jeżeli zwraca `{"success":true,"message":"ready"}`, ERP może wysyłać etykiety.

Listę drukarek możesz sprawdzić pod:

```text
http://192.168.1.25:17777/printers
```
