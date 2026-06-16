<?php

use App\Support\PartNumberNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('part_catalog_categories') || ! Schema::hasTable('part_catalog_items')) {
            return;
        }

        $categoryIds = $this->ownerInformationCategoryIds();

        if ($categoryIds->isEmpty()) {
            return;
        }

        $partNumbers = $this->partNumbersInCategories($categoryIds);

        if ($partNumbers->isEmpty()) {
            return;
        }

        DB::table('part_catalog_items')
            ->select(['id', 'part_number', 'raw_attributes'])
            ->whereIn('part_number', $partNumbers->all())
            ->orderBy('id')
            ->chunkById(500, function (Collection $items): void {
                foreach ($items as $item) {
                    $partNumber = PartNumberNormalizer::normalize((string) $item->part_number);

                    if ($partNumber === null) {
                        continue;
                    }

                    $rawAttributes = $this->rawAttributes($item);

                    if ((bool) data_get($rawAttributes, 'donor_vin_small_part')) {
                        continue;
                    }

                    $rawAttributes['donor_vin_small_part'] = true;
                    $rawAttributes['donor_vin_small_part_part_number'] = $partNumber;
                    $rawAttributes['donor_vin_small_part_reason'] = 'category: owner_information';
                    $rawAttributes['donor_vin_small_part_marked_at'] = now()->toIso8601String();

                    DB::table('part_catalog_items')
                        ->where('id', $item->id)
                        ->update([
                            'raw_attributes' => json_encode($rawAttributes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            'updated_at' => now(),
                        ]);
                }
            });
    }

    public function down(): void
    {
        // One-time data classification; intentionally not reversible.
    }

    protected function ownerInformationCategoryIds(): Collection
    {
        $rootIds = DB::table('part_catalog_categories')
            ->where('source', 'tesla_official')
            ->where(function ($query): void {
                $query
                    ->where('name', 'like', '%OWNER INFORMATION%')
                    ->orWhere('name_en', 'like', '%Owner Information%')
                    ->orWhere('name_ru', 'like', '%РУКОВОДСТВО%')
                    ->orWhere('name_ua', 'like', '%КЕРІВНИЦТВО%');
            })
            ->pluck('id');

        $categoryIds = collect();
        $frontier = $rootIds;

        while ($frontier->isNotEmpty()) {
            $categoryIds = $categoryIds->merge($frontier);
            $frontier = DB::table('part_catalog_categories')
                ->whereIn('parent_id', $frontier->all())
                ->pluck('id');
        }

        return $categoryIds
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
    }

    protected function partNumbersInCategories(Collection $categoryIds): Collection
    {
        $itemIds = DB::table('part_catalog_items')
            ->whereIn('part_catalog_category_id', $categoryIds->all())
            ->pluck('id');

        if (Schema::hasTable('part_catalog_item_occurrences')) {
            $itemIds = $itemIds->merge(
                DB::table('part_catalog_item_occurrences')
                    ->whereIn('part_catalog_category_id', $categoryIds->all())
                    ->pluck('part_catalog_item_id')
            );
        }

        return DB::table('part_catalog_items')
            ->whereIn('id', $itemIds->filter()->unique()->values()->all())
            ->pluck('part_number')
            ->map(fn ($partNumber): ?string => PartNumberNormalizer::normalize((string) $partNumber))
            ->filter()
            ->unique()
            ->values();
    }

    protected function rawAttributes(object $item): array
    {
        $rawAttributes = $item->raw_attributes ?? null;

        if (is_array($rawAttributes)) {
            return $rawAttributes;
        }

        if (is_object($rawAttributes) && method_exists($rawAttributes, 'getArrayCopy')) {
            return $rawAttributes->getArrayCopy();
        }

        if (! is_string($rawAttributes) || trim($rawAttributes) === '') {
            return [];
        }

        $decoded = json_decode($rawAttributes, true);

        return is_array($decoded) ? $decoded : [];
    }
};
