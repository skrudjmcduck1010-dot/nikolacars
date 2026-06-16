<?php

namespace App\Http\Requests;

use App\Models\Product;
use App\Models\Warehouse;
use App\Services\NikolaCarsCatalogItemService;
use App\Services\NikolaCarsProductInventorySyncService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreNikolaCarsCatalogItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $warehouse = Warehouse::query()->find($this->input('warehouse_id'));
        $isDonorWarehouse = app(NikolaCarsCatalogItemService::class)->isDonorWarehouse($warehouse);
        $selectedSourceType = $this->input('source_type') === 'donor' || filled($this->input('donor_car_id'))
            ? 'donor'
            : 'purchase';
        $floorRules = [$warehouse?->hasMultipleFloors() && ! $isDonorWarehouse ? 'required' : 'nullable', 'string'];

        if ($warehouse) {
            $floorRules[] = Rule::in(array_keys($warehouse->availableFloors()));
        }

        return [
            'create_nikolacars_part' => ['nullable', 'boolean'],
            'source_type' => ['nullable', Rule::in(['purchase', 'donor'])],
            'name' => ['nullable', 'string', 'max:255'],
            'name_ua' => ['required_without:name', 'string', 'max:255'],
            'name_ru' => ['nullable', 'string', 'max:255'],
            'part_number' => ['required', 'string', 'max:255'],
            'damage_note' => ['nullable', 'string', Rule::in(NikolaCarsProductInventorySyncService::CHECKED_DAMAGE_STATUSES)],
            'condition_type' => ['nullable', Rule::in(Product::CONDITION_TYPES)],
            'donor_car_id' => [Rule::requiredIf($isDonorWarehouse || $selectedSourceType === 'donor'), 'nullable', 'exists:donor_cars,id'],
            'purchase_price_usd' => ['nullable', 'numeric', 'min:0'],
            'selling_price' => ['nullable', 'numeric', 'min:0'],
            'description' => ['nullable', 'string'],
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'floor' => $floorRules,
            'location_cell' => ['nullable', 'string', 'max:50'],
        ];
    }
}
