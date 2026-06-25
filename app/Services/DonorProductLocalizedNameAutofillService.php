<?php

namespace App\Services;

use App\Models\PartCatalogItem;
use App\Models\Product;
use App\Support\CatalogTextEncoding;
use App\Support\PartCatalogLocalizedNameCleaner;
use App\Support\PartCatalogRawAttributes;
use App\Support\PartNumberNormalizer;

class DonorProductLocalizedNameAutofillService
{
    protected const SOURCE_ORDER = [
        'nikolacars',
        'tesla_official',
        'tcarservice',
        'teslapartsukraine',
        'erazborka',
        'dkparts',
        'teslawestparts',
        'driveparts',
        'stock-tesla',
        'teslacompany',
        'tsk',
    ];

    public function fillOnKnownDamageStatus(Product $product, ?string $previousDamageNote, ?string $nextDamageNote): array
    {
        if (! $this->isCheckedDamageStatus($nextDamageNote)) {
            return $this->emptyStats();
        }

        return $this->fillMissingNames($product);
    }

    public function fillMissingNames(Product $product): array
    {
        $stats = $this->emptyStats();
        $targetItem = $this->targetCatalogItem($product);

        if (! $targetItem instanceof PartCatalogItem) {
            return $stats;
        }

        $stats['items_seen'] = 1;
        $partNumber = PartNumberNormalizer::normalize($targetItem->part_number ?: $product->external_sku);

        if ($partNumber === null) {
            return $stats;
        }

        $updates = [];
        $localNames = [];
        foreach (['ru' => 'name_ru', 'ua' => 'name_ua'] as $locale => $column) {
            if ($this->hasUsableLocalizedText($targetItem->{$column}) || app(PartCatalogManualNameService::class)->isLocked($targetItem, $column)) {
                continue;
            }

            $sourceItem = $this->sourceItem($targetItem, $partNumber, $column);
            if (! $sourceItem instanceof PartCatalogItem) {
                continue;
            }

            $localizedName = PartCatalogLocalizedNameCleaner::clean($sourceItem->{$column});
            if (! $this->hasUsableLocalizedText($localizedName)) {
                continue;
            }

            $localNames[$locale] = $localizedName;
            $updates[$column] = $localizedName;
            $updates['raw_attributes'] = array_replace(
                $updates['raw_attributes'] ?? [],
                $this->sourceMetadata($sourceItem, $locale)
            );
            $stats['catalog_matches_found']++;
            $stats[$column.'_updated']++;
        }

        foreach (['ru' => 'name_ru', 'ua' => 'name_ua'] as $locale => $column) {
            if (isset($updates[$column])
                || $this->hasUsableLocalizedText($targetItem->{$column})
                || app(PartCatalogManualNameService::class)->isLocked($targetItem, $column)) {
                continue;
            }

            $translationSource = $this->translationSource($product, $targetItem, $locale, $localNames, $updates);
            if ($translationSource === null) {
                continue;
            }

            $translatedName = app(GoogleTranslateService::class)->translate(
                $translationSource['text'],
                $locale === 'ua' ? 'uk' : 'ru',
                $translationSource['source_language']
            );

            $translatedName = PartCatalogLocalizedNameCleaner::clean($translatedName);
            if (! $this->hasUsableLocalizedText($translatedName)) {
                $retrySource = $this->retryTranslationSource($locale, $updates);
                if ($retrySource !== null) {
                    $translatedName = PartCatalogLocalizedNameCleaner::clean(app(GoogleTranslateService::class)->translate(
                        $retrySource['text'],
                        $locale === 'ua' ? 'uk' : 'ru',
                        $retrySource['source_language']
                    ));
                }
            }

            if (! $this->hasUsableLocalizedText($translatedName)) {
                continue;
            }

            $updates[$column] = $translatedName;
            $updates['raw_attributes'] = array_replace(
                $updates['raw_attributes'] ?? [],
                $this->googleMetadata($locale)
            );
            $stats['google_translations_used']++;
            $stats[$column.'_updated']++;
        }

        if (! isset($updates['name_ru']) && ! isset($updates['name_ua'])) {
            return $stats;
        }

        $rawAttributes = array_replace(
            PartCatalogRawAttributes::from($targetItem),
            $updates['raw_attributes'] ?? []
        );
        unset($updates['raw_attributes']);

        $targetItem->forceFill($updates + ['raw_attributes' => $rawAttributes])->save();
        $stats['items_updated'] = 1;

        return $stats;
    }

    protected function targetCatalogItem(Product $product): ?PartCatalogItem
    {
        if ($product->relationLoaded('sourcePartCatalogItem') && $product->sourcePartCatalogItem instanceof PartCatalogItem) {
            return $product->sourcePartCatalogItem;
        }

        if ($product->source_part_catalog_item_id === null) {
            return null;
        }

        return PartCatalogItem::query()->find($product->source_part_catalog_item_id);
    }

