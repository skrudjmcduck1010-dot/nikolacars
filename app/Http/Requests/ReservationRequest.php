<?php

namespace App\Http\Requests;

use App\Models\Reservation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_id' => ['required', 'exists:products,id'],
            'stock_item_id' => ['required', 'exists:stock_items,id'],
            'customer_order_id' => ['nullable', 'string', 'max:255'],
            'quantity' => ['required', 'integer', 'min:1'],
            'status' => ['nullable', Rule::in(Reservation::STATUSES)],
            'expires_at' => ['nullable', 'date'],
            'comment' => ['nullable', 'string'],
        ];
    }
}
