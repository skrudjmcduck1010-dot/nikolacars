<?php

namespace App\Services;

use App\Models\PartCatalogItem;
use App\Support\PartNumberNormalizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class PartCatalogDeduplicator
{
    protected const SOURCE_PRIORITY = [
        'tesla_official' => 1,
        'tcarservice' => 10,
        'teslapartsukraine' => 20,
        'tsk' => 25,
        'stock-tesla' => 27,
        'driveparts' => 30,
        'dkparts' => 40,
    ];

    public function deduplicate(Collection $items): Collection
    {
        return $items
            ->groupBy(fn (PartCatalogItem $item): string => $this->uniqueKey($item))
            ->map(fn (Collection $group): PartCatalogItem => $this->preferredItem($group))
            ->sortBy([
                fn (PartCatalogItem $item): string => Str::lower((string) ($item->model_label ?: $item->model_name)),
                fn (PartCatalogItem $item): string => Str::lower((string) $item->name),
                fn (PartCatalogItem $item): string => (string) $item->part_number,
            ])
            ->values();
    }

    public function uniqueKey(PartCatalogItem $item): string
    {
        $partNumber = $this->normalizePartNumber($item->part_number);

        if ($partNumber !== '') {
            return 'part:'.$partNumber;
        }

        return collect([
            'text',
            $this->normalizeText($item->model_label ?: $item->model_name),
            $this->normalizeText($this->categoryText($item)),
            $this->normalizeText($item->name),
        ])->implode(':');
    }

    public function hasEquivalentGeneratedProduct(PartCatalogItem $catalogItem, int $donorCarId): bool
    {
        $key = $this->uniqueKey($catalogItem);

        return PartCatalogItem::query()
            ->whereHas('products', fn ($query) => $query->where('donor_car_id', $donorCarId))
            ->get(['id', 'part_number', 'name', 'model_label', 'model_name', 'main_category_name', 'subcategory_name', 'node_name'])
            ->contains(fn (PartCatalogItem $item): bool => $this->uniqueKey($item) === $key);
    }

    protected function preferredItem(Collection $items): PartCatalogItem
    {
        return $items
            ->sortBy([
                fn (PartCatalogItem $item): int => $item->part_number ? 0 : 1,
                fn (PartCatalogItem $item): int => trim((string) $item->name) !== '' ? 0 : 1,
                fn (PartCatalogItem $item): int => $item->source === 'tesla_official' && str_starts_with((string) $item->source_url, 'https://parts.tesla.com/') ? 0 : 1,
                fn (PartCatalogItem $item): int => $this->categoryText($item) !== '' ? 0 : 1,
                fn (PartCatalogItem $item): int => self::SOURCE_PRIORITY[$item->source] ?? 999,
                fn (PartCatalogItem $item): int => $item->id,
            ])
            ->first();
    }

    protected function normalizePartNumber(?string $value): string
    {
        return PartNumberNormalizer::compact($value);
    }

    protected function normalizeText(?string $value): string
    {
        return Str::lower(trim(preg_replace('/\s+/u', ' ', (string) $value) ?: ''));
    }

    protected function categoryText(PartCatalogItem $item): string
    {
        return collect([$item->main_category_name, $item->subcategory_name, $item->node_name])
            ->filter()
            ->implode(' / ');
    }
}
