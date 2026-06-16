<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\LocationRequest;
use App\Models\Location;
use App\Models\Warehouse;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class LocationController extends Controller
{
    public function index(): View
    {
        return view('admin.locations.index', [
            'locations' => Location::query()->with('warehouse')->latest()->paginate(20),
        ]);
    }

    public function create(): View
    {
        return view('admin.locations.form', [
            'location' => new Location,
            'warehouses' => $this->editableWarehouses(),
        ]);
    }

    public function store(LocationRequest $request): RedirectResponse
    {
        if (Warehouse::query()
            ->whereKey($request->integer('warehouse_id'))
            ->where('type', Warehouse::TYPE_DONOR)
            ->exists()) {
            return back()
                ->withErrors(['warehouse_id' => 'Для склада "На доноре" ячейки создаются автоматически по донору.'])
                ->withInput();
        }

        Location::query()->create($this->payload($request));

        if ($request->input('redirect_to') === 'warehouses') {
            return redirect()->route('admin.warehouses.index')->with('status', 'Ячейка создана.');
        }

        return redirect()->route('admin.locations.index')->with('status', 'Ячейка создана.');
    }

    public function show(Location $location): View
    {
        return view('admin.locations.show', [
            'location' => $location->load(['warehouse', 'stockItems.product']),
        ]);
    }

    public function edit(Location $location): View
    {
        return view('admin.locations.form', [
            'location' => $location,
            'warehouses' => Warehouse::query()->orderBy('name')->get(),
        ]);
    }

    public function update(LocationRequest $request, Location $location): RedirectResponse
    {
        $location->update($this->payload($request));

        return redirect()->route('admin.locations.index')->with('status', 'Ячейка обновлена.');
    }

    public function destroy(Location $location): RedirectResponse
    {
        $location->delete();

        return redirect()->route('admin.locations.index')->with('status', 'Ячейка удалена.');
    }

    protected function payload(LocationRequest $request): array
    {
        return [
            ...$request->validated(),
            'is_active' => $request->boolean('is_active'),
        ];
    }

    protected function editableWarehouses()
    {
        return Warehouse::query()
            ->where(fn ($query) => $query
                ->whereNull('type')
                ->orWhere('type', '!=', Warehouse::TYPE_DONOR))
            ->orderBy('name')
            ->get();
    }
}
