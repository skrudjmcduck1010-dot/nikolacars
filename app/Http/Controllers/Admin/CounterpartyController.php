<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\CounterpartyRequest;
use App\Models\Counterparty;
use App\Models\CounterpartyVehicle;
use App\Models\PartCatalogCategory;
use App\Support\CatalogTextEncoding;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CounterpartyController extends Controller
{
    public function index(): View
    {
        return view('admin.counterparties.index', [
            'counterparties' => Counterparty::query()->orderBy('name')->paginate(20),
        ]);
    }

    public function create(): View
    {
        return view('admin.counterparties.form', [
            'counterparty' => new Counterparty,
            'models' => PartCatalogCategory::modelOptions(),
        ]);
    }

    public function store(CounterpartyRequest $request): RedirectResponse
    {
        Counterparty::query()->create($this->payload($request));

        return redirect()->route('admin.counterparties.index')->with('status', 'Контрагент создан.');
    }

    public function show(Counterparty $counterparty): View
    {
        return view('admin.counterparties.show', [
            'counterparty' => $counterparty->load(['movements.product', 'vehicles']),
            'models' => PartCatalogCategory::modelOptions(),
        ]);
    }

    public function storeVehicle(Request $request, Counterparty $counterparty): RedirectResponse
    {
        $validated = $request->validate([
            'car_model' => ['required', 'string', 'max:255', Rule::in(PartCatalogCategory::modelOptions($request->input('car_model')))],
            'car_year' => ['required', 'integer', 'min:1990', 'max:'.(date('Y') + 1)],
            'drive_type' => ['required', Rule::in(Counterparty::DRIVE_TYPES)],
            'vin' => ['required', 'string', 'max:255'],
            'license_plate' => ['required', 'string', 'max:255'],
        ]);

        $validated['car_model'] = CatalogTextEncoding::repair($validated['car_model']);
        $validated['license_plate'] = CatalogTextEncoding::repair($validated['license_plate']);

        $counterparty->vehicles()->create($validated);

        return redirect()
            ->route('admin.counterparties.show', $counterparty)
            ->with('status', 'Автомобиль добавлен клиенту.');
    }

    public function destroyPrimaryVehicle(Counterparty $counterparty): RedirectResponse
    {
        $counterparty->update([
            'car_model' => null,
            'car_year' => null,
            'drive_type' => null,
            'vin' => null,
            'license_plate' => null,
        ]);

        return redirect()
            ->route('admin.counterparties.show', $counterparty)
            ->with('status', 'Автомобиль удален у клиента.');
    }

    public function destroyVehicle(Counterparty $counterparty, CounterpartyVehicle $vehicle): RedirectResponse
    {
        abort_unless($vehicle->counterparty_id === $counterparty->id, 404);

        $vehicle->delete();

        return redirect()
            ->route('admin.counterparties.show', $counterparty)
            ->with('status', 'Автомобиль удален у клиента.');
    }

    public function edit(Counterparty $counterparty): View
    {
        return view('admin.counterparties.form', [
            'counterparty' => $counterparty,
            'models' => PartCatalogCategory::modelOptions($counterparty->car_model),
        ]);
    }

    public function update(CounterpartyRequest $request, Counterparty $counterparty): RedirectResponse
    {
        $counterparty->update($this->payload($request));

        return redirect()->route('admin.counterparties.index')->with('status', 'Контрагент обновлен.');
    }

    protected function payload(CounterpartyRequest $request): array
    {
        return [
            ...$request->validated(),
            'is_active' => $request->boolean('is_active'),
        ];
    }
}
