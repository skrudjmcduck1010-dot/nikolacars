<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;

class StockItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_id' => ['required', 'exists:products,id'],
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'location_id' => ['required', 'exists:locations,id'],
            'quantity' => ['required', 'integer', 'min:0'],
            'reserved_quantity' => ['nullable', 'integer', 'min:0'],
            'testing_status' => ['required', Rule::in(Product::TESTING_STATUSES)],
            'received_at' => ['nullable', 'date'],
        ];
    }
}
