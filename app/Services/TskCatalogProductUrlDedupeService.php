<?php

namespace App\Services;

use App\Models\PartCatalogItem;
use App\Models\PartCatalogItemOccurrence;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TskCatalogProductUrlDedupeService
{
    public function run(array $options = []): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $limit = max(0, (int) ($options['limit'] ?? 0));
        $progress = $options['progress'] ?? null;
        $partNumber = trim((string) ($options['part_number'] ?? ''));

        $stats = [
            'items_seen' => 0,
            'product_url_groups' => 0,
            'duplicate_groups' => 0,
            'occurrences_saved' => 0,
            'items_merged' => 0,
            'items_skipped_with_product_conflicts' => 0,
            'source_urls_canonicalized' => 0,
        ];

        $items = PartCatalogItem::query()
            ->where('source', 'tsk')
            ->when($partNumber !== '', fn ($query) => $query->where('part_number', $partNumber))
            ->orderBy('id')
            ->get();
        $stats['items_seen'] = $items->count();

        $groups = $items
            ->map(fn (PartCatalogItem $item): array => [
                'item' => $item,
                'product_url' => $this->productUrl($item),
            ])
            ->filter(fn (array $entry): bool => $entry['product_url'] !== null)
            ->groupBy('product_url');

        $stats['product_url_groups'] = $groups->count();

        $groupsProcessed = 0;

        foreach ($groups as $productUrl => $entries) {
            if ($limit > 0 && $groupsProcessed >= $limit) {
                break;
            }

            $groupsProcessed++;

            /** @var Collection<int, array{item: PartCatalogItem, product_url: string}> $entries */
            $groupItems = $entries->pluck('item')->values();
            $canonical = $this->canonicalItem($groupItems, (string) $productUrl);

            foreach ($groupItems as $item) {
                if (! $dryRun) {
                    $this->saveOccurrence($canonical, $item, (string) $productUrl);
                }
                $stats['occurrences_saved']++;
            }
            if (! $dryRun) {
                $this->refreshCompatibility($canonical, $groupItems);
            }

            if ($groupItems->count() <= 1) {
                if (! $dryRun && $canonical->source_url !== $productUrl && ! $this->sourceUrlExists((string) $productUrl, $canonical->id)) {
                    $canonical->forceFill(['source_url' => $productUrl])->save();
                    $stats['source_urls_canonicalized']++;
                }

                continue;
            }

            $stats['duplicate_groups']++;
            $duplicates = $groupItems->reject(fn (PartCatalogItem $item): bool => $item->id === $canonical->id);

            if ($progress !== null) {
                $progress("{$productUrl}: keep #{$canonical->id}, merge ".$duplicates->pluck('id')->implode(', '));
            }

            if ($dryRun) {
                $stats['items_merged'] += $duplicates->count();

                continue;
            }

            DB::transaction(function () use ($canonical, $duplicates, $productUrl, &$stats): void {
                if ($canonical->source_url !== $productUrl && ! $this->sourceUrlExists((string) $productUrl, $canonical->id)) {
                    $canonical->forceFill(['source_url' => $productUrl])->save();
                    $stats['source_urls_canonicalized']++;
                }

                $rawAttributes = (array) $canonical->raw_attributes;
                $rawAttributes['product_url'] = $productUrl;
                $rawAttributes['merged_tsk_source_urls'] = collect($rawAttributes['merged_tsk_source_urls'] ?? [])
                    ->merge($duplicates->pluck('source_url'))
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();
                $canonical->forceFill(['raw_attributes' => $rawAttributes])->save();

                foreach ($duplicates as $duplicate) {
                    if ($this->hasProductReassignmentConflict($duplicate, $canonical)) {
                        $stats['items_skipped_with_product_conflicts']++;

                        continue;
                    }

                    DB::table('products')
                        ->where('source_part_catalog_item_id', $duplicate->id)
                        ->update(['source_part_catalog_item_id' => $canonical->id]);
                    DB::table('part_sales')
                        ->where('part_catalog_item_id', $duplicate->id)
                        ->update(['part_catalog_item_id' => $canonical->id]);
                    DB::table('product_price_histories')
                        ->where('part_catalog_item_id', $duplicate->id)
                        ->update(['part_catalog_item_id' => $canonical->id]);
                    DB::table('part_catalog_item_occurrences')
                        ->where('part_catalog_item_id', $duplicate->id)
                        ->update(['part_catalog_item_id' => $canonical->id]);

                    $this->mergeZones($duplicate, $canonical);
                    $duplicate->delete();
                    $stats['items_merged']++;
                }
            });
        }

        return $stats;
    }

    public function canonicalProductUrl(mixed $url): ?string
    {
        $url = is_string($url) ? trim($url) : '';

        if ($url === '' || ! str_starts_with($url, 'http')) {
            return null;
        }

        $parts = parse_url($url);
        $host = Str::lower((string) ($parts['host'] ?? ''));
        if ($host !== 'tsk.ua') {
            return null;
        }

        $path = (string) ($parts['path'] ?? '');
        $path = preg_replace('#^/(ua|ru|en)/#i', '/', $path) ?? $path;
        $path = rtrim($path, '/').'/';
        $lastSegment = Str::lower((string) collect(explode('/', trim($path, '/')))->last());

        if (preg_match('/^[a-z0-9]{6,}(?:-[a-z0-9]{1,}){0,2}$/i', $lastSegment) !== 1) {
            return null;
        }

        return 'https://tsk.ua'.$path;
    }

    protected function productUrl(PartCatalogItem $item): ?string
    {
        return $this->canonicalProductUrl(data_get($item->raw_attributes, 'product_url'))
            ?: $this->canonicalProductUrl($item->source_url)
            ?: $this->productUrlFromPartNumber($item->part_number);
    }

    protected function productUrlFromPartNumber(mixed $partNumber): ?string
    {
        $partNumber = Str::lower(trim((string) $partNumber));

        if ($partNumber === '' || preg_match('/^[a-z0-9]{6,}(?:-[a-z0-9]{1,}){0,2}$/i', $partNumber) !== 1) {
            return null;
        }

        return 'https://tsk.ua/'.$partNumber.'/';
    }

    protected function canonicalItem(Collection $items, string $productUrl): PartCatalogItem
    {
        return $items
            ->sortBy(fn (PartCatalogItem $item): string => implode('|', [
                $item->source_url === $productUrl ? '0' : '1',
                $item->price_amount !== null ? '0' : '1',
                str_pad((string) $item->id, 12, '0', STR_PAD_LEFT),
            ]))
            ->first();
    }

    protected function saveOccurrence(PartCatalogItem $canonical, PartCatalogItem $source, string $productUrl): void
    {
        $pageUrl = (string) data_get($source->raw_attributes, 'page_url', '');
        $occurrenceKey = hash('sha256', collect([
            'tsk',
            $pageUrl,
            $source->scheme_number,
            $source->part_number,
            $source->name,
            $productUrl,
        ])->filter(fn (mixed $value): bool => $value !== null && $value !== '')->implode('|'));

        PartCatalogItemOccurrence::query()->updateOrCreate(
            ['occurrence_key' => $occurrenceKey],
            [
                'part_catalog_item_id' => $canonical->id,
                'part_catalog_category_id' => $source->part_catalog_category_id,
                'source' => 'tsk',
                'page_url' => $pageUrl ?: null,
                'product_url' => $productUrl,
                'part_number' => $source->part_number,
                'name' => $source->name,
                'scheme_number' => $source->scheme_number,
                'quantity' => data_get($source->raw_attributes, 'quantity'),
                'raw_attributes' => array_filter([
                    'source_url' => $source->source_url,
                    'image_url' => data_get($source->raw_attributes, 'image_url'),
                ]),
            ]
        );
    }

    protected function refreshCompatibility(PartCatalogItem $canonical, Collection $sourceItems): void
    {
        $labels = $sourceItems
            ->flatMap(fn (PartCatalogItem $item): array => [
                $item->model_label,
                $item->model_name,
                $item->compatibility_text,
            ])
            ->merge(
                PartCatalogItemOccurrence::query()
                    ->where('part_catalog_item_id', $canonical->id)
                    ->with('category:id,model_label,model_name')
                    ->get()
                    ->flatMap(fn (PartCatalogItemOccurrence $occurrence): array => [
                        $occurrence->category?->model_label,
                        $occurrence->category?->model_name,
                    ])
            )
            ->flatMap(fn (mixed $value): array => preg_split('/\s*,\s*/u', trim((string) $value)) ?: [])
            ->map(fn (string $value): string => $this->displayModelLabel($value))
            ->filter()
            ->unique()
            ->values();

        if ($labels->isEmpty()) {
            return;
        }

        $canonical->forceFill([
            'compatibility_text' => $labels->implode(', '),
        ])->save();
    }

    protected function displayModelLabel(string $value): string
    {
        $value = trim($value);
        $lower = Str::lower($value);

        return match (true) {
            str_contains($lower, 'model s2') || str_contains($lower, 'model sr') => 'Model SR',
            str_contains($lower, 'model s palladium') => 'Model S Palladium',
            str_contains($lower, 'model s') => 'Model S',
            str_contains($lower, 'model x palladium') => 'Model X Palladium',
            str_contains($lower, 'model x') => 'Model X',
            str_contains($lower, 'model 3 highland') => 'Model 3 Highland',
            str_contains($lower, 'model 3') => 'Model 3',
            str_contains($lower, 'model y') => 'Model Y',
            default => $value,
        };
    }

    protected function sourceUrlExists(string $sourceUrl, int $exceptId): bool
    {
        return PartCatalogItem::query()
            ->where('source_url', $sourceUrl)
            ->whereKeyNot($exceptId)
            ->exists();
    }

    protected function hasProductReassignmentConflict(PartCatalogItem $duplicate, PartCatalogItem $canonical): bool
    {
        $donorCarIds = DB::table('products')
            ->where('source_part_catalog_item_id', $duplicate->id)
            ->whereNotNull('donor_car_id')
            ->pluck('donor_car_id');

        if ($donorCarIds->isEmpty()) {
            return false;
        }

        return DB::table('products')
            ->where('source_part_catalog_item_id', $canonical->id)
            ->whereIn('donor_car_id', $donorCarIds->all())
            ->exists();
    }

    protected function mergeZones(PartCatalogItem $duplicate, PartCatalogItem $canonical): void
    {
        $zones = DB::table('part_catalog_item_zones')
            ->where('part_catalog_item_id', $duplicate->id)
            ->get();

        foreach ($zones as $zone) {
            $exists = DB::table('part_catalog_item_zones')
                ->where('part_catalog_item_id', $canonical->id)
                ->where('zone', $zone->zone)
                ->exists();

            if (! $exists) {
                DB::table('part_catalog_item_zones')
                    ->where('id', $zone->id)
                    ->update(['part_catalog_item_id' => $canonical->id]);
            } else {
                DB::table('part_catalog_item_zones')->where('id', $zone->id)->delete();
            }
        }
    }
}
