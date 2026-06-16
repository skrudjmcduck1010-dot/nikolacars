<?php

namespace App\Http\Requests;

use App\Models\PartCatalogCategory;
use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $conditionType = $this->input('condition_type') ?: 'used';

        $this->merge([
            'condition_type' => $conditionType,
        ]);
    }

    public function rules(): array
    {
        $productId = $this->route('product')?->id;

        return [
            'external_sku' => ['nullable', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', Rule::unique('products', 'slug')->ignore($productId)],
            'category_id' => ['nullable', 'exists:categories,id'],
            'brand_id' => ['nullable', 'exists:brands,id'],
            'donor_car_id' => ['nullable', 'exists:donor_cars,id'],
            'part_origin' => ['nullable', Rule::in(array_keys(Product::PART_ORIGINS))],
            'source_part_catalog_item_id' => ['nullable', 'exists:part_catalog_items,id'],
            'is_auto_generated' => ['nullable', 'boolean'],
            'storage_status' => ['nullable', Rule::in(array_keys(Product::STORAGE_STATUSES))],
            'description' => ['nullable', 'string'],
            'compatibility' => ['nullable', 'string'],
            'model' => ['nullable', 'string', 'max:255', Rule::in(PartCatalogCategory::modelOptions($this->input('model')))],
            'color' => ['nullable', 'string', 'max:255'],
            'generation' => ['nullable', 'string', 'max:255'],
            'side' => ['nullable', Rule::in(Product::SIDES)],
            'condition_type' => ['nullable', Rule::in(Product::CONDITION_TYPES)],
            'testing_status' => ['required', Rule::in(Product::TESTING_STATUSES)],
            'unit' => ['required', Rule::in(Product::UNITS)],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'selling_price' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'barcode' => ['nullable', 'string', 'max:255', Rule::unique('products', 'barcode')->ignore($productId)],
            'qr_code' => ['nullable', 'string', 'max:255', Rule::unique('products', 'qr_code')->ignore($productId)],
            'main_image' => ['nullable', 'string', 'max:255'],
            'images_json' => ['nullable', 'string'],
            'weight' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
