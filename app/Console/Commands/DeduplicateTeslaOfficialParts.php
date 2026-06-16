<?php

namespace App\Console\Commands;

use App\Models\PartCatalogItem;
use App\Models\PartCatalogItemZone;
use App\Models\Product;
use App\Models\ProductPriceHistory;
use App\Support\PartCatalogRawAttributes;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class DeduplicateTeslaOfficialParts extends Command
{
    protected $signature = 'parts:dedupe-tesla-official
        {--dry-run : Show what would be merged without changing data}
        {--part-number=* : Limit to one or more part numbers}
        {--limit=0 : Limit duplicate groups}
        {--show-progress : Print each merged group}';

    protected $description = 'Merge duplicate official Tesla catalog rows by part number.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(0, (int) $this->option('limit'));
        $partNumbers = collect((array) $this->option('part-number'))
            ->map(fn (mixed $value): string => $this->normalizePartNumber((string) $value))
            ->filter()
            ->unique()
            ->values();

        $items = PartCatalogItem::query()
            ->where('source', 'tesla_official')
            ->where('source_url', 'like', 'https://parts.tesla.com/%')
            ->whereNotNull('part_number')
            ->where('part_number', '!=', '')
            ->when($partNumbers->isNotEmpty(), fn ($query) => $query->whereIn(DB::raw("replace(replace(upper(part_number), '-', ''), ' ', '')"), $partNumbers->all()))
            ->orderBy('part_number')
            ->orderBy('id')
            ->get();

        $groups = $items
            ->groupBy(fn (PartCatalogItem $item): string => $this->normalizePartNumber((string) $item->part_number))
            ->filter(fn (Collection $group): bool => $group->count() > 1)
            ->values();

        if ($limit > 0) {
            $groups = $groups->take($limit);
        }

        $stats = [
            'groups' => 0,
            'deleted_items' => 0,
            'products_relinked' => 0,
            'products_unlinked_conflict' => 0,
            'price_histories_relinked' => 0,
            'sales_relinked' => 0,
            'zones_merged' => 0,
        ];

        foreach ($groups as $group) {
            /** @var Collection<int, PartCatalogItem> $group */
            $keeper = $this->preferredItem($group);
            $duplicates = $group->reject(fn (PartCatalogItem $item): bool => (int) $item->id === (int) $keeper->id)->values();

            $stats['groups']++;
            $stats['deleted_items'] += $duplicates->count();

            if ((bool) $this->option('show-progress')) {
                $this->line(sprintf(
                    '%s keeper #%d, delete [%s]',
                    $keeper->part_number,
                    $keeper->id,
                    $duplicates->pluck('id')->implode(', ')
                ));
            }

            if ($dryRun) {
                continue;
            }

            DB::transaction(function () use ($group, $keeper, $duplicates, &$stats): void {
                $freshKeeper = PartCatalogItem::query()->lockForUpdate()->findOrFail($keeper->id);
                $freshGroup = PartCatalogItem::query()
                    ->whereIn('id', $group->pluck('id')->all())
                    ->lockForUpdate()
                    ->get();

                $this->mergeItemPayload($freshKeeper, $freshGroup);
                $this->relinkProducts($freshKeeper, $duplicates, $stats);
                $this->relinkPriceHistories($freshKeeper, $duplicates, $stats);
                $this->relinkSales($freshKeeper, $duplicates, $stats);
                $this->mergeZones($freshKeeper, $duplicates, $stats);

                PartCatalogItem::query()
                    ->whereIn('id', $duplicates->pluck('id')->all())
                    ->delete();
            });
        }

        $this->info(($dryRun ? 'Dry run: ' : '').'Tesla official duplicates processed.');
        foreach ($stats as $name => $value) {
            $this->line(" - {$name}: {$value}");
        }

        return self::SUCCESS;
    }

    protected function preferredItem(Collection $group): PartCatalogItem
    {
        /** @var PartCatalogItem $item */
        $item = $group
            ->sortBy(fn (PartCatalogItem $item): string => collect([
                str_contains((string) $item->source_url, '/find-part?') ? '0' : '1',
                str_contains((string) $item->source_url, '&vin=') || str_contains((string) $item->source_url, '?vin=') ? '1' : '0',
                trim((string) $item->name_ru) !== '' || trim((string) $item->name_ua) !== '' ? '0' : '1',
                trim((string) $item->name_en) !== '' ? '0' : '1',
                str_pad((string) $item->id, 10, '0', STR_PAD_LEFT),
            ])->implode('|'))
            ->first();

        return $item;
    }

    protected function mergeItemPayload(PartCatalogItem $keeper, Collection $group): void
    {
        $raw = $this->rawAttributes($keeper);
        $sourceUrls = collect((array) ($raw['source_urls'] ?? []));
        $occurrences = collect((array) ($raw['official_catalog_occurrences'] ?? []));
        $partImageUrls = collect((array) ($raw['part_image_urls'] ?? []));
        $partImageSourceUrls = collect((array) ($raw['part_image_source_urls'] ?? []));
        $imageUrls = collect((array) ($raw['image_urls'] ?? []));
        $systemGroupImageUrls = collect((array) ($raw['system_group_image_urls'] ?? []));
        $donorVins = collect((array) ($raw['donor_vins'] ?? []));
        $donorCarIds = collect((array) ($raw['donor_car_ids'] ?? []));

        foreach ($group as $item) {
            $itemRaw = $this->rawAttributes($item);
            $sourceUrls = $sourceUrls
                ->push($item->source_url)
                ->merge((array) ($itemRaw['source_urls'] ?? []));
            $occurrences = $occurrences
                ->merge((array) ($itemRaw['official_catalog_occurrences'] ?? []))
                ->push($this->occurrenceFromItem($item));
            $partImageUrls = $partImageUrls
                ->merge((array) ($itemRaw['part_image_urls'] ?? []));
            $partImageSourceUrls = $partImageSourceUrls
                ->merge((array) ($itemRaw['part_image_source_urls'] ?? []));
            $imageUrls = $imageUrls
                ->merge((array) ($itemRaw['image_urls'] ?? []));
            $systemGroupImageUrls = $systemGroupImageUrls
                ->merge((array) ($itemRaw['system_group_image_urls'] ?? []));
            $donorVins = $donorVins->push($itemRaw['donor_vin'] ?? null);
            $donorCarIds = $donorCarIds->push($itemRaw['donor_car_id'] ?? null);

            foreach (['name_source_url', 'name_source_site', 'name_source_url_ru', 'name_source_site_ru', 'name_source_url_ua', 'name_source_site_ua'] as $key) {
                if (! isset($raw[$key]) && isset($itemRaw[$key])) {
                    $raw[$key] = $itemRaw[$key];
                }
            }

            if (isset($itemRaw['manual_name_locks'])) {
                $raw['manual_name_locks'] = array_filter(array_merge(
                    (array) ($raw['manual_name_locks'] ?? []),
                    (array) $itemRaw['manual_name_locks']
                ));
            }
        }

        $occurrences = $occurrences
            ->filter(fn (mixed $row): bool => is_array($row))
            ->map(function (array $row): array {
                unset($row['source_url'], $row['canonical_source_url']);

                return array_filter($row, fn ($value) => $value !== null && $value !== '');
            })
            ->unique(fn (array $row): string => implode('|', [
                (string) ($row['model_label'] ?? ''),
                (string) ($row['category_id'] ?? ''),
                (string) ($row['catalog_external_reference'] ?? ''),
                (string) ($row['system_group_external_reference'] ?? ''),
                (string) ($row['donor_vin'] ?? ''),
            ]))
            ->values();

        $raw['source_urls'] = [$this->canonicalSourceUrl($keeper)];
        $raw['official_catalog_occurrences'] = $occurrences->all();
        $raw['part_image_urls'] = $partImageUrls->filter()->unique()->values()->all();
        $raw['part_image_source_urls'] = $partImageSourceUrls->filter()->unique()->values()->all();
        $raw['image_urls'] = $imageUrls->filter()->unique()->values()->all();
        $raw['system_group_image_urls'] = $systemGroupImageUrls->filter()->unique()->values()->all();
        $raw['donor_vins'] = $donorVins->filter()->unique()->values()->all();
        $raw['donor_car_ids'] = $donorCarIds->filter()->unique()->values()->all();
        unset($raw['product_source_urls'], $raw['canonical_source_url'], $raw['source_url']);

        $compatibility = $this->compatibilityText($occurrences);

        $keeper->forceFill([
            'source_url' => $this->canonicalSourceUrl($keeper),
            'name_ru' => $group->pluck('name_ru')->filter()->sortByDesc(fn (string $value): int => mb_strlen($value))->first(),
            'name_ua' => $group->pluck('name_ua')->filter()->sortByDesc(fn (string $value): int => mb_strlen($value))->first(),
            'notes_ru' => $group->pluck('notes_ru')->filter()->first(),
            'notes_ua' => $group->pluck('notes_ua')->filter()->first(),
            'compatibility_text' => $compatibility ?: $keeper->compatibility_text,
            'raw_attributes' => array_filter($raw, fn ($value) => $value !== null && $value !== '' && $value !== []),
        ])->save();
    }

    protected function relinkProducts(PartCatalogItem $keeper, Collection $duplicates, array &$stats): void
    {
        if (! Schema::hasColumn('products', 'source_part_catalog_item_id')) {
            return;
        }

        Product::query()
            ->whereIn('source_part_catalog_item_id', $duplicates->pluck('id')->all())
            ->get()
            ->each(function (Product $product) use ($keeper, &$stats): void {
                $hasConflict = Product::query()
                    ->where('id', '!=', $product->id)
                    ->where('donor_car_id', $product->donor_car_id)
                    ->where('source_part_catalog_item_id', $keeper->id)
                    ->exists();

                if ($hasConflict) {
                    $product->forceFill(['source_part_catalog_item_id' => null])->save();
                    $stats['products_unlinked_conflict']++;

                    return;
                }

                $product->forceFill(['source_part_catalog_item_id' => $keeper->id])->save();
                $stats['products_relinked']++;
            });
    }

    protected function relinkPriceHistories(PartCatalogItem $keeper, Collection $duplicates, array &$stats): void
    {
        if (! Schema::hasColumn('product_price_histories', 'part_catalog_item_id')) {
            return;
        }

        $stats['price_histories_relinked'] += ProductPriceHistory::query()
            ->whereIn('part_catalog_item_id', $duplicates->pluck('id')->all())
            ->update(['part_catalog_item_id' => $keeper->id]);
    }

    protected function relinkSales(PartCatalogItem $keeper, Collection $duplicates, array &$stats): void
    {
        if (! Schema::hasTable('part_sales') || ! Schema::hasColumn('part_sales', 'part_catalog_item_id')) {
            return;
        }

        $stats['sales_relinked'] += DB::table('part_sales')
            ->whereIn('part_catalog_item_id', $duplicates->pluck('id')->all())
            ->update(['part_catalog_item_id' => $keeper->id]);
    }

    protected function mergeZones(PartCatalogItem $keeper, Collection $duplicates, array &$stats): void
    {
        if (! Schema::hasTable('part_catalog_item_zones')) {
            return;
        }

        PartCatalogItemZone::query()
            ->whereIn('part_catalog_item_id', $duplicates->pluck('id')->all())
            ->get()
            ->each(function (PartCatalogItemZone $zone) use ($keeper, &$stats): void {
                PartCatalogItemZone::query()->updateOrCreate(
                    ['part_catalog_item_id' => $keeper->id, 'zone' => $zone->zone],
                    ['confidence' => max((int) $zone->confidence, 70)]
                );
                $zone->delete();
                $stats['zones_merged']++;
            });
    }

    protected function occurrenceFromItem(PartCatalogItem $item): array
    {
        $raw = $this->rawAttributes($item);

        return array_filter([
            'category_id' => $item->part_catalog_category_id,
            'model_label' => $item->model_label,
            'model_name' => $item->model_name,
            'main_category_code' => $item->main_category_code,
            'main_category_name' => $item->main_category_name,
            'subcategory_code' => $item->subcategory_code,
            'subcategory_name' => $item->subcategory_name,
            'node_name' => $item->node_name,
            'catalog_external_reference' => $raw['catalog_external_reference'] ?? null,
            'category_external_reference' => $raw['category_external_reference'] ?? null,
            'subcategory_external_reference' => $raw['subcategory_external_reference'] ?? null,
            'system_group_external_reference' => $raw['system_group_external_reference'] ?? null,
            'donor_vin' => $raw['donor_vin'] ?? null,
            'donor_car_id' => $raw['donor_car_id'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    protected function canonicalSourceUrl(PartCatalogItem $item): string
    {
        $raw = $this->rawAttributes($item);
        $partNumber = trim((string) $item->part_number);

        if ($partNumber === '') {
            $partNumber = $this->partNumberFromUrl((string) $item->source_url);
        }

        if ($partNumber === '') {
            $partNumber = $this->partNumberFromUrl((string) ($raw['canonical_source_url'] ?? ''));
        }

        if ($partNumber !== '') {
            return 'https://parts.tesla.com/en-US/find-part?searchTerm='.rawurlencode($partNumber);
        }

        $url = trim((string) ($raw['canonical_source_url'] ?? $item->source_url));
        if ($url === '') {
            return (string) $item->source_url;
        }

        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['query'])) {
            return $url;
        }

        parse_str($parts['query'], $query);
        unset($query['vin']);

        $base = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? 'parts.tesla.com').($parts['path'] ?? '');
        $queryString = http_build_query($query);

        return $queryString !== '' ? $base.'?'.$queryString : $base;
    }

    protected function partNumberFromUrl(string $url): string
    {
        $query = parse_url($url, PHP_URL_QUERY);

        if (! is_string($query) || $query === '') {
            return '';
        }

        parse_str($query, $params);

        return trim((string) ($params['partNumber'] ?? $params['searchTerm'] ?? ''));
    }

    protected function compatibilityText(Collection $occurrences): string
    {
        return $occurrences
            ->map(fn (array $row): string => trim((string) ($row['model_label'] ?? $row['model_name'] ?? '')))
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter()
            ->unique(fn (string $value): string => Str::lower($value))
            ->values()
            ->implode(', ');
    }

    protected function rawAttributes(PartCatalogItem $item): array
    {
        return PartCatalogRawAttributes::from($item);
    }

    protected function normalizePartNumber(string $value): string
    {
        return Str::upper(preg_replace('/[^A-Z0-9]/i', '', $value) ?: '');
    }
}
