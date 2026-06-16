<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReservationRequest;
use App\Models\Reservation;
use App\Models\StockItem;
use App\Services\InventoryAutocompleteService;
use App\Services\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReservationController extends Controller
{
    public function __construct(
        private readonly StockService $stockService,
        private readonly InventoryAutocompleteService $autocomplete,
    ) {}

    public function index(): View
    {
        return view('admin.reservations.index', [
            'reservations' => Reservation::query()
                ->with(['product', 'stockItem.location'])
                ->latest()
                ->paginate(20),
        ]);
    }

    public function create(Request $request): View
    {
        return view('admin.reservations.form', [
            'reservation' => new Reservation,
            'selectedProduct' => $this->autocomplete->selectedProduct($request->old('product_id')),
            'selectedStockItem' => $this->autocomplete->selectedStockItem($request->old('stock_item_id'), includeProduct: true),
            'statuses' => Reservation::STATUSES,
        ]);
    }

    public function productOptions(Request $request): JsonResponse
    {
        return response()->json($this->autocomplete->productOptions((string) $request->query('q', '')));
    }

    public function stockItemOptions(Request $request): JsonResponse
    {
        return response()->json($this->autocomplete->stockItemOptions(
            (string) $request->query('q', ''),
            onlyAvailable: true,
            includeProduct: true,
        ));
    }

    public function store(ReservationRequest $request): RedirectResponse
    {
        $stockItem = StockItem::query()->findOrFail($request->integer('stock_item_id'));

        $this->stockService->reserve($stockItem, $request->validated());

        return redirect()->route('admin.reservations.index')->with('status', ' .');
    }

    public function show(Reservation $reservation): View
    {
        return view('admin.reservations.show', [
            'reservation' => $reservation->load(['product', 'stockItem.location']),
        ]);
    }

    public function edit(Request $request, Reservation $reservation): View
    {
        $reservation->loadMissing([
            'product:id,sku,external_sku,name',
            'stockItem.product:id,sku,external_sku,name',
            'stockItem.warehouse:id,name',
            'stockItem.location:id,full_code',
        ]);

        return view('admin.reservations.form', [
            'reservation' => $reservation,
            'selectedProduct' => $this->autocomplete->selectedProduct($request->old('product_id', $reservation->product_id)) ?? $this->autocomplete->productOption($reservation->product),
            'selectedStockItem' => $this->autocomplete->selectedStockItem($request->old('stock_item_id', $reservation->stock_item_id), includeProduct: true) ?? $this->autocomplete->stockItemOption($reservation->stockItem, includeProduct: true),
            'statuses' => Reservation::STATUSES,
        ]);
    }

    public function update(ReservationRequest $request, Reservation $reservation): RedirectResponse
    {
        $validated = $request->validated();
        $newStatus = $validated['status'] ?? $reservation->status;

        if ($reservation->status === 'active' && in_array($newStatus, ['released', 'cancelled'], true)) {
            $this->stockService->unreserve($reservation->stockItem, $reservation->quantity, [
                'comment' => $validated['comment'] ?? null,
                'document_number' => $validated['customer_order_id'] ?? null,
            ]);

            $reservation->refresh();
        } else {
            $reservation->update([
                'customer_order_id' => $validated['customer_order_id'] ?? null,
                'expires_at' => $validated['expires_at'] ?? null,
                'comment' => $validated['comment'] ?? null,
                'status' => $newStatus,
            ]);
        }

        return redirect()->route('admin.reservations.index')->with('status', ' .');
    }

    public function destroy(Reservation $reservation): RedirectResponse
    {
        if ($reservation->status === 'active') {
            $this->stockService->unreserve($reservation->stockItem, $reservation->quantity, [
                'comment' => '    .',
            ]);
        }

        $reservation->delete();

        return redirect()->route('admin.reservations.index')->with('status', ' .');
    }
}
