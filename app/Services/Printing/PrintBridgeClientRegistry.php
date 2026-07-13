<?php

declare(strict_types=1);

namespace App\Services\Printing;

use App\Models\PrintBridgeClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

final class PrintBridgeClientRegistry
{
    public const ONLINE_AFTER_SECONDS = 90;

    /**
     * @param  list<array{name:string,driver?:string|null,port?:string|null,default?:bool}>  $printers
     */
    public function report(
        string $stationCode,
        string $workerName,
        ?string $version,
        array $printers,
        ?string $printerError = null,
    ): PrintBridgeClient {
        return PrintBridgeClient::query()->updateOrCreate([
            'station_code' => trim($stationCode),
            'worker_name' => trim($workerName),
        ], [
            'version' => filled($version) ? trim((string) $version) : null,
            'printers' => $this->cleanPrinters($printers),
            'printer_error' => filled($printerError) ? mb_substr(trim((string) $printerError), 0, 1000) : null,
            'last_seen_at' => now(),
        ]);
    }

    /**
     * @param  list<array{code?:string|null}>  $stations
     * @return array<string, array{
     *     station:string,
     *     connected:bool,
     *     status:string,
     *     worker:?string,
     *     version:?string,
     *     last_seen_at:?string,
     *     printer_error:?string,
     *     mapped_printer:?string,
     *     mapped_printer_available:?bool,
     *     printers:list<array{name:string,driver:string,port:string,default:bool}>
     * }>
     */
    public function statuses(array $stations, ?string $onlyStation = null): array
    {
        $stationCodes = collect($stations)
            ->map(static fn (array $station): string => trim((string) ($station['code'] ?? '')))
            ->filter()
            ->unique()
            ->when(
                filled($onlyStation),
                static fn ($codes) => $codes->filter(
                    static fn (string $code): bool => hash_equals($code, trim((string) $onlyStation)),
                ),
            )
            ->values();

        $latestClients = collect();
        if (Schema::hasTable('print_bridge_clients')) {
            $latestClients = PrintBridgeClient::query()
                ->whereIn('station_code', $stationCodes->all())
                ->orderByDesc('last_seen_at')
                ->orderByDesc('id')
                ->get()
                ->unique('station_code')
                ->keyBy('station_code');
        }

        $configuredPrinters = collect($stations)->mapWithKeys(static fn (array $station): array => [
            trim((string) ($station['code'] ?? '')) => trim((string) ($station['printer_name'] ?? '')),
        ]);

        $onlineThreshold = now()->subSeconds(self::ONLINE_AFTER_SECONDS);
        $result = [];

        foreach ($stationCodes as $stationCode) {
            /** @var PrintBridgeClient|null $client */
            $client = $latestClients->get($stationCode);
            $lastSeenAt = $client?->last_seen_at;
            $connected = $lastSeenAt instanceof Carbon && $lastSeenAt->greaterThanOrEqualTo($onlineThreshold);
            $printers = $this->cleanPrinters((array) ($client?->printers ?? []));
            $mappedPrinter = (string) $configuredPrinters->get($stationCode, '');
            $mappedPrinterAvailable = $client === null || $mappedPrinter === ''
                ? null
                : collect($printers)->contains(
                    static fn (array $printer): bool => mb_strtolower($printer['name']) === mb_strtolower($mappedPrinter),
                );

            $result[$stationCode] = [
                'station' => $stationCode,
                'connected' => $connected,
                'status' => $client === null ? 'never' : ($connected ? 'online' : 'offline'),
                'worker' => $client?->worker_name,
                'version' => $client?->version,
                'last_seen_at' => $lastSeenAt?->copy()->utc()->toIso8601String(),
                'printer_error' => $client?->printer_error,
                'mapped_printer' => $mappedPrinter !== '' ? $mappedPrinter : null,
                'mapped_printer_available' => $mappedPrinterAvailable,
                'printers' => $printers,
            ];
        }

        return $result;
    }

    /**
     * @param  array<int, mixed>  $printers
     * @return list<array{name:string,driver:string,port:string,default:bool}>
     */
    private function cleanPrinters(array $printers): array
    {
        $cleaned = [];

        foreach ($printers as $printer) {
            if (! is_array($printer)) {
                continue;
            }

            $name = mb_substr(trim((string) ($printer['name'] ?? '')), 0, 120);
            if ($name === '') {
                continue;
            }

            $key = mb_strtolower($name);
            if (isset($cleaned[$key])) {
                continue;
            }

            $cleaned[$key] = [
                'name' => $name,
                'driver' => mb_substr(trim((string) ($printer['driver'] ?? '')), 0, 200),
                'port' => mb_substr(trim((string) ($printer['port'] ?? '')), 0, 200),
                'default' => (bool) ($printer['default'] ?? false),
            ];
        }

        $printers = array_values($cleaned);
        usort($printers, static function (array $left, array $right): int {
            if ($left['default'] !== $right['default']) {
                return $left['default'] ? -1 : 1;
            }

            return strnatcasecmp($left['name'], $right['name']);
        });

        return $printers;
    }
}
