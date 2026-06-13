<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Warehouse;
use App\Services\Automation\DocumentAutomationSettingsService;
use App\Services\Inventory\WarehouseDocumentSettingsService;
use App\Services\Returns\ReturnSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function __invoke(): View
    {
        return view('settings.index', [
            'title' => 'Ustawienia',
            'subtitle' => 'Wybierz obszar konfiguracji. Szczegółowe formularze są rozdzielone na osobne strony modułów.',
            'module' => 'settings',
        ]);
    }

    public function documents(
        WarehouseDocumentSettingsService $documentSettings,
        DocumentAutomationSettingsService $automationSettings,
    ): View {
        return view('settings.documents', [
            'title' => 'Ustawienia dokumentów',
            'subtitle' => 'Numeracja dokumentów magazynowych, lokalizacje i automatyczny obieg akcji.',
            'module' => 'settings',
            'documentNumbering' => $documentSettings->numberingData(),
            'documentNumberExample' => $documentSettings->exampleNumber(),
            'warehouseLocations' => $documentSettings->locations(),
            'documentAutomation' => $automationSettings->data(),
            'documentAutomationRules' => $automationSettings->rules(),
        ]);
    }

    public function returns(ReturnSettingsService $returnSettings): View
    {
        return view('settings.returns', [
            'title' => 'Ustawienia zwrotów',
            'subtitle' => 'Domyślne ustawienia przyjęć zwrotów, numeracji i docelowego magazynu.',
            'module' => 'settings',
            'returnSettings' => $returnSettings->data(),
            'returnNumberExample' => $returnSettings->exampleNumber(),
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('code')->get(),
        ]);
    }

    public function updateDocuments(
        Request $request,
        WarehouseDocumentSettingsService $documentSettings,
    ): RedirectResponse {
        $validated = $request->validate([
            'pattern' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9_\/{}-]+$/'],
            'padding' => ['required', 'integer', 'min:3', 'max:9'],
        ]);

        $documentSettings->updateNumberingData($validated);

        return back()->with('status', 'Ustawienia numeracji dokumentów magazynowych zostały zapisane.');
    }

    public function updateLocations(
        Request $request,
        WarehouseDocumentSettingsService $documentSettings,
    ): RedirectResponse {
        $validated = $request->validate([
            'locations_text' => ['nullable', 'string', 'max:5000'],
        ]);

        $locations = preg_split('/[\r\n,;]+/', (string) ($validated['locations_text'] ?? '')) ?: [];
        $documentSettings->updateLocations($locations);

        return back()->with('status', 'Lokalizacje magazynowe zostały zapisane.');
    }

    public function updateDocumentAutomation(
        Request $request,
        DocumentAutomationSettingsService $automationSettings,
    ): RedirectResponse {
        $automationSettings->updateRules((array) $request->input('automation', []));

        return back()->with('status', 'Ustawienia automatycznego obiegu dokumentów zostały zapisane.');
    }

    public function updateReturns(
        Request $request,
        ReturnSettingsService $returnSettings,
    ): RedirectResponse {
        $validated = $request->validate([
            'numbering_pattern' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9_\/{}-]+$/'],
            'numbering_prefix' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_\/-]+$/'],
            'numbering_padding' => ['required', 'integer', 'min:3', 'max:9'],
            'default_target_warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'default_condition' => ['required', 'string', 'max:40'],
            'default_disposition' => ['required', 'string', 'max:40'],
            'return_reasons' => ['nullable', 'array'],
            'return_reasons.*' => ['nullable', 'string', 'max:120'],
            'conditions' => ['required', 'array', 'min:1'],
            'conditions.*.code' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
            'conditions.*.label' => ['nullable', 'string', 'max:80'],
            'dispositions' => ['required', 'array', 'min:1'],
            'dispositions.*.code' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
            'dispositions.*.label' => ['nullable', 'string', 'max:80'],
            'dispositions.*.warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
        ]);

        $returnSettings->update($validated);

        return back()->with('status', 'Ustawienia zwrotów zostały zapisane.');
    }
}
