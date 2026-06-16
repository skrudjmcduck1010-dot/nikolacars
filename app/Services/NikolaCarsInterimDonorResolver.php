<?php

namespace App\Services;

use App\Models\DonorCar;
use App\Models\PartCatalogItem;
use App\Support\PartCatalogRawAttributes;
use Illuminate\Support\Str;

class NikolaCarsInterimDonorResolver
{
    public function resolve(?PartCatalogItem $item, ?string $donorVin = null): ?DonorCar
    {
        $donorVin = trim((string) $donorVin) ?: null;

        if ($donorVin !== null) {
            return DonorCar::query()->firstOrCreate(
                ['vin' => $donorVin],
                $this->payload($item, $donorVin, false),
            );
        }

        $label = $this->donorLabel($item);
        if ($label === null) {
            return null;
        }

        return DonorCar::query()->firstOrCreate(
            ['vin' => $this->vinFromLabel($label)],
            $this->payload($item, $label, true),
        );
    }

    public function donorLabel(?PartCatalogItem $item): ?string
    {
        if (! $item instanceof PartCatalogItem) {
            return null;
        }

        $raw = $this->rawAttributes($item);

        foreach (['category_display', 'category_path'] as $key) {
            $label = collect(explode(';', (string) ($raw[$key] ?? '')))
                ->map(fn (string $segment): string => $this->clean($segment))
                ->filter(fn (string $segment): bool => $this->isRemainderLabel($segment))
                ->first();

            if (is_string($label) && $label !== '') {
                return $label;
            }
        }

        $label = $this->clean((string) $item->compatibility_text);
        if ($this->isRemainderLabel($label)) {
            return $label;
        }

        $label = $this->clean((string) ($raw['donor_label'] ?? ''));
        if ($this->isUsableLabel($label)) {
            return $label;
        }

        $label = $this->clean((string) $item->compatibility_text);

        return $this->isUsableLabel($label) ? $label : null;
    }

    protected function payload(?PartCatalogItem $item, string $label, bool $interim): array
    {
        $model = $this->modelName($item);
        $notes = collect([
            $interim ? 'Interim NikolaCars donor created from catalog VIN/category.' : 'NikolaCars donor created from sale/catalog VIN.',
            'Catalog donor label: '.$label,
            $item?->part_number ? 'First linked part number: '.$item->part_number : null,
        ])->filter()->implode("\n");

        return [
            'status' => DonorCar::STATUS_DISMANTLING,
            'brand' => 'Tesla',
            'model' => $model,
            'year' => $this->year($item),
            'color' => null,
            'mileage' => null,
            'purchase_date' => null,
            'warehouse_arrival_date' => null,
            'notes' => $notes,
        ];
    }

    protected function vinFromLabel(string $label): string
    {
        return Str::limit($label, 255, '');
    }

    protected function modelName(?PartCatalogItem $item): string
    {
        $model = trim((string) ($item?->model_name ?: $item?->model_label));

        foreach (DonorCar::MODELS as $knownModel) {
            if (Str::contains(Str::lower($model), Str::lower($knownModel))) {
                return $knownModel;
            }
        }

        return 'Model Y';
    }

    protected function year(?PartCatalogItem $item): ?int
    {
        if ($item?->year_from !== null) {
            return (int) $item->year_from;
        }

        if (preg_match('/\b(20\d{2})\b/', (string) $item?->model_label, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    protected function isUsableLabel(string $label): bool
    {
        if ($label === '') {
            return false;
        }

        $normalized = Str::lower($label);

        return ! in_array($normalized, ['nikolacars', 'tesla', 'tesla nikolacars'], true);
    }

    protected function isRemainderLabel(string $label): bool
    {
        if (! $this->isUsableLabel($label)) {
            return false;
        }

        $normalized = Str::lower($label);

        return Str::contains($normalized, ['залишки', 'остатки']);
    }

    protected function rawAttributes(PartCatalogItem $item): array
    {
        return PartCatalogRawAttributes::from($item);
    }

    protected function clean(?string $value): string
    {
        return trim(html_entity_decode(preg_replace('/\s+/u', ' ', (string) $value) ?: '', ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
