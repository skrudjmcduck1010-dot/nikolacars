<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\WarehouseRequest;
use App\Models\Location;
use App\Models\StockItem;
use App\Models\Warehouse;
use App\Services\NikolaCarsProductInventorySyncService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class WarehouseController extends Controller
{
    public function index(Request $request): View
    {
        $selectedWarehouse = null;
        $selectedFloor = null;
        $selectedLocation = null;
        $selectedStockItems = collect();
        $selectedPartsTitle = null;

        $warehouseId = $request->integer('warehouse_id');
        $locationId = $request->integer('location_id');
        $requestedFloor = $request->string('floor')->toString();

        if ($warehouseId > 0) {
            $selectedWarehouse = Warehouse::query()->find($warehouseId);
        }

        if ($selectedWarehouse instanceof Warehouse && $locationId > 0) {
            $selectedLocation = Location::query()
                ->where('warehouse_id', $selectedWarehouse->id)
                ->find($locationId);
        }

        if ($selectedWarehouse instanceof Warehouse && $selectedWarehouse->type !== Warehouse::TYPE_DONOR && $selectedLocation instanceof Location) {
            $selectedFloor = $selectedLocation->floor;
        } elseif ($selectedWarehouse instanceof Warehouse && $selectedWarehouse->type !== Warehouse::TYPE_DONOR && array_key_exists($requestedFloor, $selectedWarehouse->availableFloors())) {
            $selectedFloor = $requestedFloor;
        }

        if ($selectedWarehouse instanceof Warehouse) {
            $selectedStockItems = StockItem::query()
                ->with(['product.sourcePartCatalogItem', 'warehouse', 'location'])
                ->where('warehouse_id', $selectedWarehouse->id)
                ->when($selectedLocation instanceof Location, fn ($query) => $query->where('location_id', $selectedLocation->id))
                ->when($selectedWarehouse->type !== Warehouse::TYPE_DONOR && ! ($selectedLocation instanceof Location) && $selectedFloor, fn ($query) => $query->whereHas('location', fn ($locationQuery) => $locationQuery->where('floor', $selectedFloor)))
                ->tap(fn (Builder $query) => $this->constrainVisibleStockItems($query))
                ->orderBy(Location::query()->select('floor')->whereColumn('locations.id', 'stock_items.location_id'))
                ->orderBy(Location::query()->select('full_code')->whereColumn('locations.id', 'stock_items.location_id'))
                ->latest('id')
                ->get();

            $selectedPartsTitle = $selectedWarehouse->name;

            if ($selectedWarehouse->type !== Warehouse::TYPE_DONOR && $selectedFloor) {
                $selectedPartsTitle .= ' · '.($selectedWarehouse->availableFloors()[$selectedFloor] ?? $selectedFloor);
            }

            if ($selectedLocation instanceof Location) {
                $selectedPartsTitle .= ' · '.$selectedLocation->shortCode();
            }
        }

        return view('admin.warehouses.index', [
            'warehouses' => Warehouse::query()
                ->with(['locations' => fn ($query) => $query
                    ->withSum([
                        'stockItems as parts_quantity' => fn ($stockQuery) => $stockQuery
                            ->tap(fn (Builder $query) => $this->constrainVisibleStockItems($query)),
                    ], 'quantity')
                    ->orderBy('floor')
                    ->orderBy('full_code')])
                ->withExists([
                    'stockItems as has_stock' => fn ($query) => $query
                        ->tap(fn (Builder $stockQuery) => $this->constrainVisibleStockItems($stockQuery)),
                ])
                ->withSum([
                    'stockItems as stock_quantity' => fn ($query) => $query
                        ->tap(fn (Builder $stockQuery) => $this->constrainVisibleStockItems($stockQuery)),
                ], 'quantity')
                ->withSum([
                    'stockItems as reserved_quantity' => fn ($query) => $query
                        ->tap(fn (Builder $stockQuery) => $this->constrainVisibleStockItems($stockQuery)),
                ], 'reserved_quantity')
                ->withSum([
                    'stockItems as available_quantity' => fn ($query) => $query
                        ->tap(fn (Builder $stockQuery) => $this->constrainVisibleStockItems($stockQuery)),
                ], 'available_quantity')
                ->withCount([
                    'stockItems as product_positions_count' => fn ($query) => $query
                        ->tap(fn (Builder $stockQuery) => $this->constrainVisibleStockItems($stockQuery))
                        ->select(DB::raw('count(distinct product_id)')),
                ])
                ->latest()
                ->paginate(15),
            'selectedWarehouse' => $selectedWarehouse,
            'selectedFloor' => $selectedFloor,
            'selectedLocation' => $selectedLocation,
            'selectedPartsTitle' => $selectedPartsTitle,
            'selectedStockItems' => $selectedStockItems,
        ]);
    }

    public function create(): View
    {
        return view('admin.warehouses.form', [
            'warehouse' => new Warehouse,
        ]);
    }

    public function store(WarehouseRequest $request): RedirectResponse
    {
        Warehouse::query()->create($this->payload($request));

        return redirect()->route('admin.warehouses.index')->with('status', 'Склад создан.');
    }

    public function show(Warehouse $warehouse): View
    {
        return view('admin.warehouses.show', [
            'warehouse' => $warehouse->load('locations'),
        ]);
    }

    public function edit(Warehouse $warehouse): View
    {
        return view('admin.warehouses.form', compact('warehouse'));
    }

    public function update(WarehouseRequest $request, Warehouse $warehouse): RedirectResponse
    {
        $warehouse->update($this->payload($request));

        return redirect()->route('admin.warehouses.index')->with('status', 'Склад обновлен.');
    }

    public function destroy(Warehouse $warehouse): RedirectResponse
    {
        if ($warehouse->stockItems()
            ->where(fn ($query) => $query
                ->where('quantity', '>', 0)
                ->orWhere('reserved_quantity', '>', 0))
            ->exists()) {
            return redirect()
                ->route('admin.warehouses.index')
                ->with('status', 'Нельзя удалить склад, на котором есть товары.');
        }

        $warehouse->delete();

        return redirect()->route('admin.warehouses.index')->with('status', 'Склад удален.');
    }

    protected function payload(WarehouseRequest $request): array
    {
        return [
            ...$request->validated(),
            'floor_count' => (int) $request->input('floor_count', 1),
            'is_active' => $request->boolean('is_active'),
        ];
    }

    protected function constrainVisibleStockItems(Builder $query): void
    {
        $query
            ->where(fn (Builder $stockQuery) => $stockQuery
                ->where('quantity', '>', 0)
                ->orWhere('reserved_quantity', '>', 0))
            ->where(fn (Builder $stockQuery) => $stockQuery
                ->whereHas('warehouse', fn (Builder $warehouseQuery) => $warehouseQuery
                    ->where(fn (Builder $typeQuery) => $typeQuery
                        ->whereNull('type')
                        ->orWhere('type', '!=', Warehouse::TYPE_DONOR)))
                ->orWhereHas('product', fn (Builder $productQuery) => $productQuery
                    ->whereIn('notes', NikolaCarsProductInventorySyncService::CHECKED_DAMAGE_STATUSES)));
    }
}
