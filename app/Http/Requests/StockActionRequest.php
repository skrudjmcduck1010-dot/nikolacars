<?php

namespace App\Http\Requests;

use App\Models\Movement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StockActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'type' => ['required', Rule::in(Movement::TYPES)],
            'product_id' => ['nullable', 'exists:products,id'],
            'stock_item_id' => ['nullable', 'exists:stock_items,id'],
            'warehouse_id' => ['nullable', 'exists:warehouses,id'],
            'location_id' => ['nullable', 'exists:locations,id'],
            'to_location_id' => ['nullable', 'exists:locations,id'],
            'counterparty_id' => ['nullable', 'exists:counterparties,id'],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'target_quantity' => ['nullable', 'integer', 'min:0'],
            'reason' => ['nullable', 'string', 'max:255'],
            'document_number' => ['nullable', 'string', 'max:255'],
            'comment' => ['nullable', 'string'],
            'customer_order_id' => ['nullable', 'string', 'max:255'],
            'expires_at' => ['nullable', 'date'],
        ];

        $type = $this->input('type');

        if (in_array($type, ['intake'], true)) {
            $rules['product_id'][0] = 'required';
            $rules['warehouse_id'][0] = 'required';
            $rules['location_id'][0] = 'required';
            $rules['quantity'][0] = 'required';
        }

        if (in_array($type, ['move', 'reserve', 'unreserve', 'sale', 'writeoff', 'adjustment'], true)) {
            $rules['stock_item_id'][0] = 'required';
        }

        if (in_array($type, ['move', 'reserve', 'unreserve', 'sale', 'writeoff'], true)) {
            $rules['quantity'][0] = 'required';
        }

        if ($type === 'move') {
            $rules['to_location_id'][0] = 'required';
        }

        if ($type === 'adjustment') {
            $rules['target_quantity'][0] = 'required';
        }

        if ($type === 'writeoff') {
            $rules['reason'][0] = 'required';
        }

        return [
            ...$rules,
        ];
    }
}
