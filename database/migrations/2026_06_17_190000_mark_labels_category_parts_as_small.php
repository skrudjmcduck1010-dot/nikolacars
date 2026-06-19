<?php

use App\Support\PartNumberNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const REASON = 'category: labels';

    private const LABEL_CATEGORY_SOURCES = [
        'tesla_official',
        'nikolacars',
    ];

    private const LABEL_NAMES = [
        'LABELS',
        'Labels',
        "\u{042D}\u{0442}\u{0438}\u{043A}\u{0435}\u{0442}\u{043A}\u{0438}",
        "\u{042D}\u{0422}\u{0418}\u{041A}\u{0415}\u{0422}\u{041A}\u{0418}",
        "\u{0415}\u{0442}\u{0438}\u{043A}\u{0435}\u{0442}\u{043A}\u{0438}",
        "\u{0415}\u{0422}\u{0418}\u{041A}\u{0415}\u{0422}\u{041A}\u{0418}",
    ];

    public function up(): void
    {
        if (! Schema::hasTable('part_catalog_categories') || ! Schema::hasTable('part_catalog_items')) {
            return;
        }

        $partNumbers = $this->labelsCategoryPartNumbers();

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
                    $rawAttributes['donor_vin_small_part_reason'] = self::REASON;
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

    protected function labelsCategoryPartNumbers(): Collection
    {
        $categoryIds = $this->labelsCategoryIds();
        $itemIds = collect();

        if ($categoryIds->isNotEmpty()) {
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
        }

        $partNumbers = collect();

        if ($itemIds->isNotEmpty()) {
            $partNumbers = $partNumbers->merge(
                DB::table('part_catalog_items')
                    ->whereIn('id', $itemIds->filter()->unique()->values()->all())
                    ->pluck('part_number')
            );
        }

        $partNumbers = $partNumbers->merge($this->labelsTextPartNumbers());

        return $partNumbers
            ->map(fn ($partNumber): ?string => PartNumberNormalizer::normalize((string) $partNumber))
            ->filter()
            ->unique()
            ->values();
    }

    protected function labelsCategoryIds(): Collection
    {
        $rootIds = DB::table('part_catalog_categories')
            ->whereIn('source', self::LABEL_CATEGORY_SOURCES)
            ->where(function ($query): void {
                foreach (['name', 'name_en', 'name_ru', 'name_ua'] as $column) {
                    $query->orWhereIn($column, self::LABEL_NAMES);
                }
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

    protected function labelsTextPartNumbers(): Collection
    {
        return DB::table('part_catalog_items')
            ->whereIn('source', self::LABEL_CATEGORY_SOURCES)
            ->where(function ($query): void {
                foreach (['main_category_name', 'subcategory_name', 'node_name'] as $column) {
                    $query->orWhereIn($column, self::LABEL_NAMES);
                }
            })
            ->pluck('part_number');
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
