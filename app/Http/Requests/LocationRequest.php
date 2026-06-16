<?php

namespace App\Http\Requests;

use App\Models\Location;
use App\Models\Warehouse;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $locationId = $this->route('location')?->id;
        $warehouse = Warehouse::query()->find($this->input('warehouse_id'));
        $availableFloors = $warehouse?->availableFloors() ?? Location::floorsForCount(1);

        return [
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'floor' => [$warehouse?->hasMultipleFloors() ? 'required' : 'nullable', Rule::in(array_keys($availableFloors))],
            'zone' => ['required', Rule::in(array_keys(Location::ZONES))],
            'row' => ['nullable', 'string', 'max:50'],
            'shelf' => ['nullable', 'string', 'max:50'],
            'cell' => ['nullable', 'string', 'max:50'],
            'full_code' => ['required', 'string', 'max:255', Rule::unique('locations', 'full_code')->ignore($locationId)],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $warehouse = Warehouse::query()->find($this->input('warehouse_id'));

        if ($warehouse && ! $warehouse->hasMultipleFloors()) {
            $this->merge(['floor' => 'floor_1']);
        }
    }
}
