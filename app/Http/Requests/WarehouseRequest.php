<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WarehouseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'floor_count' => ['required', 'integer', 'min:1', 'max:20'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
