<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateNikolaCarsCatalogItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'part_number' => ['nullable', 'string', 'max:80'],
            'name_ru' => ['nullable', 'string', 'max:255'],
            'name_ua' => ['nullable', 'string', 'max:255'],
            'quantity' => ['nullable', 'numeric', 'min:0'],
            'price_amount' => ['nullable', 'numeric', 'min:0'],
            'notes_ua' => ['nullable', 'string'],
            'apply_to_part_number' => ['nullable', 'boolean'],
        ];
    }
}