    protected function sourceItem(PartCatalogItem $targetItem, string $partNumber, string $column): ?PartCatalogItem
    {
        return PartCatalogItem::query()
            ->where('id', '<>', $targetItem->id)
            ->whereNotNull($column)
            ->whereRaw('upper(trim(part_number)) = ?', [$partNumber])
            ->get()
            ->filter(fn (PartCatalogItem $item): bool => $this->isTrustedSource($item->source)
                && $this->hasUsableLocalizedText($item->{$column}))
            ->sortBy(fn (PartCatalogItem $item): string => $this->sourcePreferenceKey($item))
            ->first();
    }

    protected function isTrustedSource(?string $source): bool
    {
        return in_array((string) $source, self::SOURCE_ORDER, true);
    }

    protected function sourcePreferenceKey(PartCatalogItem $item): string
    {
        $sourceIndex = array_search((string) $item->source, self::SOURCE_ORDER, true);

        return implode('|', [
            str_pad((string) ($sourceIndex === false ? 999 : $sourceIndex), 3, '0', STR_PAD_LEFT),
            str_pad((string) $item->id, 12, '0', STR_PAD_LEFT),
        ]);
    }

    protected function sourceMetadata(PartCatalogItem $sourceItem, string $locale): array
    {
        $rawAttributes = PartCatalogRawAttributes::from($sourceItem);
        $sourceSite = data_get($rawAttributes, "name_source_site_{$locale}") ?: data_get($rawAttributes, 'name_source_site');
        $sourceUrl = data_get($rawAttributes, "name_source_url_{$locale}") ?: data_get($rawAttributes, 'name_source_url');
        $sourceType = data_get($rawAttributes, "name_source_type_{$locale}");

        if ($sourceSite === 'Google Translate') {
            $metadata = $this->googleMetadata($locale);
            $metadata["name_source_type_{$locale}"] = $sourceType ?: 'donor_status_google_translate';

            if ($sourceUrl !== null && trim((string) $sourceUrl) !== '') {
                $metadata["name_source_url_{$locale}"] = $sourceUrl;
                $metadata['name_source_url'] = $sourceUrl;
            }

            return $metadata;
        }

        return [
            "name_source_type_{$locale}" => 'donor_status_catalog_match',
            "name_source_item_id_{$locale}" => $sourceItem->id,
            "name_source_site_{$locale}" => $sourceItem->source,
            "name_source_url_{$locale}" => $sourceItem->source_url,
        ];
    }

    protected function googleMetadata(string $locale): array
    {
        return [
            "name_source_type_{$locale}" => 'tesla_official_google_translate',
            "name_source_site_{$locale}" => 'Google Translate',
            "name_source_url_{$locale}" => 'https://cloud.google.com/translate',
            'name_source_site' => 'Google Translate',
            'name_source_url' => 'https://cloud.google.com/translate',
        ];
    }

    protected function translationSource(Product $product, PartCatalogItem $targetItem, string $locale, array $localNames, array $updates): ?array
    {
        $oppositeLocale = $locale === 'ru' ? 'ua' : 'ru';
        $oppositeColumn = $oppositeLocale === 'ru' ? 'name_ru' : 'name_ua';

        $oppositeName = $updates[$oppositeColumn] ?? $localNames[$oppositeLocale] ?? null;
        if ($this->hasUsableLocalizedText($oppositeName)) {
            return [
                'text' => $oppositeName,
                'source_language' => $oppositeLocale === 'ua' ? 'uk' : 'ru',
            ];
        }

        $sourceText = trim((string) ($targetItem->name_en ?: $targetItem->name ?: $product->name));
        if ($sourceText === '') {
            return null;
        }

        return [
            'text' => $sourceText,
            'source_language' => 'en',
        ];
    }

    protected function retryTranslationSource(string $locale, array $updates): ?array
    {
        $oppositeColumn = $locale === 'ru' ? 'name_ua' : 'name_ru';
        $oppositeName = $updates[$oppositeColumn] ?? null;

        if (! $this->hasUsableLocalizedText($oppositeName)) {
            return null;
        }

        return [
            'text' => $oppositeName,
            'source_language' => $locale === 'ru' ? 'uk' : 'ru',
        ];
    }

    protected function hasUsableLocalizedText(mixed $value): bool
    {
        $value = trim((string) $value);

        return $value !== '' && preg_match('/[\x{0400}-\x{04FF}]/u', $value) === 1;
    }

    protected function isCheckedDamageStatus(?string $status): bool
    {
        $status = trim((string) (CatalogTextEncoding::repair((string) $status) ?? $status));

        return in_array($status, NikolaCarsProductInventorySyncService::CHECKED_DAMAGE_STATUSES, true);
    }

    protected function emptyStats(): array
    {
        return [
            'items_seen' => 0,
            'items_updated' => 0,
            'catalog_matches_found' => 0,
            'google_translations_used' => 0,
            'name_ru_updated' => 0,
            'name_ua_updated' => 0,
        ];
    }
}
