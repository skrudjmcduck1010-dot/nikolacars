<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreNikolaCarsCatalogItemPhotosRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'photos' => ['required', 'array', 'min:1'],
            'photos.*' => ['image', 'max:15360'],
        ];
    }
}
