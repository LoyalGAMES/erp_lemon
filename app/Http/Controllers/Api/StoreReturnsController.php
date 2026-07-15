<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExternalOrder;
use App\Models\ReturnCase;
use App\Services\Returns\StoreReturnIntakeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class StoreReturnsController extends Controller
{
    public function __construct(
        private readonly StoreReturnIntakeService $intake,
    ) {}

    /**
     * Endpoint wyszukiwania zamówienia dla formularza zwrotu w sklepie.
     */
    public function lookupOrder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_reference' => ['required', 'string', 'max:80'],
            'contact' => ['required', 'string', 'max:255'],
        ]);

        $order = $this->intake->findOrder($validated['order_reference'], $validated['contact']);

        if (! $order instanceof ExternalOrder) {
            return response()->json([
                'success' => false,
                'message' => 'Nie znaleziono zamówienia dla podanych danych.',
            ], 404);
        }

        $serialized = $this->intake->serializeOrderForStore($order);

        if ($serialized['items'] === []) {
            return response()->json([
                'success' => false,
                'message' => 'Wszystkie pozycje tego zamówienia zostały już zwrócone.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'order' => $serialized,
        ]);
    }

    /**
     * Endpoint tworzenia zgłoszenia zwrotu.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'return_reference' => ['required', 'string', 'max:80'],
            'local_return_id' => ['nullable'],
            'order_reference' => ['required', 'string', 'max:80'],
            'order_number' => ['nullable', 'string', 'max:80'],
            'order_id' => ['nullable'],
            'return_method' => ['nullable', 'string', 'max:40'],
            'refund_method' => ['nullable', 'string', 'in:cashback,bank_transfer'],
            'refund_bank_account' => ['nullable', 'string', 'max:34'],
            'refund_recipient_name' => ['nullable', 'string', 'max:255'],
            'customer_contact' => ['nullable', 'string', 'max:255'],
            'customer_email' => ['nullable', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:64'],
            'customer_note' => ['nullable', 'string', 'max:2000'],
            'site_url' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['nullable'],
            'items.*.name' => ['nullable', 'string', 'max:255'],
            'items.*.sku' => ['nullable', 'string', 'max:120'],
            'items.*.quantity' => ['required', 'numeric', 'min:1'],
            'items.*.reason' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $returnCase = $this->intake->createFromStorePayload(array_merge($request->all(), $validated));
        } catch (RuntimeException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'external_id' => $returnCase->number,
            'status' => $this->intake->statusForStore($returnCase),
        ]);
    }

    /**
     * Endpoint statusu zgłoszenia (odpytywany cyklicznie przez wtyczkę).
     */
    public function status(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'return_reference' => ['nullable', 'string', 'max:80'],
            'external_id' => ['nullable', 'string', 'max:80'],
        ]);

        $returnCase = $this->intake->findByReference(
            $validated['return_reference'] ?? null,
            $validated['external_id'] ?? null,
        );

        if (! $returnCase instanceof ReturnCase) {
            return response()->json([
                'success' => false,
                'message' => 'Nie znaleziono zgłoszenia zwrotu.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'external_id' => $returnCase->number,
            'status' => $this->intake->statusForStore($returnCase),
        ]);
    }
}
