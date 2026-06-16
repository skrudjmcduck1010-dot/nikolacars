<?php

namespace App\Services;

use App\Models\PartCatalogItem;
use App\Models\Product;
use App\Support\PartCatalogRawAttributes;
use App\Support\PartNumberNormalizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class DonorProductLocalizedNameAutofillService
{
    public const UNKNOWN_DAMAGE_STATUS = "\u{041D}\u{0435}\u{0438}\u{0437}\u{0432}\u{0435}\u{0441}\u{0442}\u{043D}\u{043E}";

    protected const LOCALE_COLUMNS = [
        'ru' => 'name_ru',
        'ua' => 'name_ua',
    ];

    protected const SOURCE_LOCALE_POLICY = [
        'tesla_official' => ['ru', 'ua'],
        'nikolacars' => ['ru', 'ua'],
        'tcarservice' => ['ru', 'ua'],
        'teslapartsukraine' => ['ua'],
        'erazborka' => ['ru', 'ua'],
        'dkparts' => ['ru', 'ua'],
        'teslawestparts' => ['ua'],
        'toprazborka' => ['ua'],
        'driveparts' => ['ru', 'ua'],
        'stock-tesla' => ['ru', 'ua'],
        'teslacompany' => ['ru'],
        'tsk' => ['mixed'],
    ];

    protected const PREFERRED_SOURCES = [
        'ru' => [
            'tesla_official',
            'nikolacars',
            'tcarservice',
            'erazborka',
            'dkparts',
            'driveparts',
            'stock-tesla',
            'teslacompany',
            'tsk',
        ],
        'ua' => [
            'tesla_official',
            'nikolacars',
            'tcarservice',
            'teslapartsukraine',
            'erazborka',
            'dkparts',
            'teslawestparts',
            'toprazborka',
            'driveparts',
            'stock-tesla',
            'tsk',
        ],
    ];

    public function fillOnKnownDamageStatus(Product $product, ?string $previousDamageNote, ?string $nextDamageNote): array
    {
        if (! $this->isUnknownDamageStatus($previousDamageNote) || $this->isUnknownDamageStatus($nextDamageNote)) {
            return $this->emptyStats();
        }

        return $this->fillMissingNames($product);
    }

    public function fillMissingNames(Product $product): array
    {
        $stats = $this->emptyStats();
        $product = $product->fresh(['sourcePartCatalogItem']) ?? $product;
        $item = $product->sourcePartCatalogItem;

        if (! $item instanceof PartCatalogItem) {
            return $stats;
        }

        $manualNames = app(PartCatalogManualNameService::class);
        $rawAttributes = $this->rawAttributes($item);
        $nameFillLocales = collect(self::LOCALE_COLUMNS)
            ->filter(fn (string $column): bool => $this->canAutofillName($manualNames, $item, $column)
                && $this->shouldFillName($item->{$column}))
            ->keys()
            ->values();
        $sourceOnlyLocales = collect(self::LOCALE_COLUMNS)
            ->filter(fn (string $column, string $locale): bool => $this->canAutofillName($manualNames, $item, $column)
                && ! $this->shouldFillName($item->{$column})
                && $this->isUsableLocalizedName($item->{$column})
                && ! $this->hasLocalizedNameSource($rawAttributes, $locale))
            ->keys()
            ->values();
        $fillableLocales = $nameFillLocales
            ->merge($sourceOnlyLocales)
            ->unique()
            ->values();

        if ($fillableLocales->isEmpty()) {
            return $stats;
        }

        $stats['items_seen'] = 1;
        $updates = [];
        $localizedNameUpdates = [];
        $originalRawAttributes = $rawAttributes;
        $matches = $this->matchesForProduct($product, $item);

        foreach ($fillableLocales as $locale) {
            $sourceItem = $this->preferredSourceItem($matches, $locale, $item);
            $name = $sourceItem instanceof PartCatalogItem ? $this->localizedName($sourceItem, $locale) : null;

            if ($name === null) {
                continue;
            }

            $column = self::LOCALE_COLUMNS[$locale];
            if ($this->shouldFillName($item->{$column})) {
                $updates[$column] = $name;
                $localizedNameUpdates[$column] = $name;
                $rawAttributes = $this->withItemNameSource($rawAttributes, $sourceItem, $locale);
                $stats['catalog_matches_found']++;

                continue;
            }

            if (trim((string) $item->{$column}) === $name) {
                $rawAttributes = $this->withItemNameSource($rawAttributes, $sourceItem, $locale);
            }
        }

        foreach ($nameFillLocales as $locale) {
            $column = self::LOCALE_COLUMNS[$locale];

            if (array_key_exists($column, $updates)) {
                continue;
            }

            $sourceText = $this->machineTranslationSourceText($product, $item, $locale, $localizedNameUpdates);
            $sourceLocale = $this->localizedTranslationSourceLocale($item, $locale, $localizedNameUpdates, $sourceText)
                ?? ($this->looksEnglishSourceText($sourceText) ? 'en' : null);
            $name = $this->googleTranslate($sourceText, $locale, $sourceLocale);

            if ($name !== null) {
                $updates[$column] = $name;
                $localizedNameUpdates[$column] = $name;
                $rawAttributes = $this->withGoogleNameSource($rawAttributes, $locale);
                $stats['google_translations_used']++;

                continue;
            }
        }

        foreach ($nameFillLocales as $locale) {
            $column = self::LOCALE_COLUMNS[$locale];

            if (array_key_exists($column, $updates)) {
                continue;
            }

            $sourceText = $this->oppositeLocalizedTranslationSourceText($item, $locale, $localizedNameUpdates);
            $sourceLocale = $locale === 'ru' ? 'ua' : 'ru';
            $name = $this->googleTranslate($sourceText, $locale, $sourceLocale);

            if ($name === null) {
                if ($locale === 'ua' && trim((string) $item->{$column}) !== '') {
                    $updates[$column] = null;
                }

                continue;
            }

            $updates[$column] = $name;
            $localizedNameUpdates[$column] = $name;
            $rawAttributes = $this->withGoogleNameSource($rawAttributes, $locale);
            $stats['google_translations_used']++;
        }

        if ($updates === [] && $rawAttributes == $originalRawAttributes) {
            return $stats;
        }

        [$updates, $rawAttributes] = $this->withoutEmptyManualNameLocks($manualNames, $item, $updates, $rawAttributes);

        $item->forceFill($updates + ['raw_attributes' => $rawAttributes])->save();
        $stats['items_updated'] = 1;
        $stats['name_ru_updated'] = array_key_exists('name_ru', $updates) ? 1 : 0;
        $stats['name_ua_updated'] = array_key_exists('name_ua', $updates) ? 1 : 0;

        return $stats;
    }

    protected function matchesForProduct(Product $product, PartCatalogItem $item): Collection
    {
        $partNumber = PartNumberNormalizer::compact($product->external_sku ?: $item->part_number);

        if ($partNumber === '') {
            return collect();
        }

        return PartCatalogItem::query()
            ->whereIn('source', array_keys(self::SOURCE_LOCALE_POLICY))
            ->where('id', '!=', $item->id)
            ->whereNotNull('part_number')
            ->whereRaw("upper(replace(replace(replace(trim(part_number), '-', ''), ' ', ''), '.', '')) = ?", [$partNumber])
            ->get([
                'id',
                'source',
                'source_url',
                'part_number',
                'name',
                'name_en',
                'name_ru',
                'name_ua',
                'raw_attributes',
            ]);
    }

    protected function preferredSourceItem(Collection $matches, string $locale, PartCatalogItem $targetItem): ?PartCatalogItem
    {
        $referencedSourceItem = $this->referencedSourceItem($targetItem);

        if ($referencedSourceItem instanceof PartCatalogItem
            && $this->localizedName($referencedSourceItem, $locale) !== null) {
            return $referencedSourceItem;
        }

        foreach (self::PREFERRED_SOURCES[$locale] as $source) {
            $candidate = $matches
                ->where('source', $source)
                ->filter(fn (PartCatalogItem $item): bool => $this->localizedName($item, $locale) !== null)
                ->sortBy(fn (PartCatalogItem $item): string => collect([
                    str_pad((string) max(0, 1000 - mb_strlen((string) $this->localizedName($item, $locale))), 4, '0', STR_PAD_LEFT),
                    str_pad((string) $item->id, 12, '0', STR_PAD_LEFT),
                ])->implode('|'))
                ->first();

            if ($candidate instanceof PartCatalogItem) {
                return $candidate;
            }
        }

        return null;
    }

    protected function referencedSourceItem(PartCatalogItem $targetItem): ?PartCatalogItem
    {
        $sourceItemId = data_get($this->rawAttributes($targetItem), 'source_catalog_item_id');

        return is_numeric($sourceItemId) && (int) $sourceItemId !== (int) $targetItem->id
            ? PartCatalogItem::query()->find((int) $sourceItemId)
            : null;
    }

    protected function localizedName(PartCatalogItem $item, string $locale): ?string
    {
        if (! $this->sourceSupportsLocale((string) $item->source, $locale)) {
            return null;
        }

        $name = null;

        if ($locale === 'ru') {
            $name = $this->filled($item->name_ru)
                ? $item->name_ru
                : ($this->sourceIsMixedLanguage((string) $item->source) ? $item->name : null);

            if ($this->sourceIsMixedLanguage((string) $item->source) && $this->looksUkrainian((string) $name)) {
                return null;
            }
        }

        if ($locale === 'ua') {
            $name = $this->filled($item->name_ua)
                ? $item->name_ua
                : ($this->sourceIsMixedLanguage((string) $item->source) ? $item->name : null);

            if ($this->sourceIsMixedLanguage((string) $item->source) && ! $this->looksUkrainian((string) $name)) {
                return null;
            }
        }

        $name = trim((string) $name);

        return $this->isUsableLocalizedName($name) ? Str::limit($name, 255, '') : null;
    }

    protected function machineTranslationSourceText(Product $product, PartCatalogItem $item, string $targetLocale, array $localizedNameUpdates): ?string
    {
        $oppositeColumn = $targetLocale === 'ru' ? 'name_ua' : 'name_ru';
        $oppositeLocale = $targetLocale === 'ru' ? 'ua' : 'ru';

        if (array_key_exists($oppositeColumn, $localizedNameUpdates)
            && $this->isUsableLocalizedName($localizedNameUpdates[$oppositeColumn])) {
            return trim((string) $localizedNameUpdates[$oppositeColumn]);
        }

        if (! $this->isGoogleTranslatedName($item, $oppositeLocale)
            && $this->isUsableLocalizedName($item->{$oppositeColumn})) {
            return trim((string) $item->{$oppositeColumn});
        }

        return collect([
            $item->name_en,
            $item->name,
            $product->name,
        ])
            ->map(fn (mixed $value): string => trim((string) $value))
            ->first(fn (string $value): bool => $this->isUsableMachineTranslationSource($value));
    }

    protected function oppositeLocalizedTranslationSourceText(PartCatalogItem $item, string $targetLocale, array $localizedNameUpdates): ?string
    {
        $oppositeColumn = $targetLocale === 'ru' ? 'name_ua' : 'name_ru';

        if (array_key_exists($oppositeColumn, $localizedNameUpdates)
            && $this->isUsableLocalizedName($localizedNameUpdates[$oppositeColumn])) {
            return trim((string) $localizedNameUpdates[$oppositeColumn]);
        }

        if ($this->isUsableLocalizedName($item->{$oppositeColumn})) {
            return trim((string) $item->{$oppositeColumn});
        }

        return null;
    }

    protected function localizedTranslationSourceLocale(PartCatalogItem $item, string $targetLocale, array $localizedNameUpdates, ?string $sourceText): ?string
    {
        $sourceText = trim((string) $sourceText);
        if ($sourceText === '') {
            return null;
        }

        $oppositeColumn = $targetLocale === 'ru' ? 'name_ua' : 'name_ru';
        $oppositeLocale = $targetLocale === 'ru' ? 'ua' : 'ru';

        if (array_key_exists($oppositeColumn, $localizedNameUpdates)
            && trim((string) $localizedNameUpdates[$oppositeColumn]) === $sourceText) {
            return $oppositeLocale;
        }

        if (trim((string) $item->{$oppositeColumn}) === $sourceText) {
            return $oppositeLocale;
        }

        return null;
    }

    protected function looksEnglishSourceText(?string $sourceText): bool
    {
        $sourceText = trim((string) $sourceText);

        return $sourceText !== ''
            && preg_match('/[A-Za-z]/', $sourceText) === 1
            && preg_match('/\p{Cyrillic}/u', $sourceText) !== 1;
    }

    protected function isGoogleTranslatedName(PartCatalogItem $item, string $locale): bool
    {
        $rawAttributes = $this->rawAttributes($item);
        $site = data_get($rawAttributes, 'name_source_site_'.$locale)
            ?: ($locale === 'ru' ? data_get($rawAttributes, 'name_source_site') : null);
        $type = data_get($rawAttributes, 'name_source_type_'.$locale);

        return trim((string) $type) === 'donor_status_google_translate'
            || Str::lower(trim((string) $site)) === 'google translate';
    }

    protected function googleTranslate(?string $text, string $locale, ?string $sourceLocale = null): ?string
    {
        $text = trim((string) $text);
        $key = trim((string) config('services.google_translate.key'));

        if ($text === '' || $key === '') {
            return null;
        }

        if (app()->environment('testing') && ! (bool) config('services.google_translate.allow_in_testing', false)) {
            return null;
        }

        try {
            $payload = [
                'key' => $key,
                'q' => $text,
                'target' => $locale === 'ua' ? 'uk' : $locale,
                'format' => 'text',
            ];

            if ($sourceLocale !== null) {
                $payload['source'] = $sourceLocale === 'ua' ? 'uk' : $sourceLocale;
            }

            $response = Http::timeout((int) config('services.google_translate.timeout', 5))
                ->asForm()
                ->post('https://translation.googleapis.com/language/translate/v2', $payload);

            if (! $response->successful()) {
                return null;
            }

            $translated = data_get($response->json(), 'data.translations.0.translatedText');
        } catch (Throwable) {
            return null;
        }

        if (! is_string($translated)) {
            return null;
        }

        $translated = html_entity_decode(trim($translated), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return $this->isUsableLocalizedName($translated) ? Str::limit($translated, 255, '') : null;
    }

    protected function canAutofillName(PartCatalogManualNameService $manualNames, PartCatalogItem $item, string $column): bool
    {
        return ! $manualNames->isLocked($item, $column)
            || trim((string) $item->{$column}) === '';
    }

    protected function withoutEmptyManualNameLocks(
        PartCatalogManualNameService $manualNames,
        PartCatalogItem $item,
        array $updates,
        array $rawAttributes
    ): array {
        foreach (['ru' => 'name_ru', 'ua' => 'name_ua'] as $locale => $column) {
            if (! array_key_exists($column, $updates)) {
                continue;
            }

            if (! $manualNames->isLocked($item, $column) || trim((string) $item->{$column}) !== '') {
                continue;
            }

            unset($rawAttributes['manual_name_locks'][$locale]);
            $lockColumn = $column === 'name_ru' ? 'name_ru_manually_locked_at' : 'name_ua_manually_locked_at';
            $updates[$lockColumn] = null;
        }

        if (($rawAttributes['manual_name_locks'] ?? null) === []) {
            unset($rawAttributes['manual_name_locks']);
        }

        return [$updates, $rawAttributes];
    }

    protected function withCatalogNameSource(array $rawAttributes, PartCatalogItem $sourceItem, string $locale): array
    {
        $sourceUrl = $this->displayableSourceUrl($sourceItem);
        $site = $sourceUrl !== null ? $this->siteFromUrl($sourceUrl) : null;

        $rawAttributes['name_source_site'] = $site ?: $sourceItem->source;
        $rawAttributes['name_source_site_'.$locale] = $site ?: $sourceItem->source;
        $rawAttributes['name_source_url_'.$locale] = $sourceUrl;
        $rawAttributes['name_source_item_id_'.$locale] = $sourceItem->id;
        $rawAttributes['name_source_type_'.$locale] = 'donor_status_catalog_match';

        if ($locale === 'ru') {
            $rawAttributes['name_source_url'] = $sourceUrl;
        }

        return $rawAttributes;
    }

    protected function withItemNameSource(array $rawAttributes, PartCatalogItem $sourceItem, string $locale): array
    {
        $sourceRawAttributes = $this->rawAttributes($sourceItem);
        $copied = false;

        foreach (['site', 'url', 'item_id', 'type'] as $sourceKey) {
            $key = 'name_source_'.$sourceKey.'_'.$locale;

            if (array_key_exists($key, $sourceRawAttributes) && $sourceRawAttributes[$key] !== null && $sourceRawAttributes[$key] !== '') {
                $rawAttributes[$key] = $sourceRawAttributes[$key];
                $copied = true;
            }
        }

        if ($locale === 'ru') {
            foreach (['name_source_site', 'name_source_url'] as $key) {
                if (array_key_exists($key, $sourceRawAttributes) && $sourceRawAttributes[$key] !== null && $sourceRawAttributes[$key] !== '') {
                    $rawAttributes[$key] = $sourceRawAttributes[$key];
                    $copied = true;
                }
            }
        }

        return $copied
            ? $rawAttributes
            : $this->withCatalogNameSource($rawAttributes, $sourceItem, $locale);
    }

    protected function withGoogleNameSource(array $rawAttributes, string $locale): array
    {
        foreach (['item_id', 'marker'] as $sourceKey) {
            unset($rawAttributes['name_source_'.$sourceKey.'_'.$locale]);
        }

        $rawAttributes['name_source_site'] = 'Google Translate';
        $rawAttributes['name_source_site_'.$locale] = 'Google Translate';
        $rawAttributes['name_source_url_'.$locale] = 'https://cloud.google.com/translate';
        $rawAttributes['name_source_type_'.$locale] = 'donor_status_google_translate';

        if ($locale === 'ru') {
            $rawAttributes['name_source_url'] = 'https://cloud.google.com/translate';
        }

        return $rawAttributes;
    }

    protected function hasLocalizedNameSource(array $rawAttributes, string $locale): bool
    {
        return trim((string) data_get($rawAttributes, 'name_source_site_'.$locale)) !== ''
            || trim((string) data_get($rawAttributes, 'name_source_type_'.$locale)) !== ''
            || ($locale === 'ru' && trim((string) data_get($rawAttributes, 'name_source_site')) !== '');
    }

    protected function displayableSourceUrl(PartCatalogItem $item): ?string
    {
        return collect([
            data_get($item->raw_attributes, 'product_url'),
            data_get($item->raw_attributes, 'page_url'),
            data_get($item->raw_attributes, 'buy_url'),
            data_get($item->raw_attributes, 'teslashop_url'),
            data_get($item->raw_attributes, 'teslahelp_page_url'),
            data_get($item->raw_attributes, 'competitor_source_url'),
            data_get($item->raw_attributes, 'competitor_raw_attributes.product_url'),
            data_get($item->raw_attributes, 'competitor_raw_attributes.page_url'),
            data_get($item->raw_attributes, 'competitor_raw_attributes.buy_url'),
            $item->source_url,
        ])->first(fn (mixed $url): bool => is_string($url) && Str::startsWith($url, ['http://', 'https://']));
    }

    protected function sourceSupportsLocale(string $source, string $locale): bool
    {
        $policy = self::SOURCE_LOCALE_POLICY[$source] ?? [];

        return in_array($locale, $policy, true) || in_array('mixed', $policy, true);
    }

    protected function sourceIsMixedLanguage(string $source): bool
    {
        return in_array('mixed', self::SOURCE_LOCALE_POLICY[$source] ?? [], true);
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

    protected function isUsableMachineTranslationSource(string $text): bool
    {
        return $text !== ''
            && preg_match('/^\d+$/', $text) !== 1
            && preg_match('/^\d{6,8}(?:[-\s]?[A-Z0-9]{1,3}){0,3}$/i', $text) !== 1;
    }

    protected function looksUkrainian(string $name): bool
    {
        return preg_match('/[іїєґІЇЄҐ]/u', $name) === 1;
    }

    protected function isUnknownDamageStatus(?string $value): bool
    {
        $value = trim((string) $value);

        return $value === '' || $value === self::UNKNOWN_DAMAGE_STATUS;
    }

    protected function siteFromUrl(?string $url): ?string
    {
        $host = parse_url((string) $url, PHP_URL_HOST);

        return is_string($host) && $host !== ''
            ? preg_replace('/^www\./', '', $host)
            : null;
    }

    protected function rawAttributes(PartCatalogItem $item): array
    {
        return PartCatalogRawAttributes::from($item);
    }

    protected function filled(?string $value): bool
    {
        return trim((string) $value) !== '';
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
