<?php

namespace App\Http\Requests;

use App\Models\Counterparty;
use App\Models\PartCatalogCategory;
use App\Support\CatalogTextEncoding;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CounterpartyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge($this->repairTextFields([
            'name',
            'address',
            'notes',
            'car_model',
            'license_plate',
        ]));
    }

    public function rules(): array
    {
        $isStoClient = in_array($this->input('type'), [Counterparty::TYPE_STO_CUSTOMER, Counterparty::TYPE_BOTH], true);

        return [
            'type' => ['required', Rule::in(Counterparty::TYPES)],
            'name' => ['required', 'string', 'max:255'],
            'phone' => [$isStoClient ? 'required' : 'nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'car_model' => [$isStoClient ? 'required' : 'nullable', 'string', 'max:255', Rule::in(PartCatalogCategory::modelOptions($this->input('car_model')))],
            'car_year' => [$isStoClient ? 'required' : 'nullable', 'integer', 'min:1990', 'max:'.(date('Y') + 1)],
            'drive_type' => [$isStoClient ? 'required' : 'nullable', Rule::in(Counterparty::DRIVE_TYPES)],
            'vin' => [$isStoClient ? 'required' : 'nullable', 'string', 'max:255'],
            'license_plate' => [$isStoClient ? 'required' : 'nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    protected function repairTextFields(array $fields): array
    {
        return collect($fields)
            ->filter(fn (string $field): bool => $this->has($field))
            ->mapWithKeys(fn (string $field): array => [
                $field => CatalogTextEncoding::repair($this->input($field)),
            ])
            ->all();
    }
}
