<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\WarehouseRequest;
use App\Models\Warehouse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class WarehouseController extends Controller
{
    public function index(): View
    {
        return view('admin.warehouses.index', [
            'warehouses' => Warehouse::query()
                ->with(['locations' => fn ($query) => $query->orderBy('floor')->orderBy('full_code')])
                ->withExists([
                    'stockItems as has_stock' => fn ($query) => $query->where(fn ($stockQuery) => $stockQuery
                        ->where('quantity', '>', 0)
                        ->orWhere('reserved_quantity', '>', 0)),
                ])
                ->withSum('stockItems as stock_quantity', 'quantity')
                ->withSum('stockItems as reserved_quantity', 'reserved_quantity')
                ->withSum('stockItems as available_quantity', 'available_quantity')
                ->withCount([
                    'stockItems as product_positions_count' => fn ($query) => $query
                        ->select(DB::raw('count(distinct product_id)')),
                ])
                ->latest()
                ->paginate(15),
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
}
