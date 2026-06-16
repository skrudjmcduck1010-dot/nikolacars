<?php

namespace App\Services;

use App\Models\PartCatalogCategory;
use App\Models\PartCatalogItem;
use App\Support\PartCatalogRawAttributes;
use Illuminate\Support\Collection;

class NikolaCarsOfficialPartEnrichmentService
{
    public function enrich(PartCatalogItem|string $itemOrPartNumber): NikolaCarsOfficialPartEnrichment
    {
        $match = app(NikolaCarsOfficialPartMatcher::class)->match($itemOrPartNumber);
        $officialItem = $match->officialItem;

        if (! $officialItem instanceof PartCatalogItem) {
            return new NikolaCarsOfficialPartEnrichment(
                match: $match,
                officialItem: null,
                requestedPartNumber: $match->normalizedPartNumber,
                officialPartNumber: null,
                officialUrl: null,
                officialName: null,
                categoryParts: [],
                categoryPath: null,
                compatibilityModels: [],
                occurrences: [],
                schemeNumber: null,
                partImageUrls: [],
                schemeImageUrls: [],
                imageUrls: [],
            );
        }

        $rawAttributes = $this->rawAttributes($officialItem);
        $occurrences = $this->occurrences($officialItem, $rawAttributes);
        $categoryParts = $this->categoryParts($officialItem);
        $partImageUrls = $this->stringList(data_get($rawAttributes, 'part_image_urls'));
        $schemeImageUrls = $this->stringList(data_get($rawAttributes, 'system_group_image_urls'));
        $imageUrls = $this->stringList(data_get($rawAttributes, 'image_urls'))
            ->merge($partImageUrls)
            ->merge($schemeImageUrls)
            ->unique()
            ->values()
            ->all();

        return new NikolaCarsOfficialPartEnrichment(
            match: $match,
            officialItem: $officialItem,
            requestedPartNumber: $match->normalizedPartNumber,
            officialPartNumber: $officialItem->part_number,
            officialUrl: $officialItem->source_url,
            officialName: trim((string) ($officialItem->name_en ?: $officialItem->name)) ?: null,
            categoryParts: $categoryParts->all(),
            categoryPath: $categoryParts->isNotEmpty() ? $categoryParts->implode(' / ') : null,
            compatibilityModels: $this->compatibilityModels($officialItem, $occurrences)->all(),
            occurrences: $occurrences->all(),
            schemeNumber: $officialItem->scheme_number !== null ? (int) $officialItem->scheme_number : null,
            partImageUrls: $partImageUrls->all(),
            schemeImageUrls: $schemeImageUrls->all(),
            imageUrls: $imageUrls,
        );
    }

    protected function occurrences(PartCatalogItem $officialItem, array $rawAttributes): Collection
    {
        $occurrences = collect((array) data_get($rawAttributes, 'official_catalog_occurrences', []))
            ->filter(fn (mixed $row): bool => is_array($row))
            ->map(fn (array $row): array => [
                'model' => $this->clean($row['model_label'] ?? $row['model_name'] ?? null),
                'category' => $this->codeName($row['main_category_code'] ?? null, $row['main_category_name'] ?? null),
                'subcategory' => $this->codeName($row['subcategory_code'] ?? null, $row['subcategory_name'] ?? null),
                'group' => $this->clean($row['node_name'] ?? $row['system_group_name'] ?? null),
                'category_id' => (int) ($row['category_id'] ?? 0) ?: null,
                'source_url' => $this->clean($row['source_url'] ?? null),
            ])
            ->values();

        if ($occurrences->isNotEmpty()) {
            return $occurrences;
        }

        return collect([[
            'model' => $this->clean($officialItem->model_label ?: $officialItem->model_name),
            'category' => $this->codeName($officialItem->main_category_code, $officialItem->main_category_name),
            'subcategory' => $this->codeName($officialItem->subcategory_code, $officialItem->subcategory_name),
            'group' => $this->clean($officialItem->node_name),
            'category_id' => $officialItem->part_catalog_category_id ? (int) $officialItem->part_catalog_category_id : null,
            'source_url' => $officialItem->source_url,
        ]])->filter(fn (array $row): bool => collect($row)->filter()->isNotEmpty())->values();
    }

    protected function categoryParts(PartCatalogItem $officialItem): Collection
    {
        $category = $officialItem->category
            ?: $officialItem->occurrences->pluck('category')->filter()->first();

        if ($category instanceof PartCatalogCategory) {
            $trail = collect();
            $current = $category;

            while ($current) {
                $trail->prepend($current);
                $current = $current->parent;
            }

            $parts = $trail
                ->filter(fn (PartCatalogCategory $trailCategory): bool => (int) $trailCategory->depth > 0)
                ->values();

            return ($parts->isNotEmpty() ? $parts : $trail)
                ->map(fn (PartCatalogCategory $trailCategory): string => $this->categoryLabel($trailCategory))
                ->filter()
                ->unique()
                ->values();
        }

        return collect([
            $officialItem->main_category_name,
            $officialItem->subcategory_name,
            $officialItem->node_name,
        ])
            ->map(fn (mixed $value): string => $this->clean($value))
            ->filter()
            ->unique()
            ->values();
    }

    protected function compatibilityModels(PartCatalogItem $officialItem, Collection $occurrences): Collection
    {
        return $occurrences
            ->pluck('model')
            ->push($officialItem->model_label)
            ->push($officialItem->model_name)
            ->map(fn (mixed $value): string => $this->clean($value))
            ->filter()
            ->unique()
            ->values();
    }

    protected function stringList(mixed $value): Collection
    {
        return collect((array) $value)
            ->map(fn (mixed $url): string => $this->clean($url))
            ->filter()
            ->unique()
            ->values();
    }

    protected function categoryLabel(PartCatalogCategory $category): string
    {
        return $this->clean(
            $category->name_ru
            ?: $category->name_ua
            ?: $category->name_en
            ?: $category->name
        );
    }

    protected function codeName(mixed $code, mixed $name): ?string
    {
        $code = $this->clean($code);
        $name = $this->clean($name);

        if ($code === '') {
            return $name !== '' ? $name : null;
        }

        if ($name === '') {
            return $code;
        }

        return $code.' '.$name;
    }

    protected function clean(mixed $value): string
    {
        return trim((string) $value);
    }

    protected function rawAttributes(PartCatalogItem $item): array
    {
        return PartCatalogRawAttributes::from($item);
    }
}
