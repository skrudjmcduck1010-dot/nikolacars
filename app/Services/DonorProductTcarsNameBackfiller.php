<?php

namespace App\Services;

use App\Models\PartCatalogItem;
use App\Models\Product;
use App\Support\PartCatalogRawAttributes;
use App\Support\PartNumberNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class DonorProductTcarsNameBackfiller
{
    protected const SOURCE = 'tcarservice';

    public function run(array $options = []): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $overwrite = (bool) ($options['overwrite'] ?? false);
        $donorCarId = (int) ($options['donor_car_id'] ?? 0);
        $limit = max(0, (int) ($options['limit'] ?? 0));
        $progress = $options['progress'] ?? null;

        $stats = [
            'donor_products_seen' => 0,
            'donor_products_with_article' => 0,
            'donor_products_linked_to_catalog' => 0,
            'tcars_matches_found' => 0,
            'catalog_items_updated' => 0,
            'name_ru_updated' => 0,
            'name_ua_updated' => 0,
            'manual_locked_skipped' => 0,
            'products_without_tcars_match' => 0,
        ];

        $query = Product::query()
            ->with('sourcePartCatalogItem')
            ->whereNotNull('donor_car_id')
            ->whereNotNull('external_sku')
            ->where('external_sku', '!=', '')
            ->when($donorCarId > 0, fn (Builder $query) => $query->where('donor_car_id', $donorCarId))
            ->orderBy('id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $query->chunkById(300, function (Collection $products) use (&$stats, $dryRun, $overwrite, $progress): void {
            $stats['donor_products_seen'] += $products->count();

            $partNumbers = $products
                ->pluck('external_sku')
                ->map(fn (?string $partNumber): string => PartNumberNormalizer::compact($partNumber))
                ->filter()
                ->unique()
                ->values();

            if ($partNumbers->isEmpty()) {
                return;
            }

            $matchesByPartNumber = $this->tcarsMatchesByPartNumber($partNumbers);
            $manualNames = app(PartCatalogManualNameService::class);

            foreach ($products as $product) {
                $partNumber = PartNumberNormalizer::compact($product->external_sku);
                if ($partNumber === '') {
                    continue;
                }

                $stats['donor_products_with_article']++;

                $sourceItem = $this->sourceCatalogItem($product, $dryRun);
                if (! $sourceItem instanceof PartCatalogItem) {
                    continue;
                }

                if ((int) $product->source_part_catalog_item_id === (int) $sourceItem->id) {
                    $stats['donor_products_linked_to_catalog']++;
                }

                $tcarsItem = $this->preferredTcarsItem($matchesByPartNumber->get($partNumber, collect()));
                if (! $tcarsItem instanceof PartCatalogItem) {
                    $stats['products_without_tcars_match']++;

                    continue;
                }

                $stats['tcars_matches_found']++;

                $updates = [];
                $rawAttributes = $this->rawAttributes($sourceItem);

                foreach (['name_ru' => 'ru', 'name_ua' => 'ua'] as $column => $locale) {
                    if ($manualNames->isLocked($sourceItem, $column)) {
                        $stats['manual_locked_skipped']++;

                        continue;
                    }

                    if (! $overwrite && ! $this->shouldFillName($sourceItem->{$column})) {
                        continue;
                    }

                    $name = $this->localizedName($tcarsItem, $locale);
                    if ($name === null || $name === (string) $sourceItem->{$column}) {
                        continue;
                    }

                    $updates[$column] = $name;
                    $rawAttributes = $this->withNameSource($rawAttributes, $tcarsItem, $locale);
                }

                if ($updates === []) {
                    continue;
                }

                $updates['raw_attributes'] = $rawAttributes;

                if (! $dryRun) {
                    $sourceItem->forceFill($updates)->save();
                }

                $stats['catalog_items_updated']++;
                $stats['name_ru_updated'] += array_key_exists('name_ru', $updates) ? 1 : 0;
                $stats['name_ua_updated'] += array_key_exists('name_ua', $updates) ? 1 : 0;

                if ($progress !== null) {
                    $progress("#{$sourceItem->id} {$sourceItem->part_number}: ".implode(', ', array_keys($updates))." from TCARS #{$tcarsItem->id}");
                }
            }
        });

        return $stats;
    }

    protected function sourceCatalogItem(Product $product, bool $dryRun): ?PartCatalogItem
    {
        if ($product->sourcePartCatalogItem instanceof PartCatalogItem) {
            return $product->sourcePartCatalogItem;
        }

        if ($dryRun) {
            return null;
        }

        $result = app(TeslaCatalogDonorProductSync::class)->syncProduct($product);
        $item = $result['item'] ?? null;

        return $item instanceof PartCatalogItem ? $item : null;
    }

    protected function tcarsMatchesByPartNumber(Collection $partNumbers): Collection
    {
        return PartCatalogItem::query()
            ->where('source', self::SOURCE)
            ->whereNotNull('part_number')
            ->whereIn(\DB::raw("upper(replace(replace(replace(trim(part_number), '-', ''), ' ', ''), '.', ''))"), $partNumbers->all())
            ->get(['id', 'source', 'source_url', 'part_number', 'name', 'name_ru', 'name_ua', 'raw_attributes'])
            ->groupBy(fn (PartCatalogItem $item): string => PartNumberNormalizer::compact($item->part_number));
    }

    protected function preferredTcarsItem(Collection $items): ?PartCatalogItem
    {
        return $items
            ->filter(fn (PartCatalogItem $item): bool => $this->localizedName($item, 'ru') !== null || $this->localizedName($item, 'ua') !== null)
            ->sortBy(fn (PartCatalogItem $item): string => collect([
                $this->localizedName($item, 'ru') !== null ? '0' : '1',
                $this->localizedName($item, 'ua') !== null ? '0' : '1',
                str_pad((string) $item->id, 12, '0', STR_PAD_LEFT),
            ])->implode('|'))
            ->first();
    }

    protected function localizedName(PartCatalogItem $item, string $locale): ?string
    {
        $name = $locale === 'ru'
            ? ($this->filled($item->name_ru) ? $item->name_ru : ($this->isRussianUrl((string) $item->source_url) ? $item->name : null))
            : ($this->filled($item->name_ua) ? $item->name_ua : (! $this->isRussianUrl((string) $item->source_url) ? $item->name : null));

        return $this->isUsableLocalizedName($name) ? Str::limit((string) $name, 255, '') : null;
    }

    protected function shouldFillName(?string $currentName): bool
    {
        $currentName = trim((string) $currentName);

        return $currentName === '' || preg_match('/\p{Cyrillic}/u', $currentName) !== 1;
    }

    protected function isUsableLocalizedName(?string $name): bool
    {
        $name = trim((string) $name);

        return $name !== ''
            && preg_match('/\p{Cyrillic}/u', $name) === 1
            && preg_match('/^[A-Z0-9.\-\s]+$/i', $name) !== 1;
    }

    protected function withNameSource(array $rawAttributes, PartCatalogItem $sourceItem, string $locale): array
    {
        $sourceUrl = $this->displayableSourceUrl($sourceItem);

        if ($sourceUrl === null) {
            return $rawAttributes;
        }

        $rawAttributes['name_source_site'] = 'tcarservice.com';
        $rawAttributes['name_source_site_'.$locale] = 'tcarservice.com';
        $rawAttributes['name_source_url'] = $sourceUrl;
        $rawAttributes['name_source_url_'.$locale] = $sourceUrl;
        $rawAttributes['name_source_item_id_'.$locale] = $sourceItem->id;
        $rawAttributes['name_source_type_'.$locale] = 'donor_tcars_article_match';

        return $rawAttributes;
    }

    protected function displayableSourceUrl(PartCatalogItem $item): ?string
    {
        $sourceUrl = (string) ($item->source_url ?? '');

        if (Str::startsWith($sourceUrl, ['http://', 'https://'])) {
            return $sourceUrl;
        }

        $pageUrl = data_get($item->raw_attributes, 'source_url_'.$this->urlLocale($item))
            ?: data_get($item->raw_attributes, 'product_url')
            ?: data_get($item->raw_attributes, 'page_url');

        return is_string($pageUrl) && Str::startsWith($pageUrl, ['http://', 'https://'])
            ? $pageUrl
            : null;
    }

    protected function urlLocale(PartCatalogItem $item): string
    {
        return $this->isRussianUrl((string) $item->source_url) ? 'ru' : 'ua';
    }

    protected function isRussianUrl(string $url): bool
    {
        return str_starts_with((string) parse_url($url, PHP_URL_PATH), '/ru/');
    }

    protected function rawAttributes(PartCatalogItem $item): array
    {
        return PartCatalogRawAttributes::from($item);
    }

    protected function filled(?string $value): bool
    {
        return trim((string) $value) !== '';
    }
}
