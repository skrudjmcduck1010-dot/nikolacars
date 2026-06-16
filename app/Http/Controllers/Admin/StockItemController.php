<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StockItemRequest;
use App\Models\Location;
use App\Models\Product;
use App\Models\StockItem;
use App\Models\Warehouse;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class StockItemController extends Controller
{
    public function index(): View
    {
        return view('admin.stock_items.index', [
            'stockItems' => StockItem::query()
                ->with(['product', 'warehouse', 'location'])
                ->latest()
                ->paginate(20),
        ]);
    }

    public function create(): View
    {
        return view('admin.stock_items.form', [
            'stockItem' => new StockItem,
            ...$this->formOptions(),
        ]);
    }

    public function store(StockItemRequest $request): RedirectResponse
    {
        $stockItem = new StockItem($this->payload($request));
        $stockItem->syncAvailableQuantity();
        $stockItem->save();

        return redirect()->route('admin.stock-items.index')->with('status', 'Остаток создан.');
    }

    public function show(StockItem $stockItem): View
    {
        return view('admin.stock_items.show', [
            'stockItem' => $stockItem->load(['product', 'warehouse', 'location', 'reservations', 'movements']),
        ]);
    }

    public function edit(StockItem $stockItem): View
    {
        return view('admin.stock_items.form', [
            'stockItem' => $stockItem,
            ...$this->formOptions(),
        ]);
    }

    public function update(StockItemRequest $request, StockItem $stockItem): RedirectResponse
    {
        $stockItem->fill($this->payload($request));
        $stockItem->syncAvailableQuantity();
        $stockItem->save();

        return redirect()->route('admin.stock-items.index')->with('status', 'Остаток обновлен.');
    }

    public function destroy(StockItem $stockItem): RedirectResponse
    {
        $stockItem->delete();

        return redirect()->route('admin.stock-items.index')->with('status', 'Остаток удален.');
    }

    protected function payload(StockItemRequest $request): array
    {
        return [
            ...$request->validated(),
            'reserved_quantity' => (int) ($request->validated()['reserved_quantity'] ?? 0),
        ];
    }

    protected function formOptions(): array
    {
        return [
            'products' => Product::query()->orderBy('name')->get(),
            'warehouses' => Warehouse::query()->orderBy('name')->get(),
            'locations' => Location::query()->with('warehouse')->orderBy('full_code')->get(),
            'testingStatuses' => Product::TESTING_STATUSES,
        ];
    }
}
