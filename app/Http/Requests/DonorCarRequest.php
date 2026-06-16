<?php

namespace App\Http\Requests;

use App\Models\DonorCar;
use App\Models\PartCatalogCategory;
use App\Services\TeslaVinDecoder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DonorCarRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $donorCar = $this->route('donorCar');
        $decoded = app(TeslaVinDecoder::class)->decode($this->input('vin'));

        if ($donorCar && ($this->isMethod('put') || $this->isMethod('patch'))) {
            $lockedExpenseFields = [];

            foreach (DonorCar::DONOR_EXPENSE_FIELDS as $field) {
                if ($donorCar->isDonorExpenseFieldLocked($field)) {
                    $lockedExpenseFields[$field] = $donorCar->{$field};
                }
            }

            $this->merge([
                'vin' => $donorCar->vin,
                'status' => $donorCar->status,
                'brand' => $donorCar->brand,
                'model' => $donorCar->model,
                'drive_type' => trim((string) $this->input('drive_type')) !== ''
                    ? $this->input('drive_type')
                    : $donorCar->drive_type,
                'battery_type' => trim((string) $this->input('battery_type')) !== ''
                    ? $this->input('battery_type')
                    : $donorCar->battery_type,
                'is_performance' => $this->has('is_performance')
                    ? $this->input('is_performance')
                    : $donorCar->is_performance,
                ...$lockedExpenseFields,
            ]);

            return;
        }

        if ($decoded === null) {
            $this->merge([
                'status' => DonorCar::STATUS_IN_TRANSIT,
            ]);

            return;
        }

        $this->merge([
            'vin' => $decoded['vin'],
            'status' => DonorCar::STATUS_IN_TRANSIT,
            'brand' => trim((string) $this->input('brand')) !== '' ? $this->input('brand') : $decoded['brand'],
            'model' => trim((string) $this->input('model')) !== '' ? $this->input('model') : $decoded['model'],
            'year' => trim((string) $this->input('year')) !== '' ? $this->input('year') : $decoded['year'],
        ]);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $donorCar = $this->route('donorCar');
        $donorCar = $donorCar instanceof DonorCar ? $donorCar : null;
        $donorCarId = $donorCar?->id;

        return [
            'vin' => ['required', 'string', 'max:255', Rule::unique('donor_cars', 'vin')->ignore($donorCarId)],
            'brand' => ['required', Rule::in(DonorCar::BRANDS)],
            'model' => ['required', Rule::in(PartCatalogCategory::modelOptions($this->input('model')))],
            'drive_type' => ['nullable', Rule::in(array_keys(DonorCar::DRIVE_TYPES))],
            'battery_type' => ['nullable', Rule::in(array_keys(DonorCar::BATTERY_TYPES))],
            'is_performance' => ['nullable', 'boolean'],
            'year' => ['nullable', 'integer', 'between:1990,2100'],
            'color' => ['required', 'string', 'max:255'],
            'paint_code' => ['nullable', 'string', 'max:50'],
            'mileage' => ['required', 'integer', 'min:0', 'max:2000000'],
            'purchase_date' => [$this->isMethod('post') ? 'required' : 'nullable', 'date'],
            'warehouse_arrival_date' => ['nullable', 'date'],
            'estimated_cost_usd' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
            'usa_delivery_price_usd' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
            'klaipeda_ukraine_delivery_price_usd' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
            'customs_clearance_price_usd' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
            'notes' => ['nullable', 'string'],
            'photos' => ['nullable', 'array', 'max:'.DonorCar::PHOTO_LIMIT],
            'photos.*' => ['image', 'max:10240'],
            'remove_photos' => ['nullable', 'array'],
            'remove_photos.*' => ['string'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $donorCar = $this->route('donorCar');
            $donorCar = $donorCar instanceof DonorCar ? $donorCar : null;

            if ($this->photoCountAfterSave($donorCar) > DonorCar::PHOTO_LIMIT) {
                $validator->errors()->add('photos', 'Можно добавить не больше '.DonorCar::PHOTO_LIMIT.' фотографий к одному донору.');
            }
        });
    }

    private function photoCountAfterSave(?DonorCar $donorCar): int
    {
        $existingPhotos = $donorCar?->photos ?? [];
        $removePhotos = $this->input('remove_photos', []);
        $newPhotos = $this->file('photos', []);

        return count(array_diff($existingPhotos, $removePhotos)) + count($newPhotos);
    }
}
