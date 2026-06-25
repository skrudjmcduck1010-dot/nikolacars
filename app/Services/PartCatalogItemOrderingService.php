<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class PartCatalogItemOrderingService
{
    public function orderNikolaCarsItems(Builder $query, string $sort, string $direction): void
    {
        $direction = $this->direction($direction);
        $stockQuantity = $this->jsonText('raw_attributes', 'stock_quantity');
        $reservedQuantity = $this->jsonText('raw_attributes', 'reserved_quantity');
        $availableQuantity = $this->availableQuantityExpression($stockQuantity, $reservedQuantity);
        $donorVin = $this->jsonText('raw_attributes', 'donor_vin');
        $categoryDisplay = $this->jsonText('raw_attributes', 'category_display');
        $categoryPath = $this->jsonText('raw_attributes', 'category_path');
        $donorDamageCheckedAt = "nullif({$this->jsonText('raw_attributes', 'donor_damage_checked_at')}, '')";
        $normalizedDonorDamageCheckedAt = "replace(substr({$donorDamageCheckedAt}, 1, 19), 'T', ' ')";
        $changedAt = "coalesce({$normalizedDonorDamageCheckedAt}, updated_at, created_at)";

        match ($sort) {
            'stock', 'quantity' => $query
                ->orderByRaw("{$availableQuantity} {$direction}")
                ->orderBy('name_ua')
                ->orderBy('name'),
            'name' => $query
                ->orderBy('name_ua', $direction)
                ->orderBy('name', $direction),
            'part_number' => $query
                ->orderByRaw('part_number is null')
                ->orderBy('part_number', $direction)
                ->orderBy('name_ua'),
            'vin' => $query
                ->orderByRaw("{$donorVin} is null")
                ->orderByRaw("{$donorVin} {$direction}")
                ->orderBy('name_ua'),
            'price' => $query
                ->orderByRaw('price_amount is null')
                ->orderBy('price_amount', $direction)
                ->orderBy('name_ua')
                ->orderBy('name'),
            'created_at' => $query
                ->orderByRaw("{$changedAt} is null")
                ->orderByRaw("{$changedAt} {$direction}")
                ->orderBy('id', $direction),
            default => $query
                ->orderByRaw("coalesce({$categoryDisplay}, {$categoryPath}, '') {$direction}")
                ->orderBy('name_ua')
                ->orderBy('name'),
        };
    }

    public function orderCompetitorCatalogItems(Builder $query, string $sort, string $direction): void
    {
        $direction = $this->direction($direction);

        match ($sort) {
            'id' => $query->orderBy('id', $direction),
            'part_number' => $query
                ->orderByRaw('part_number is null')
                ->orderBy('part_number', $direction)
                ->orderBy('id'),
            'name' => $query
                ->orderByRaw("coalesce(name_ru, name_ua, name_en, name, '') {$direction}")
                ->orderBy('id'),
            'category' => $query
                ->orderByRaw("coalesce(main_category_name, subcategory_name, node_name, '') {$direction}")
                ->orderBy('id'),
            'price' => $query
                ->orderByRaw('price_amount is null')
                ->orderBy('price_amount', $direction)
                ->orderBy('id'),
            'availability' => $query
                ->orderByRaw("coalesce(availability, '') {$direction}")
                ->orderBy('id'),
            'created_at' => $query
                ->orderByRaw('created_at is null')
                ->orderBy('created_at', $direction)
                ->orderBy('id'),
            default => $query->orderByDesc('created_at')->orderByDesc('id'),
        };
    }

    private function direction(string $direction): string
    {
        return $direction === 'desc' ? 'desc' : 'asc';
    }

    private function jsonText(string $column, string $key): string
    {
        $path = '$.'.$key;

        return DB::connection()->getDriverName() === 'sqlite'
            ? "json_extract({$column}, '{$path}')"
            : "json_unquote(json_extract({$column}, '{$path}'))";
    }

    private function availableQuantityExpression(string $stockQuantity, string $reservedQuantity): string
    {
        $availableDifference = "cast(coalesce({$stockQuantity}, '0') as decimal(12,3)) - cast(coalesce({$reservedQuantity}, '0') as decimal(12,3))";

        return "case when {$availableDifference} > 0 then {$availableDifference} else 0 end";
    }
}
